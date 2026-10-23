<?php

namespace App\Models;

enum PaymentMovementKind: string
{
    case Receipt = 'receipt';
    case Return = 'return';
}
