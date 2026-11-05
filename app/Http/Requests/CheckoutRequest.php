<?php

namespace App\Http\Requests;

use App\CheckoutDetails;
use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->role === UserRole::Customer;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CheckoutDetails::normalize($this->all()));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return CheckoutDetails::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CheckoutDetails::messages();
    }
}
