<?php

namespace App;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateOwnerAccount
{
    /**
     * @param  array{name: string, email: string, whatsapp_number: string, password: string}  $data
     */
    public function create(array $data): User
    {
        $data['name'] = trim($data['name']);
        $data['email'] = CanonicalContact::email($data['email']);
        $data['whatsapp_number'] = CanonicalContact::whatsapp($data['whatsapp_number']);

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'whatsapp_number' => ['required', 'string', 'regex:/\A\+[1-9][0-9]{1,14}\z/', Rule::unique('users', 'whatsapp_number')],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ])->validate();

        $connection = DB::connection();
        $lockName = 'tbloom:owner-bootstrap';
        $lock = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw ValidationException::withMessages([
                'owner' => 'Bootstrap Owner sedang berjalan di proses lain. Coba kembali setelah proses selesai.',
            ]);
        }

        try {
            return DB::transaction(function () use ($validated): User {
                $existingOwner = User::query()
                    ->where('role', UserRole::Owner)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->exists();

                if ($existingOwner) {
                    throw ValidationException::withMessages([
                        'owner' => 'Akun Owner sudah tersedia. Pembuatan Owner baru ditolak.',
                    ]);
                }

                $owner = new User([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'whatsapp_number' => $validated['whatsapp_number'],
                ]);
                $owner->password = Hash::make($validated['password']);
                $owner->forceFill([
                    'role' => UserRole::Owner,
                    'is_active' => true,
                    'email_verified_at' => null,
                ])->save();

                return $owner;
            });
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
}
