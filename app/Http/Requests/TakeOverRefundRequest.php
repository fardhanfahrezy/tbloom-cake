<?php

namespace App\Http\Requests;

use App\AssignRefund;
use App\CancellationRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TakeOverRefundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::authorize('takeOver', $this->route('refund'))->allowed();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return AssignRefund::versionRules() + CancellationRequests::reasonRules()
            + ['assigned_admin_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]];
    }
}
