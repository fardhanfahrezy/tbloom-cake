<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentClosureResult;
use App\Models\PaymentStatus;
use Database\Seeders\PaymentTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores one pending payment bill matching its order with unknown timing preserved', function () {
    $order = Order::factory()->create(['product_total' => 100001]);
    $payment = Payment::factory()->for($order)->create()->fresh();

    expect($payment->order->is($order))->toBeTrue();
    expect($order->payment->is($payment))->toBeTrue();
    expect($payment->expected_amount)->toBe(100001);
    expect($payment->provider)->toBe('midtrans');
    expect($payment->currency)->toBe('IDR');
    expect($payment->is_late)->toBeNull();
    expect($payment->provider_paid_at)->toBeNull();
    expect($payment->closure_event_id)->toBeNull();
});

test('casts financial status to the payment enum', function () {
    $payment = Payment::factory()->create()->fresh();

    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->reconciliation_attempts)->toBe(0);
});

test('builds synthetic paid and closed-unpaid fixtures without live gateway verification', function () {
    $this->freezeTime();
    $paid = Payment::factory()->paid()->create()->fresh();
    $closed = Payment::factory()->closedUnpaid()->create()->fresh();

    expect($paid->status)->toBe(PaymentStatus::Paid);
    expect($paid->provider_paid_at)->not->toBeNull();
    expect($paid->is_late)->toBeFalse();
    expect($closed->status)->toBe(PaymentStatus::Expired);
    expect($closed->closure_result)->toBe(PaymentClosureResult::UnpaidClosed);
    expect($closed->closureEvent->payment_id)->toBe($closed->id);
    expect($closed->closureEvent->processed_at)->not->toBeNull();
});

test('encrypts Snap tokens at rest and hides them from serialization', function () {
    $plaintext = '[TEST] synthetic Snap token';
    $payment = Payment::factory()->create(['snap_token' => $plaintext]);
    $stored = DB::table('payments')->where('id', $payment->id)->value('snap_token');

    expect($stored)->not->toBe($plaintext)->not->toContain($plaintext);
    expect($payment->fresh()->snap_token)->toBe($plaintext);
    expect(array_key_exists('snap_token', $payment->fresh()->toArray()))->toBeFalse();
    expect($payment->fresh()->toJson())->not->toContain($plaintext);
});

test('rejects invalid payment bill and closure fields in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(Payment::factory()->raw(), [
        'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
    ], $overrides);

    try {
        DB::table('payments')->insert($attributes);
        $this->fail('MySQL accepted invalid payment values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'zero amount' => [['expected_amount' => 0], 3819],
    'negative amount' => [['expected_amount' => -1], 1264],
    'PHP integer overflow' => [['expected_amount' => '9223372036854775808'], 3819],
    'null amount' => [['expected_amount' => null], 1048],
    'unknown status' => [['status' => 'review'], 3819],
    'status case mismatch' => [['status' => 'PAID'], 3819],
    'wrong currency' => [['currency' => 'USD'], 3819],
    'currency case mismatch' => [['currency' => 'idr'], 3819],
    'invalid late flag' => [['is_late' => 2], 3819],
    'negative reconciliation attempts' => [['reconciliation_attempts' => -1], 1264],
    'overflow reconciliation attempts' => [['reconciliation_attempts' => 4294967296], 1264],
    'missing order' => [['order_id' => 0], 1452],
    'null creation time' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
    'only closure result' => [['closure_result' => 'unpaid_closed'], 3819],
    'only closure verification time' => [['closure_verified_at' => '2026-11-01 00:31:00'], 3819],
    'only closure event' => [['closure_event_id' => 1], 3819],
    'closure missing event' => [['closure_result' => 'unpaid_closed', 'closure_verified_at' => '2026-11-01 00:31:00'], 3819],
    'closure missing verification time' => [['closure_result' => 'unpaid_closed', 'closure_event_id' => 1], 3819],
    'closure missing result' => [['closure_verified_at' => '2026-11-01 00:31:00', 'closure_event_id' => 1], 3819],
]);

