<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class StoreAdminRequest extends RegisterCustomerRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('create', User::class)->allowed();
    }
}
