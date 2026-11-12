<?php

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('about page renders successfully with store information, principles, and navigation links', function () {
    $response = $this->withoutVite()->get('/about');

    $response->assertOk()
        ->assertSee('Tentang Kami')
        ->assertSee('TBloom Cake')
        ->assertSee(config('store.pickup_address'))
        ->assertSee(config('store.contact_display'))
        ->assertSee('tel:'.config('store.contact_international'), false)
        ->assertSee('Produksi / Pemrosesan')
        ->assertSee('09.00–17.00 WIB')
        ->assertSee('Penyerahan Cake di Toko')
        ->assertSee('09.00–21.00 WIB')
        ->assertSee('24 Jam')
        ->assertSee(route('catalog.index'), false)
        ->assertSee('Nilai &amp; Filosofi', false);
});

test('about page shows custom request button for guests and customers, and catalog link for staff', function () {
    $this->withoutVite()->get('/about')
        ->assertOk()
        ->assertSee(route('custom-requests.create'), false);

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer)->withoutVite()->get('/about')
        ->assertOk()
        ->assertSee(route('custom-requests.create'), false);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->withoutVite()->get('/about')
        ->assertOk()
        ->assertDontSee(route('custom-requests.create'), false);
});
