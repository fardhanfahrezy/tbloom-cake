<?php

namespace App;

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentStatus;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class OrderChanges
{
    public function __construct(private NotificationRecipientIdentity $recipients) {}

    /** @return array<string, list<string>> */
    public static function submissionRules(): array
    {
        return CancellationRequests::submissionRules() + ['requested_changes' => ['required', 'array:flavor_code,filling_code,cover_color,inscription_choice,inscription', 'min:1']];
    }

    /** @param array<string, mixed> $input */
    public function submit(int $orderId, User $actor, array $input): OrderRequest
    {
        $data = Validator::make($input, self::submissionRules())->validate();
        $changes = $this->canonicalChanges($data['requested_changes']);
        $reason = trim($data['reason']);
        $hash = hash('sha256', json_encode(['type' => 'change', 'order_id' => $orderId, 'changes' => $changes, 'reason' => $reason], JSON_THROW_ON_ERROR));

        return $this->transaction(function () use ($orderId, $actor, $data, $changes, $reason, $hash): OrderRequest {
            $customer = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->authorizeCustomer($actor, $customer);
            $order = Order::query()->where('user_id', $customer->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $item = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $this->authorizeCustomer($actor, $customer);
            $existing = OrderRequest::query()->where('user_id', $customer->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Kunci pengajuan sudah digunakan untuk detail berbeda.');

                return $existing;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($order->status !== OrderStatus::Paid || $now < $order->created_at || ! $this->eligibleDate($order, $now)
                || $item->quota_released_at !== null || $item->quota_release_reason !== null) {
                throw ValidationException::withMessages(['change' => 'Perubahan personalisasi paid harus diajukan paling lambat akhir H-2 sebelum penyerahan.']);
            }
            abort_if($requests->contains('status', OrderRequestStatus::Pending), 409, 'Masih ada pengajuan aktif untuk pesanan ini.');
            $this->canonicalChanges($changes, true);
            $request = new OrderRequest;
            $request->forceFill(['order_id' => $order->id, 'user_id' => $customer->id, 'type' => OrderRequestType::Change,
                'decision_mode' => OrderRequestDecisionMode::Manual, 'status' => OrderRequestStatus::Pending, 'requested_changes' => $changes,
                'reason' => $reason, 'submitted_at' => $now, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash])->save();
            $this->audit('order_request', $request->id, $customer, 'order_change.submitted', [],
                ['status' => 'pending', 'fields' => array_keys($changes), 'reason_sha256' => hash('sha256', $reason)]);
            $this->notice($request, $order, 'submitted');

            return $request;
        });
    }

    public function approve(int $requestId, User $actor, string $reason): OrderRequest
    {
        return $this->decide($requestId, $actor, $reason, true);
    }

    public function reject(int $requestId, User $actor, string $reason): OrderRequest
    {
        return $this->decide($requestId, $actor, $reason, false);
    }

    private function decide(int $requestId, User $actor, string $reason, bool $applying): OrderRequest
    {
        $this->authorizeStaff($actor, $actor->fresh());
        $reason = trim(Validator::make(['reason' => $reason], CancellationRequests::reasonRules())->validate()['reason']);
        $snapshot = OrderRequest::query()->findOrFail($requestId);

        return $this->transaction(function () use ($snapshot, $actor, $reason, $applying): OrderRequest {
            $users = User::query()->whereIn('id', [$actor->id, $snapshot->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $item = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $this->authorizeStaff($actor, $users->get($actor->id));
            $request = $requests->get($snapshot->id);
            abort_unless($request !== null && $request->user_id === $order->user_id && $request->user_id === $snapshot->user_id
                && $request->type === OrderRequestType::Change && $request->decision_mode === OrderRequestDecisionMode::Manual, 409);
            $target = $applying ? OrderRequestStatus::Applied : OrderRequestStatus::Rejected;
            $action = $applying ? 'order_change.applied' : 'order_change.rejected';
            if ($request->status === $target) {
                abort_unless($request->decision_reason === $reason && $request->reviewed_at !== null
                    && (! $applying || $request->applied_at !== null)
                    && AuditLog::query()->where('entity_type', 'order_request')->where('entity_id', $request->id)->where('action', $action)
                        ->where('after_redacted->reason_sha256', hash('sha256', $reason))->exists(), 409);

                return $request;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            abort_unless($request->status === OrderRequestStatus::Pending && $request->submitted_at >= $order->created_at
                && $request->submitted_at <= $now && $this->eligibleDate($order, $request->submitted_at), 409);
            $before = ['status' => 'pending'];
            $after = ['status' => $target->value, 'reason_sha256' => hash('sha256', $reason)];
            if ($applying) {
                abort_unless($order->status === OrderStatus::Paid && $payment->status === PaymentStatus::Paid && $payment->review_reason === null
                    && $item->quota_released_at === null && $item->quota_release_reason === null, 409);
                $changes = $this->canonicalChanges($request->requested_changes ?? [], true);
                $effective = Arr::only($changes, ['flavor_code', 'filling_code', 'cover_color']);
                if (isset($changes['flavor_code'])) {
                    $effective['flavor_label_snapshot'] = config('checkout.flavors.'.$changes['flavor_code']);
                }
                if (isset($changes['filling_code'])) {
                    $effective['filling_label_snapshot'] = config('checkout.fillings.'.$changes['filling_code']);
                }
                if (isset($changes['inscription_choice'])) {
                    $effective['inscription'] = $changes['inscription_choice'] === 'none' ? '' : $changes['inscription'];
                }
                $before += Arr::only($item->getAttributes(), array_keys($effective));
                $item->forceFill($effective)->save();
                $after += $effective;
            }
            $request->forceFill(['status' => $target, 'reviewed_by' => $actor->id, 'reviewed_at' => $now,
                'applied_at' => $applying ? $now : null, 'decision_reason' => $reason])->save();
            $this->audit('order_request', $request->id, $actor, $action, $before, $after);
            $this->notice($request, $order, $applying ? 'applied' : 'rejected');

            return $request;
        });
    }

    public function updateNote(int $orderId, User $actor, ?string $note): Order
    {
        $note = Validator::make(['handover_note' => $note], ['handover_note' => ['nullable', 'string', 'max:255']])->validate()['handover_note'];
        $note = $note === null || trim($note) === '' ? null : trim($note);

        return $this->transaction(function () use ($orderId, $actor, $note): Order {
            $customer = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->authorizeCustomer($actor, $customer);
            $order = Order::query()->where('user_id', $customer->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->authorizeCustomer($actor, $customer);
            abort_unless(in_array($order->status, [OrderStatus::Pending, OrderStatus::Paid], true) && $order->completed_at === null && $order->handed_over_by === null, 409);
            if ($order->handover_note === $note) {
                return $order;
            }
            $before = $order->handover_note;
            $order->forceFill(['handover_note' => $note])->save();
            $this->audit('order', $order->id, $customer, 'order.handover_note_updated', ['note_sha256' => $before === null ? null : hash('sha256', $before)],
                ['note_sha256' => $note === null ? null : hash('sha256', $note)]);

            return $order;
        });
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function canonicalChanges(array $changes, bool $live = false): array
    {
        Validator::make(['requested_changes' => $changes], ['requested_changes' => self::submissionRules()['requested_changes']])->validate();
        if (array_key_exists('inscription', $changes) && ! array_key_exists('inscription_choice', $changes)) {
            throw ValidationException::withMessages(['inscription_choice' => 'Pilih tulisan atau tanpa tulisan secara eksplisit.']);
        }
        $rules = Arr::only(CheckoutDetails::rules($live ? CarbonImmutable::now('UTC') : null), ['flavor_code', 'filling_code', 'cover_color', 'inscription_choice', 'inscription']);
        foreach (['flavor_code', 'filling_code', 'cover_color', 'inscription_choice'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $changes = CheckoutDetails::normalize($changes);
        if (isset($changes['cover_color']) && is_string($changes['cover_color'])) {
            $changes['cover_color'] = trim($changes['cover_color']);
        }
        $changes = Validator::make($changes, $rules, CheckoutDetails::messages())->validate();
        if (($changes['inscription_choice'] ?? null) === 'none') {
            unset($changes['inscription']);
        }
        ksort($changes);

        return $changes;
    }

    private function eligibleDate(Order $order, CarbonImmutable $submitted): bool
    {
        return $submitted->setTimezone('Asia/Jakarta')->toDateString() <= $order->pickup_date->subDays(2)->toDateString();
    }

    private function authorizeCustomer(User $actor, ?User $current): void
    {
        abort_unless((int) Auth::id() === $actor->id && $current !== null && $current->is_active && $current->role === UserRole::Customer, 404);
    }

    private function authorizeStaff(User $actor, ?User $current): void
    {
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Owner], true)
            || $time < '09:00:00' || $time > '17:00:00') {
            throw new AuthorizationException('Keputusan perubahan hanya untuk staf aktif pada 09.00–17.00 WIB.');
        }
    }

    private function notice(OrderRequest $request, Order $order, string $event): void
    {
        foreach ([NotificationChannel::Email, NotificationChannel::WhatsApp] as $channel) {
            $address = $channel === NotificationChannel::Email ? $order->email_snapshot : $order->whatsapp_snapshot;
            $keys = $this->recipients->candidateKeys($channel, $address);
            $key = 'order_request:'.$request->id.':change_'.$event;
            $template = 'customer.order_change_'.$event.'.v1';
            if (NotificationDelivery::query()->where('event_key', $key)->where('template_key', $template)->whereIn('recipient_key', $keys)->exists()) {
                continue;
            }
            $delivery = new NotificationDelivery;
            $delivery->forceFill(['event_key' => $key, 'template_key' => $template, 'recipient_key' => $keys[0],
                'recipient_ciphertext' => ['channel' => $channel->value, 'address' => $address], 'entity_type' => 'order_request', 'entity_id' => $request->id,
                'payload_minimal' => ['order_number' => $order->order_number, 'request_id' => $request->id, 'status' => $request->status->value], 'status' => 'pending'])->save();
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function audit(string $entityType, int $entityId, User $actor, string $action, array $before, array $after): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'correlation_id' => (string) Str::uuid()])->save();
    }

    /** @param Closure(): (Order|OrderRequest) $operation */
    private function transaction(Closure $operation): Order|OrderRequest
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Order changes require MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Order changes require READ COMMITTED and UTC.');
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction($operation);
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }
}
