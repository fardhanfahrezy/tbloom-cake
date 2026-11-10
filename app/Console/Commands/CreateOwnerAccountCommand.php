<?php

namespace App\Console\Commands;

use App\CreateOwnerAccount;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateOwnerAccountCommand extends Command
{
    protected $signature = 'owner:create';

    protected $description = 'Create the first active Owner account using secure interactive prompts';

    public function handle(CreateOwnerAccount $creator): int
    {
        $name = $this->ask('Nama Owner');
        $email = $this->ask('Email Owner');
        $whatsapp = $this->ask('Nomor WhatsApp Owner (contoh: 08xxxxxxxxxx atau +62xxxxxxxxxx)');
        $password = $this->secret('Password (minimal 12 karakter)');
        $passwordConfirmation = $this->secret('Konfirmasi password');

        if (! is_string($password) || $password === '' || ! hash_equals($password, (string) $passwordConfirmation)) {
            $this->error('Konfirmasi password tidak cocok. Tidak ada akun yang dibuat.');

            return self::FAILURE;
        }

        try {
            $owner = $creator->create([
                'name' => (string) $name,
                'email' => (string) $email,
                'whatsapp_number' => (string) $whatsapp,
                'password' => $password,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info('Akun Owner berhasil dibuat.');
        $this->line('Email: '.$owner->email);
        $this->warn('Simpan kredensial dengan aman. Password tidak ditampilkan atau dicatat.');

        return self::SUCCESS;
    }
}
