<?php

use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\PaymentNotices;
use App\PaymentReviewReason;
use Illuminate\Support\Facades\DB;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
});

function paymentNoticeTransaction(Order $order, Closure $operation): void
{
    DB::transaction(function () use ($order, $operation): void {
        Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $operation(app(PaymentNotices::class));
    });
}

test('paid notifications use the owners immutable contacts without routine Admin messages', function () {
    $payment = Payment::factory()->create();
    User::factory()->admin()->create();
    User::factory()->create();
    $order = $payment->order;
    paymentNoticeTransaction($order, fn (PaymentNotices $notices) => $notices->paid($order));
    $deliveries = NotificationDelivery::all();
    expect($deliveries)->toHaveCount(2);
    expect($deliveries->pluck('template_key')->unique()->all())->toBe(['customer.payment_paid.v1']);
    expect($deliveries->map(fn ($delivery) => $delivery->recipient_ciphertext['address'])->sort()->values()->all())
        ->toBe(collect([$order->email_snapshot, $order->whatsapp_snapshot])->sort()->values()->all());
    expect(json_encode($deliveries->pluck('payload_minimal')->all()))->not->toContain($order->email_snapshot, $order->whatsapp_snapshot);
});

test('staff payment exceptions route only to active Admin and Owner and deduplicate repeated observations', function () {
    $order = Payment::factory()->create()->order;
    User::factory()->admin()->create();
    User::factory()->owner()->create();
    User::factory()->admin()->create(['is_active' => false]);
    paymentNoticeTransaction($order, function (PaymentNotices $notices) use ($order): void {
        $notices->review($order, PaymentReviewReason::AmountAnomaly);
        $notices->review($order, PaymentReviewReason::AmountAnomaly);
    });
    $this->assertDatabaseCount('notification_deliveries', 6);
    expect(NotificationDelivery::query()->where('template_key', 'admin.payment_exception.v1')->count())->toBe(4);
    expect(NotificationDelivery::query()->pluck('template_key')->unique()->sort()->values()->all())->toBe(['admin.payment_exception.v1', 'customer.payment_exception.v1']);
});

test('key rotation retains delivery deduplication across the entire keyring', function () {
    $order = Payment::factory()->create()->order;
    paymentNoticeTransaction($order, fn (PaymentNotices $notices) => $notices->paid($order));
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('b', 32)),
        'notifications.previous_recipient_hmac_keys' => ['base64:'.base64_encode(str_repeat('a', 32))]]);
    paymentNoticeTransaction($order, fn (PaymentNotices $notices) => $notices->paid($order));
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('outbox writes roll back together with the domain transition', function () {
    $order = Payment::factory()->create()->order;
    expect(fn () => paymentNoticeTransaction($order, function (PaymentNotices $notices) use ($order): void {
        $notices->paid($order);
        throw new RuntimeException('synthetic failure');
    }))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('requires the callers parent transaction to serialize deduplication', function () {
    $order = Payment::factory()->create()->order;
    expect(fn () => app(PaymentNotices::class)->paid($order))->toThrow(LogicException::class);
});

test('payment exceptions include only the owning customers immutable contacts with known status', function () {
    $order = Payment::factory()->create()->order;
    $stranger = User::factory()->create();
    paymentNoticeTransaction($order, function (PaymentNotices $notices) use ($order): void {
        $notices->review($order, PaymentReviewReason::TimeUnverified);
        $notices->review($order, PaymentReviewReason::TimeUnverified);
    });
    $deliveries = NotificationDelivery::query()->where('template_key', 'customer.payment_exception.v1')->get();
    expect($deliveries)->toHaveCount(2);
    expect($deliveries->map(fn ($delivery) => $delivery->recipient_ciphertext['address'])->sort()->values()->all())
        ->toBe(collect([$order->email_snapshot, $order->whatsapp_snapshot])->sort()->values()->all());
    expect($deliveries->first()->payload_minimal)->toHaveCount(3)->toMatchArray(['order_number' => $order->order_number, 'status' => 'pending', 'reason' => 'payment_time_unverified']);
    expect(json_encode($deliveries->toArray()))->not->toContain($stranger->email, $stranger->whatsapp_number);
});
