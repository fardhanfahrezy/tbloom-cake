<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Models\UserRole;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Customer;
    }

    public function view(User $user, Order $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->user_id);
    }

    public function pay(User $user, Order $resource): Response
    {
        return $user->is_active && $user->role === UserRole::Customer && $user->id === $resource->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
