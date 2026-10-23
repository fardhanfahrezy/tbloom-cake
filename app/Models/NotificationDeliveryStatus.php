<?php

namespace App\Models;

enum NotificationDeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Unknown = 'unknown';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
}
