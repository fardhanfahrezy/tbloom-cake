<?php

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Account endpoint tests require isolated MySQL.');
    }
    $this->withoutVite();
});

function registrationPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Pelanggan Uji',
        'email' => 'customer@example.test',
        'whatsapp_number' => '0812 3456 7890',
        'password' => 'Secret-pass-123',
        'password_confirmation' => 'Secret-pass-123',
    ], $overrides);
}

test('renders accessible registration fields and a CSRF protected form', function () {
    $this->get('/register')->assertOk()->assertSee('Buat akun')->assertSee('name="_token"', false)
        ->assertSee('for="name"', false)->assertSee('autocomplete="new-password"', false);
});

test('creates an active unverified Customer and starts a fresh session despite privileged payload', function () {
    Notification::fake();
    $this->get('/register');
    $oldSession = session()->getId();

    $this->post('/register', registrationPayload([
        'email' => '  Customer@Example.Test  ', 'role' => 'owner', 'is_active' => false,
        'email_verified_at' => '2026-01-01 00:00:00', 'remember_token' => 'attacker',
    ]))->assertRedirect('/')->assertSessionHasNoErrors();

    $user = User::query()->sole();
    expect($user->role)->toBe(UserRole::Customer);
    expect($user->is_active)->toBeTrue();
    expect($user->email)->toBe('customer@example.test');
    expect($user->whatsapp_number)->toBe('+6281234567890');
    expect($user->email_verified_at)->toBeNull();
    expect($user->remember_token)->toBeNull();
    expect(Hash::check('Secret-pass-123', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('Secret-pass-123');
    expect(session()->getId())->not->toBe($oldSession);
    $this->assertAuthenticatedAs($user);
    Notification::assertNothingSent();
});

test('normalizes presentation formats without asserting WhatsApp ownership', function (string $input, string $canonical) {
    $this->post('/register', registrationPayload(['whatsapp_number' => $input]))->assertRedirect('/')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['whatsapp_number' => $canonical, 'email_verified_at' => null]);
})->with([
    'local mobile' => ['0812-3456-7890', '+6281234567890'],
    'country prefix' => ['6281234567890', '+6281234567890'],
    'international' => ['+62 (812) 3456-7890', '+6281234567890'],
    'international dial prefix' => ['006281234567890', '+6281234567890'],
    'other international country' => ['+44 7700 900123', '+447700900123'],
]);

test('rejects invalid registration data without creating or authenticating an account', function (array $input, string $field) {
    $this->postJson('/register', registrationPayload($input))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('users', 0);
    $this->assertGuest();
})->with([
    'short name' => [['name' => 'A'], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'missing name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'long email' => [['email' => str_repeat('a', 255).'@example.test'], 'email'],
    'missing email' => [['email' => null], 'email'],
    'letters in number' => [['whatsapp_number' => '0812call34567890'], 'whatsapp_number'],
    'embedded plus' => [['whatsapp_number' => '+62+81234567890'], 'whatsapp_number'],
    'long E164' => [['whatsapp_number' => '+1234567890123456'], 'whatsapp_number'],
    'zero country' => [['whatsapp_number' => '+0123456789'], 'whatsapp_number'],
    'missing number' => [['whatsapp_number' => ''], 'whatsapp_number'],
    'short password' => [['password' => '12345678901', 'password_confirmation' => '12345678901'], 'password'],
    'mismatched confirmation' => [['password_confirmation' => 'different-pass'], 'password'],
    'missing confirmation' => [['password_confirmation' => null], 'password'],
    'too many bcrypt bytes' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
    'Unicode bcrypt overflow' => [['password' => str_repeat("\u{00E9}", 37), 'password_confirmation' => str_repeat("\u{00E9}", 37)], 'password'],
    'NUL password' => [['password' => "secret\0password", 'password_confirmation' => "secret\0password"], 'password'],
    'email array' => [['email' => ['x@example.test']], 'email'],
    'number array' => [['whatsapp_number' => ['+6281234567890']], 'whatsapp_number'],
]);

test('accepts the documented account field boundaries and twelve character password', function () {
    $email = str_repeat('x', 64).'@'.str_repeat('a', 62).'.'.str_repeat('b', 62).'.'.str_repeat('c', 58).'.test';
    $this->post('/register', registrationPayload(['name' => str_repeat('a', 100), 'email' => $email,
        'whatsapp_number' => '+123456789012345', 'password' => '123456789012', 'password_confirmation' => '123456789012',
    ]))->assertRedirect('/')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['name' => str_repeat('a', 100), 'email' => $email]);
});

test('rejects duplicate canonical email or WhatsApp across existing accounts', function (string $field) {
    User::factory()->create(['email' => 'customer@example.test', 'whatsapp_number' => '+6281234567890']);
    $payload = $field === 'email'
        ? registrationPayload(['email' => ' Customer@Example.Test ', 'whatsapp_number' => '+6281234567891'])
        : registrationPayload(['email' => 'other@example.test', 'whatsapp_number' => '+62 (812) 3456-7890']);
    $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('users', 1);
    $this->assertGuest();
})->with(['email', 'whatsapp_number']);

test('does not flash passwords into the session after validation failure', function () {
    $this->from('/register')->post('/register', registrationPayload(['name' => '']))->assertRedirect('/register')
        ->assertSessionHasErrors('name')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
});

test('throttles registration attempts before creating additional accounts', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/register', [])->assertUnprocessable();
    }
    $this->postJson('/register', registrationPayload())->assertTooManyRequests()->assertHeader('Retry-After');
    $this->assertDatabaseCount('users', 0);
});

test('redirects already authenticated users away from public registration', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/register')->assertRedirect('/')->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/register', registrationPayload())->assertRedirect('/')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('users', 1);
});

test('renders safely when malformed array input is flashed after validation', function () {
    $this->from('/register')->post('/register', registrationPayload(['email' => ['bad@example.test']]))->assertRedirect('/register')->assertSessionHasErrors('email');
    $this->get('/register')->assertOk()->assertSee('Buat akun');
});

test('returns validation feedback if a canonical contact wins the insert race', function () {
    User::creating(function (User $user): void {
        DB::table('users')->insert(array_replace($user->getAttributes(), [
            'created_at' => '2026-10-22 00:00:00', 'updated_at' => '2026-10-22 00:00:00',
        ]));
    });
    try {
        $this->postJson('/register', registrationPayload())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    } finally {
        Event::forget('eloquent.creating: '.User::class);
    }
});

test('treats a bcrypt-looking registration password as a literal secret', function () {
    $literal = Hash::make('short');

    $this->post('/register', registrationPayload(['password' => $literal, 'password_confirmation' => $literal]))
        ->assertRedirect('/')->assertSessionHasNoErrors();

    expect(Auth::validate(['email' => 'customer@example.test', 'password' => $literal]))->toBeTrue();
    expect(Auth::validate(['email' => 'customer@example.test', 'password' => 'short']))->toBeFalse();
});
