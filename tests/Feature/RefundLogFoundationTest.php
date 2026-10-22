<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundReasonType;
use App\Models\User;
use Database\Seeders\RefundLogTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores a gross customer obligation with bank details still unknown', function () {
    $payment = Payment::factory()->for(Order::factory()->paid()->state(['product_total' => 100001]))->paid()->create();
    $refund = RefundLog::factory()->for($payment)->create()->fresh();

    expect($refund->payment->is($payment))->toBeTrue();
    expect($refund->order_id)->toBe($payment->order_id);
    expect($refund->basis_amount)->toBe(100001);
    expect($refund->percentage)->toBe(80);
    expect($refund->amount)->toBe(80000);
    expect($refund->bank_name)->toBeNull();
    expect($refund->bank_verified_at)->toBeNull();
});

test('casts the obligation reason status and assignment version', function () {
    $refund = RefundLog::factory()->create()->fresh();

    expect($refund->reason_type)->toBe(RefundReasonType::CustomerCancel);
    expect($refund->status)->toBe(RefundLogStatus::Pending);
    expect($refund->assignment_version)->toBe(0);
});

test('preserves integer floor and maximum safe rupiah amounts', function (int $basis, string $reason, int $percentage, int $expected) {
    $payment = Payment::factory()->for(Order::factory()->paid()->state(['product_total' => $basis]))->paid()->create();
    $refund = RefundLog::factory()->for($payment)->create(['reason_type' => $reason, 'percentage' => $percentage])->fresh();

    expect($refund->basis_amount)->toBe($basis);
    expect($refund->amount)->toBe($expected);
})->with([
    'customer floor total' => [100001, 'customer_cancel', 80, 80000],
    'small total rounds to zero' => [1, 'customer_cancel', 80, 0],
    'maximum eighty percent' => [9223372036854775807, 'customer_cancel', 80, 7378697629483820645],
    'maximum full refund' => [9223372036854775807, 'store_cancel', 100, 9223372036854775807],
]);

test('encrypts and hides each recoverable bank field', function (string $column) {
    $bank = ['bank_name' => '[TEST] Bank', 'account_number' => '[TEST] private account', 'account_holder' => '[TEST] Synthetic holder'];
    $refund = RefundLog::factory()->create($bank);
    $stored = DB::table('refund_logs')->where('id', $refund->id)->value($column);

    expect($stored)->not->toBe($bank[$column])->not->toContain($bank[$column]);
    expect($refund->fresh()->getAttribute($column))->toBe($bank[$column]);
    expect(array_intersect(array_keys($bank), array_keys($refund->fresh()->toArray())))->toBe([]);
    expect($refund->fresh()->toJson())->not->toContain($bank[$column]);
})->with(['bank_name', 'account_number', 'account_holder']);

test('rejects invalid obligation values in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(RefundLog::factory()->raw(), [
        'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
    ], $overrides);

    try {
        DB::table('refund_logs')->insert($attributes);
        $this->fail('MySQL accepted invalid obligation values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'unknown reason' => [['reason_type' => 'other'], 3819],
    'reason case mismatch' => [['reason_type' => 'CUSTOMER_CANCEL'], 3819],
    'customer wrong percentage despite correct floor' => [['percentage' => 100, 'amount' => 100000], 3819],
    'full refund wrong percentage despite correct floor' => [['reason_type' => 'store_cancel'], 3819],
    'unknown status' => [['status' => 'paid'], 3819],
    'status case mismatch' => [['status' => 'PENDING'], 3819],
    'negative basis' => [['basis_amount' => -1], 1264],
    'negative amount' => [['amount' => -1], 1264],
    'money overflow despite correct floor' => [['reason_type' => 'store_cancel', 'percentage' => 100, 'basis_amount' => '9223372036854775808', 'amount' => '9223372036854775808'], 3819],
    'wrong floor' => [['basis_amount' => 100001, 'amount' => 80001], 3819],
    'null basis' => [['basis_amount' => null], 1048],
    'null percentage' => [['percentage' => null], 1048],
    'null amount' => [['amount' => null], 1048],
    'negative assignment version' => [['assignment_version' => -1], 1264],
    'overflow assignment version' => [['assignment_version' => 4294967296], 1264],
    'bank name only' => [['bank_name' => '[TEST] Bank'], 3819],
    'account number only' => [['account_number' => '[TEST] Number'], 3819],
    'holder only' => [['account_holder' => '[TEST] Holder'], 3819],
    'missing holder' => [['bank_name' => '[TEST] Bank', 'account_number' => '[TEST] Number'], 3819],
    'missing number' => [['bank_name' => '[TEST] Bank', 'account_holder' => '[TEST] Holder'], 3819],
    'missing bank' => [['account_number' => '[TEST] Number', 'account_holder' => '[TEST] Holder'], 3819],
    'bank verified without actor' => [['bank_verified_at' => '2026-11-01 00:00:00'], 3819],
    'completed without evidence' => [['status' => 'completed'], 3819],
    'missing payment' => [['payment_id' => 0], 1452],
    'missing bank verifier' => [['bank_verified_by' => 0, 'bank_verified_at' => '2026-11-01 00:00:00'], 1452],
    'missing assignee' => [['assigned_admin_id' => 0], 1452],
    'missing completion actor' => [['completed_by' => 0], 1452],
    'null creation time' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
]);

