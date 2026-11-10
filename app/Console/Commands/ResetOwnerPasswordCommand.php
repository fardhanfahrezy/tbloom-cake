<?php

namespace App\Console\Commands;

use App\CanonicalContact;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ResetOwnerPasswordCommand extends Command
{
    protected $signature = 'owner:reset-password';

    protected $description = 'Rotate an existing Owner password from a trusted server shell';

    public function handle(): int
    {
        $email = $this->ask('Email akun Owner yang akan dirotasi');
        $password = $this->secret('Password baru (minimal 12 karakter)');
        $confirmation = $this->secret('Konfirmasi password baru');

        if (! is_string($password) || $password === '' || ! hash_equals($password, (string) $confirmation)) {
            $this->error('Konfirmasi password tidak cocok. Password tidak diubah.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 12 || mb_strlen($password) > 255) {
            $this->error('Password harus terdiri dari 12 sampai 255 karakter.');

            return self::FAILURE;
        }

        $normalizedEmail = CanonicalContact::email((string) $email);
        $updated = DB::transaction(function () use ($normalizedEmail, $password): bool {
            $owner = User::query()
                ->where('email', $normalizedEmail)
                ->where('role', UserRole::Owner)
                ->lockForUpdate()
                ->first();

            if (! $owner) {
                return false;
            }

            $owner->forceFill(['password' => Hash::make($password)])->save();

            return true;
        });

        if (! $updated) {
            $this->error('Akun Owner dengan email tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        $this->info('Password akun Owner berhasil dirotasi.');

        return self::SUCCESS;
    }
}
