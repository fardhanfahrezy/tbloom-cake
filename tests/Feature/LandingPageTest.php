<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Testimonial;
use App\Models\TestimonialPublicationStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Landing page data queries require the isolated MySQL test database.');
    }

    $this->withoutVite();
});

test('homepage shows active products and published testimonials without requiring authentication', function () {
    $product = Product::factory()->create([
        'name' => 'Cake Cokelat TBloom',
        'category_id' => Category::query()->where('slug', 'simple')->value('id'),
        'sales_unit_label' => 'per box',
    ]);
    ProductVariant::factory()->for($product)->create(['size_label' => 'D15', 'price' => 125000]);
    ProductImage::factory()->for($product)->create([
        'path' => 'products/cake-cokelat.webp',
        'alt_text' => 'Cake cokelat TBloom',
    ]);

    Testimonial::factory()->create([
        'display_name' => 'Pelanggan TBloom',
        'content' => 'Kue diterima dengan baik.',
        'publication_status' => TestimonialPublicationStatus::Published,
    ]);
    Testimonial::factory()->create([
        'display_name' => 'Testimoni Draft',
        'content' => 'Konten ini tidak boleh tampil.',
        'publication_status' => TestimonialPublicationStatus::Draft,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Lorem ipsum dolor sit amet.')
        ->assertSee('Cake Cokelat TBloom')
        ->assertSee('Rp125.000')
        ->assertSee('Pelanggan TBloom')
        ->assertSee('Kue diterima dengan baik.')
        ->assertDontSee('Testimoni Draft')
        ->assertDontSee('Konten ini tidak boleh tampil.');
});

test('homepage hides unavailable products and renders an empty product state', function () {
    Product::factory()->create(['name' => 'Produk tanpa varian aktif']);
    Product::factory()->create(['name' => 'Produk nonaktif', 'is_active' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Koleksi sedang disiapkan.')
        ->assertDontSee('Produk tanpa varian aktif')
        ->assertDontSee('Produk nonaktif');
});
