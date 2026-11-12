<?php
namespace App\Policies;
use App\Models\Category;
use App\Models\User;
use App\Models\UserRole;
class CategoryPolicy
{
    public function viewAny(User $user): bool { return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Owner], true); }
    public function create(User $user): bool { return $this->viewAny($user); }
    public function update(User $user, Category $category): bool { return $this->viewAny($user); }
    public function delete(User $user, Category $category): bool { return $this->viewAny($user); }
}
