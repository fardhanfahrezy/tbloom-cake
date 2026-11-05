<?php

namespace App;

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundTransfer;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;

class AssignRefund
{
    public function __construct(private NotificationRecipientIdentity $recipients) {}

    /** @return array<string, list<string>> */
    public static function versionRules(): array
    {
        return ['assignment_version' => ['required', 'integer', 'min:0', 'max:4294967295']];
    }

    public function claim(int $refundId, User $actor, int $expectedVersion): ?RefundLog
    {
        return $this->execute($refundId, $actor, $actor->id, $expectedVersion, null);
    }

    public function takeOver(int $refundId, User $actor, int $destinationId, int $expectedVersion, string $reason): ?RefundLog
    {
        $reason = trim(Validator::make(['reason' => $reason], CancellationRequests::reasonRules())->validate()['reason']);

        return $this->execute($refundId, $actor, $destinationId, $expectedVersion, $reason);
    }

    private function execute(int $refundId, User $actor, int $destinationId, int $expectedVersion, ?string $reason): ?RefundLog
    {
        $takeover = $reason !== null;
        $this->authorize($actor, $actor->fresh(), $takeover);
        Validator::make(['assignment_version' => $expectedVersion, 'assigned_admin_id' => $destinationId], self::versionRules()
            + ['assigned_admin_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]])->validate();
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Refund assignment requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Refund assignment requires READ COMMITTED and UTC.');
        }
        $snapshot = RefundLog::query()->with('order')->findOrFail($refundId);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(fn (): ?RefundLog => $this->persist($snapshot, $actor, $destinationId, $expectedVersion, $reason));
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }

    private function persist(RefundLog $snapshot, User $actor, int $destinationId, int $expectedVersion, ?string $reason): ?RefundLog
    {
        $takeover = $reason !== null;
        $ids = array_unique(array_filter([$actor->id, $snapshot->order->user_id, $snapshot->assigned_admin_id, $destinationId]));
        $users = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
        $payment = Payment::query()->whereKey($snapshot->payment_id)->lockForUpdate()->firstOrFail();
        $refund = RefundLog::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $transfers = RefundTransfer::query()->where('refund_log_id', $refund->id)->orderBy('id')->lockForUpdate()->get();
        $this->authorize($actor, $users->get($actor->id), $takeover);
        if ($refund->order_id !== $order->id || $refund->payment_id !== $payment->id || $payment->order_id !== $order->id
            || $order->user_id !== $snapshot->order->user_id) {
            return null;
        }
        $action = $takeover ? 'refund.assignment_taken_over' : 'refund.claimed';
        $hash = $reason === null ? null : hash('sha256', $reason);
        if ($refund->assignment_version === $expectedVersion + 1 && $refund->assigned_admin_id === $destinationId
            && AuditLog::query()->where('entity_type', 'refund_log')->where('entity_id', $refund->id)->where('action', $action)
                ->where('actor_type', 'user')->where('actor_user_id', $actor->id)->where('before_redacted->assignment_version', $expectedVersion)
                ->where('after_redacted->assignment_version', $refund->assignment_version)->where('after_redacted->assigned_admin_id', $destinationId)
                ->where('after_redacted->reason_sha256', $hash)->exists()) {
            return $refund;
        }
        $destination = $users->get($destinationId);
        if ($refund->status === RefundLogStatus::Completed || $refund->assignment_version !== $expectedVersion || $refund->assignment_version >= 4294967295
            || $refund->assigned_admin_id !== $snapshot->assigned_admin_id || $destination === null || ! $destination->is_active
            || ! in_array($destination->role, $takeover ? [UserRole::Admin, UserRole::Owner] : [UserRole::Admin], true)) {
            return null;
        }
        if ($takeover) {
            if ($refund->assigned_admin_id === $destinationId) {
                return null;
            }
        } elseif ($refund->assigned_admin_id !== null || $refund->assignment_version !== 0 || $transfers->isNotEmpty()
            || ! in_array($refund->status, [RefundLogStatus::Pending, RefundLogStatus::Review], true)) {
            return null;
        }
        $before = ['assigned_admin_id' => $refund->assigned_admin_id, 'assignment_version' => $refund->assignment_version, 'status' => $refund->status->value];
        $refund->forceFill(['assigned_admin_id' => $destinationId, 'assignment_version' => $refund->assignment_version + 1,
            'status' => ! $takeover && $refund->status === RefundLogStatus::Pending ? RefundLogStatus::Processing : $refund->status])->save();
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => 'refund_log', 'entity_id' => $refund->id,
            'action' => $action, 'reason' => $reason, 'before_redacted' => $before,
            'after_redacted' => ['assigned_admin_id' => $destinationId, 'assignment_version' => $refund->assignment_version,
                'status' => $refund->status->value, 'reason_sha256' => $hash], 'correlation_id' => (string) Str::uuid()])->save();
        $this->notices($refund, $order, $before['assigned_admin_id']);

        return $refund;
    }

    private function authorize(User $actor, ?User $current, bool $takeover): void
    {
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        if ((int) Auth::id() !== $actor->id || $current === null || ! $current->is_active
            || $current->role !== ($takeover ? UserRole::Owner : UserRole::Admin) || $time < '09:00:00' || $time > '17:00:00') {
            throw new AuthorizationException('Refund assignment requires the authorized active staff role during 09:00–17:00 WIB.');
        }
    }

    private function notices(RefundLog $refund, Order $order, ?int $oldAssigneeId): void
    {
        $staff = User::query()->where('is_active', true)->whereIn('role', [UserRole::Admin, UserRole::Owner])
            ->where(fn ($query) => $query->whereIn('id', array_filter([$oldAssigneeId, $refund->assigned_admin_id]))
                ->orWhere('role', UserRole::Owner))->orderBy('id')->get();
        foreach ($staff as $recipient) {
            foreach ([NotificationChannel::Email, NotificationChannel::WhatsApp] as $channel) {
                $address = $channel === NotificationChannel::Email ? $recipient->email : $recipient->whatsapp_number;
                $keys = $this->recipients->candidateKeys($channel, $address);
                $key = 'refund:'.$refund->id.':assignment:'.$refund->assignment_version;
                $template = 'admin.refund_assignment.v1';
                if (NotificationDelivery::query()->where('event_key', $key)->where('template_key', $template)->whereIn('recipient_key', $keys)->exists()) {
                    continue;
                }
                $delivery = new NotificationDelivery;
                $delivery->forceFill(['event_key' => $key, 'template_key' => $template, 'recipient_key' => $keys[0],
                    'recipient_ciphertext' => ['channel' => $channel->value, 'address' => $address], 'entity_type' => 'refund_log', 'entity_id' => $refund->id,
                    'payload_minimal' => ['order_number' => $order->order_number, 'refund_log_id' => $refund->id, 'status' => $refund->status->value], 'status' => 'pending'])->save();
            }
        }
    }
}
