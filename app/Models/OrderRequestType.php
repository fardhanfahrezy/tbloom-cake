<?php

namespace App\Models;

enum OrderRequestType: string
{
    case Cancellation = 'cancellation';
    case Change = 'change';
}
