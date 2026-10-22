<?php

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementKind;
use App\Models\PaymentMovementSource;
use App\Models\RefundTransfer;
use App\Models\User;
use Database\Seeders\PaymentMovementTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('links a synthetic receipt to its payment and verified source event', function () {
    $movement = PaymentMovement::factory()->create()->fresh();
    expect($movement->payment->movements->sole()->is($movement))->toBeTrue();
    expect($movement->sourceEvent->payment_id)->toBe($movement->payment_id);
    expect($movement->sourceEvent->verified_at)->not->toBeNull();
    expect($movement->amount)->toBe(100001);
});

test('represents each actual money source without inventing a second receipt', function (string $state, string $kind, string $source) {
    $movement = PaymentMovement::factory()->$state()->create()->fresh();
    expect($movement->kind)->toBe(PaymentMovementKind::from($kind));
    expect($movement->source)->toBe(PaymentMovementSource::from($source));
    if ($state === 'manualRefund') {
        expect($movement->refundTransfer->payment_id)->toBe($movement->payment_id);
        expect($movement->refundTransfer->movement->is($movement))->toBeTrue();
        expect($movement->verifiedBy->is($movement->refundTransfer->verifiedBy))->toBeTrue();
    } else {
        expect($movement->sourceEvent->payment_id)->toBe($movement->payment_id);
        expect($movement->sourceEvent->movements->sole()->is($movement))->toBeTrue();
    }
})->with([
    ['receipt', 'receipt', 'gateway_receipt'],
    ['manualRefund', 'return', 'manual_refund'],
    ['providerRefund', 'return', 'provider_refund'],
    ['reversal', 'return', 'reversal'],
]);

