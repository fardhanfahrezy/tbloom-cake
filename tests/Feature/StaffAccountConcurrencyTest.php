<?php

use App\Models\AuditLog;
use App\Models\User;
use App\StaffAccountManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('serializes competing Owner deactivations and rechecks the actor after waiting', function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Owner locking proof requires isolated MySQL.');
    }
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $first = DB::connection();
    $second = DB::build(config('database.connections.mysql_testing'));
    $originalConnection = DB::getDefaultConnection();
    try {
        $ownerA = User::factory()->owner()->create();
        $ownerB = User::factory()->owner()->create();
        $manager = app(StaffAccountManager::class);
        expect($first->selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe($second->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $first->beginTransaction();
        $manager->setActive($ownerA, $ownerB, false, 'First synthetic Owner deactivation');
        DB::setDefaultConnection($second->getName());
        try {
            $manager->setActive($ownerB, $ownerA, false, 'Competing synthetic deactivation');
            $this->fail('A contender bypassed the ordered Owner locks.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }
        DB::setDefaultConnection($originalConnection);
        $first->commit();
        DB::setDefaultConnection($second->getName());
        expect(fn () => $manager->setActive($ownerB, $ownerA, false, 'Stale actor after commit'))->toThrow(AuthorizationException::class);
        DB::setDefaultConnection($originalConnection);
        expect($ownerA->fresh()->is_active)->toBeTrue();
        expect($ownerB->fresh()->is_active)->toBeFalse();
        expect(fn () => $manager->setActive($ownerA, $ownerA, false, 'Last Owner'))->toThrow(ValidationException::class);
        expect(User::query()->where('role', 'owner')->where('is_active', true)->count())->toBe(1);
        expect(AuditLog::query()->count())->toBe(1);
    } finally {
        DB::setDefaultConnection($originalConnection);
        while ($first->transactionLevel() > 0) {
            $first->rollBack();
        }
        while ($second->transactionLevel() > 0) {
            $second->rollBack();
        }
        $second->disconnect();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
    }
});
