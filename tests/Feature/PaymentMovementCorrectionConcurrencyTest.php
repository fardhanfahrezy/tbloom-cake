<?php

use App\Models\PaymentMovementCorrection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

test('allows only one revision commit from two overlapping MySQL transactions with the same head', function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Two-connection constraint proof requires phpunit.mysql.xml.');
    }

    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $firstConnection = DB::connection();
    $secondConnection = DB::build(config('database.connections.mysql_testing'));

    try {
        $first = PaymentMovementCorrection::factory()->create();
        $candidate = PaymentMovementCorrection::factory()->after($first)->raw(['created_at' => '2026-11-01 00:00:00']);
        $firstConnection->beginTransaction();
        $secondConnection->beginTransaction();

        expect($firstConnection->selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe($secondConnection->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $headA = $firstConnection->table('payment_movement_corrections')->where('payment_movement_id', $first->payment_movement_id)->max('revision');
        $headB = $secondConnection->table('payment_movement_corrections')->where('payment_movement_id', $first->payment_movement_id)->max('revision');
        expect($headA)->toBe(1);
        expect($headB)->toBe(1);

        $firstConnection->table('payment_movement_corrections')->insert($candidate);
        $firstConnection->commit();

        $candidate['idempotency_key'] = 'test-second-contender';
        try {
            $secondConnection->table('payment_movement_corrections')->insert($candidate);
            $this->fail('Two contenders committed a second revision.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1062);
            $secondConnection->rollBack();
        }

        $this->assertDatabaseCount('payment_movement_corrections', 2);
        expect($first->movement->headCorrection->revision)->toBe(2);
    } finally {
        while ($firstConnection->transactionLevel() > 0) {
            $firstConnection->rollBack();
        }
        while ($secondConnection->transactionLevel() > 0) {
            $secondConnection->rollBack();
        }
        $secondConnection->disconnect();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }
});
