<?php

namespace App;

use App\Models\NotificationDeliveryStatus;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailNotificationClient
{
    public function send(string $canonicalRecipient, string $message): NotificationSendResult
    {
        if (config('notifications.enabled') !== true || config('mail.default') !== 'smtp'
            || config('mail.mailers.smtp.transport') !== 'smtp'
            || ! is_numeric(config('mail.mailers.smtp.timeout')) || config('mail.mailers.smtp.timeout') < 1 || config('mail.mailers.smtp.timeout') > 30
            || (app()->environment('testing') && ! Mail::isFake())
            || strlen($canonicalRecipient) > 254 || CanonicalContact::email($canonicalRecipient) !== $canonicalRecipient
            || ! filter_var($canonicalRecipient, FILTER_VALIDATE_EMAIL)
            || ! mb_check_encoding($message, 'UTF-8') || trim($message) === '' || mb_strlen($message) > 4096
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)) {
            return new NotificationSendResult(NotificationDeliveryStatus::Failed);
        }

        try {
            $sent = Mail::mailer('smtp')->to($canonicalRecipient)->send(new OperationalNotificationMail($message));
            if ($sent instanceof SentMessage) {
                $id = $sent->getMessageId();
                if (is_string($id) && preg_match('/\A[\x21-\x7e]{1,128}\z/D', $id)) {
                    return new NotificationSendResult(NotificationDeliveryStatus::Sent, $id);
                }
            }
        } catch (Throwable) {
            return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
        }

        return new NotificationSendResult(NotificationDeliveryStatus::Unknown);
    }
}
