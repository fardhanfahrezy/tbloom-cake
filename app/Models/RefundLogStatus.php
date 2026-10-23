<?php

namespace App\Models;

enum RefundLogStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Review = 'review';
    case Completed = 'completed';
}
