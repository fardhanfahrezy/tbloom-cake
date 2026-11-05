<?php

namespace App;

use App\Models\NotificationDelivery;

interface NotificationTransport
{
    public function send(NotificationDelivery $delivery): NotificationSendResult;
}
