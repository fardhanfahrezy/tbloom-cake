<?php

use App\Models\CustomCakeRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Profile changes require isolated MySQL.');
    }
    $this->withoutVite();
});

test('requires an active session to read or update profile', function () {
    $this->get('/profile')->assertRedirect('/login');
    $this->patchJson('/profile', [])->assertUnauthorized();
    $this->actingAs(User::factory()->inactive()->create())->get('/profile')->assertRedirect('/login');
});

test('renders escaped self profile without exposing a password or verification claim', function () {
    $user = User::factory()->unverified()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($user)->get('/profile')->assertOk()->assertSee($user->email)->assertSee($user->name)
        ->assertDontSee($user->name, false)->assertDontSee($user->password, false)->assertDontSee('Email terverifikasi');
});

test('allows name-only changes without a password and ignores privileged or foreign identity input', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user)->patch('/profile', ['name' => 'Nama Baru', 'email' => $user->email, 'whatsapp_number' => $user->whatsapp_number,
        'id' => $other->id, 'user_id' => $other->id, 'role' => 'owner', 'is_active' => false, 'password' => 'malicious-new-password', 'remember_token' => 'attacker',
    ])->assertRedirect('/profile')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nama Baru', 'role' => 'customer', 'is_active' => 1]);
    expect($other->fresh()->name)->toBe($other->name);
    expect($user->fresh()->password)->toBe($user->password);
    expect($user->fresh()->remember_token)->toBe($user->remember_token);
});

test('requires the current password when changing either canonical contact', function (string $field, ?string $password) {
    $user = User::factory()->create();
    $input = ['name' => $user->name, 'email' => $user->email, 'whatsapp_number' => $user->whatsapp_number,
        $field => $field === 'email' ? 'new@example.test' : '+6281234567890', 'current_password' => $password];
    $this->actingAs($user)->patchJson('/profile', $input)->assertUnprocessable()->assertJsonValidationErrors('current_password');
    expect($user->fresh()->$field)->toBe($user->$field);
})->with(['email', 'whatsapp_number'])->with([null, 'wrong-password']);

test('updates canonical contacts after reauthentication while keeping historical snapshots', function () {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create();
    $custom = CustomCakeRequest::factory()->for($user)->create();
    $oldOrder = $order->fresh()->getAttributes();
    $oldCustom = $custom->fresh()->getAttributes();
    $this->actingAs($user)->patch('/profile', ['name' => 'Nama Baru', 'email' => ' NEW@Example.Test ', 'whatsapp_number' => '0812 3456 7890', 'current_password' => 'password-test', 'email_verified_at' => '2026-01-01'])
        ->assertRedirect('/profile')->assertSessionHasNoErrors()->assertSessionMissing('_old_input.current_password');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new@example.test', 'whatsapp_number' => '+6281234567890', 'email_verified_at' => null]);
    expect($order->fresh()->getAttributes())->toBe($oldOrder);
    expect($custom->fresh()->getAttributes())->toBe($oldCustom);
});

test('does not require reauthentication for an unchanged canonical contact representation', function () {
    $user = User::factory()->create(['email' => 'unchanged@example.test', 'whatsapp_number' => '+6281234567890']);
    $verified = $user->email_verified_at->toDateTimeString();
    $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => ' UNCHANGED@Example.Test ', 'whatsapp_number' => '0812-3456-7890'])
        ->assertRedirect('/profile')->assertSessionHasNoErrors();
    expect($user->fresh()->email_verified_at->toDateTimeString())->toBe($verified);
});

test('rejects another users canonical contacts and does not flash reauthentication secrets', function (string $field) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $input = ['name' => $user->name, 'email' => $user->email, 'whatsapp_number' => $user->whatsapp_number, $field => $other->$field, 'current_password' => 'password-test'];
    $this->actingAs($user)->from('/profile')->patch('/profile', $input)->assertRedirect('/profile')->assertSessionHasErrors($field)->assertSessionMissing('_old_input.current_password');
    expect($user->fresh()->$field)->toBe($user->$field);
})->with(['email', 'whatsapp_number']);

test('validates profile data before writing', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/profile', ['name' => 'A', 'email' => ['not-email'], 'whatsapp_number' => 'letters'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'whatsapp_number']);
    expect($user->fresh()->name)->toBe($user->name);
});

test('rechecks reauthentication against the locked password after preliminary validation', function () {
    $user = User::factory()->create();
    $changed = false;
    DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use ($user, &$changed): void {
        if (! $changed && str_contains($query, 'for update') && str_contains($query, 'users')) {
            $changed = true;
            $connection->table('users')->where('id', $user->id)->update(['password' => Hash::make('changed-password-123')]);
        }
    });
    $this->actingAs($user)->patchJson('/profile', ['name' => $user->name, 'email' => 'new@example.test', 'whatsapp_number' => $user->whatsapp_number, 'current_password' => 'password-test'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    expect($changed)->toBeTrue();
    expect($user->fresh()->email)->toBe($user->email);
});

test('returns validation feedback for a contact conflict discovered at the update boundary', function () {
    $user = User::factory()->create();
    User::updating(function (User $updating): void {
        User::factory()->create(['email' => $updating->email]);
    });
    try {
        $this->actingAs($user)->patchJson('/profile', ['name' => $user->name, 'email' => 'new@example.test', 'whatsapp_number' => $user->whatsapp_number, 'current_password' => 'password-test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        expect($user->fresh()->email)->toBe($user->email);
    } finally {
        Event::forget('eloquent.updating: '.User::class);
    }
});
