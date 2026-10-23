<?php

namespace App\Policies;

use App\Models\CustomCakeRequest;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class CustomCakeRequestPolicy
{
    public function view(User $user, CustomCakeRequest $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->user_id);
    }
}
