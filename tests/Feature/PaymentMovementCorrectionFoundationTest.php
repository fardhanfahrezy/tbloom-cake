<?php

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\RefundTransfer;
use Database\Seeders\PaymentMovementCorrectionTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('links an initial correction to the same root payment event and Owner', function () {
    $correction = PaymentMovementCorrection::factory()->create()->fresh();
    expect($correction->movement->payment_id)->toBe($correction->payment_id);
    expect($correction->movement->corrections->sole()->is($correction))->toBeTrue();
    expect($correction->payment->is($correction->movement->payment))->toBeTrue();
    expect($correction->sourceEvent->payment_id)->toBe($correction->payment_id);
    expect($correction->approvedBy->role->value)->toBe('owner');
    expect($correction->revision)->toBe(1);
    expect($correction->initial_movement_id)->toBe($correction->payment_movement_id);
    expect($correction->previousCorrection)->toBeNull();
});

test('projects only the current correction and preserves the original fact and earlier revision', function () {
    $root = PaymentMovement::factory()->create(['occurred_at' => '2026-11-01 00:00:00']);
    expect($root->effectiveFact()['amount'])->toBe(100001);
    $first = PaymentMovementCorrection::factory()->for($root, 'movement')->create(['effective_amount' => 90000, 'effective_occurred_at' => '2026-11-01 00:01:00']);
    expect($root->effectiveFact()['amount'])->toBe(90000);
    $second = PaymentMovementCorrection::factory()->after($first)->create(['effective_amount' => 85000, 'effective_occurred_at' => '2026-11-01 00:02:00']);
    expect($root->headCorrection->is($second))->toBeTrue();
    expect($root->effectiveFact()['amount'])->toBe(85000);
    expect($root->effectiveFact()['occurred_at']->toDateTimeString())->toBe('2026-11-01 00:02:00');
    expect($first->fresh()->effective_amount)->toBe(90000);
    expect($second->previousCorrection->is($first))->toBeTrue();
    expect($second->initial_movement_id)->toBeNull();
    expect($root->fresh()->amount)->toBe(100001);
    expect($root->fresh()->occurred_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
    $this->assertDatabaseCount('payment_movements', 1);
});

test('voids an incorrect fact without inventing a bank return', function () {
    $correction = PaymentMovementCorrection::factory()->voided()->create();
    expect($correction->movement->effectiveFact()['amount'])->toBe(0);
    expect($correction->movement->fresh()->amount)->toBe(100001);
    $this->assertDatabaseCount('payment_movements', 1);
});

test('does not add correction amounts to receipts or count actual returns twice', function () {
    $receipt = PaymentMovement::factory()->create();
    $return = PaymentMovement::factory()->providerRefund()->create(['payment_id' => $receipt->payment_id, 'source_event_id' => $receipt->source_event_id]);
    PaymentMovementCorrection::factory()->for($receipt, 'movement')->create(['effective_amount' => 90000]);
    expect($receipt->effectiveFact()['amount'])->toBe(90000);
    expect($return->effectiveFact()['amount'])->toBe(20000);
    expect($receipt->effectiveFact()['amount'] - $return->effectiveFact()['amount'])->toBe(70000);
    $this->assertDatabaseCount('payment_movements', 2);
});

test('rejects invalid correction rows in MySQL', function (array $overrides, int $code) {
    $attributes = array_replace(PaymentMovementCorrection::factory()->raw(), ['created_at' => '2026-11-01 00:00:00'], $overrides);
    try {
        DB::table('payment_movement_corrections')->insert($attributes);
        $this->fail('MySQL accepted an invalid correction.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code);
    }
})->with([
    'revision zero' => [['revision' => 0], 3819],
    'later revision without previous' => [['revision' => 2], 3819],
    'amount overflow' => [['effective_amount' => '9223372036854775808'], 3819],
    'negative amount' => [['effective_amount' => -1], 1264],
    'no provenance' => [['source_event_id' => null, 'refund_transfer_id' => null], 3819],
    'empty reason' => [['reason' => ''], 3819],
    'blank reason' => [['reason' => '   '], 3819],
    'empty key' => [['idempotency_key' => ''], 3819],
    'null Owner' => [['approved_by' => null], 1048],
    'null verification' => [['verified_at' => null], 1048],
    'null occurrence' => [['effective_occurred_at' => null], 1048],
    'null amount' => [['effective_amount' => null], 1048],
    'null reason' => [['reason' => null], 1048],
    'null request hash' => [['request_hash' => null], 1048],
    'null creation' => [['created_at' => null], 1048],
    'missing Owner' => [['approved_by' => 0], 1452],
    'missing root' => [['payment_movement_id' => 0], 1452],
    'missing payment' => [['payment_id' => 0], 1452],
    'missing event' => [['source_event_id' => 0], 1452],
]);

test('rejects provenance from a different payment or unmatched event', function (string $state) {
    $event = PaymentEvent::factory()->$state()->create();
    try {
        PaymentMovementCorrection::factory()->create(['source_event_id' => $event->id]);
        $this->fail('MySQL accepted unrelated correction evidence.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
})->with(['processed', 'unmatched']);

test('accepts manual evidence only for the same payment', function () {
    $transfer = RefundTransfer::factory()->succeeded()->create();
    $root = PaymentMovement::factory()->manualRefund()->create(['refund_transfer_id' => $transfer->id]);
    $correction = PaymentMovementCorrection::factory()->for($root, 'movement')->create(['source_event_id' => null, 'refund_transfer_id' => $transfer->id]);
    expect($correction->refundTransfer->is($transfer))->toBeTrue();
    $other = RefundTransfer::factory()->succeeded()->create();
    try {
        PaymentMovementCorrection::factory()->after($correction)->create(['source_event_id' => null, 'refund_transfer_id' => $other->id]);
        $this->fail('MySQL accepted unrelated transfer evidence.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('rejects a root associated with a different payment even with matching event evidence', function () {
    $payment = Payment::factory()->create();
    $event = PaymentEvent::factory()->processed()->create(['payment_id' => $payment->id]);
    try {
        PaymentMovementCorrection::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id]);
        $this->fail('MySQL accepted a cross-payment root.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('prevents multiple first corrections and duplicate retry keys', function (string $conflict) {
    $first = PaymentMovementCorrection::factory()->create();
    $factory = match ($conflict) {
        'initial' => PaymentMovementCorrection::factory()->for($first->movement, 'movement'),
        'revision' => PaymentMovementCorrection::factory()->after($first)->state(['revision' => 1]),
        'retry key' => PaymentMovementCorrection::factory()->after($first)->state(['idempotency_key' => $first->idempotency_key]),
    };
    try {
        $factory->create();
        $this->fail('MySQL accepted conflicting correction history.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBeIn([1062, 3819]);
    }
    $this->assertDatabaseCount('payment_movement_corrections', 1);
})->with(['initial', 'revision', 'retry key']);

test('prevents branching an old correction head', function () {
    $first = PaymentMovementCorrection::factory()->create();
    $second = PaymentMovementCorrection::factory()->after($first)->create();
    try {
        PaymentMovementCorrection::factory()->after($first)->create(['revision' => 3]);
        $this->fail('MySQL accepted a second child of an old head.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    expect($first->movement->headCorrection->is($second))->toBeTrue();
});

test('rejects a previous correction from another root within the same payment', function () {
    $first = PaymentMovementCorrection::factory()->create();
    $otherRoot = PaymentMovement::factory()->providerRefund()->create(['payment_id' => $first->payment_id, 'source_event_id' => $first->source_event_id]);
    try {
        PaymentMovementCorrection::factory()->after($first)->create(['payment_movement_id' => $otherRoot->id]);
        $this->fail('MySQL accepted another root history.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('rejects a previous correction from a different payment', function () {
    $first = PaymentMovementCorrection::factory()->create();
    $other = PaymentMovementCorrection::factory()->create();
    try {
        PaymentMovementCorrection::factory()->after($first)->create(['previous_correction_id' => $other->id]);
        $this->fail('MySQL accepted another payment history.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('prevents rewriting and deleting correction history through the model', function (string $operation) {
    $correction = PaymentMovementCorrection::factory()->create();
    $correction->effective_amount = 1;
    expect(fn () => $correction->$operation())->toThrow(LogicException::class, 'append-only');
    expect($correction->fresh()->effective_amount)->toBe(90000);
})->with(['save', 'saveQuietly', 'delete', 'deleteQuietly']);

test('accepts the inclusive PHP money limit on corrections', function () {
    $correction = PaymentMovementCorrection::factory()->create(['effective_amount' => PHP_INT_MAX]);
    expect($correction->fresh()->effective_amount)->toBe(PHP_INT_MAX);
});

test('refuses rollback of populated correction history', function () {
    $correction = PaymentMovementCorrection::factory()->create();
    $migration = require database_path('migrations/2026_10_22_084014_create_payment_movement_corrections_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($correction);
});

test('seeds a synthetic correction chain only in the isolated database', function () {
    $this->seed(PaymentMovementCorrectionTestSeeder::class);
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('payment_movement_corrections', 2);
});

test('rolls back an appended revision without changing the prior effective fact', function () {
    $first = PaymentMovementCorrection::factory()->create();
    try {
        DB::transaction(function () use ($first): void {
            PaymentMovementCorrection::factory()->after($first)->voided()->create();
            throw new RuntimeException('synthetic crash before commit');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('synthetic crash before commit');
    }
    expect($first->movement->effectiveFact()['amount'])->toBe(90000);
    $this->assertDatabaseCount('payment_movement_corrections', 1);
    $this->assertDatabaseCount('payment_movements', 1);
});

test('restricts deletion and renumbering of correction parents', function (string $parent, string $operation) {
    $first = PaymentMovementCorrection::factory()->create();
    $second = PaymentMovementCorrection::factory()->after($first)->create();
    $target = match ($parent) {
        'root' => DB::table('payment_movements')->where('id', $first->payment_movement_id),
        'previous' => DB::table('payment_movement_corrections')->where('id', $first->id),
        'Owner' => DB::table('users')->where('id', $second->approved_by),
    };
    try {
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => 999999999]);
        }
        $this->fail('MySQL discarded correction provenance.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($second);
})->with(['root', 'previous', 'Owner'])->with(['delete', 'update']);

test('refuses correction seeding outside the isolated environment', function (string $boundary) {
    if ($boundary === 'environment') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config(['database.default' => 'mysql']);
    }
    try {
        expect(fn () => app(PaymentMovementCorrectionTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('payment_movement_corrections', 0);
})->with(['environment', 'connection']);

test('protects the generated initial slot from caller-supplied values', function () {
    $attributes = PaymentMovementCorrection::factory()->raw(['initial_movement_id' => null, 'created_at' => '2026-11-01 00:00:00']);
    try {
        DB::table('payment_movement_corrections')->insert($attributes);
        $this->fail('MySQL accepted a spoofed generated slot.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3105);
    }
});

test('matches the complete correction dictionary and indexes', function () {
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA, DATETIME_PRECISION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_movement_corrections' ORDER BY ORDINAL_POSITION"));
    expect($columns->pluck('COLUMN_NAME')->all())->toBe(['id', 'payment_movement_id', 'payment_id', 'revision', 'previous_correction_id', 'effective_amount', 'effective_occurred_at', 'verified_at', 'approved_by', 'source_event_id', 'refund_transfer_id', 'reason', 'idempotency_key', 'request_hash', 'created_at', 'initial_movement_id']);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe(['previous_correction_id', 'source_event_id', 'refund_transfer_id', 'initial_movement_id']);
    expect($columns->firstWhere('COLUMN_NAME', 'initial_movement_id')->EXTRA)->toBe('STORED GENERATED');
    expect($columns->whereNotNull('DATETIME_PRECISION')->pluck('DATETIME_PRECISION')->all())->toBe([0, 0, 0]);
    expect($columns->firstWhere('COLUMN_NAME', 'revision')->COLUMN_TYPE)->toBe('int unsigned');
    $indexes = DB::select("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payment_movement_corrections' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME");
    expect(collect($indexes)->pluck('columns_list')->sort()->values()->all())->toBe(collect(['id', 'id,payment_movement_id,payment_id', 'payment_movement_id,revision', 'previous_correction_id', 'initial_movement_id', 'payment_id,idempotency_key'])->sort()->values()->all());
});

test('rejects model arithmetic that would overwrite append-only history', function (string $operation, bool $usesColumns, bool $persisted) {
    $record = PaymentMovementCorrection::factory()->create();
    $target = $persisted ? $record : new PaymentMovementCorrection;
    $arguments = $usesColumns ? [['effective_amount' => 1]] : ['effective_amount', 1];

    expect(fn () => $target->$operation(...$arguments))->toThrow(LogicException::class, 'append-only');
    expect($record->fresh()->effective_amount)->toBe(90000);
})->with([
    'increment' => ['increment', false],
    'decrement' => ['decrement', false],
    'quiet increment' => ['incrementQuietly', false],
    'quiet decrement' => ['decrementQuietly', false],
    'increment columns' => ['incrementEach', true],
    'decrement columns' => ['decrementEach', true],
    'quiet increment columns' => ['incrementEachQuietly', true],
    'quiet decrement columns' => ['decrementEachQuietly', true],
])->with(['persisted' => true, 'unsaved' => false]);
