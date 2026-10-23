<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $target): Response
    {
        return $this->viewAny($user) && in_array($target->role, [UserRole::Admin, UserRole::Owner], true)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
