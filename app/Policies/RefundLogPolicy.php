<?php

namespace App\Policies;

use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\User;
use App\Models\UserRole;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class RefundLogPolicy
{
    public function privateData(User $user, RefundLog $resource): Response
    {
        return $user->is_active && ($user->role === UserRole::Owner
            || ($user->role === UserRole::Admin && $resource->assigned_admin_id === $user->id)
            || ($user->role === UserRole::Customer && $resource->order->user_id === $user->id))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, RefundLog $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->order->user_id);
    }

    public function claim(User $user, RefundLog $resource): bool
    {
        return $user->is_active && $user->role === UserRole::Admin;
    }

    public function takeOver(User $user, RefundLog $resource): bool
    {
        return $user->is_active && $user->role === UserRole::Owner;
    }

    public function work(User $user, RefundLog $resource, int $expectedVersion): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Owner], true) && $resource->assigned_admin_id === $user->id
            && $resource->assignment_version === $expectedVersion && $resource->status !== RefundLogStatus::Completed;
    }
}
