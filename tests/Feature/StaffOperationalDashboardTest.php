<?php

use App\Models\AuditActorType;
use App\Models\AuditLog;
use App\Models\CustomCakeRequest;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use App\Models\PickupMethod;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundReasonType;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('staff dashboard enforces active admin and owner access while denying guests customers and inactive staff', function () {
    $this->get('/staff')->assertRedirectToRoute('login');

    $customer = User::factory()->create();
    $this->actingAs($customer)->get('/staff')->assertForbidden();

    $inactiveAdmin = User::factory()->admin()->inactive()->create();
    $this->actingAs($inactiveAdmin)->get('/staff')->assertRedirectToRoute('login');

    $admin = User::factory()->admin()->create();
    $this->withoutVite()->actingAs($admin)->get('/staff')->assertOk();

    $owner = User::factory()->owner()->create();
    $this->withoutVite()->actingAs($owner)->get('/staff')->assertOk();
});

test('staff dashboard filters orders by order number status dates pickup method and customer contact with direct detail links', function () {
    $admin = User::factory()->admin()->create();

    $matchingOrder = Order::factory()->paid()->onlineCourier()->create([
        'order_number' => 'ORD-20261107-FIND01',
        'customer_name_snapshot' => 'Nadia Putri',
        'email_snapshot' => 'nadia.filter@example.test',
        'whatsapp_snapshot' => '+6281234009988',
        'pickup_date' => '2026-11-12',
    ]);
    OrderItem::factory()->for($matchingOrder)->create([
        'product_name_snapshot' => 'Red Velvet Berry',
    ]);
    Payment::factory()->for($matchingOrder)->paid()->create();

    $otherOrder = Order::factory()->create([
        'order_number' => 'ORD-20261107-SKIP99',
        'status' => OrderStatus::Pending,
        'customer_name_snapshot' => 'Raka Pratama',
        'email_snapshot' => 'raka.skip@example.test',
        'whatsapp_snapshot' => '+6281299990000',
        'pickup_date' => '2026-11-15',
        'pickup_method' => PickupMethod::SelfPickup,
    ]);
    OrderItem::factory()->for($otherOrder)->create([
        'product_name_snapshot' => 'Matcha Cream Cake',
    ]);
    Payment::factory()->for($otherOrder)->create();

    $response = $this->withoutVite()->actingAs($admin)->get('/staff?'.http_build_query([
        'order_number' => 'FIND01',
        'status' => 'paid',
        'pickup_date' => '2026-11-12',
        'pickup_method' => 'online_courier',
        'customer_contact' => '081234009988',
    ]));

    $response->assertOk()
        ->assertSee('ORD-20261107-FIND01')
        ->assertSee('Nadia Putri')
        ->assertSee('Red Velvet Berry')
        ->assertSee(route('orders.show', $matchingOrder), false)
        ->assertSee(route('staff.orders.handover.show', $matchingOrder), false)
        ->assertDontSee('ORD-20261107-SKIP99')
        ->assertDontSee('Matcha Cream Cake');
});

test('staff dashboard surfaces separate queues for custom requests customer requests refunds integration failures and audit logs without forbidden controls', function () {
    $owner = User::factory()->owner()->create(['name' => 'Owner Utama']);
    $admin = User::factory()->admin()->create(['name' => 'Admin Operasional']);

    $order = Order::factory()->paid()->create([
        'order_number' => 'ORD-20261107-OPS001',
    ]);
    OrderItem::factory()->for($order)->create();
    $payment = Payment::factory()->for($order)->paid()->create([
        'review_reason' => 'late_payment_after_expiry',
    ]);

    PaymentEvent::factory()->for($payment)->create([
        'provider_reference' => 'EVT-OPS-REVIEW-01',
        'processing_status' => PaymentEventStatus::Review,
        'error' => 'Signature mismatch or manual review needed',
    ]);

    OrderRequest::factory()->for($order)->for($order->user)->change()->create([
        'decision_mode' => OrderRequestDecisionMode::Manual,
        'status' => OrderRequestStatus::Pending,
        'reason' => 'Ubah tulisan kartu ucapan',
    ]);

    $refundLog = RefundLog::factory()->for($order)->for($payment)->verifiedBank()->create([
        'reason_type' => RefundReasonType::StoreCancel,
        'basis_amount' => $order->product_total,
        'percentage' => 100,
        'amount' => $order->product_total,
        'status' => RefundLogStatus::Review,
        'assigned_admin_id' => $admin->id,
        'assignment_version' => 1,
        'review_reason' => 'Transfer status unknown membutuhkan rekonsiliasi BCA',
    ]);
    RefundTransfer::factory()->for($refundLog)->for($payment)->create([
        'status' => RefundTransferStatus::Unknown,
        'transfer_reference' => 'RFND-OPS-UNKNOWN-01',
        'prepared_by' => $admin->id,
    ]);

    $customRequest = CustomCakeRequest::factory()->submitted()->create([
        'request_number' => 'REQ-20261107-OPS01',
    ]);

    NotificationDelivery::factory()->unknown()->create([
        'entity_type' => 'order',
        'entity_id' => $order->id,
        'template_key' => 'customer.payment_exception.v1',
        'attempts' => 2,
        'last_error' => 'Timeout membaca respons Fonnte',
    ]);

    AuditLog::factory()->create([
        'actor_type' => AuditActorType::User,
        'actor_user_id' => $owner->id,
        'action' => 'refund.taken_over',
        'entity_type' => 'refund_log',
        'entity_id' => $refundLog->id,
        'reason' => 'Pengambilalihan investigasi transfer unknown',
    ]);

    $response = $this->withoutVite()->actingAs($owner)->get('/staff');

    $response->assertOk()
        ->assertSee('ORD-20261107-OPS001')
        ->assertSee('REQ-20261107-OPS01')
        ->assertSee('Ubah tulisan kartu ucapan')
        ->assertSee('Admin Operasional')
        ->assertSee('RFND-OPS-UNKNOWN-01')
        ->assertSee('late_payment_after_expiry')
        ->assertSee('EVT-OPS-REVIEW-01')
        ->assertSee('customer.payment_exception.v1')
        ->assertSee('Timeout membaca respons Fonnte')
        ->assertSee('refund.taken_over')
        ->assertSee('Pengambilalihan investigasi transfer unknown')
        ->assertDontSee('in_production')
        ->assertDontSee('Tandai lunas')
        ->assertDontSee('Grafik pendapatan');
});
