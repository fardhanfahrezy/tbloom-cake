<?php

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Upgrade rehearsal only allows isolated MySQL.');
    }
});

afterEach(function () {
    if (config('database.default') === 'mysql_testing') {
        try {
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        } finally {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$lazilyRefreshed = false;
        }
    }
});

test('upgrades real scaffold history only after explicit verified identity backfill', function () {
    $paths = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_10_21_154506_expand_users_for_r1.php',
    ];
    $this->artisan('migrate:fresh', ['--path' => $paths, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $legacy = Arr::only(User::factory()->make()->getAttributes(), ['name', 'email', 'email_verified_at', 'password', 'remember_token']);
    $legacy['created_at'] = '2026-10-20 01:02:03';
    $legacy['updated_at'] = '2026-10-20 02:03:04';
    $id = DB::table('users')->insertGetId($legacy);
    $before = (array) DB::table('users')->find($id);

    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');
    expect(fn () => $contract->up())->toThrow(RuntimeException::class, 'backfill');
    expect((array) DB::table('users')->find($id))->toBe($before);
    expect(DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'name'")->COLUMN_TYPE)->toBe('varchar(255)');
    $this->assertDatabaseMissing('migrations', ['migration' => '2026_10_21_154507_enforce_r1_user_contract']);

    DB::table('users')->where('id', $id)->update(['whatsapp_number' => '+6281234567890', 'role' => 'owner', 'is_active' => 1]);
    $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $user = User::findOrFail($id);
    expect(Arr::only($user->getRawOriginal(), array_keys($legacy)))->toBe($legacy);
    expect($user->role)->toBe(UserRole::Owner);
    expect($user->whatsapp_number)->toBe('+6281234567890');
    expect(DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()')->aggregate)->toBe(28);
});

test('resumes remaining migrations after a rehearsed partial DDL failure without replaying committed contract', function () {
    $paths = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_10_21_154506_expand_users_for_r1.php',
    ];
    $this->artisan('migrate:fresh', ['--path' => $paths, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $id = DB::table('users')->insertGetId(array_replace(User::factory()->make()->getAttributes(), ['created_at' => '2026-10-20 01:02:03', 'updated_at' => '2026-10-20 02:03:04']));
    Schema::create('products', function (Blueprint $table) {
        $table->id();
    });
    try {
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $this->fail('The synthetic table collision did not interrupt DDL.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1050);
    }
    $this->assertDatabaseHas('migrations', ['migration' => '2026_10_21_154507_enforce_r1_user_contract']);
    $this->assertDatabaseMissing('migrations', ['migration' => '2026_10_21_155412_create_products_table']);
    $beforeResume = (array) DB::table('users')->find($id);
    $this->assertDatabaseCount('products', 0);
    Schema::drop('products');
    $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    expect((array) DB::table('users')->find($id))->toBe($beforeResume);
    expect(DB::table('migrations')->where('migration', '2026_10_21_154507_enforce_r1_user_contract')->count())->toBe(1);
    expect(DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()')->aggregate)->toBe(28);
});

test('reverses all empty migrations in dependency order and rebuilds the full foundation', function () {
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $migrationCount = DB::table('migrations')->count();
    expect($migrationCount)->toBeGreaterThan(20);
    $this->artisan('migrate:rollback', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    expect(collect(DB::select('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()'))->pluck('TABLE_NAME')->all())->toBe(['migrations']);
    $this->assertDatabaseCount('migrations', 0);
    $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    expect(DB::table('migrations')->count())->toBe($migrationCount);
    expect(DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()')->aggregate)->toBe(28);
});

test('rejects padded legacy roles before applying any user contract DDL', function () {
    $paths = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_10_21_154506_expand_users_for_r1.php',
    ];
    $this->artisan('migrate:fresh', ['--path' => $paths, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $id = DB::table('users')->insertGetId(array_replace(User::factory()->make()->getAttributes(), ['role' => 'admin ', 'created_at' => '2026-10-20 01:02:03', 'updated_at' => '2026-10-20 02:03:04']));
    $before = (array) DB::table('users')->find($id);
    $contract = require database_path('migrations/2026_10_21_154507_enforce_r1_user_contract.php');
    expect(fn () => $contract->up())->toThrow(RuntimeException::class, 'backfill');
    expect((array) DB::table('users')->find($id))->toBe($before);
    expect(DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'name'")->COLUMN_TYPE)->toBe('varchar(255)');
});
