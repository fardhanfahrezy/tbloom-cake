<?php

namespace App\Models;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
