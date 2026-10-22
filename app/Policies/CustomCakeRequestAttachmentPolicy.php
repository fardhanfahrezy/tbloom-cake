<?php

namespace App\Policies;

use App\Models\CustomCakeRequestAttachment;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class CustomCakeRequestAttachmentPolicy
{
    public function view(User $user, CustomCakeRequestAttachment $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->request->user_id);
    }
}
