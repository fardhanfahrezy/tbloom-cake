<?php

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('persists integer rupiah variants belonging to one product', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['price' => 100001])->fresh();

    expect($variant->price)->toBe(100001);
    expect($variant->is_active)->toBeTrue();
    expect($variant->product->is($product))->toBeTrue();
    expect($product->variants->modelKeys())->toBe([$variant->id]);
});

test('rejects unsafe variant prices and flags in MySQL', function (array $overrides, int $errorCode) {
    $variant = ProductVariant::factory()->create();
    $attributes = array_replace(ProductVariant::factory()->for($variant->product)->make()->getAttributes(), [
        'created_at' => '2026-10-21 12:00:00',
        'updated_at' => '2026-10-21 12:00:00',
    ], $overrides);

    try {
        DB::table('product_variants')->insert($attributes);
        $this->fail('MySQL accepted an invalid variant value.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'zero price' => [['price' => 0], 3819],
    'negative price' => [['price' => -1], 1264],
    'price exceeds PHP integer' => [['price' => '9223372036854775808'], 3819],
    'invalid active flag' => [['is_active' => 2], 3819],
    'missing product' => [['product_id' => 0], 1452],
]);

test('allows the maximum PHP integer price without losing precision', function () {
    $variant = ProductVariant::factory()->create(['price' => PHP_INT_MAX]);

    expect($variant->fresh()->price)->toBe(9223372036854775807);
});

test('keeps size labels unique within a product and restricts parent deletion', function () {
    $variant = ProductVariant::factory()->create(['size_label' => '16 cm']);
    $other = ProductVariant::factory()->create(['size_label' => '16 cm']);

    expect($other->product_id)->not->toBe($variant->product_id);
    expect(fn () => ProductVariant::factory()->for($variant->product)->create(['size_label' => '16 cm']))
        ->toThrow(QueryException::class);
    expect(fn () => $variant->product->delete())->toThrow(QueryException::class);
});

test('refuses rollback that would discard populated variant history', function () {
    $variant = ProductVariant::factory()->create();
    $migration = require database_path('migrations/2026_10_21_175314_create_product_variants_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');

    $this->assertModelExists($variant);
});
