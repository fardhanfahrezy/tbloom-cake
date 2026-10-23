<?php

use App\Models\Product;
use App\Models\ProductCategory;
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

test('persists catalog products with five units and a typed category', function () {
    $product = Product::factory()->create()->fresh();

    expect($product->category)->toBe(ProductCategory::Simple);
    expect($product->default_daily_quota)->toBe(5);
    expect($product->is_active)->toBeTrue();
    expect($product->archived_at)->toBeNull();
});

test('rejects invalid catalog values in MySQL', function (array $overrides, int $errorCode) {
    Product::factory()->create();
    $attributes = array_replace(Product::factory()->make()->getAttributes(), [
        'created_at' => '2026-10-21 12:00:00',
        'updated_at' => '2026-10-21 12:00:00',
    ], $overrides);

    try {
        DB::table('products')->insert($attributes);
        $this->fail('MySQL accepted an invalid catalog value.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'unknown category' => [['category' => 'custom'], 3819],
    'invalid active flag' => [['is_active' => 2], 3819],
    'negative quota' => [['default_daily_quota' => -1], 1264],
    'missing unit label' => [['sales_unit_label' => null], 1048],
]);

test('allows a zero daily quota and rejects duplicate slugs', function () {
    $product = Product::factory()->create(['default_daily_quota' => 0]);

    expect($product->fresh()->default_daily_quota)->toBe(0);
    expect(fn () => Product::factory()->create(['slug' => $product->slug]))->toThrow(QueryException::class);
});

test('matches the catalog dictionary column types and InnoDB metadata', function () {
    $expectedTypes = [
        'products' => [
            'id' => 'bigint unsigned', 'name' => 'varchar(150)', 'slug' => 'varchar(191)',
            'category' => 'varchar(32)', 'description' => 'text', 'sales_unit_label' => 'varchar(32)',
            'default_daily_quota' => 'int unsigned', 'is_active' => 'tinyint unsigned',
            'archived_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'product_variants' => [
            'id' => 'bigint unsigned', 'product_id' => 'bigint unsigned', 'size_label' => 'varchar(100)',
            'price' => 'bigint unsigned', 'is_active' => 'tinyint unsigned',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'product_images' => [
            'id' => 'bigint unsigned', 'product_id' => 'bigint unsigned', 'path' => 'varchar(255)',
            'alt_text' => 'varchar(255)', 'sort_order' => 'int unsigned',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
    ];

    foreach ($expectedTypes as $table => $types) {
        $metadata = DB::selectOne('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        $columns = collect(DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]));

        expect($metadata->ENGINE)->toBe('InnoDB');
        expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
        expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe($types);
        expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())
            ->toBe($table === 'products' ? ['description', 'archived_at'] : []);
    }
});

test('refuses rollback that would discard populated product history', function () {
    $product = Product::factory()->create();
    $migration = require database_path('migrations/2026_10_21_155412_create_products_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');

    $this->assertModelExists($product);
});

test('seeds synthetic catalog metadata with related variants and images in the isolated database', function () {
    $this->seed(CatalogTestSeeder::class);

    $this->assertDatabaseCount('products', 3);
    $this->assertDatabaseCount('product_variants', 6);
    $this->assertDatabaseCount('product_images', 3);
    expect(Product::query()->orderBy('category')->pluck('category')->map(fn (ProductCategory $category): string => $category->value)->all())
        ->toBe(['bento', 'cupcake', 'simple']);

    foreach (Product::with(['variants', 'images'])->get() as $product) {
        expect($product->name)->toStartWith('[TEST]');
        expect($product->variants)->toHaveCount(2);
        expect($product->images)->toHaveCount(1);
    }
});

test('refuses synthetic catalog seeding outside the test environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    try {
        expect(fn () => app(CatalogTestSeeder::class)->run())->toThrow(LogicException::class);
        $this->assertDatabaseCount('products', 0);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
});

test('refuses synthetic catalog seeding when the default connection is development', function () {
    config(['database.default' => 'mysql']);

    try {
        expect(fn () => app(CatalogTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }

    $this->assertDatabaseCount('products', 0);
});
