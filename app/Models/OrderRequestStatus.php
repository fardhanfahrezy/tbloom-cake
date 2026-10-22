<?php

namespace App\Models;

enum OrderRequestStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Rejected = 'rejected';
}
