<?php

use App\Models\RefundEvidence;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use App\Models\User;
use Database\Seeders\RefundEvidenceTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('links private evidence to its exact transfer obligation and uploader', function () {
    $evidence = RefundEvidence::factory()->create()->fresh();
    expect($evidence->refundTransfer->refund_log_id)->toBe($evidence->refund_log_id);
    expect($evidence->refundLog->evidence->sole()->is($evidence))->toBeTrue();
    expect($evidence->refundTransfer->evidence->sole()->is($evidence))->toBeTrue();
    expect($evidence->uploadedBy->is($evidence->refundTransfer->preparedBy))->toBeTrue();
    expect($evidence->refundTransfer->status)->toBe(RefundTransferStatus::Unknown);
    expect($evidence->size_bytes)->toBe(1000);
    expect($evidence->getTable())->toBe('refund_evidence');
});

test('encrypts the filename and hides private metadata from serialization', function () {
    $evidence = RefundEvidence::factory()->create();
    $plaintext = $evidence->original_name_ciphertext;
    $stored = DB::table('refund_evidence')->where('id', $evidence->id)->value('original_name_ciphertext');
    expect($stored)->not->toBe($plaintext)->not->toContain($plaintext);
    expect($evidence->fresh()->original_name_ciphertext)->toBe($plaintext);
    expect($evidence->fresh()->toArray())->not->toHaveKeys(['private_path', 'original_name_ciphertext']);
    expect($evidence->fresh()->toJson())->not->toContain($plaintext)->not->toContain($evidence->private_path);
});

