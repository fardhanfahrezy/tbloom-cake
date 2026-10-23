<?php

namespace App\Models;

enum PickupMethod: string
{
    case SelfPickup = 'self_pickup';
    case OnlineCourier = 'online_courier';
}
