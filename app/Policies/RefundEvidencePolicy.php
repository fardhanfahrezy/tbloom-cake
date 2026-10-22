<?php

namespace App\Policies;

use App\Models\RefundEvidence;
use App\Models\User;
use App\OwnedResourceAccess;
use Illuminate\Auth\Access\Response;

class RefundEvidencePolicy
{
    public function view(User $user, RefundEvidence $resource): Response
    {
        return OwnedResourceAccess::view($user, $resource->refundLog->order->user_id);
    }
}