test('rejects invalid movement facts in MySQL', function (array $overrides, int $code) {
    $attributes = array_replace(PaymentMovement::factory()->raw(), ['created_at' => '2026-11-01 00:00:00'], $overrides);
    try {
        DB::table('payment_movements')->insert($attributes);
        $this->fail('MySQL accepted an invalid money fact.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code);
    }
})->with([
    'zero amount' => [['amount' => 0], 3819],
    'negative amount' => [['amount' => -1], 1264],
    'PHP overflow' => [['amount' => '9223372036854775808'], 3819],
    'invalid kind' => [['kind' => 'adjustment'], 3819],
    'invalid source' => [['source' => 'cash'], 3819],
    'kind case' => [['kind' => 'RECEIPT'], 3819],
    'source whitespace' => [['source' => 'gateway_receipt '], 3819],
    'receipt reversal' => [['source' => 'reversal'], 3819],
    'return receipt' => [['kind' => 'return'], 3819],
    'gateway without event' => [['source_event_id' => null], 3819],
    'missing payment' => [['payment_id' => 0], 1452],
    'missing event' => [['source_event_id' => 0], 1452],
    'missing actor' => [['verified_by' => 0], 1452],
    'null amount' => [['amount' => null], 1048],
    'null occurrence' => [['occurred_at' => null], 1048],
    'null verification' => [['verified_at' => null], 1048],
    'null creation' => [['created_at' => null], 1048],
    'null identity' => [['canonical_key' => null], 1048],
    'empty identity' => [['canonical_key' => ''], 3819],
]);

test('rejects cross-payment and unmatched event provenance', function (string $state) {
    $event = PaymentEvent::factory()->$state()->create();
    try {
        PaymentMovement::factory()->create(['payment_id' => Payment::factory(), 'source_event_id' => $event->id]);
        $this->fail('MySQL accepted another payment event.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
})->with(['processed', 'unmatched']);

test('rejects cross-payment manual transfer provenance', function () {
    $other = Payment::factory()->create();
    try {
        PaymentMovement::factory()->manualRefund()->create(['payment_id' => $other->id]);
        $this->fail('MySQL accepted another payment transfer.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('requires manual transfer provenance and excludes it from gateway sources', function (string $state, bool $includeTransfer) {
    $transfer = RefundTransfer::factory()->succeeded()->create();
    $event = PaymentEvent::factory()->processed()->create(['payment_id' => $transfer->payment_id]);
    $attributes = PaymentMovement::factory()->$state()->raw([
        'payment_id' => $transfer->payment_id,
        'amount' => 20000,
        'verified_by' => $transfer->prepared_by,
        'source_event_id' => $state === 'manualRefund' ? null : $event->id,
        'refund_transfer_id' => $includeTransfer ? $transfer->id : null,
        'created_at' => '2026-11-01 00:00:00',
    ]);
    try {
        DB::table('payment_movements')->insert($attributes);
        $this->fail('MySQL accepted invalid transfer provenance.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
})->with([['manualRefund', false], ['receipt', true], ['providerRefund', true], ['reversal', true]]);

test('deduplicates canonical facts per payment even across distinct transport events', function () {
    $first = PaymentMovement::factory()->create();
    $event = PaymentEvent::factory()->processed()->create(['payment_id' => $first->payment_id]);
    try {
        PaymentMovement::factory()->create(['payment_id' => $first->payment_id, 'source_event_id' => $event->id, 'canonical_key' => $first->canonical_key]);
        $this->fail('Duplicate fact was inserted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    $this->assertDatabaseCount('payment_movements', 1);
    expect($first->fresh()->amount)->toBe(100001);
});

test('allows the same canonical key in another payment and distinguishes its case', function () {
    $first = PaymentMovement::factory()->create(['canonical_key' => 'test:Case']);
    PaymentMovement::factory()->create(['canonical_key' => $first->canonical_key]);
    PaymentMovement::factory()->create(['payment_id' => $first->payment_id, 'source_event_id' => $first->source_event_id, 'canonical_key' => 'test:case']);
    $this->assertDatabaseCount('payment_movements', 3);
});

test('allows only one actual return per manual transfer', function () {
    $first = PaymentMovement::factory()->manualRefund()->create();
    try {
        PaymentMovement::factory()->manualRefund()->create(['refund_transfer_id' => $first->refund_transfer_id]);
        $this->fail('Duplicate transfer return was inserted.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('accepts the full PHP money domain without floating point conversion', function () {
    $movement = PaymentMovement::factory()->create(['amount' => PHP_INT_MAX]);
    expect($movement->fresh()->amount)->toBe(PHP_INT_MAX);
});

test('restricts deletion and renumbering of every movement parent', function (string $parent, string $operation) {
    $movement = PaymentMovement::factory()->manualRefund()->create(['verified_by' => User::factory()->owner()]);
    $event = PaymentEvent::factory()->processed()->create(['payment_id' => $movement->payment_id]);
    $gateway = PaymentMovement::factory()->create(['payment_id' => $movement->payment_id, 'source_event_id' => $event->id]);
    $target = match ($parent) {
        'payment' => DB::table('payments')->where('id', $movement->payment_id),
        'event' => DB::table('payment_events')->where('id', $gateway->source_event_id),
        'transfer' => DB::table('refund_transfers')->where('id', $movement->refund_transfer_id),
        'actor' => DB::table('users')->where('id', $movement->verified_by),
    };
    try {
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => 999999999]);
        }
        $this->fail('MySQL changed a movement parent.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($movement);
})->with(['payment', 'event', 'transfer', 'actor'])->with(['delete', 'update']);

test('refuses rollback of populated movement history', function () {
    $movement = PaymentMovement::factory()->create();
    $migration = require database_path('migrations/2026_10_22_082202_create_payment_movements_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($movement);
});

test('prevents rewriting or deleting a persisted root through the model', function (string $operation) {
    $movement = PaymentMovement::factory()->create();
    $movement->amount = 1;
    expect(fn () => $movement->$operation())->toThrow(LogicException::class, 'append-only');
    expect($movement->fresh()->amount)->toBe(100001);
})->with(['save', 'saveQuietly', 'delete', 'deleteQuietly']);

test('seeds only synthetic movement sources in the isolated database', function () {
    $this->seed(PaymentMovementTestSeeder::class);
    $this->assertDatabaseCount('payment_movements', 4);
    expect(PaymentMovement::query()->orderBy('id')->pluck('source')->map(fn ($source) => $source->value)->all())
        ->toBe(['gateway_receipt', 'manual_refund', 'provider_refund', 'reversal']);
});

test('refuses movement seeding outside the isolated environment', function (string $boundary) {
    if ($boundary === 'environment') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config(['database.default' => 'mysql']);
    }
    try {
        expect(fn () => app(PaymentMovementTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('payment_movements', 0);
})->with(['environment', 'connection']);

test('matches the root dictionary and enforced constraint metadata', function () {
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, DATETIME_PRECISION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_movements' ORDER BY ORDINAL_POSITION"));
    expect($columns->pluck('COLUMN_NAME')->all())->toBe(['id', 'payment_id', 'kind', 'source', 'canonical_key', 'amount', 'occurred_at', 'verified_at', 'source_event_id', 'refund_transfer_id', 'verified_by', 'reconciliation_note', 'created_at']);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe(['source_event_id', 'refund_transfer_id', 'verified_by', 'reconciliation_note']);
    expect($columns->firstWhere('COLUMN_NAME', 'amount')->COLUMN_TYPE)->toBe('bigint unsigned');
    expect($columns->whereNotNull('DATETIME_PRECISION')->pluck('DATETIME_PRECISION')->all())->toBe([0, 0, 0]);
    $checks = DB::select("SELECT ENFORCED FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'payment_movements' AND constraint_type = 'CHECK'");
    expect(count($checks))->toBe(4);
    expect(collect($checks)->pluck('ENFORCED')->unique()->all())->toBe(['YES']);
});

test('rejects model arithmetic that would overwrite append-only history', function (string $operation, bool $usesColumns, bool $persisted) {
    $record = PaymentMovement::factory()->create();
    $target = $persisted ? $record : new PaymentMovement;
    $arguments = $usesColumns ? [['amount' => 1]] : ['amount', 1];

    expect(fn () => $target->$operation(...$arguments))->toThrow(LogicException::class, 'append-only');
    expect($record->fresh()->amount)->toBe(100001);
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
