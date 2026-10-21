<?php

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

afterEach(function () {
    if (config('database.default') === 'mysql_testing') {
        DB::table('users')->delete();
    }
});

test('persists active unverified customers with a canonical contact and hashed password', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $user = User::factory()->unverified()->create();
    $fresh = $user->fresh();

    expect($fresh->role)->toBe(UserRole::Customer);
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->whatsapp_number)->toMatch('/^\+[1-9][0-9]{1,14}$/');
    expect($fresh->email_verified_at)->toBeNull();
    expect(Hash::check('password-test', $fresh->password))->toBeTrue();
});

test('rejects invalid account values in MySQL', function (array $overrides, int $errorCode) {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    User::factory()->create();
    $attributes = array_replace(User::factory()->make()->getAttributes(), [
        'created_at' => '2026-10-21 12:00:00',
        'updated_at' => '2026-10-21 12:00:00',
    ], $overrides);

    try {
        DB::table('users')->insert($attributes);
        $this->fail('MySQL accepted an invalid account value.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'invalid role' => [['role' => 'superuser'], 3819],
    'invalid active flag' => [['is_active' => 2], 3819],
    'missing WhatsApp' => [['whatsapp_number' => null], 1048],
    'missing creation time' => [['created_at' => null], 1048],
    'long name' => [['name' => str_repeat('a', 101)], 1406],
    'long email' => [['email' => str_repeat('a', 255)], 1406],
]);

test('retains a valid account through contract rollback and reapplication', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $user = User::factory()->owner()->inactive()->create();
    $attributes = $user->fresh()->getRawOriginal();
    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');

    $contract->down();
    $contract->up();

    expect($user->fresh()->getRawOriginal())->toBe($attributes);
});

test('does not mass assign staff privileges through public account fields', function () {
    $user = User::factory()->make();

    $user->fill(['role' => 'owner', 'is_active' => false]);

    expect($user->role)->toBe(UserRole::Customer);
    expect($user->is_active)->toBeTrue();
});

test('rejects duplicate WhatsApp contacts', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $user = User::factory()->create();

    expect(fn () => User::factory()->create(['whatsapp_number' => $user->whatsapp_number]))
        ->toThrow(QueryException::class);
});

test('refuses contract before legacy accounts are backfilled and preserves their data', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');
    $contract->down();
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['whatsapp_number' => null, 'role' => null, 'is_active' => null]);

    expect(fn () => $contract->up())->toThrow(RuntimeException::class, 'backfill');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'whatsapp_number' => null]);
});

test('preserves UTC legacy timestamp instants when the migration session starts in Jakarta time', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');
    $contract->down();
    DB::statement('ALTER TABLE users MODIFY email_verified_at TIMESTAMP NULL, MODIFY created_at TIMESTAMP NULL, MODIFY updated_at TIMESTAMP NULL');
    $user = User::factory()->create([
        'created_at' => '2026-10-21 00:15:00',
        'updated_at' => '2026-10-21 01:15:00',
        'email_verified_at' => '2026-10-21 02:15:00',
    ]);
    DB::statement("SET SESSION time_zone = '+07:00'");

    try {
        $contract->up();

        $row = DB::table('users')->where('id', $user->id)->first();
        expect($row->created_at)->toBe('2026-10-21 00:15:00');
        expect($row->updated_at)->toBe('2026-10-21 01:15:00');
        expect($row->email_verified_at)->toBe('2026-10-21 02:15:00');
        expect(DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone)->toBe('+07:00');
    } finally {
        DB::statement("SET SESSION time_zone = '+00:00'");
    }
});

test('refuses duplicate legacy WhatsApp identities before contract without rewriting them', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');
    $contract->down();
    $user = User::factory()->create();
    User::factory()->create(['whatsapp_number' => $user->whatsapp_number]);

    expect(fn () => $contract->up())->toThrow(RuntimeException::class, 'backfill');

    expect(DB::table('users')->where('whatsapp_number', $user->whatsapp_number)->count())->toBe(2);
});

test('refuses rollback that would discard populated account identities', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $user = User::factory()->create();
    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');
    $contract->down();
    $expand = require database_path('migrations/2026_10_21_154506_expand_users_for_r1.php');

    expect(fn () => $expand->down())->toThrow(RuntimeException::class, 'forward fix');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'whatsapp_number' => $user->whatsapp_number]);
});
