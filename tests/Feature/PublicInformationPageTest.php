<?php

use App\CheckoutDetails;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('home page publishes store profile pickup address operating hours cutoff rules and policy links without false claims', function () {
    $response = $this->withoutVite()->get('/');

    $response->assertOk()
        ->assertSee('TBloom Cake')
        ->assertSee('icon.PNG', false)
        ->assertSee('id="main-content" tabindex="-1"', false)
        ->assertSee('Jalan Mayor Sastra Atmaja No.2, Margadadi Indramayu')
        ->assertSee('081395439751')
        ->assertSee('tel:+6281395439751', false)
        ->assertSee('Produksi 09.00–17.00 WIB')
        ->assertSee('Penyerahan 09.00–21.00 WIB')
        ->assertSee('24 jam')
        ->assertSee('17.00.00 WIB')
        ->assertSee('17.00.01 WIB')
        ->assertSee('H-3')
        ->assertSee('H-7')
        ->assertSee(route('catalog.index'), false)
        ->assertSee(route('pickup.show').'#policies', false)
        ->assertDontSee('Produksi 09.00–21.00 WIB')
        ->assertDontSee('Checkout tutup pukul 17.00');
});

test('pickup and policies page publishes complete payment cancellation refund change and courier terms', function () {
    $response = $this->withoutVite()->get('/pickup');

    $response->assertOk()
        ->assertSee('id="policies"', false)
        ->assertSee('Jalan Mayor Sastra Atmaja No.2, Margadadi Indramayu')
        ->assertSee('081395439751')
        ->assertSee('Produksi 09.00–17.00 WIB')
        ->assertSee('Penyerahan 09.00–21.00 WIB')
        ->assertSee('17.00.00 WIB')
        ->assertSee('17.00.01 WIB')
        ->assertSee('30 menit')
        ->assertSee('23.59.59 WIB')
        ->assertSee('80%')
        ->assertSee('100%')
        ->assertSee(CheckoutDetails::COURIER_POLICY_TEXT)
        ->assertDontSee('Produksi 09.00–21.00 WIB')
        ->assertDontSee('name="shipping_address"', false)
        ->assertDontSee('name="shipping_cost"', false);
});

test('public pages remain readable by guests while authenticated navigation exposes account and role links', function () {
    $this->withoutVite()->get('/')
        ->assertOk()
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false)
        ->assertDontSee(route('staff.dashboard'), false);

    $customer = User::factory()->create();
    $this->withoutVite()->actingAs($customer)->get('/')
        ->assertOk()
        ->assertSee(route('account.show'), false)
        ->assertSee(route('custom-requests.create'), false)
        ->assertDontSee(route('staff.dashboard'), false);

    $admin = User::factory()->admin()->create();
    $this->withoutVite()->actingAs($admin)->get('/')
        ->assertOk()
        ->assertSee(route('account.show'), false)
        ->assertSee(route('staff.dashboard'), false);
});
