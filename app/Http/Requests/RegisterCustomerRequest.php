<?php

namespace App\Http\Requests;

use App\AccountPassword;
use App\CanonicalContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'whatsapp_number' => ['required', 'string', 'regex:/\A\+[1-9][0-9]{1,14}\z/', Rule::unique('users', 'whatsapp_number')],
            'password' => ['bail', 'required', 'string', 'min:12', 'confirmed', new AccountPassword],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.min' => 'Nama minimal 2 karakter.',
            'name.max' => 'Nama maksimal 100 karakter.',
            'email.unique' => 'Email sudah digunakan.',
            'whatsapp_number.unique' => 'Nomor WhatsApp sudah digunakan.',
            'whatsapp_number.regex' => 'Masukkan nomor WhatsApp dengan format internasional yang valid.',
            'password.min' => 'Password minimal 12 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ];
    }
}
