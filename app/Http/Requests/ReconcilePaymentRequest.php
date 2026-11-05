<?php

namespace App\Http\Requests;

use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class ReconcilePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true && $this->user()->role === UserRole::Owner;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:observe,confirm_unpaid,resolve_exception,recover_timely'],
            'transaction_id' => ['nullable', 'required_if:action,confirm_unpaid', 'required_with:paid_at_utc', 'uuid'],
            'paid_at_utc' => [$this->input('action') === 'observe' && $this->filled('transaction_id') ? 'required' : 'nullable', 'prohibited_unless:action,observe', 'date_format:Y-m-d H:i:s'],
            'confirmed_at_utc' => ['nullable', 'prohibited_unless:action,confirm_unpaid', 'required_if:action,confirm_unpaid', 'date_format:Y-m-d H:i:s'],
            'evidence_reference' => ['nullable', 'required_if:action,confirm_unpaid,resolve_exception,recover_timely', 'required_with:transaction_id,paid_at_utc', 'string', 'regex:/\A[\x21-\x7e]{1,128}\z/'],
        ];
    }
}