test('requires both bank verification fields when a real actor exists', function () {
    $actor = User::factory()->admin()->create();
    try {
        RefundLog::factory()->create(['bank_verified_by' => $actor->id]);
        $this->fail('MySQL accepted a bank verifier without verification time.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
});

test('requires both completion fields independently', function (string $missing) {
    $actor = User::factory()->admin()->create();
    $fields = ['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => '2026-11-01 01:00:00'];
    $fields[$missing] = null;
    try {
        RefundLog::factory()->create($fields);
        $this->fail('MySQL accepted incomplete completion evidence.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
})->with(['completed_by', 'completed_at']);

test('rejects an obligation connecting a payment to another existing order', function () {
    $other = Order::factory()->create();
    try {
        RefundLog::factory()->create(['order_id' => $other->id]);
        $this->fail('MySQL accepted a cross-order payment obligation.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
    $this->assertDatabaseCount('refund_logs', 0);
});

test('allows only one obligation per payment', function () {
    $refund = RefundLog::factory()->create();
    try {
        RefundLog::factory()->for($refund->payment)->create();
        $this->fail('MySQL accepted a second obligation for one payment.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('builds full-refund fixtures using the supplied gross basis', function (string $state, RefundReasonType $reason) {
    $refund = RefundLog::factory()->{$state}()->create(['basis_amount' => 90000])->fresh();

    expect($refund->reason_type)->toBe($reason);
    expect($refund->percentage)->toBe(100);
    expect($refund->basis_amount)->toBe(90000);
    expect($refund->amount)->toBe(90000);
})->with([
    ['storeCancellation', RefundReasonType::StoreCancel],
    ['latePayment', RefundReasonType::LatePayment],
    ['amountAnomaly', RefundReasonType::AmountAnomaly],
]);

test('builds synthetic verified processing review and completed fixture states', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
    $processing = RefundLog::factory()->processing()->create()->fresh();
    $review = RefundLog::factory()->review()->create()->fresh();
    $completed = RefundLog::factory()->completed()->create()->fresh();

    expect($processing->status)->toBe(RefundLogStatus::Processing);
    expect($processing->assignedAdmin->role->value)->toBe('admin');
    expect($processing->assignment_version)->toBe(1);
    expect($processing->bankVerifiedBy->role->value)->toBe('admin');
    expect($processing->bank_verified_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
    expect($review->status)->toBe(RefundLogStatus::Review);
    expect($review->review_reason)->not->toBeNull();
    expect($completed->status)->toBe(RefundLogStatus::Completed);
    expect($completed->completedBy->is($completed->assignedAdmin))->toBeTrue();
    expect($completed->completed_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
    expect($completed->order->refundLog->is($completed))->toBeTrue();
    expect($completed->payment->refundLog->is($completed))->toBeTrue();
});

test('restricts changing any referenced refund actor', function (string $column, string $operation) {
    $actor = User::factory()->admin()->create();
    $attributes = [$column => $actor->id];
    if ($column === 'bank_verified_by') {
        $attributes['bank_verified_at'] = now();
    }
    $refund = RefundLog::factory()->create($attributes);
    try {
        $target = DB::table('users')->where('id', $refund->getAttribute($column));
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => $actor->id + 1000000]);
        }
        $this->fail('MySQL changed a referenced obligation actor.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($refund);
})->with([
    ['bank_verified_by', 'delete'], ['bank_verified_by', 'update'],
    ['assigned_admin_id', 'delete'], ['assigned_admin_id', 'update'],
    ['completed_by', 'delete'], ['completed_by', 'update'],
]);

test('restricts deleting or relinking the referenced payment', function (string $operation) {
    $refund = RefundLog::factory()->create();
    $other = Order::factory()->create();
    try {
        $payment = DB::table('payments')->where('id', $refund->payment_id);
        if ($operation === 'delete') {
            $payment->delete();
        } else {
            $payment->update(['order_id' => $other->id]);
        }
        $this->fail('MySQL changed the obligation payment or ownership tuple.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($refund);
})->with(['delete', 'relink']);

test('refuses rollback that would discard refund history', function () {
    $refund = RefundLog::factory()->create();
    $migration = require database_path('migrations/2026_10_22_071728_create_refund_logs_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($refund);
});

test('seeds synthetic obligation reasons and states only in the isolated database', function () {
    $this->seed(RefundLogTestSeeder::class);

    $this->assertDatabaseCount('refund_logs', 7);
    expect(RefundLog::query()->where('percentage', 100)->count())->toBe(3);
    expect(RefundLog::query()->where('status', 'completed')->count())->toBe(1);
    foreach (RefundLog::with('payment')->get() as $refund) {
        expect($refund->order_id)->toBe($refund->payment->order_id);
    }
});

test('refuses synthetic obligation seeding through the development connection', function () {
    config(['database.default' => 'mysql']);
    try {
        expect(fn () => app(RefundLogTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('refund_logs', 0);
});

test('refuses synthetic obligation seeding outside the testing environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(RefundLogTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
    $this->assertDatabaseCount('refund_logs', 0);
});

test('matches the obligation dictionary types nullability defaults and indexes', function () {
    $metadata = DB::selectOne("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_logs'");
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, DATA_TYPE, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_logs' ORDER BY ORDINAL_POSITION"));

    expect($metadata->ENGINE)->toBe('InnoDB');
    expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
    expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe([
        'id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'payment_id' => 'bigint unsigned', 'reason_type' => 'varchar(32)',
        'basis_amount' => 'bigint unsigned', 'percentage' => 'smallint unsigned', 'amount' => 'bigint unsigned', 'status' => 'varchar(32)',
        'bank_name' => 'text', 'account_number' => 'text', 'account_holder' => 'text', 'bank_verified_at' => 'datetime',
        'bank_verified_by' => 'bigint unsigned', 'assigned_admin_id' => 'bigint unsigned', 'assignment_version' => 'int unsigned',
        'completed_by' => 'bigint unsigned', 'completed_at' => 'datetime', 'review_reason' => 'text', 'created_at' => 'datetime', 'updated_at' => 'datetime',
    ]);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe([
        'bank_name', 'account_number', 'account_holder', 'bank_verified_at', 'bank_verified_by', 'assigned_admin_id', 'completed_by', 'completed_at', 'review_reason',
    ]);
    expect($columns->whereNotNull('COLUMN_DEFAULT')->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')->all())->toBe(['status' => 'pending', 'assignment_version' => '0']);
    expect($columns->whereIn('COLUMN_NAME', ['reason_type', 'status'])->pluck('COLLATION_NAME')->unique()->all())->toBe(['ascii_bin']);
    expect($columns->where('DATA_TYPE', 'datetime')->pluck('DATETIME_PRECISION')->unique()->all())->toBe([0]);

    $indexes = collect(DB::select("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_logs' ORDER BY INDEX_NAME, SEQ_IN_INDEX"))->groupBy('INDEX_NAME');
    foreach ([['status', 'created_at', 'id'], ['assigned_admin_id', 'status', 'id']] as $index) {
        expect($indexes->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
    }
    foreach ([['id'], ['payment_id'], ['id', 'payment_id']] as $index) {
        expect($indexes->filter(fn ($columns): bool => $columns->first()->NON_UNIQUE === 0)->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
    }
});
