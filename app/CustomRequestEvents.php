<?php

namespace App;

use App\Models\AuditLog;
use App\Models\CustomCakeRequest;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Str;

class CustomRequestEvents
{
    public function __construct(private NotificationRecipientIdentity $recipients) {}

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(CustomCakeRequest $custom, User $actor, string $action, array $before, array $after, ?string $reason = null): void
    {
        $eventId = (string) Str::uuid();
        $audit = new AuditLog;
        $audit->forceFill(['actor_user_id' => $actor->id, 'actor_type' => 'user', 'entity_type' => 'custom_request',
            'entity_id' => $custom->id, 'action' => 'custom_request.'.$action, 'before_redacted' => $before,
            'after_redacted' => $after, 'reason' => $reason, 'correlation_id' => $eventId])->save();
        $audience = User::query()->where('is_active', true)->where(function ($query) use ($custom): void {
            $query->where(function ($query) use ($custom): void {
                $query->whereKey($custom->user_id)->where('role', UserRole::Customer);
            })->orWhereIn('role', [UserRole::Admin, UserRole::Owner]);
        })->orderBy('id')->get();
        foreach ($audience as $recipient) {
            foreach ([NotificationChannel::Email, NotificationChannel::WhatsApp] as $channel) {
                $address = $channel === NotificationChannel::Email ? $recipient->email : $recipient->whatsapp_number;
                $delivery = new NotificationDelivery;
                $delivery->forceFill(['event_key' => 'custom:'.$custom->id.':'.$eventId,
                    'recipient_key' => $this->recipients->key($channel, $address),
                    'recipient_ciphertext' => ['channel' => $channel->value, 'address' => $address],
                    'template_key' => ($recipient->role === UserRole::Customer ? 'customer' : 'admin').'.custom_request.v1',
                    'entity_type' => 'custom_request', 'entity_id' => $custom->id,
                    'payload_minimal' => ['request_number' => $custom->request_number, 'status' => $custom->status->value],
                    'status' => 'pending'])->save();
                NotificationOutbox::enqueue($delivery);
            }
        }
    }
}
