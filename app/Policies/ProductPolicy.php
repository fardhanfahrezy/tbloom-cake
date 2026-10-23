<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Models\UserRole;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Owner], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }
}
