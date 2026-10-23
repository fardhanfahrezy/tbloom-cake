<?php

namespace App;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\Response;

class OwnedResourceAccess
{
    public static function view(User $actor, int $ownerId): Response
    {
        return $actor->is_active && (in_array($actor->role, [UserRole::Admin, UserRole::Owner], true)
            || ($actor->role === UserRole::Customer && $actor->id === $ownerId))
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
