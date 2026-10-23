<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->is_active, 403);
                $data = $request->safe()->only(['name', 'email', 'whatsapp_number']);
                $contactsChanged = $data['email'] !== $user->email || $data['whatsapp_number'] !== $user->whatsapp_number;
                if ($contactsChanged && ! Hash::check($request->string('current_password')->toString(), $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'Password saat ini tidak sesuai.']);
                }
                if ($data['email'] !== $user->email) {
                    $user->email_verified_at = null;
                }
                $user->fill($data)->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email atau nomor WhatsApp sudah digunakan.']);
        }

        return to_route('profile.edit')->with('status', 'Profil berhasil diperbarui.');
    }
}
