<?php

namespace App\Http\Requests;

use App\CanonicalContact;
use Illuminate\Foundation\Http\FormRequest;

class PasswordResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => CanonicalContact::email($this->input('email'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:254']];
    }
}
