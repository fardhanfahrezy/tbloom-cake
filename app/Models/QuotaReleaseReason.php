<?php

namespace App\Models;

enum QuotaReleaseReason: string
{
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
