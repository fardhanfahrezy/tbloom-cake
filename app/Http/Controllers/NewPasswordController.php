<?php

namespace App\Http\Controllers;

use App\Http\Requests\NewPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $status = DB::transaction(function () use ($request): string {
            $user = User::query()->where('email', $request->validated('email'))->lockForUpdate()->first();
            if (! $user || ! $user->is_active) {
                return Password::InvalidUser;
            }

            return Password::reset(array_merge($request->safe()->only(['email', 'token', 'password']), ['is_active' => true]), function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'Tautan pemulihan tidak valid atau sudah kedaluwarsa.']);
        }

        return to_route('login')->with('status', 'Password berhasil diperbarui. Silakan masuk kembali.');
    }
}
