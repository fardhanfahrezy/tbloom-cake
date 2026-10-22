<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserRole;
use App\StaffAccountManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Staff account management requires isolated MySQL.');
    }
    $this->withoutVite();
});

function adminAccountPayload(array $overrides = []): array
{
    return array_replace(['name' => 'Admin Uji', 'email' => 'admin-new@example.test', 'whatsapp_number' => '0812 3456 7890', 'password' => 'Admin-secret-123', 'password_confirmation' => 'Admin-secret-123'], $overrides);
}

test('allows only active staff into the staff area and only Owner into account management', function (UserRole $role, int $staffStatus, int $accountsStatus) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->get('/staff')->assertStatus($staffStatus);
    $this->get('/staff/accounts')->assertStatus($accountsStatus);
})->with([
    'Customer' => [UserRole::Customer, 403, 403],
    'Admin' => [UserRole::Admin, 200, 403],
    'Owner' => [UserRole::Owner, 200, 200],
]);

test('redirects guests and disabled staff before protected staff pages', function () {
    $this->get('/staff/accounts')->assertRedirect('/login');
    $this->actingAs(User::factory()->owner()->inactive()->create())->get('/staff')->assertRedirect('/login');
});

test('creates only an active unverified Admin and atomically records a redacted audit', function () {
    $owner = User::factory()->owner()->create();
    Notification::fake();
    $this->actingAs($owner)->post('/staff/accounts', adminAccountPayload(['email' => ' ADMIN-NEW@Example.Test ', 'role' => 'owner', 'is_active' => false, 'email_verified_at' => '2026-01-01']))
        ->assertRedirect('/staff/accounts')->assertSessionHasNoErrors();
    $admin = User::query()->where('email', 'admin-new@example.test')->sole();
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->is_active)->toBeTrue();
    expect($admin->email_verified_at)->toBeNull();
    expect($admin->whatsapp_number)->toBe('+6281234567890');
    expect(Hash::check('Admin-secret-123', $admin->password))->toBeTrue();
    $audit = AuditLog::query()->sole();
    expect($audit->actor_user_id)->toBe($owner->id);
    expect($audit->entity_id)->toBe($admin->id);
    expect($audit->action)->toBe('account.admin_created');
    expect(json_encode([$audit->before_redacted, $audit->after_redacted]))->not->toContain('Admin-secret-123', 'admin-new@example.test', '+6281234567890');
    $this->get('/staff/accounts')->assertOk()->assertDontSee($admin->password, false)->assertDontSee('Admin-secret-123');
    Notification::assertNothingSent();
});

test('forbids Customer and Admin from creating or changing staff', function (UserRole $role) {
    $actor = User::factory()->create(['role' => $role]);
    $target = User::factory()->admin()->create();
    $this->actingAs($actor)->postJson('/staff/accounts', adminAccountPayload())->assertForbidden();
    $this->patchJson('/staff/accounts/'.$target->id, ['is_active' => false, 'reason' => 'Uji'])->assertForbidden();
    $this->patchJson('/staff/accounts/99999999', ['is_active' => false, 'reason' => 'Uji'])->assertForbidden();
    expect($target->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 0);
})->with([UserRole::Customer, UserRole::Admin]);

test('toggles Admin status with a required reason while preserving role credentials and history', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($owner)->patch('/staff/accounts/'.$admin->id, ['is_active' => false, 'reason' => 'Akses staf dihentikan', 'role' => 'owner', 'password' => 'attacker-password'])
        ->assertRedirect('/staff/accounts')->assertSessionHasNoErrors();
    expect($admin->fresh()->is_active)->toBeFalse();
    expect($admin->fresh()->role)->toBe(UserRole::Admin);
    expect($admin->fresh()->password)->toBe($admin->password);
    $audit = AuditLog::query()->sole();
    expect($audit->before_redacted)->toBe(['is_active' => true]);
    expect($audit->after_redacted)->toBe(['is_active' => false]);
    expect($audit->reason)->toBe('Akses staf dihentikan');
    $this->patch('/staff/accounts/'.$admin->id, ['is_active' => true, 'reason' => 'Akses dipulihkan'])->assertRedirect('/staff/accounts');
    expect($admin->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 2);
});

