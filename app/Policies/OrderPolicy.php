<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    public function view(User $user, Order $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->user_id);
    }
}
