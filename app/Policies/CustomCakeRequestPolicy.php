<?php

namespace App\Policies;

use App\Models\CustomCakeRequest;
use App\Models\User;
use App\Models\UserRole;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class CustomCakeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Owner], true);
    }

    public function manage(User $user, CustomCakeRequest $resource): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Customer;
    }

    public function view(User $user, CustomCakeRequest $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->user_id);
    }
}
