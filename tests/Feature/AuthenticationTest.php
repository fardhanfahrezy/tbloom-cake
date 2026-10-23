<?php

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Authentication endpoints require isolated MySQL.');
    }
    $this->withoutVite();
});

test('renders login and directs guests to sign in before protected account access', function () {
    $this->get('/login')->assertOk()->assertSee('Masuk')->assertSee('autocomplete="current-password"', false);
    $this->get('/account')->assertRedirect('/login');
    $this->getJson('/account')->assertUnauthorized();
});

test('authenticates active unverified accounts of each role with a regenerated session', function (UserRole $role) {
    $user = User::factory()->unverified()->create(['role' => $role, 'email' => 'login@example.test']);
    $this->get('/login');
    $oldSession = session()->getId();
    $this->withSession(['url.intended' => url('/account')])->post('/login', ['email' => ' LOGIN@Example.Test ', 'password' => 'password-test'])
        ->assertRedirect('/account')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($oldSession);
    $this->get('/account')->assertOk()->assertSee($user->name)->assertDontSee('password-test');
})->with(UserRole::cases());

test('returns the same generic credential error for invalid inactive and missing accounts', function (string $case) {
    if ($case !== 'missing') {
        User::factory()->create(['email' => 'login@example.test', 'is_active' => $case !== 'inactive']);
    }
    $this->postJson('/login', ['email' => 'login@example.test', 'password' => $case === 'wrong' ? 'wrong-password' : 'password-test'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Email atau password tidak sesuai.']);
    $this->assertGuest();
})->with(['wrong', 'inactive', 'missing']);

test('invalidates a previously authenticated session when the account is disabled in the database', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/account')->assertOk();
    User::query()->whereKey($user->id)->update(['is_active' => false]);
    $this->get('/account')->assertRedirect('/login');
    $this->assertGuest();
});

test('rejects disabled sessions as unauthenticated JSON without exposing account data', function () {
    $user = User::factory()->inactive()->create();
    $this->actingAs($user)->getJson('/account')->assertUnauthorized()->assertDontSee($user->email);
    $this->assertGuest();
});

test('refreshes the current role from the database before protected requests', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->get('/account')->assertOk();
    User::query()->whereKey($user->id)->update(['role' => 'customer']);
    $this->get('/account')->assertOk();
    expect(Auth::user()->role)->toBe(UserRole::Customer);
});

test('logs out only through POST and regenerates the CSRF token and session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/account')->assertOk();
    $oldId = session()->getId();
    $oldToken = session()->token();
    $this->get('/logout')->assertMethodNotAllowed();
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
    expect(session()->getId())->not->toBe($oldId);
    expect(session()->token())->not->toBe($oldToken);
    $this->get('/account')->assertRedirect('/login');
});

test('only enables a remember cookie when explicitly requested', function (bool $remember) {
    User::factory()->create(['email' => 'login@example.test']);
    $response = $this->post('/login', ['email' => 'login@example.test', 'password' => 'password-test', 'remember' => $remember]);
    $response->assertRedirect('/account')->assertSessionHasNoErrors();
    if ($remember) {
        $response->assertCookie(Auth::guard()->getRecallerName());
    } else {
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }
})->with([true, false]);

test('throttles canonical email aliases before further login attempts', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/login', ['email' => 'LOGIN@Example.Test', 'password' => 'bad-password'])->assertUnprocessable();
    }
    $this->postJson('/login', ['email' => 'login@example.test', 'password' => 'bad-password'])->assertTooManyRequests()->assertHeader('Retry-After');
    $this->assertGuest();
});

test('validates login payloads without flashing credentials', function () {
    $this->from('/login')->post('/login', ['email' => '', 'password' => 'secret-pass-123'])->assertRedirect('/login')
        ->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
    $this->postJson('/login', ['email' => ['bad'], 'password' => ['bad']])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
});

test('escapes account display names in HTML', function () {
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($user)->get('/account')->assertOk()->assertSee($user->name)->assertDontSee($user->name, false);
});
