<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Password recovery requires isolated MySQL.');
    }
    $this->withoutVite();
});

function resetPayload(User $user, string $token, array $overrides = []): array
{
    return array_replace(['email' => $user->email, 'token' => $token, 'password' => 'New-secret-pass-123', 'password_confirmation' => 'New-secret-pass-123'], $overrides);
}

test('renders forgot and reset forms without reflecting a token into page text', function () {
    $this->get('/forgot-password')->assertOk()->assertSee('Lupa password');
    $this->get('/reset-password/synthetic-token?email=someone%40example.test')->assertOk()->assertSee('Password baru');
});

test('sends a broker email notification only to an active account with a hashed expiring token', function () {
    $user = User::factory()->unverified()->create(['email' => 'reset@example.test']);
    Notification::fake();
    $this->from('/forgot-password')->post('/forgot-password', ['email' => ' RESET@Example.Test '])->assertRedirect('/forgot-password')->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification, array $channels) use ($user): bool {
        expect($channels)->toBe(['mail']);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        expect($stored)->not->toBe($notification->token);
        expect(Hash::check($notification->token, $stored))->toBeTrue();
        expect($notification->toMail($user)->actionUrl)->toContain('/reset-password/'.$notification->token);

        return true;
    });
    $this->assertGuest();
});

test('returns identical recovery feedback for existing missing inactive and throttled accounts', function (string $case) {
    $user = $case === 'missing' ? null : User::factory()->create(['email' => 'reset@example.test', 'is_active' => $case !== 'inactive']);
    if ($case === 'recent') {
        Password::createToken($user);
    }
    Notification::fake();
    $this->postJson('/forgot-password', ['email' => 'reset@example.test'])->assertOk()->assertExactJson(['message' => 'Jika akun aktif ditemukan, tautan pemulihan akan dikirim ke email Anda.']);
    if ($case === 'existing') {
        Notification::assertSentTo($user, ResetPassword::class);
    } else {
        Notification::assertNothingSent();
    }
})->with(['existing', 'missing', 'inactive', 'recent']);

test('resets once without automatic login and rotates remember token', function () {
    $user = User::factory()->create();
    $oldRemember = $user->remember_token;
    $token = Password::createToken($user);
    $this->post('/reset-password', resetPayload($user, $token))->assertRedirect('/login')->assertSessionHasNoErrors()->assertSessionHas('status');
    expect(Hash::check('New-secret-pass-123', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->remember_token)->not->toBe($oldRemember);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertGuest();
    $this->postJson('/reset-password', resetPayload($user, $token))->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('rejects invalid expired and inactive recovery tokens without changing the password', function (string $case) {
    $this->freezeTime();
    $user = User::factory()->create();
    $oldHash = $user->password;
    $token = Password::createToken($user);
    if ($case === 'invalid') {
        $token = 'wrong-token';
    } elseif ($case === 'expired') {
        $this->travel(61)->minutes();
    } else {
        $user->forceFill(['is_active' => false])->save();
    }
    $this->postJson('/reset-password', resetPayload($user, $token))->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Tautan pemulihan tidak valid atau sudah kedaluwarsa.']);
    expect($user->fresh()->password)->toBe($oldHash);
    $this->assertGuest();
})->with(['invalid', 'expired', 'inactive']);

test('applies the shared password policy during recovery and preserves usable tokens on invalid input', function (array $overrides) {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->postJson('/reset-password', resetPayload($user, $token, $overrides))->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(Password::tokenExists($user, $token))->toBeTrue();
})->with([
    'short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'different confirmation' => [['password_confirmation' => 'different-password']],
    'bcrypt length' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)]],
]);

test('does not flash reset tokens or passwords after rejected recovery input', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->from('/reset-password/'.$token)->post('/reset-password', resetPayload($user, $token, ['password_confirmation' => 'wrong']))
        ->assertRedirect('/reset-password/'.$token)->assertSessionHasErrors('password')
        ->assertSessionMissing('_old_input.token')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
});

test('limits recovery requests consistently before sending further notifications', function () {
    Notification::fake();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertOk();
    }
    $this->postJson('/forgot-password', ['email' => 'MISSING@example.test'])->assertTooManyRequests();
    Notification::assertNothingSent();
});

test('rejects existing sessions after their password hash changes', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/account')->assertOk();
    expect(session()->all())->toHaveKey('password_hash_web');
    User::query()->whereKey($user->id)->update(['password' => Hash::make('New-secret-pass-123')]);
    $this->get('/account')->assertRedirect('/login');
    $this->assertGuest();
});

test('builds recovery links from the configured application origin instead of an untrusted Host header', function () {
    config(['app.url' => 'https://tbloom.example.test']);
    $user = User::factory()->create();
    Notification::fake();
    $this->withServerVariables(['HTTP_HOST' => 'attacker.example.test'])->post('/forgot-password', ['email' => $user->email])->assertRedirect();
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        expect($notification->toMail($user)->actionUrl)->toStartWith('https://tbloom.example.test/reset-password/');

        return true;
    });
});
