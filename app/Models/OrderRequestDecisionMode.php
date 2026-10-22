<?php

namespace App\Models;

enum OrderRequestDecisionMode: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
