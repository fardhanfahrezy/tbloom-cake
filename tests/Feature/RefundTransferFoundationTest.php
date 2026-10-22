<?php

use App\Models\Payment;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use App\Models\User;
use Database\Seeders\RefundTransferTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores a prepared attempt with the obligation payment and approved source alias', function () {
    $refund = RefundLog::factory()->processing()->create();
    $transfer = RefundTransfer::factory()->for($refund)->create()->fresh();

    expect($transfer->refundLog->is($refund))->toBeTrue();
    expect($transfer->payment_id)->toBe($refund->payment_id);
    expect($transfer->amount)->toBe(80000);
    expect($transfer->active_refund_id)->toBe($refund->id);
    expect(config('refunds.source_account_key'))->toBe('REK_UTAMA_TBLOOM_BCA');
    expect($transfer->source_account_key)->toBe('REK_UTAMA_TBLOOM_BCA');
    expect($transfer->transfer_reference)->toBeNull();
});

test('casts attempt states versions and UTC evidence dates', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
    $transfer = RefundTransfer::factory()->create()->fresh();

    expect($transfer->status)->toBe(RefundTransferStatus::Prepared);
    expect($transfer->attempt_number)->toBe(1);
    expect($transfer->assignment_version)->toBe(1);
    expect($transfer->prepared_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
    expect($transfer->bank_verified_at)->not->toBeNull();
});

test('encrypts and hides each destination snapshot field', function (string $column) {
    $transfer = RefundTransfer::factory()->create();
    $plaintext = $transfer->getAttribute($column);
    $stored = DB::table('refund_transfers')->where('id', $transfer->id)->value($column);

    expect($stored)->not->toBe($plaintext)->not->toContain($plaintext);
    expect($transfer->fresh()->getAttribute($column))->toBe($plaintext);
    expect(array_intersect(['bank_name', 'account_number', 'account_holder'], array_keys($transfer->fresh()->toArray())))->toBe([]);
    expect($transfer->fresh()->toJson())->not->toContain($plaintext);
})->with(['bank_name', 'account_number', 'account_holder']);

test('rejects invalid attempt values in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(RefundTransfer::factory()->raw(), [
        'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
    ], $overrides);
    try {
        DB::table('refund_transfers')->insert($attributes);
        $this->fail('MySQL accepted invalid transfer fields.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'unknown status' => [['status' => 'review'], 3819],
    'status case mismatch' => [['status' => 'PREPARED'], 3819],
    'zero amount' => [['amount' => 0], 3819],
    'negative amount' => [['amount' => -1], 1264],
    'amount overflow' => [['amount' => '9223372036854775808'], 3819],
    'negative attempt number' => [['attempt_number' => -1], 1264],
    'attempt number overflow' => [['attempt_number' => 4294967296], 1264],
    'negative assignment version' => [['assignment_version' => -1], 1264],
    'assignment version overflow' => [['assignment_version' => 4294967296], 1264],
    'missing obligation' => [['refund_log_id' => 0], 1452],
    'missing payment' => [['payment_id' => 0], 1452],
    'missing preparer' => [['prepared_by' => 0], 1452],
    'missing verifier' => [['verified_by' => 0], 1452],
    'null amount' => [['amount' => null], 1048],
    'null source alias' => [['source_account_key' => null], 1048],
    'null idempotency key' => [['idempotency_key' => null], 1048],
    'null bank' => [['bank_name' => null], 1048],
    'null account' => [['account_number' => null], 1048],
    'null holder' => [['account_holder' => null], 1048],
    'null bank verification time' => [['bank_verified_at' => null], 1048],
    'null preparation time' => [['prepared_at' => null], 1048],
    'null creation time' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
]);

