<?php

namespace App\Models;

enum PaymentClosureResult: string
{
    case UnpaidClosed = 'unpaid_closed';
}
