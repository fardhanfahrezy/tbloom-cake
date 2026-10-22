<?php

namespace App\Policies;

use App\Models\RefundLog;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class RefundLogPolicy
{
    public function view(User $user, RefundLog $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->order->user_id);
    }
}
