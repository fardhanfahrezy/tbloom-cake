<?php

namespace App;

use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

class PreparedNotificationTransport implements NotificationTransport
{
    public function __construct(private NotificationMessage $messages, private NotificationRecipientIdentity $identities,
        private FonnteClient $whatsapp, private EmailNotificationClient $email) {}

    public function send(NotificationDelivery $delivery): NotificationSendResult
    {
        if (config('notifications.enabled') !== true || DB::transactionLevel() !== 0) {
            return new NotificationSendResult(NotificationDeliveryStatus::Failed);
        }
        try {
            $recipient = $delivery->recipient_ciphertext;
            $channel = is_array($recipient) && is_string($recipient['channel'] ?? null) ? NotificationChannel::tryFrom($recipient['channel']) : null;
            $address = is_array($recipient) ? ($recipient['address'] ?? null) : null;
            $message = is_array($delivery->payload_minimal) ? $this->messages->render($delivery->template_key, $delivery->payload_minimal) : null;
            if ($channel === null || ! is_string($address) || $address === '' || $message === null
                || ! in_array($delivery->recipient_key, $this->identities->candidateKeys($channel, $address), true)) {
                return new NotificationSendResult(NotificationDeliveryStatus::Suppressed);
            }

            return $channel === NotificationChannel::WhatsApp ? $this->whatsapp->send($address, $message) : $this->email->send($address, $message);
        } catch (Throwable) {
            return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
        }
    }
}
