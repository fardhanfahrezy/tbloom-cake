<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class PaymentPolicy
{
    public function view(User $user, Payment $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->order->user_id);
    }
}
