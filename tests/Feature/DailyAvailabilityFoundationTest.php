<?php

use App\Models\DailyAvailability;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores one product day across variants with a Jakarta calendar date and optional updater', function () {
    $product = Product::factory()->create();
    $availability = DailyAvailability::factory()->for($product)->create([
        'availability_date' => '2026-11-01',
        'quota_limit' => 0,
    ])->fresh();

    expect($availability->product->is($product))->toBeTrue();
    expect($product->dailyAvailabilities->sole()->is($availability))->toBeTrue();
    expect($availability->availability_date->format('Y-m-d'))->toBe('2026-11-01');
    expect($availability->toArray()['availability_date'])->toBe('2026-11-01');
    expect($availability->quota_limit)->toBe(0);
    expect($availability->updatedBy)->toBeNull();
});

test('snapshots the product default only when creating a new availability fixture', function () {
    $product = Product::factory()->create(['default_daily_quota' => 5]);
    $existing = DailyAvailability::factory()->for($product)->create(['availability_date' => '2026-11-01']);
    $product->update(['default_daily_quota' => 9]);
    $new = DailyAvailability::factory()->for($product)->create(['availability_date' => '2026-11-02']);

    expect($existing->fresh()->quota_limit)->toBe(5);
    expect($new->fresh()->quota_limit)->toBe(9);
});

test('records the account that updated a daily quota', function () {
    $admin = User::factory()->admin()->create();
    $availability = DailyAvailability::factory()->create(['updated_by' => $admin->id]);

    expect($availability->fresh()->updatedBy->is($admin))->toBeTrue();
});

test('allows different product days and rejects a duplicate product date', function () {
    $existing = DailyAvailability::factory()->create(['availability_date' => '2026-11-01']);
    DailyAvailability::factory()->for($existing->product)->create(['availability_date' => '2026-11-02']);
    DailyAvailability::factory()->create(['availability_date' => '2026-11-01']);

    $this->assertDatabaseCount('daily_availabilities', 3);

    try {
        DailyAvailability::factory()->for($existing->product)->create(['availability_date' => '2026-11-01']);
        $this->fail('MySQL accepted a duplicate product date.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('rejects invalid daily availability values in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(DailyAvailability::factory()->make()->getAttributes(), [
        'created_at' => '2026-10-21 12:00:00',
        'updated_at' => '2026-10-21 12:00:00',
    ], $overrides);

    try {
        DB::table('daily_availabilities')->insert($attributes);
        $this->fail('MySQL accepted invalid daily availability values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'negative quota' => [['quota_limit' => -1], 1264],
    'overflow quota' => [['quota_limit' => 4294967296], 1264],
    'null quota' => [['quota_limit' => null], 1048],
    'missing product' => [['product_id' => 0], 1452],
    'null product' => [['product_id' => null], 1048],
    'missing updater' => [['updated_by' => 0], 1452],
    'null date' => [['availability_date' => null], 1048],
    'invalid calendar date' => [['availability_date' => '2026-02-30'], 1292],
    'missing creation time' => [['created_at' => null], 1048],
    'missing update time' => [['updated_at' => null], 1048],
]);

test('requires an explicit quota on raw inserts without a database default', function () {
    $attributes = DailyAvailability::factory()->make()->getAttributes();
    unset($attributes['quota_limit']);

    try {
        DB::table('daily_availabilities')->insert(array_replace($attributes, [
            'created_at' => '2026-10-21 12:00:00',
            'updated_at' => '2026-10-21 12:00:00',
        ]));
        $this->fail('MySQL silently defaulted the daily quota.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1364);
    }
});

test('supports the full unsigned quota range', function () {
    $availability = DailyAvailability::factory()->create(['quota_limit' => 4294967295]);

    expect($availability->fresh()->quota_limit)->toBe(4294967295);
});

test('restricts deleting and renumbering referenced availability parents', function (string $table, string $operation) {
    $admin = User::factory()->admin()->create();
    $availability = DailyAvailability::factory()->create(['updated_by' => $admin->id]);
    $parentId = $table === 'products' ? $availability->product_id : $admin->id;

    try {
        $parent = DB::table($table)->where('id', $parentId);
        if ($operation === 'delete') {
            $parent->delete();
        } else {
            $parent->update(['id' => $parentId + 1000000]);
        }
        $this->fail('MySQL changed a referenced availability parent.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }

    $this->assertModelExists($availability);
})->with([
    'product deletion' => ['products', 'delete'],
    'product renumbering' => ['products', 'update'],
    'updater deletion' => ['users', 'delete'],
    'updater renumbering' => ['users', 'update'],
]);

test('keeps the daily allocation history when the product is archived', function () {
    $availability = DailyAvailability::factory()->create();
    $availability->product->update(['is_active' => false, 'archived_at' => now()]);

    $this->assertModelExists($availability);
    expect($availability->fresh()->product->is_active)->toBeFalse();
});

test('matches the availability dictionary without a separate used counter or variant key', function () {
    $metadata = DB::selectOne("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_availabilities'");
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, DATETIME_PRECISION, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_availabilities' ORDER BY ORDINAL_POSITION"));

    expect($metadata->ENGINE)->toBe('InnoDB');
    expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
    expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe([
        'id' => 'bigint unsigned',
        'product_id' => 'bigint unsigned',
        'availability_date' => 'date',
        'quota_limit' => 'int unsigned',
        'updated_by' => 'bigint unsigned',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ]);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe(['updated_by']);
    expect($columns->whereNotNull('COLUMN_DEFAULT'))->toHaveCount(0);
    expect($columns->firstWhere('COLUMN_NAME', 'id')->EXTRA)->toBe('auto_increment');
    expect($columns->whereIn('COLUMN_NAME', ['created_at', 'updated_at'])->pluck('DATETIME_PRECISION')->all())->toBe([0, 0]);

    $indexes = collect(DB::select("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_availabilities' ORDER BY INDEX_NAME, SEQ_IN_INDEX"))->groupBy('INDEX_NAME');
    $uniqueIndexes = $indexes->filter(fn ($index): bool => $index->first()->NON_UNIQUE === 0)->map(fn ($index): array => $index->pluck('COLUMN_NAME')->all())->values()->all();
    expect($uniqueIndexes)->toContain(['product_id', 'availability_date'], ['product_id', 'id'], ['id']);
    expect($indexes->map(fn ($index): array => $index->pluck('COLUMN_NAME')->all())->values()->all())->toContain(['availability_date', 'product_id']);
});

test('seeds availability fixtures for each synthetic catalog product using its quota default', function () {
    $this->travelTo(now()->setTimezone('Asia/Jakarta')->setDate(2026, 11, 1)->startOfDay());
    $this->seed(CatalogTestSeeder::class);

    $this->assertDatabaseCount('daily_availabilities', 3);
    foreach (DailyAvailability::with('product')->get() as $availability) {
        expect($availability->quota_limit)->toBe($availability->product->default_daily_quota);
        expect($availability->availability_date->format('Y-m-d'))->toBe('2026-11-02');
        expect($availability->updated_by)->toBeNull();
    }
});

test('refuses rollback that would discard daily availability history', function () {
    $availability = DailyAvailability::factory()->create();
    $migration = require database_path('migrations/2026_10_21_184835_create_daily_availabilities_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');

    $this->assertModelExists($availability);
});
