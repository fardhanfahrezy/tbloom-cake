<?php

namespace App\Policies;

use App\Models\RefundTransfer;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class RefundTransferPolicy
{
    public function view(User $user, RefundTransfer $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->refundLog->order->user_id);
    }
}
