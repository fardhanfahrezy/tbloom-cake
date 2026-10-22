<?php

use App\Models\DailyAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\QuotaReleaseReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Catalog browsing requires isolated MySQL.');
    }
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));
});

test('uses Jakarta calendar dates and the inclusive cutoff for the default selection', function (string $utc, string $earliest) {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    $this->get('/catalog')->assertOk()->assertSee('value="'.$earliest.'"', false);
})->with([
    ['2026-10-22 09:59:59', '2026-10-23'],
    ['2026-10-22 10:00:00', '2026-10-23'],
    ['2026-10-22 10:00:01', '2026-10-24'],
    ['2026-10-22 16:59:59', '2026-10-24'],
    ['2026-10-22 17:00:00', '2026-10-24'],
]);

test('rejects invalid category and calendar filters', function (array $query, string $field) {
    $this->getJson('/catalog?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['pickup_date' => '2026-10-22'], 'pickup_date'],
    [['pickup_date' => '2027-01-21'], 'pickup_date'],
    [['pickup_date' => '2026-02-30'], 'pickup_date'],
    [['pickup_date' => '23/10/2026'], 'pickup_date'],
    [['pickup_date' => ['2026-10-23']], 'pickup_date'],
    [['category' => 'custom'], 'category'],
    [['category' => ['simple']], 'category'],
]);

test('accepts D90 inclusive and never creates availability rows during browsing', function () {
    Product::factory()->create();
    $this->get('/catalog?pickup_date=2027-01-20')->assertOk()->assertSee('2027-01-20');
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('filters active products and variants and displays packaging prices images and escaped copy', function () {
    $product = Product::factory()->create(['name' => 'Cupcake contoh', 'category' => ProductCategory::Cupcake, 'sales_unit_label' => 'box isi 6', 'description' => '<script>alert(1)</script>']);
    ProductVariant::factory()->for($product)->create(['size_label' => 'Ukuran aktif', 'price' => 125000]);
    ProductVariant::factory()->for($product)->create(['size_label' => 'Ukuran nonaktif', 'is_active' => false]);
    ProductImage::factory()->for($product)->create(['path' => 'products/test.jpg', 'alt_text' => 'Foto cupcake contoh']);
    Product::factory()->create(['name' => 'Produk nonaktif', 'is_active' => false]);
    Product::factory()->create(['name' => 'Produk arsip', 'archived_at' => now()]);
    Product::factory()->create(['name' => 'Kategori lain']);

    $this->get('/catalog?category=cupcake&pickup_date=2026-10-23')->assertOk()
        ->assertSee('Cupcake contoh')->assertSee('box isi 6')->assertSee('Ukuran aktif')->assertSee('125.000')
        ->assertSee('Foto cupcake contoh')->assertDontSee('Ukuran nonaktif')->assertDontSee('Produk nonaktif')->assertDontSee('Produk arsip')->assertDontSee('Kategori lain');
    $this->get('/catalog/'.$product->slug.'?pickup_date=2026-10-23')->assertOk()
        ->assertSee($product->description)->assertDontSee($product->description, false)->assertSee('box isi 6');
});

test('uses allocated units across sizes without releasing overdue pending paid or completed orders', function (string $state) {
    $product = Product::factory()->create();
    $small = ProductVariant::factory()->for($product)->create();
    ProductVariant::factory()->for($product)->create();
    $orderFactory = Order::factory()->state(['pickup_date' => '2026-10-23', 'created_at' => '2026-10-22 05:00:00']);
    if ($state !== 'pending') {
        $orderFactory = $orderFactory->$state();
    }
    $order = $orderFactory->create(['product_total' => $small->price * 3]);
    OrderItem::factory()->create(['product_variant_id' => $small->id, 'order_id' => $order->id, 'quantity' => 3]);

    $this->get('/catalog?pickup_date=2026-10-23')->assertOk()->assertSee('Tersedia 2 dari 5 unit');
    $this->get('/catalog/'.$product->slug.'?pickup_date=2026-10-23')->assertOk()->assertSee('Tersedia 2 dari 5 unit');
})->with(['pending', 'paid', 'completed']);

test('uses the materialized capacity and excludes only explicitly released items and other dates', function () {
    $product = Product::factory()->create(['default_daily_quota' => 9]);
    $variant = ProductVariant::factory()->for($product)->create();
    DailyAvailability::factory()->for($product)->create(['availability_date' => '2026-10-23', 'quota_limit' => 5]);
    $order = Order::factory()->cancelled()->create(['pickup_date' => '2026-10-23']);
    OrderItem::factory()->released(QuotaReleaseReason::Cancelled)->create(['product_variant_id' => $variant->id, 'order_id' => $order->id]);
    $other = Order::factory()->create(['pickup_date' => '2026-10-24']);
    OrderItem::factory()->create(['product_variant_id' => $variant->id, 'order_id' => $other->id]);

    $this->get('/catalog?pickup_date=2026-10-23')->assertOk()->assertSee('Tersedia 5 dari 5 unit');
});

test('marks a zero capacity product as full and handles empty sizes without an order action', function () {
    $product = Product::factory()->create(['default_daily_quota' => 0]);
    $this->get('/catalog/'.$product->slug)->assertOk()->assertSee('Penuh')->assertSee('Belum ada ukuran yang tersedia')->assertDontSee('action="/checkout"', false);
});

test('hides inactive archived and unknown product detail', function (string $state) {
    $product = Product::factory()->create($state === 'inactive' ? ['is_active' => false] : ['archived_at' => now()]);
    $this->get('/catalog/'.$product->slug)->assertNotFound();
    $this->get('/catalog/unknown-product')->assertNotFound();
})->with(['inactive', 'archived']);

test('recovers an outdated browser date without redirecting back to the invalid URL', function () {
    $product = Product::factory()->create(['name' => 'Kue setelah pergantian tanggal']);
    $this->from('/catalog?pickup_date=2026-10-22')->get('/catalog?pickup_date=2026-10-22')
        ->assertRedirect('/catalog')->assertSessionHasErrors('pickup_date');
    $this->withCookie(config('session.cookie'), session()->getId())->get('/catalog')->assertOk()->assertSee('Pilih tanggal pengambilan dalam rentang yang tersedia.')->assertSee('value="2026-10-23"', false)->assertSee($product->name);
});

test('displays exact rupiah values at the integer money boundary', function () {
    $variant = ProductVariant::factory()->create(['price' => PHP_INT_MAX]);
    $this->get('/catalog/'.$variant->product->slug)->assertOk()->assertSee('Rp9.223.372.036.854.775.807');
});