test('accepts each approved MIME at the inclusive five MiB boundary', function (string $mime) {
    $evidence = RefundEvidence::factory()->create(['mime_type' => $mime, 'size_bytes' => 5242880]);
    expect($evidence->fresh()->size_bytes)->toBe(5242880);
    expect(config('refunds.evidence_max_size_bytes'))->toBe(5242880);
})->with(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

test('rejects invalid evidence metadata in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(RefundEvidence::factory()->raw(), ['created_at' => '2026-11-01 00:00:00'], $overrides);
    try {
        DB::table('refund_evidence')->insert($attributes);
        $this->fail('MySQL accepted invalid evidence metadata.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'zero bytes' => [['size_bytes' => 0], 3819],
    'negative bytes' => [['size_bytes' => -1], 1264],
    'above five MiB' => [['size_bytes' => 5242881], 3819],
    'integer overflow' => [['size_bytes' => 4294967296], 1264],
    'SVG' => [['mime_type' => 'image/svg+xml'], 3819],
    'executable' => [['mime_type' => 'application/x-msdownload'], 3819],
    'MIME case' => [['mime_type' => 'IMAGE/PNG'], 3819],
    'MIME trailing space' => [['mime_type' => 'application/pdf '], 3819],
    'missing obligation' => [['refund_log_id' => 0], 1452],
    'missing transfer' => [['refund_transfer_id' => 0], 1452],
    'missing uploader' => [['uploaded_by' => 0], 1452],
    'null path' => [['private_path' => null], 1048],
    'null filename' => [['original_name_ciphertext' => null], 1048],
    'null MIME' => [['mime_type' => null], 1048],
    'null size' => [['size_bytes' => null], 1048],
    'null digest' => [['sha256' => null], 1048],
    'null timestamp' => [['created_at' => null], 1048],
]);

test('rejects evidence linked to another existing obligation', function () {
    $other = RefundTransfer::factory()->unknown()->create();
    try {
        RefundEvidence::factory()->create(['refund_log_id' => $other->refund_log_id]);
        $this->fail('MySQL accepted cross-obligation evidence.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});

test('deduplicates private keys without making digests globally unique', function () {
    $first = RefundEvidence::factory()->create();
    $second = RefundEvidence::factory()->image()->create(['sha256' => $first->sha256]);
    expect($second->fresh()->sha256)->toBe($first->sha256);
    expect($second->mime_type)->toBe('image/png');
    try {
        RefundEvidence::factory()->create(['private_path' => $first->private_path]);
        $this->fail('MySQL accepted a duplicate private key.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    $this->assertDatabaseCount('refund_evidence', 2);
});

test('retains multiple entries and the original creation time', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
    $first = RefundEvidence::factory()->create();
    $this->travel(1)->hour();
    $second = RefundEvidence::factory()->for($first->refundTransfer)->image()->create();
    expect($first->fresh()->created_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
    expect($second->created_at->toDateTimeString())->toBe('2026-11-01 01:00:00');
    expect($first->refundTransfer->evidence()->count())->toBe(2);
    expect($first->getUpdatedAtColumn())->toBeNull();
    expect($first->isGuarded('private_path'))->toBeTrue();
});

test('restricts deletion and renumbering of a transfer with evidence', function (string $operation) {
    $evidence = RefundEvidence::factory()->create();
    try {
        $target = DB::table('refund_transfers')->where('id', $evidence->refund_transfer_id);
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => $evidence->refund_transfer_id + 1000000]);
        }
        $this->fail('MySQL changed an evidence parent.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($evidence);
})->with(['delete', 'update']);

test('restricts deletion and renumbering of an independent uploader', function (string $operation) {
    $actor = User::factory()->admin()->create();
    $evidence = RefundEvidence::factory()->create(['uploaded_by' => $actor->id]);
    try {
        $target = DB::table('users')->where('id', $actor->id);
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => $actor->id + 1000000]);
        }
        $this->fail('MySQL changed an evidence uploader.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($evidence);
})->with(['delete', 'update']);

test('refuses rollback that would discard evidence history', function () {
    $evidence = RefundEvidence::factory()->create();
    $migration = require database_path('migrations/2026_10_22_075603_create_refund_evidence_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($evidence);
});

test('seeds synthetic PDF and image metadata only in the isolated database', function () {
    $this->seed(RefundEvidenceTestSeeder::class);
    $this->assertDatabaseCount('refund_evidence', 2);
    expect(RefundEvidence::query()->orderBy('id')->pluck('mime_type')->all())->toBe(['application/pdf', 'image/png']);
});

test('refuses evidence seeding through the development connection', function () {
    config(['database.default' => 'mysql']);
    try {
        expect(fn () => app(RefundEvidenceTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('refund_evidence', 0);
});

test('refuses evidence seeding outside testing', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(RefundEvidenceTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
    $this->assertDatabaseCount('refund_evidence', 0);
});

test('matches the evidence dictionary nullability timestamps and indexes', function () {
    $metadata = DB::selectOne("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_evidence'");
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_evidence' ORDER BY ORDINAL_POSITION"));
    expect($metadata->ENGINE)->toBe('InnoDB');
    expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
    expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe([
        'id' => 'bigint unsigned', 'refund_log_id' => 'bigint unsigned', 'refund_transfer_id' => 'bigint unsigned', 'private_path' => 'varchar(255)',
        'original_name_ciphertext' => 'text', 'mime_type' => 'varchar(100)', 'size_bytes' => 'int unsigned', 'sha256' => 'char(64)',
        'uploaded_by' => 'bigint unsigned', 'created_at' => 'datetime',
    ]);
    expect($columns->where('IS_NULLABLE', 'YES')->all())->toBe([]);
    expect($columns->whereNotNull('COLUMN_DEFAULT')->all())->toBe([]);
    expect($columns->where('DATA_TYPE', 'datetime')->pluck('DATETIME_PRECISION')->all())->toBe([0]);
    $indexes = collect(DB::select("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_evidence' ORDER BY INDEX_NAME, SEQ_IN_INDEX"))->groupBy('INDEX_NAME');
    expect($indexes->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain(['refund_transfer_id', 'id']);
    expect($indexes->filter(fn ($columns): bool => $columns->first()->NON_UNIQUE === 0)->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toBe([['id'], ['private_path']]);
});
