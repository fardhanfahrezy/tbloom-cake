<?php

namespace App\Http\Requests;

use App\AssignRefund;
use App\Models\UserRole;
use App\RefundPrivateData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveRefundBankRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::authorize('privateData', $this->route('refund'))->allowed();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return RefundPrivateData::bankRules() + ($this->user()->role === UserRole::Customer ? [] : AssignRefund::versionRules());
    }
}
