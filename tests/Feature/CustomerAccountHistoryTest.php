<?php

use App\Models\CustomCakeRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentStatus;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundReasonType;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('customer account page lists owned orders and custom cake requests with separate order payment and refund statuses while isolating other customers', function () {
    $customerA = User::factory()->create(['name' => 'Alya Customer']);
    $customerB = User::factory()->create(['name' => 'Budi Customer']);

    $pendingReviewOrder = Order::factory()->for($customerA)->create([
        'order_number' => 'ORD-20261107-AAAA01',
        'status' => OrderStatus::Pending,
        'product_total' => 250000,
    ]);
    OrderItem::factory()->for($pendingReviewOrder)->create([
        'product_name_snapshot' => 'Classic Chocolate',
        'size_snapshot' => 'Diameter 16 cm',
    ]);
    Payment::factory()->for($pendingReviewOrder)->create([
        'status' => PaymentStatus::Pending,
        'review_reason' => 'snap_creation_unknown',
    ]);

    $expiredRefundOrder = Order::factory()->for($customerA)->expired()->create([
        'order_number' => 'ORD-20261107-AAAA02',
        'product_total' => 300000,
    ]);
    OrderItem::factory()->for($expiredRefundOrder)->create([
        'product_name_snapshot' => 'Strawberry Shortcake',
        'size_snapshot' => 'Diameter 20 cm',
    ]);
    $latePayment = Payment::factory()->for($expiredRefundOrder)->paid()->create([
        'is_late' => true,
        'review_reason' => 'late_payment_after_expiry',
    ]);
    $refundLog = RefundLog::factory()->for($expiredRefundOrder)->for($latePayment)->create([
        'reason_type' => RefundReasonType::LatePayment,
        'basis_amount' => 300000,
        'percentage' => 100,
        'amount' => 300000,
        'status' => RefundLogStatus::Pending,
    ]);

    $customRequestA = CustomCakeRequest::factory()->for($customerA)->submitted()->create([
        'request_number' => 'REQ-20261107-CAKE01',
    ]);

    $otherOrder = Order::factory()->for($customerB)->paid()->create([
        'order_number' => 'ORD-20261107-BBBB99',
    ]);
    OrderItem::factory()->for($otherOrder)->create([
        'product_name_snapshot' => 'Secret Lemon Cake',
    ]);
    Payment::factory()->for($otherOrder)->paid()->create();
    $otherCustom = CustomCakeRequest::factory()->for($customerB)->submitted()->create([
        'request_number' => 'REQ-20261107-BBBB99',
    ]);

    $response = $this->withoutVite()->actingAs($customerA)->get('/account');

    $response->assertOk()
        ->assertSee('ORD-20261107-AAAA01')
        ->assertSee('Classic Chocolate')
        ->assertSee('Jangan membayar ulang')
        ->assertSee('ORD-20261107-AAAA02')
        ->assertSee('Strawberry Shortcake')
        ->assertSee('Pesanan kedaluwarsa')
        ->assertSee('Refund menunggu tindak lanjut')
        ->assertSee('Rp300.000')
        ->assertSee(route('refunds.bank.show', $refundLog), false)
        ->assertSee('REQ-20261107-CAKE01')
        ->assertSee(route('custom-requests.show', $customRequestA), false)
        ->assertDontSee('ORD-20261107-BBBB99')
        ->assertDontSee('Secret Lemon Cake')
        ->assertDontSee('REQ-20261107-BBBB99');
});

test('order detail page displays order status payment status and refund status separately including expired order with pending refund', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();

    $order = Order::factory()->for($customer)->expired()->create([
        'order_number' => 'ORD-20261107-EXPREF',
        'product_total' => 400000,
    ]);
    OrderItem::factory()->for($order)->create();
    $payment = Payment::factory()->for($order)->paid()->create([
        'is_late' => true,
        'review_reason' => 'late_payment_after_expiry',
    ]);
    $refund = RefundLog::factory()->for($order)->for($payment)->create([
        'reason_type' => RefundReasonType::LatePayment,
        'basis_amount' => 400000,
        'percentage' => 100,
        'amount' => 400000,
        'status' => RefundLogStatus::Pending,
    ]);

    $this->withoutVite()->actingAs($customer)->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('Status pesanan')
        ->assertSee('Pesanan kedaluwarsa')
        ->assertSee('Status pembayaran')
        ->assertSee('Pembayaran diterima')
        ->assertSee('Status refund')
        ->assertSee('Refund menunggu tindak lanjut (100% — Rp400.000)')
        ->assertSee(route('refunds.bank.show', $refund), false);

    $this->withoutVite()->actingAs($otherCustomer)->get(route('orders.show', $order))
        ->assertNotFound();
});