test('preserves inclusive maximum integer rupiah amounts', function () {
    $order = Order::factory()->create(['product_total' => PHP_INT_MAX]);
    $payment = Payment::factory()->for($order)->create();

    expect($payment->fresh()->expected_amount)->toBe(9223372036854775807);
});

test('enforces one bill for each order', function () {
    $payment = Payment::factory()->create();

    try {
        Payment::factory()->for($payment->order)->create();
        $this->fail('MySQL accepted a second payment reference for one order.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('deduplicates merchant references and provider transaction identities', function (string $column) {
    $payment = Payment::factory()->create(['merchant_reference' => 'Ref-A', 'provider_transaction_id' => 'Txn-A']);

    try {
        Payment::factory()->create([$column => $payment->getAttribute($column)]);
        $this->fail('MySQL accepted a duplicate payment identity.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
})->with(['merchant_reference', 'provider_transaction_id']);

test('permits unknown provider identities and keeps reference namespaces binary', function () {
    Payment::factory()->count(2)->create();
    Payment::factory()->create(['merchant_reference' => 'Ref-A', 'provider_transaction_id' => 'Txn-A']);
    Payment::factory()->create(['merchant_reference' => 'ref-a', 'provider_transaction_id' => 'txn-a']);
    Payment::factory()->create(['provider' => 'test-provider', 'merchant_reference' => 'Ref-A', 'provider_transaction_id' => 'Txn-A']);

    $this->assertDatabaseCount('payments', 5);
    expect(Payment::query()->whereNull('provider_transaction_id')->count())->toBe(2);
});

test('casts a known late flag without losing the distinction from unknown', function (int $flag, bool $expected) {
    $payment = Payment::factory()->create(['is_late' => $flag]);

    expect($payment->fresh()->is_late)->toBe($expected);
})->with([[0, false], [1, true]]);

test('restricts deletion and renumbering of the payment order', function (string $operation) {
    $payment = Payment::factory()->create();
    try {
        $parent = DB::table('orders')->where('id', $payment->order_id);
        if ($operation === 'delete') {
            $parent->delete();
        } else {
            $parent->update(['id' => $payment->order_id + 1000000]);
        }
        $this->fail('MySQL changed a referenced payment order.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($payment);
})->with(['delete', 'update']);

test('refuses rollback that would discard payment history', function () {
    $payment = Payment::factory()->create();
    $migration = require database_path('migrations/2026_10_22_015752_create_payments_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($payment);
});

test('seeds synthetic bills and inbox evidence only in the isolated database', function () {
    $this->seed(PaymentTestSeeder::class);

    $this->assertDatabaseCount('payments', 3);
    $this->assertDatabaseCount('payment_events', 4);
    expect(Payment::query()->where('status', 'paid')->sole()->order->status->value)->toBe('paid');
    $closed = Payment::query()->where('status', 'expired')->sole();
    expect($closed->order->status->value)->toBe('expired');
    expect($closed->closure_result)->toBe(PaymentClosureResult::UnpaidClosed);
    expect($closed->closureEvent->payment_id)->toBe($closed->id);
    expect(DB::table('payment_events')->whereNull('payment_id')->count())->toBe(1);
});

test('refuses synthetic payment seeding through the development connection', function () {
    config(['database.default' => 'mysql']);
    try {
        expect(fn () => app(PaymentTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('payments', 0);
});

test('refuses synthetic payment seeding outside the testing environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(PaymentTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
    $this->assertDatabaseCount('payments', 0);
});

test('matches the payment dictionary types defaults precision and indexes', function () {
    $expectedTypes = [
        'payments' => [
            'id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'provider' => 'varchar(32)',
            'merchant_reference' => 'varchar(100)', 'provider_transaction_id' => 'varchar(128)', 'expected_amount' => 'bigint unsigned',
            'currency' => 'char(3)', 'status' => 'varchar(32)', 'provider_status' => 'varchar(64)', 'fraud_status' => 'varchar(64)',
            'provider_paid_at' => 'datetime', 'verified_at' => 'datetime', 'is_late' => 'tinyint unsigned', 'review_reason' => 'varchar(64)',
            'reconciliation_attempts' => 'int unsigned', 'next_reconcile_at' => 'datetime', 'closure_result' => 'varchar(32)',
            'closure_verified_at' => 'datetime', 'closure_event_id' => 'bigint unsigned', 'snap_token' => 'text',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'payment_events' => [
            'id' => 'bigint unsigned', 'payment_id' => 'bigint unsigned', 'provider' => 'varchar(32)', 'provider_reference' => 'varchar(128)',
            'event_fingerprint' => 'char(64)', 'payload_redacted' => 'json', 'received_at' => 'datetime', 'verified_at' => 'datetime',
            'processing_status' => 'varchar(32)', 'attempts' => 'int unsigned', 'next_attempt_at' => 'datetime', 'claim_token' => 'char(36)',
            'lease_until' => 'datetime', 'processed_at' => 'datetime', 'error' => 'varchar(255)', 'created_at' => 'datetime',
        ],
    ];
    $nullableColumns = [
        'payments' => ['provider_transaction_id', 'provider_status', 'fraud_status', 'provider_paid_at', 'verified_at', 'is_late', 'review_reason', 'next_reconcile_at', 'closure_result', 'closure_verified_at', 'closure_event_id', 'snap_token'],
        'payment_events' => ['payment_id', 'verified_at', 'next_attempt_at', 'claim_token', 'lease_until', 'processed_at', 'error'],
    ];
    $technicalColumns = [
        'payments' => ['provider', 'merchant_reference', 'provider_transaction_id', 'currency', 'status', 'provider_status', 'fraud_status', 'review_reason', 'closure_result'],
        'payment_events' => ['provider', 'provider_reference', 'event_fingerprint', 'processing_status', 'claim_token'],
    ];
    $defaults = [
        'payments' => ['provider' => 'midtrans', 'currency' => 'IDR', 'status' => 'pending', 'reconciliation_attempts' => '0'],
        'payment_events' => ['processing_status' => 'pending', 'attempts' => '0'],
    ];
    $requiredIndexes = [
        'payments' => [['status', 'next_reconcile_at', 'id'], ['review_reason', 'next_reconcile_at', 'id']],
        'payment_events' => [['processing_status', 'next_attempt_at', 'id'], ['processing_status', 'lease_until', 'id'], ['provider', 'provider_reference', 'id']],
    ];
    $requiredUnique = [
        'payments' => [['id'], ['order_id'], ['provider', 'merchant_reference'], ['provider', 'provider_transaction_id'], ['id', 'order_id']],
        'payment_events' => [['id'], ['provider', 'event_fingerprint'], ['id', 'payment_id']],
    ];

    foreach ($expectedTypes as $table => $types) {
        $metadata = DB::selectOne('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        $columns = collect(DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, DATA_TYPE, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]));
        expect($metadata->ENGINE)->toBe('InnoDB');
        expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
        expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe($types);
        expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe($nullableColumns[$table]);
        expect($columns->whereNotNull('COLUMN_DEFAULT')->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')->all())->toBe($defaults[$table]);
        expect($columns->whereIn('COLUMN_NAME', $technicalColumns[$table])->pluck('COLLATION_NAME')->unique()->all())->toBe(['ascii_bin']);
        expect($columns->where('DATA_TYPE', 'datetime')->pluck('DATETIME_PRECISION')->unique()->all())->toBe([0]);

        $indexes = collect(DB::select('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]))->groupBy('INDEX_NAME');
        foreach ($requiredIndexes[$table] as $index) {
            expect($indexes->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
        }
        foreach ($requiredUnique[$table] as $index) {
            expect($indexes->filter(fn ($columns): bool => $columns->first()->NON_UNIQUE === 0)->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
        }
    }
});