test('requires each success evidence field independently', function (string $missing) {
    $actor = User::factory()->admin()->create();
    $attributes = array_replace(RefundTransfer::factory()->raw(), [
        'status' => 'succeeded', 'transfer_reference' => 'TEST-BANK-REF', 'transferred_at' => '2026-11-01 00:00:00',
        'verified_by' => $actor->id, 'verified_at' => '2026-11-01 00:00:00', 'completed_at' => '2026-11-01 00:00:00',
        'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
    ], [$missing => null]);
    try {
        DB::table('refund_transfers')->insert($attributes);
        $this->fail('MySQL accepted incomplete success evidence.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
})->with(['transfer_reference', 'transferred_at', 'verified_by', 'verified_at', 'completed_at']);

test('requires each confirmed failure evidence field independently', function (string $missing) {
    $actor = User::factory()->admin()->create();
    $attributes = array_replace(RefundTransfer::factory()->raw(), [
        'status' => 'failed_confirmed', 'verified_by' => $actor->id, 'verified_at' => '2026-11-01 00:00:00',
        'verification_note' => '[TEST] verified bank failure', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
    ], [$missing => null]);
    try {
        DB::table('refund_transfers')->insert($attributes);
        $this->fail('MySQL accepted a failure without verification.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
})->with(['verified_by', 'verified_at', 'verification_note']);

test('holds the active slot for prepared and unknown attempts', function (string $status) {
    $transfer = RefundTransfer::factory()->create(['status' => $status]);
    try {
        RefundTransfer::factory()->for($transfer->refundLog)->create(['attempt_number' => 2]);
        $this->fail('MySQL allowed a second unresolved transfer.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    $this->assertDatabaseCount('refund_transfers', 1);
})->with(['prepared', 'unknown']);

test('rejects a transfer linked to an existing payment from another obligation', function () {
    $other = Payment::factory()->create();
    try {
        RefundTransfer::factory()->create(['payment_id' => $other->id]);
        $this->fail('MySQL accepted a cross-payment attempt.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('preserves destination and assignment snapshots after the obligation changes', function () {
    $transfer = RefundTransfer::factory()->create();
    $snapshot = $transfer->only(['bank_name', 'account_number', 'account_holder', 'assignment_version']);
    $transfer->refundLog->forceFill([
        'bank_name' => '[TEST] Another bank', 'account_number' => '[TEST] Another account',
        'account_holder' => '[TEST] Another holder', 'assignment_version' => 2,
    ])->save();

    expect($transfer->fresh()->only(array_keys($snapshot)))->toBe($snapshot);
});

test('builds synthetic unknown succeeded and confirmed failure states with relationships', function () {
    $unknown = RefundTransfer::factory()->unknown()->create()->fresh();
    $success = RefundTransfer::factory()->succeeded()->create()->fresh();
    $failed = RefundTransfer::factory()->failedConfirmed()->create()->fresh();

    expect($unknown->status)->toBe(RefundTransferStatus::Unknown);
    expect($unknown->active_refund_id)->toBe($unknown->refund_log_id);
    expect($success->status)->toBe(RefundTransferStatus::Succeeded);
    expect($success->active_refund_id)->toBeNull();
    expect($success->transfer_reference)->toStartWith('TEST-BANK-');
    expect($success->preparedBy->role->value)->toBe('admin');
    expect($success->verifiedBy->role->value)->toBe('admin');
    expect($success->payment->refundTransfers->sole()->is($success))->toBeTrue();
    expect($success->refundLog->transfers->sole()->is($success))->toBeTrue();
    expect($failed->status)->toBe(RefundTransferStatus::FailedConfirmed);
    expect($failed->active_refund_id)->toBeNull();
    expect($failed->verification_note)->not->toBeNull();
});

test('retains terminal attempts while allowing one new active attempt', function () {
    $refund = RefundLog::factory()->processing()->create();
    RefundTransfer::factory()->for($refund)->succeeded()->create(['attempt_number' => 1, 'amount' => 20000]);
    RefundTransfer::factory()->for($refund)->failedConfirmed()->create(['attempt_number' => 2, 'amount' => 60000]);
    $active = RefundTransfer::factory()->for($refund)->unknown()->create(['attempt_number' => 3, 'amount' => 60000]);

    $this->assertDatabaseCount('refund_transfers', 3);
    expect(DB::table('refund_transfers')->whereNull('active_refund_id')->count())->toBe(2);
    expect($active->fresh()->active_refund_id)->toBe($refund->id);
});

test('deduplicates attempt number and idempotency key within each obligation', function (string $column) {
    $first = RefundTransfer::factory()->failedConfirmed()->create();
    $fields = ['attempt_number' => 2, $column => $first->getAttribute($column)];
    try {
        RefundTransfer::factory()->for($first->refundLog)->failedConfirmed()->create($fields);
        $this->fail('MySQL accepted a duplicate attempt identity.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
})->with(['attempt_number', 'idempotency_key']);

test('deduplicates bank references across obligations sharing a source account', function () {
    RefundTransfer::factory()->succeeded()->create(['transfer_reference' => 'TEST-REF-A']);
    try {
        RefundTransfer::factory()->succeeded()->create(['transfer_reference' => 'TEST-REF-A']);
        $this->fail('MySQL accepted the same bank transfer identity twice.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('preserves binary and source namespaces while allowing unknown bank references', function () {
    RefundTransfer::factory()->count(2)->create(['idempotency_key' => 'Same-key']);
    RefundTransfer::factory()->succeeded()->create(['transfer_reference' => 'TEST-REF-A']);
    RefundTransfer::factory()->succeeded()->create(['transfer_reference' => 'test-ref-a']);
    RefundTransfer::factory()->succeeded()->create(['source_account_key' => 'TEST-OTHER-SOURCE', 'transfer_reference' => 'TEST-REF-A']);

    $this->assertDatabaseCount('refund_transfers', 5);
    expect(DB::table('refund_transfers')->whereNull('transfer_reference')->count())->toBe(2);
});

test('retains exact bank reference bytes including trailing spaces and Unicode', function () {
    foreach (['TEST-REF-A', 'TEST-REF-A ', 'TEST-REF-É', "TEST-REF-E\u{0301}"] as $reference) {
        $transfer = RefundTransfer::factory()->succeeded()->create(['transfer_reference' => $reference]);
        expect($transfer->fresh()->transfer_reference)->toBe($reference);
    }

    $this->assertDatabaseCount('refund_transfers', 4);
});

test('recomputes the active slot only after confirmed terminal evidence', function () {
    $transfer = RefundTransfer::factory()->unknown()->create();
    $actor = User::factory()->admin()->create();
    $transfer->forceFill([
        'status' => 'failed_confirmed', 'verified_by' => $actor->id, 'verified_at' => now(), 'verification_note' => '[TEST] confirmed bank failure',
    ])->save();
    expect($transfer->fresh()->active_refund_id)->toBeNull();
    $next = RefundTransfer::factory()->for($transfer->refundLog)->create(['attempt_number' => 2]);
    expect($next->fresh()->active_refund_id)->toBe($transfer->refund_log_id);
});

test('rejects spoofing the generated active transfer slot', function () {
    $transfer = RefundTransfer::factory()->create();
    try {
        DB::table('refund_transfers')->where('id', $transfer->id)->update(['active_refund_id' => null]);
        $this->fail('MySQL accepted an explicit active slot override.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3105);
    }
});

test('accepts the inclusive maximum safe transfer amount', function () {
    $refund = RefundLog::factory()->storeCancellation()->processing()->create(['basis_amount' => PHP_INT_MAX]);
    $transfer = RefundTransfer::factory()->for($refund)->create();

    expect($transfer->fresh()->amount)->toBe(9223372036854775807);
});

test('restricts deletion and renumbering of each transfer actor', function (string $column, string $operation) {
    $actor = User::factory()->admin()->create();
    $transfer = RefundTransfer::factory()->create([$column => $actor->id]);
    try {
        $target = DB::table('users')->where('id', $actor->id);
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => $actor->id + 1000000]);
        }
        $this->fail('MySQL changed a transfer actor.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($transfer);
})->with([
    ['prepared_by', 'delete'], ['prepared_by', 'update'], ['verified_by', 'delete'], ['verified_by', 'update'],
]);

test('restricts deletion and renumbering of an obligation with attempts', function (string $operation) {
    $transfer = RefundTransfer::factory()->create();
    try {
        $refund = DB::table('refund_logs')->where('id', $transfer->refund_log_id);
        if ($operation === 'delete') {
            $refund->delete();
        } else {
            $refund->update(['id' => $transfer->refund_log_id + 1000000]);
        }
        $this->fail('MySQL changed the referenced obligation.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($transfer);
})->with(['delete', 'update']);

test('refuses rollback that would discard transfer history', function () {
    $transfer = RefundTransfer::factory()->create();
    $migration = require database_path('migrations/2026_10_22_073644_create_refund_transfers_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($transfer);
});

test('seeds four synthetic attempt states only in the isolated database', function () {
    $this->seed(RefundTransferTestSeeder::class);

    $this->assertDatabaseCount('refund_transfers', 4);
    expect(DB::table('refund_transfers')->whereNotNull('active_refund_id')->count())->toBe(2);
    expect(RefundTransfer::query()->where('status', 'succeeded')->sole()->transfer_reference)->toStartWith('TEST-BANK-');
});

test('refuses synthetic transfer seeding through the development connection', function () {
    config(['database.default' => 'mysql']);
    try {
        expect(fn () => app(RefundTransferTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('refund_transfers', 0);
});

test('refuses synthetic transfer seeding outside the testing environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(RefundTransferTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
    $this->assertDatabaseCount('refund_transfers', 0);
});

test('matches the transfer dictionary and generated slot metadata', function () {
    $metadata = DB::selectOne("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_transfers'");
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, DATA_TYPE, DATETIME_PRECISION, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_transfers' ORDER BY ORDINAL_POSITION"));

    expect($metadata->ENGINE)->toBe('InnoDB');
    expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
    expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe([
        'id' => 'bigint unsigned', 'refund_log_id' => 'bigint unsigned', 'payment_id' => 'bigint unsigned', 'attempt_number' => 'int unsigned',
        'amount' => 'bigint unsigned', 'status' => 'varchar(32)', 'prepared_by' => 'bigint unsigned', 'assignment_version' => 'int unsigned',
        'idempotency_key' => 'varchar(128)', 'source_account_key' => 'varchar(64)', 'bank_name' => 'text', 'account_number' => 'text',
        'account_holder' => 'text', 'bank_verified_at' => 'datetime', 'prepared_at' => 'datetime', 'transfer_reference' => 'varchar(128)',
        'transferred_at' => 'datetime', 'verified_by' => 'bigint unsigned', 'verified_at' => 'datetime', 'verification_note' => 'text',
        'completed_at' => 'datetime', 'active_refund_id' => 'bigint unsigned', 'created_at' => 'datetime', 'updated_at' => 'datetime',
    ]);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe([
        'transfer_reference', 'transferred_at', 'verified_by', 'verified_at', 'verification_note', 'completed_at', 'active_refund_id',
    ]);
    expect($columns->whereNotNull('COLUMN_DEFAULT')->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')->all())->toBe(['status' => 'prepared']);
    expect($columns->whereIn('COLUMN_NAME', ['status', 'idempotency_key', 'source_account_key'])->pluck('COLLATION_NAME')->unique()->all())->toBe(['ascii_bin']);
    expect($columns->firstWhere('COLUMN_NAME', 'transfer_reference')->COLLATION_NAME)->toBe('utf8mb4_0900_bin');
    expect($columns->where('DATA_TYPE', 'datetime')->pluck('DATETIME_PRECISION')->unique()->all())->toBe([0]);
    expect($columns->firstWhere('COLUMN_NAME', 'active_refund_id')->EXTRA)->toContain('STORED GENERATED');

    $indexes = collect(DB::select("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_transfers' ORDER BY INDEX_NAME, SEQ_IN_INDEX"))->groupBy('INDEX_NAME');
    expect($indexes->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain(['status', 'prepared_at', 'id']);
    foreach ([['id'], ['id', 'payment_id'], ['id', 'refund_log_id'], ['refund_log_id', 'attempt_number'], ['refund_log_id', 'idempotency_key'], ['source_account_key', 'transfer_reference'], ['active_refund_id']] as $index) {
        expect($indexes->filter(fn ($columns): bool => $columns->first()->NON_UNIQUE === 0)->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
    }
});
