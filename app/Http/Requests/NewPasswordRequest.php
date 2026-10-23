<?php

namespace App\Http\Requests;

use App\AccountPassword;

class NewPasswordRequest extends PasswordResetLinkRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'token' => ['required', 'string', 'max:255'],
            'password' => ['bail', 'required', 'string', 'min:12', 'confirmed', new AccountPassword],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.min' => 'Password minimal 12 karakter.', 'password.confirmed' => 'Konfirmasi password tidak cocok.'];
    }
}
