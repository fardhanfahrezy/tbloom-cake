<?php

namespace App\Http\Requests;

use App\CanonicalContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'whatsapp_number'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $field === 'email'
                    ? CanonicalContact::email($this->input($field))
                    : CanonicalContact::whatsapp($this->input($field))]);
            }
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $contactsChanged = $this->input('email') !== $this->user()->email || $this->input('whatsapp_number') !== $this->user()->whatsapp_number;

        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'whatsapp_number' => ['required', 'string', 'regex:/\A\+[1-9][0-9]{1,14}\z/', Rule::unique('users', 'whatsapp_number')->ignore($this->user()->id)],
            'current_password' => [Rule::requiredIf($contactsChanged), 'nullable', 'string', 'current_password:web'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['current_password.required' => 'Masukkan password saat ini untuk mengubah kontak.', 'current_password.current_password' => 'Password saat ini tidak sesuai.'];
    }
}
