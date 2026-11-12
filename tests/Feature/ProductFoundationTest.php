<?php
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CatalogTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
uses(LazilyRefreshDatabase::class);
beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') { $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.'); }
});
test('persists catalog products with a database-backed category and five units', function () {
    $product = Product::factory()->create()->fresh();
    expect($product->category)->toBeInstanceOf(Category::class)->and($product->category_id)->toBe($product->category->id);
    expect($product->default_daily_quota)->toBe(5)->and($product->is_active)->toBeTrue()->and($product->archived_at)->toBeNull();
});
test('rejects invalid category references and catalog values in MySQL', function (array $overrides, int $errorCode) {
    Product::factory()->create();
    $attributes = array_replace(Product::factory()->make()->getAttributes(), ['created_at' => '2026-10-21 12:00:00', 'updated_at' => '2026-10-21 12:00:00'], $overrides);
    try { DB::table('products')->insert($attributes); $this->fail('MySQL accepted an invalid catalog value.'); }
    catch (QueryException $exception) { expect($exception->errorInfo[1])->toBe($errorCode); }
})->with([
    'unknown category reference' => [['category_id' => 999999], 1452],
    'invalid active flag' => [['is_active' => 2], 3819],
    'negative quota' => [['default_daily_quota' => -1], 1264],
    'missing unit label' => [['sales_unit_label' => null], 1048],
]);
test('allows a zero daily quota and rejects duplicate slugs', function () {
    $product = Product::factory()->create(['default_daily_quota' => 0]);
    expect($product->fresh()->default_daily_quota)->toBe(0);
    expect(fn () => Product::factory()->create(['slug' => $product->slug]))->toThrow(QueryException::class);
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
    expect(Product::query()->with('category')->get()->pluck('category.slug')->sort()->values()->all())->toBe(['bento', 'cupcake', 'simple']);
    foreach (Product::with(['variants', 'images'])->get() as $product) {
        expect($product->name)->toStartWith('[TEST]')->and($product->variants)->toHaveCount(2)->and($product->images)->toHaveCount(1);
    }
});
test('refuses synthetic catalog seeding outside the test environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try { expect(fn () => app(CatalogTestSeeder::class)->run())->toThrow(LogicException::class); $this->assertDatabaseCount('products', 0); }
    finally { $this->app->detectEnvironment(fn (): string => 'testing'); }
});
test('refuses synthetic catalog seeding when the default connection is development', function () {
    config(['database.default' => 'mysql']);
    try { expect(fn () => app(CatalogTestSeeder::class)->run())->toThrow(LogicException::class); }
    finally { config(['database.default' => 'mysql_testing']); }
    $this->assertDatabaseCount('products', 0);
});
