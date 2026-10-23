<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminRequest;
use App\Http\Requests\UpdateStaffStatusRequest;
use App\Models\User;
use App\Models\UserRole;
use App\StaffAccountManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffAccountController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('staff.accounts', [
            'staffAccounts' => User::query()->whereIn('role', [UserRole::Admin, UserRole::Owner])->orderBy('id')->paginate(15),
            'activeOwnerCount' => User::query()->where('role', UserRole::Owner)->where('is_active', true)->count(),
        ]);
    }

    public function store(StoreAdminRequest $request, StaffAccountManager $manager): RedirectResponse
    {
        try {
            $manager->createAdmin($request->user(), $request->safe()->only(['name', 'email', 'whatsapp_number', 'password']));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email atau nomor WhatsApp sudah digunakan.']);
        }

        return to_route('staff.accounts.index')->with('status', 'Akun Admin berhasil dibuat.');
    }

    public function update(UpdateStaffStatusRequest $request, User $user, StaffAccountManager $manager): RedirectResponse
    {
        $updated = $manager->setActive($request->user(), $user, $request->boolean('is_active'), $request->validated('reason'));
        if ($updated->id === $request->user()->id && ! $updated->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        return to_route('staff.accounts.index')->with('status', 'Status akun staf berhasil diperbarui.');
    }
}
