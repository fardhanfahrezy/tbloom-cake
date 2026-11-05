<?php

namespace App\Http\Requests;

use App\RefundTransfers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ResolveRefundTransferRequest extends FormRequest
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
        return RefundTransfers::resolutionRules($this->routeIs('staff.refunds.transfers.completion.store'));
    }
}
