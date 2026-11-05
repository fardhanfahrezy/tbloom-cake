<?php

namespace App;

use App\Models\NotificationDeliveryStatus;
use InvalidArgumentException;

class NotificationSendResult
{
    public function __construct(public readonly NotificationDeliveryStatus $status, public readonly ?string $providerMessageId = null)
    {
        if (! in_array($status, [NotificationDeliveryStatus::Sent, NotificationDeliveryStatus::Pending, NotificationDeliveryStatus::Unknown,
            NotificationDeliveryStatus::Failed, NotificationDeliveryStatus::Suppressed], true)
            || ($providerMessageId !== null && (! preg_match('/\A[\x21-\x7e]{1,128}\z/D', $providerMessageId)
                || ! in_array($status, [NotificationDeliveryStatus::Sent, NotificationDeliveryStatus::Unknown], true)))) {
            throw new InvalidArgumentException('Invalid notification transport outcome.');
        }
    }
}
