<?php

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use App\Models\User;
use App\PrivateUploadPurpose;
use App\PrivateUploadStorage;
use Database\Seeders\CustomCakeRequestAttachmentTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('links private attachment metadata to its exact request and uploader', function () {
    $attachment = CustomCakeRequestAttachment::factory()->create()->fresh();
    expect($attachment->request->attachments->sole()->is($attachment))->toBeTrue();
    expect($attachment->uploadedBy->is($attachment->request->user))->toBeTrue();
    expect($attachment->size_bytes)->toBe(1000);
    expect($attachment->created_at)->not->toBeNull();
    expect($attachment->getAttributes())->not->toHaveKey('updated_at');
});

test('encrypts original filenames and hides private attachment locations', function () {
    $attachment = CustomCakeRequestAttachment::factory()->create();
    $stored = DB::table('custom_cake_request_attachments')->where('id', $attachment->id)->value('original_name_ciphertext');
    expect($stored)->not->toContain('[TEST] synthetic-reference.png');
    expect($attachment->fresh()->original_name_ciphertext)->toBe('[TEST] synthetic-reference.png');
    expect($attachment->fresh()->toArray())->not->toHaveKeys(['private_path', 'original_name_ciphertext']);
    expect($attachment->fresh()->toJson())->not->toContain($attachment->private_path)->not->toContain('[TEST] synthetic-reference.png');
});

test('accepts each approved private image MIME at the inclusive byte limit', function (string $mime) {
    $attachment = CustomCakeRequestAttachment::factory()->create(['mime_type' => $mime, 'size_bytes' => 5242880]);
    expect($attachment->fresh()->size_bytes)->toBe(5242880);
})->with(['image/jpeg', 'image/png', 'image/webp']);

test('rejects invalid private attachment metadata in MySQL', function (array $overrides, int $code) {
    $attributes = array_replace(CustomCakeRequestAttachment::factory()->raw(), ['created_at' => '2026-11-01 00:00:00'], $overrides);
    try {
        DB::table('custom_cake_request_attachments')->insert($attributes);
        $this->fail('MySQL accepted invalid custom attachment metadata.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code);
    }
})->with([
    'zero bytes' => [['size_bytes' => 0], 3819],
    'negative bytes' => [['size_bytes' => -1], 1264],
    'above five MiB' => [['size_bytes' => 5242881], 3819],
    'integer overflow' => [['size_bytes' => 4294967296], 1264],
    'PDF reference' => [['mime_type' => 'application/pdf'], 3819],
    'SVG reference' => [['mime_type' => 'image/svg+xml'], 3819],
    'MIME case' => [['mime_type' => 'IMAGE/PNG'], 3819],
    'MIME whitespace' => [['mime_type' => 'image/png '], 3819],
    'missing request' => [['custom_cake_request_id' => 0], 1452],
    'missing uploader' => [['uploaded_by' => 0], 1452],
    'null path' => [['private_path' => null], 1048],
    'null encrypted filename' => [['original_name_ciphertext' => null], 1048],
    'null MIME' => [['mime_type' => null], 1048],
    'null size' => [['size_bytes' => null], 1048],
    'null digest' => [['sha256' => null], 1048],
    'null timestamp' => [['created_at' => null], 1048],
    'oversize path' => [['private_path' => str_repeat('a', 256)], 1406],
]);

test('deduplicates object keys without making content digests globally unique', function () {
    $first = CustomCakeRequestAttachment::factory()->create();
    $second = CustomCakeRequestAttachment::factory()->create(['sha256' => $first->sha256]);
    expect($second->sha256)->toBe($first->sha256);
    try {
        CustomCakeRequestAttachment::factory()->create(['private_path' => $first->private_path]);
        $this->fail('MySQL accepted a duplicate private object key.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    $this->assertDatabaseCount('custom_cake_request_attachments', 2);
});

test('restricts deletion and renumbering of request and independent uploader', function (string $parent, string $operation) {
    $uploader = User::factory()->admin()->create();
    $attachment = CustomCakeRequestAttachment::factory()->create(['uploaded_by' => $uploader->id]);
    $target = $parent === 'request'
        ? DB::table('custom_cake_requests')->where('id', $attachment->custom_cake_request_id)
        : DB::table('users')->where('id', $uploader->id);
    try {
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => 999999999]);
        }
        $this->fail('MySQL discarded attachment history.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($attachment);
})->with(['request', 'uploader'])->with(['delete', 'update']);

test('persists metadata from a verified private upload through the encrypted filename cast', function () {
    Storage::fake('private_uploads');
    $stored = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('private-design.png'), PrivateUploadPurpose::CustomReference);
    $attachment = CustomCakeRequestAttachment::factory()->create([
        'private_path' => $stored['private_path'],
        'original_name_ciphertext' => $stored['original_name'],
        'mime_type' => $stored['mime_type'],
        'size_bytes' => $stored['size_bytes'],
        'sha256' => $stored['sha256'],
    ]);
    app(PrivateUploadStorage::class)->assertReadable($attachment->only(['private_path', 'size_bytes', 'sha256']));
    expect($attachment->fresh()->original_name_ciphertext)->toBe('private-design.png');
    Storage::disk('private_uploads')->assertExists($attachment->private_path);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('refuses rollback that would discard private custom attachment history', function () {
    $attachment = CustomCakeRequestAttachment::factory()->create();
    $migration = require database_path('migrations/2026_10_22_094808_create_custom_cake_request_attachments_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($attachment);
});

test('seeds three synthetic private references without creating financial rows', function () {
    $this->seed(CustomCakeRequestAttachmentTestSeeder::class);
    $this->assertDatabaseCount('custom_cake_requests', 1);
    $this->assertDatabaseCount('custom_cake_request_attachments', 3);
    expect(CustomCakeRequest::query()->sole()->attachments)->toHaveCount(3);
    expect(CustomCakeRequestAttachment::query()->orderBy('id')->pluck('mime_type')->all())->toBe(['image/jpeg', 'image/png', 'image/webp']);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('refuses private custom attachment seeding outside the isolated environment', function (string $boundary) {
    if ($boundary === 'environment') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config(['database.default' => 'mysql']);
    }
    try {
        expect(fn () => app(CustomCakeRequestAttachmentTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('custom_cake_request_attachments', 0);
})->with(['environment', 'connection']);

test('matches the private attachment dictionary and key constraints', function () {
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, DATETIME_PRECISION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'custom_cake_request_attachments' ORDER BY ORDINAL_POSITION"));
    expect($columns->pluck('COLUMN_NAME')->all())->toBe(['id', 'custom_cake_request_id', 'private_path', 'original_name_ciphertext', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by', 'created_at']);
    expect($columns->pluck('IS_NULLABLE')->unique()->all())->toBe(['NO']);
    expect($columns->firstWhere('COLUMN_NAME', 'original_name_ciphertext')->COLUMN_TYPE)->toBe('text');
    expect($columns->firstWhere('COLUMN_NAME', 'size_bytes')->COLUMN_TYPE)->toBe('int unsigned');
    expect($columns->whereNotNull('DATETIME_PRECISION')->pluck('DATETIME_PRECISION')->all())->toBe([0]);
    $unique = DB::select("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'custom_cake_request_attachments' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME");
    expect(collect($unique)->pluck('columns_list')->sort()->values()->all())->toBe(['id', 'private_path']);
});
