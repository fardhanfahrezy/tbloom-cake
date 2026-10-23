<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordResetLinkRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public const RECOVERY_MESSAGE = 'Jika akun aktif ditemukan, tautan pemulihan akan dikirim ke email Anda.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(PasswordResetLinkRequest $request): RedirectResponse|JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email'), 'is_active' => true]);

        return $request->expectsJson()
            ? response()->json(['message' => self::RECOVERY_MESSAGE])
            : to_route('password.request')->with('status', self::RECOVERY_MESSAGE);
    }
}
