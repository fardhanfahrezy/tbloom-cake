<?php

namespace App\Http\Requests;

use App\CancellationRequests;
use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class DecidePaidCancellationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_active === true && in_array($this->user()->role, [UserRole::Admin, UserRole::Owner], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return CancellationRequests::reasonRules();
    }
}
