<?php

use App\CreateOwnerAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Owner bootstrap proof requires phpunit.mysql.xml.');
    }

    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
});

afterEach(function () {
    if (config('database.default') === 'mysql_testing') {
        DB::table('users')->delete();
    }
});

function validOwnerBootstrapData(array $overrides = []): array
{
    return array_replace([
        'name' => 'Tbloom Owner',
        'email' => 'owner@example.test',
        'whatsapp_number' => '081234567890',
        'password' => 'a-secure-password-123',
    ], $overrides);
}

test('creates the first active owner with normalized identity and a hashed password', function () {
    $owner = app(CreateOwnerAccount::class)->create(validOwnerBootstrapData());

    expect($owner->role)->toBe(UserRole::Owner)
        ->and($owner->is_active)->toBeTrue()
        ->and($owner->email)->toBe('owner@example.test')
        ->and($owner->whatsapp_number)->toBe('+6281234567890')
        ->and(Hash::check('a-secure-password-123', $owner->password))->toBeTrue();
});

test('refuses to create a second owner', function () {
    User::factory()->owner()->create();

    expect(fn () => app(CreateOwnerAccount::class)->create(validOwnerBootstrapData()))
        ->toThrow(ValidationException::class);

    expect(User::query()->where('role', UserRole::Owner)->count())->toBe(1);
});

test('rejects duplicate email and invalid WhatsApp values', function () {
    User::factory()->create(['email' => 'owner@example.test']);

    expect(fn () => app(CreateOwnerAccount::class)->create(validOwnerBootstrapData()))
        ->toThrow(ValidationException::class);

    expect(fn () => app(CreateOwnerAccount::class)->create(validOwnerBootstrapData([
        'email' => 'another@example.test',
        'whatsapp_number' => 'not-a-phone-number',
    ])))->toThrow(ValidationException::class);
});

test('rejects passwords shorter than twelve characters', function () {
    expect(fn () => app(CreateOwnerAccount::class)->create(validOwnerBootstrapData([
        'password' => 'short-pass',
    ])))->toThrow(ValidationException::class);
});

test('rotates an existing owner password through the server-side command', function () {
    $owner = User::factory()->owner()->create();

    $this->artisan('owner:reset-password')
        ->expectsQuestion('Email akun Owner yang akan dirotasi', $owner->email)
        ->expectsQuestion('Password baru (minimal 12 karakter)', 'replacement-password-456')
        ->expectsQuestion('Konfirmasi password baru', 'replacement-password-456')
        ->expectsOutput('Password akun Owner berhasil dirotasi.')
        ->assertSuccessful();

    expect(Hash::check('replacement-password-456', $owner->fresh()->password))->toBeTrue();
});

test('creates an owner through the interactive Artisan bootstrap command', function () {
    $this->artisan('owner:create')
        ->expectsQuestion('Nama Owner', 'Tbloom Owner')
        ->expectsQuestion('Email Owner', 'owner@example.test')
        ->expectsQuestion('Nomor WhatsApp Owner (contoh: 08xxxxxxxxxx atau +62xxxxxxxxxx)', '081234567890')
        ->expectsQuestion('Password (minimal 12 karakter)', 'a-secure-password-123')
        ->expectsQuestion('Konfirmasi password', 'a-secure-password-123')
        ->expectsOutput('Akun Owner berhasil dibuat.')
        ->assertSuccessful();

    expect(User::query()->where('role', UserRole::Owner)->count())->toBe(1);
});

test('does not create an owner when password confirmation differs', function () {
    $this->artisan('owner:create')
        ->expectsQuestion('Nama Owner', 'Tbloom Owner')
        ->expectsQuestion('Email Owner', 'owner@example.test')
        ->expectsQuestion('Nomor WhatsApp Owner (contoh: 08xxxxxxxxxx atau +62xxxxxxxxxx)', '081234567890')
        ->expectsQuestion('Password (minimal 12 karakter)', 'a-secure-password-123')
        ->expectsQuestion('Konfirmasi password', 'a-different-password-123')
        ->expectsOutput('Konfirmasi password tidak cocok. Tidak ada akun yang dibuat.')
        ->assertFailed();

    expect(User::query()->where('role', UserRole::Owner)->count())->toBe(0);
});
