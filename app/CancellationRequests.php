<?php

namespace App;

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class CancellationRequests
{
    public function __construct(private NotificationRecipientIdentity $recipients) {}

    /** @return array<string, list<string>> */
    public static function reasonRules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000', 'regex:/[^\s\p{Z}]/u']];
    }

    /** @return array<string, list<string>> */
    public static function submissionRules(): array
    {
        return self::reasonRules() + ['idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D']];
    }

    /** @param array<string, mixed> $input */
    public function submit(int $orderId, User $actor, array $input): OrderRequest
    {
        $input = Validator::make($input, self::submissionRules())->validate();
        $reason = trim($input['reason']);
        $hash = hash('sha256', json_encode(['order_id' => $orderId, 'type' => 'cancellation', 'reason' => $reason], JSON_THROW_ON_ERROR));

        return $this->transaction(function () use ($orderId, $actor, $input, $reason, $hash): OrderRequest {
            $customer = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($customer !== null && Auth::id() === $customer->id && $customer->is_active && $customer->role === UserRole::Customer, 404);
            $order = Order::query()->where('user_id', $customer->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->where('order_id', $order->id)->lockForUpdate()->first();
            $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $existing = OrderRequest::query()->where('user_id', $customer->id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Kunci pengajuan sudah dipakai untuk detail berbeda.');

                return $existing;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Paid], true) || $now < $order->created_at
                || $payment === null || $order->item === null || $order->item->quota_released_at !== null
                || ($order->status === OrderStatus::Paid && ! $this->withinCancellationDate($order, $now))) {
                throw ValidationException::withMessages(['cancellation' => 'Pembatalan paid harus diajukan paling lambat akhir H-2 dan sebelum penyerahan.']);
            }
            $active = $requests->firstWhere('status', OrderRequestStatus::Pending);
            if ($active !== null) {
                abort_if($active->type === OrderRequestType::Cancellation, 409, 'Pengajuan pembatalan masih menunggu penyelesaian.');
                $active->forceFill(['status' => OrderRequestStatus::Rejected, 'reviewed_by' => $customer->id, 'reviewed_at' => $now,
                    'decision_reason' => 'superseded_by_cancellation'])->save();
                $this->audit($active, $customer, 'order_request.superseded', ['status' => 'pending'], ['status' => 'rejected', 'reason_code' => 'superseded_by_cancellation']);
            }
            $request = new OrderRequest;
            $request->forceFill(['order_id' => $order->id, 'user_id' => $customer->id, 'type' => OrderRequestType::Cancellation,
                'decision_mode' => $order->status === OrderStatus::Pending ? OrderRequestDecisionMode::Automatic : OrderRequestDecisionMode::Manual,
                'reason' => $reason, 'status' => OrderRequestStatus::Pending, 'submitted_at' => $now,
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash])->save();
            $this->audit($request, $customer, 'cancellation.submitted', null, ['status' => 'pending', 'decision_mode' => $request->decision_mode->value,
                'submitted_at' => $now->toIso8601String(), 'reason_sha256' => hash('sha256', $reason)]);
            $this->notices($request, $order, 'submitted');

            return $request;
        });
    }

    public function reject(int $requestId, User $actor, string $reason): OrderRequest
    {
        $reason = trim(Validator::make(['reason' => $reason], self::reasonRules())->validate()['reason']);
        $snapshot = OrderRequest::query()->findOrFail($requestId);

        return $this->transaction(function () use ($snapshot, $actor, $reason): OrderRequest {
            $users = User::query()->whereIn('id', [$actor->id, $snapshot->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $requests = OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $staff = $users->get($actor->id);
            $local = CarbonImmutable::now('Asia/Jakarta');
            if ($staff === null || Auth::id() !== $staff->id || ! $staff->is_active || ! in_array($staff->role, [UserRole::Admin, UserRole::Owner], true)
                || $local->lt($local->startOfDay()->setTime(9, 0)) || $local->gt($local->startOfDay()->setTime(17, 0))) {
                throw new AuthorizationException('Keputusan pembatalan hanya untuk staf aktif pada 09.00–17.00 WIB.');
            }
            $request = $requests->get($snapshot->id);
            abort_unless($request !== null && $request->user_id === $order->user_id && $request->user_id === $snapshot->user_id
                && $request->type === OrderRequestType::Cancellation && $request->decision_mode === OrderRequestDecisionMode::Manual, 409);
            if ($request->status === OrderRequestStatus::Rejected) {
                abort_unless($request->reviewed_by === $staff->id && $request->decision_reason === $reason, 409);

                return $request;
            }
            abort_unless($request->status === OrderRequestStatus::Pending && $order->status === OrderStatus::Paid
                && $request->submitted_at >= $order->created_at && $request->submitted_at <= CarbonImmutable::now('UTC')
                && $this->withinCancellationDate($order, $request->submitted_at), 409);
            $request->forceFill(['status' => OrderRequestStatus::Rejected, 'reviewed_by' => $staff->id, 'reviewed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                'decision_reason' => $reason])->save();
            $this->audit($request, $staff, 'cancellation.rejected', ['status' => 'pending'], ['status' => 'rejected', 'reason_sha256' => hash('sha256', $reason)]);
            $this->notices($request, $order, 'rejected');

            return $request;
        });
    }

    private function withinCancellationDate(Order $order, CarbonImmutable $submitted): bool
    {
        return $submitted->setTimezone('Asia/Jakarta')->toDateString() <= $order->pickup_date->subDays(2)->toDateString();
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(OrderRequest $request, User $actor, string $action, ?array $before, array $after): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_user_id' => $actor->id, 'actor_type' => 'user', 'entity_type' => 'order_request', 'entity_id' => $request->id,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'correlation_id' => (string) Str::uuid()])->save();
    }

    private function notices(OrderRequest $request, Order $order, string $event): void
    {
        $recipients = [['email' => $order->email_snapshot, 'whatsapp' => $order->whatsapp_snapshot, 'template' => 'customer.cancellation_'.$event.'.v1']];
        foreach (User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->get() as $staff) {
            $recipients[] = ['email' => $staff->email, 'whatsapp' => $staff->whatsapp_number, 'template' => 'admin.cancellation.v1'];
        }
        foreach ($recipients as $recipient) {
            foreach ([NotificationChannel::Email, NotificationChannel::WhatsApp] as $channel) {
                $address = $recipient[$channel === NotificationChannel::Email ? 'email' : 'whatsapp'];
                $keys = $this->recipients->candidateKeys($channel, $address);
                $eventKey = 'order_request:'.$request->id.':'.$event;
                if (NotificationDelivery::query()->where('event_key', $eventKey)->where('template_key', $recipient['template'])->whereIn('recipient_key', $keys)->exists()) {
                    continue;
                }
                $delivery = new NotificationDelivery;
                $delivery->forceFill(['event_key' => $eventKey, 'template_key' => $recipient['template'], 'recipient_key' => $keys[0],
                    'recipient_ciphertext' => ['channel' => $channel->value, 'address' => $address], 'entity_type' => 'order_request', 'entity_id' => $request->id,
                    'payload_minimal' => ['order_number' => $order->order_number, 'request_id' => $request->id, 'status' => $request->status->value], 'status' => 'pending'])->save();
            }
        }
    }

    private function transaction(Closure $operation): OrderRequest
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Cancellation requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Cancellation requires READ COMMITTED and UTC.');
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
