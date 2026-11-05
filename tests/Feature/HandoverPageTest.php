<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

test('public pickup information publishes the confirmed address contact and independent courier booking', function () {
    $this->withoutVite()->get('/pickup')->assertOk()->assertSee('Jalan Mayor Sastra Atmaja No.2, Margadadi Indramayu')
        ->assertSee('081395439751')->assertSee('09.00–17.00 WIB')->assertSee('09.00–21.00 WIB')->assertSee('Gojek atau Grab');
});

test('staff can inspect the paid handover checklist while customers and guests cannot', function () {
    $order = Order::factory()->paid()->create();
    OrderItem::factory()->for($order)->create();
    Payment::factory()->for($order)->paid()->create();
    $url = '/staff/orders/'.$order->id.'/handover';
    $this->get($url)->assertRedirectToRoute('login');
    $this->actingAs($order->user)->get($url)->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()->assertSee($order->order_number)
        ->assertSee('Cocokkan nomor pesanan')->assertSee('Jalan Mayor Sastra Atmaja No.2');
    expect($order->fresh()->status->value)->toBe('paid');
    $this->assertDatabaseCount('audit_logs', 0);
});

test('owned order details share official pickup information without adding shipping inputs', function () {
    $order = Order::factory()->paid()->onlineCourier()->create(['handover_note' => '<script>booking</script>']);
    OrderItem::factory()->for($order)->create();
    Payment::factory()->for($order)->paid()->create();
    $this->actingAs($order->user)->get('/orders/'.$order->id)->assertOk()
        ->assertSee('Margadadi Indramayu')->assertSee('tel:+6281395439751', false)
        ->assertSee('&lt;script&gt;booking&lt;/script&gt;', false)->assertDontSee('<script>booking</script>', false)
        ->assertDontSee('name="shipping_address"', false)->assertDontSee('name="shipping_cost"', false);
});

test('handover policy grants active staff and hides the checklist from other roles', function () {
    $order = Order::factory()->create();
    foreach ([User::factory()->admin()->create(), User::factory()->owner()->create()] as $staff) {
        expect(Gate::forUser($staff)->allows('handover', $order))->toBeTrue();
    }
    foreach ([$order->user, User::factory()->create(), User::factory()->admin()->inactive()->create(), User::factory()->owner()->inactive()->create()] as $actor) {
        expect(Gate::forUser($actor)->inspect('handover', $order)->status())->toBe(404);
    }
});
