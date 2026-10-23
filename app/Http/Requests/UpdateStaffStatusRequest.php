<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('update', $this->route('user'))->allowed();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:2', 'max:1000']];
    }
}
