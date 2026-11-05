<?php

namespace App;

use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;

class DisabledNotificationTransport implements NotificationTransport
{
    public function send(NotificationDelivery $delivery): NotificationSendResult
    {
        return new NotificationSendResult(NotificationDeliveryStatus::Failed);
    }
}
