<?php

namespace App\Models;

enum PaymentEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Review = 'review';
    case Failed = 'failed';
}
