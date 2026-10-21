<?php

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('keeps image metadata attached to its product in display order', function () {
    $product = Product::factory()->create();
    $last = ProductImage::factory()->for($product)->create(['sort_order' => 9]);
    $first = ProductImage::factory()->for($product)->create(['sort_order' => 1, 'alt_text' => 'Kue ulang tahun 🎂']);

    expect($first->fresh()->alt_text)->toBe('Kue ulang tahun 🎂');
    expect($first->product->is($product))->toBeTrue();
    expect($product->images->modelKeys())->toBe([$first->id, $last->id]);
});

test('rejects invalid image metadata in MySQL', function (array $overrides, int $errorCode) {
    $image = ProductImage::factory()->create();
    $attributes = array_replace(ProductImage::factory()->for($image->product)->make()->getAttributes(), [
        'created_at' => '2026-10-21 12:00:00',
        'updated_at' => '2026-10-21 12:00:00',
    ], $overrides);

    try {
        DB::table('product_images')->insert($attributes);
        $this->fail('MySQL accepted invalid image metadata.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'missing product' => [['product_id' => 0], 1452],
    'missing alt text' => [['alt_text' => null], 1048],
    'negative sort order' => [['sort_order' => -1], 1264],
    'missing timestamp' => [['created_at' => null], 1048],
]);

test('rejects duplicate object keys and restricts parent deletion', function () {
    $image = ProductImage::factory()->create();

    expect(fn () => ProductImage::factory()->create(['path' => $image->path]))->toThrow(QueryException::class);
    expect(fn () => $image->product->delete())->toThrow(QueryException::class);
});
