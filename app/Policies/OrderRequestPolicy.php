<?php

namespace App\Policies;

use App\Models\OrderRequest;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class OrderRequestPolicy
{
    public function view(User $user, OrderRequest $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->user_id);
    }
}