test('requires status and a nonblank reason before account activation changes', function (array $input, string $field) {
    $owner = User::factory()->owner()->create();
    $target = User::factory()->admin()->create();
    $this->actingAs($owner)->patchJson('/staff/accounts/'.$target->id, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($target->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'missing reason' => [['is_active' => false], 'reason'],
    'blank reason' => [['is_active' => false, 'reason' => '   '], 'reason'],
    'invalid flag' => [['is_active' => 'invalid', 'reason' => 'Uji'], 'is_active'],
    'missing flag' => [['reason' => 'Uji'], 'is_active'],
]);

test('refuses deactivation of the last active Owner with no audit or partial update', function () {
    $owner = User::factory()->owner()->create();
    User::factory()->owner()->inactive()->create();
    $this->actingAs($owner)->patchJson('/staff/accounts/'.$owner->id, ['is_active' => false, 'reason' => 'Uji perlindungan Owner'])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    expect($owner->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('allows changing existing Owner activation only while another active Owner remains', function () {
    $owner = User::factory()->owner()->create();
    $other = User::factory()->owner()->create();
    $this->actingAs($owner)->patch('/staff/accounts/'.$other->id, ['is_active' => false, 'reason' => 'Uji nonaktif Owner kedua'])->assertRedirect('/staff/accounts')->assertSessionHasNoErrors();
    expect($other->fresh()->role)->toBe(UserRole::Owner);
    expect($other->fresh()->is_active)->toBeFalse();
    expect($owner->fresh()->is_active)->toBeTrue();
});

test('keeps same-state updates idempotent without duplicating audit entries', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($owner)->patch('/staff/accounts/'.$admin->id, ['is_active' => true, 'reason' => 'Tidak berubah'])->assertRedirect('/staff/accounts');
    $this->assertDatabaseCount('audit_logs', 0);
});

test('does not expose customer records as staff account targets', function () {
    $owner = User::factory()->owner()->create();
    $customer = User::factory()->create();
    $this->actingAs($owner)->get('/staff/accounts')->assertOk()->assertDontSee($customer->email);
    $this->patchJson('/staff/accounts/'.$customer->id, ['is_active' => false, 'reason' => 'Tidak sah'])->assertNotFound();
    expect($customer->fresh()->is_active)->toBeTrue();
});

test('rolls back a newly created Admin if the required audit cannot be persisted', function () {
    $owner = User::factory()->owner()->create();
    AuditLog::creating(function (): void {
        throw new RuntimeException('Synthetic audit failure.');
    });
    try {
        expect(fn () => app(StaffAccountManager::class)->createAdmin($owner, adminAccountPayload()))->toThrow(RuntimeException::class, 'Synthetic audit failure.');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
});

test('does not trust a stale Owner object inside the staff operation', function () {
    $owner = User::factory()->owner()->create();
    User::query()->whereKey($owner->id)->update(['role' => 'customer']);
    expect(fn () => app(StaffAccountManager::class)->createAdmin($owner, adminAccountPayload()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('users', 1);
});

test('registers staff management policy boundaries without granting hard deletion', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    expect(Gate::forUser($owner)->allows('viewAny', User::class))->toBeTrue();
    expect(Gate::forUser($admin)->allows('create', User::class))->toBeFalse();
    expect(Gate::forUser($owner)->allows('delete', $admin))->toBeFalse();
});

test('validates Admin contact and password input before account creation', function () {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->postJson('/staff/accounts', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'whatsapp_number', 'password']);
    $this->postJson('/staff/accounts', adminAccountPayload(['email' => $owner->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('ends the current Owner session after an allowed self deactivation', function () {
    $owner = User::factory()->owner()->create();
    User::factory()->owner()->create();
    $this->actingAs($owner)->patch('/staff/accounts/'.$owner->id, ['is_active' => false, 'reason' => 'Owner mengakhiri akses sendiri'])
        ->assertRedirect('/login');
    $this->assertGuest();
    expect($owner->fresh()->is_active)->toBeFalse();
});

test('treats a bcrypt-looking Admin password as a literal secret', function () {
    $owner = User::factory()->owner()->create();
    $literal = Hash::make('short');

    $this->actingAs($owner)->post('/staff/accounts', adminAccountPayload(['password' => $literal, 'password_confirmation' => $literal]))
        ->assertRedirect('/staff/accounts')->assertSessionHasNoErrors();

    expect(Auth::validate(['email' => 'admin-new@example.test', 'password' => $literal]))->toBeTrue();
    expect(Auth::validate(['email' => 'admin-new@example.test', 'password' => 'short']))->toBeFalse();
});
