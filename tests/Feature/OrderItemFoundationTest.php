<?php

use App\Models\DailyAvailability;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotaReleaseReason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores a coherent single item allocation with integer snapshots and matching product parents', function () {
    $item = OrderItem::factory()->create(['quantity' => 3, 'unit_price' => 100001])->fresh();

    expect($item->line_total)->toBe(300003);
    expect($item->order->product_total)->toBe(300003);
    expect($item->order->item->is($item))->toBeTrue();
    expect($item->productVariant->product_id)->toBe($item->product_id);
    expect($item->dailyAvailability->product_id)->toBe($item->product_id);
    expect($item->dailyAvailability->availability_date->toDateString())->toBe($item->order->pickup_date->toDateString());
    expect($item->product_name_snapshot)->toBe($item->product->name);
    expect($item->size_snapshot)->toBe($item->productVariant->size_label);
    expect($item->sales_unit_snapshot)->toBe($item->product->sales_unit_label);
    expect($item->inscription)->toBe('');
    expect($item->quota_released_at)->toBeNull();
});

test('rejects invalid allocation arithmetic and release markers in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(OrderItem::factory()->raw(), ['created_at' => '2026-11-01 00:00:00'], $overrides);

    try {
        DB::table('order_items')->insert($attributes);
        $this->fail('MySQL accepted invalid allocation values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'zero quantity' => [['quantity' => 0], 3819],
    'negative quantity' => [['quantity' => -1], 1264],
    'quantity overflow' => [['quantity' => 4294967296], 1264],
    'zero unit price' => [['unit_price' => 0, 'line_total' => 0], 3819],
    'negative unit price' => [['unit_price' => -1], 1264],
    'unit price overflow' => [['unit_price' => '9223372036854775808', 'line_total' => '9223372036854775808'], 3819],
    'wrong line total' => [['quantity' => 2, 'unit_price' => 100001, 'line_total' => 200001], 3819],
    'PHP integer multiplication overflow' => [['quantity' => 2, 'unit_price' => PHP_INT_MAX, 'line_total' => '18446744073709551614'], 3819],
    'MySQL integer multiplication overflow' => [['quantity' => 4294967295, 'unit_price' => PHP_INT_MAX, 'line_total' => 1], 3819],
    'release time only' => [['quota_released_at' => '2026-11-01 00:31:00'], 3819],
    'release reason only' => [['quota_release_reason' => 'expired'], 3819],
    'unknown release reason' => [['quota_released_at' => '2026-11-01 00:31:00', 'quota_release_reason' => 'completed'], 3819],
    'release reason case mismatch' => [['quota_released_at' => '2026-11-01 00:31:00', 'quota_release_reason' => 'EXPIRED'], 3819],
    'missing order' => [['order_id' => 0], 1452],
    'null variant' => [['product_variant_id' => null], 1048],
    'null availability' => [['daily_availability_id' => null], 1048],
    'null inscription' => [['inscription' => null], 1048],
    'null snapshot' => [['product_name_snapshot' => null], 1048],
    'null creation time' => [['created_at' => null], 1048],
]);

test('rejects a variant or availability belonging to a different product', function (string $column) {
    $attributes = OrderItem::factory()->raw();
    $attributes[$column] = $column === 'product_variant_id'
        ? ProductVariant::factory()->create()->id
        : DailyAvailability::factory()->create()->id;

    try {
        DB::table('order_items')->insert(array_replace($attributes, ['created_at' => '2026-11-01 00:00:00']));
        $this->fail('MySQL accepted a cross-product allocation.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
})->with(['product_variant_id', 'daily_availability_id']);

test('enforces one item per MVP order', function () {
    $item = OrderItem::factory()->create();

    try {
        OrderItem::factory()->for($item->order)->create();
        $this->fail('MySQL accepted a second item for one order.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('shares one availability fixture across orders for the same product and pickup day', function () {
    $this->freezeTime();
    $variant = ProductVariant::factory()->create();
    $first = OrderItem::factory()->for($variant, 'productVariant')->create();
    $second = OrderItem::factory()->for($variant, 'productVariant')->create();

    expect($second->daily_availability_id)->toBe($first->daily_availability_id);
    $this->assertDatabaseCount('daily_availabilities', 1);
    $this->assertDatabaseCount('orders', 2);
});

test('preserves price name size and unit snapshots when catalog records change', function () {
    $product = Product::factory()->create(['name' => 'Nama awal', 'sales_unit_label' => 'box uji']);
    $variant = ProductVariant::factory()->for($product)->create(['size_label' => '16 cm', 'price' => 100001]);
    $item = OrderItem::factory()->for($variant, 'productVariant')->create();
    $product->update(['name' => 'Nama baru', 'sales_unit_label' => 'unit baru']);
    $variant->update(['size_label' => '18 cm', 'price' => 200000]);

    expect($item->fresh()->only(['product_name_snapshot', 'size_snapshot', 'sales_unit_snapshot', 'unit_price', 'line_total']))->toBe([
        'product_name_snapshot' => 'Nama awal', 'size_snapshot' => '16 cm', 'sales_unit_snapshot' => 'box uji', 'unit_price' => 100001, 'line_total' => 100001,
    ]);
});

test('keeps integer precision at the maximum money and quantity boundaries', function () {
    $maxMoney = OrderItem::factory()->create(['unit_price' => PHP_INT_MAX]);
    $maxQuantity = OrderItem::factory()->create(['quantity' => 4294967295, 'unit_price' => 1]);

    expect($maxMoney->fresh()->line_total)->toBe(9223372036854775807);
    expect($maxQuantity->fresh()->line_total)->toBe(4294967295);
});

test('creates explicit released fixtures with a typed reason', function (string $reason) {
    $item = OrderItem::factory()->released(QuotaReleaseReason::from($reason))->create()->fresh();

    expect($item->quota_release_reason)->toBe(QuotaReleaseReason::from($reason));
    expect($item->quota_released_at)->not->toBeNull();
})->with(['expired', 'cancelled']);

test('writes item changes without inventing an updated_at column', function () {
    $item = OrderItem::factory()->create();
    $createdAt = $item->created_at->toDateTimeString();
    $item->forceFill(['inscription' => 'Tulisan uji'])->save();

    expect($item->fresh()->inscription)->toBe('Tulisan uji');
    expect($item->fresh()->created_at->toDateTimeString())->toBe($createdAt);
});

test('restricts deletion and renumbering of allocation parents', function (string $table, string $column, string $operation) {
    $item = OrderItem::factory()->create();
    $parentId = $item->getAttribute($column);

    try {
        $parent = DB::table($table)->where('id', $parentId);
        if ($operation === 'delete') {
            $parent->delete();
        } else {
            $parent->update(['id' => $parentId + 1000000]);
        }
        $this->fail('MySQL changed a referenced allocation parent.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($item);
})->with([
    ['orders', 'order_id', 'delete'], ['orders', 'order_id', 'update'],
    ['product_variants', 'product_variant_id', 'delete'], ['product_variants', 'product_variant_id', 'update'],
    ['daily_availabilities', 'daily_availability_id', 'delete'], ['daily_availabilities', 'daily_availability_id', 'update'],
]);

test('refuses rollback that would discard allocation history', function () {
    $item = OrderItem::factory()->create();
    $migration = require database_path('migrations/2026_10_21_194533_create_order_items_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($item);
});
