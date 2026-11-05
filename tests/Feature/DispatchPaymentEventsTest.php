<?php

use App\DispatchPaymentEvents;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\PaymentEventInbox;
use App\ProcessPaymentEvent;
use App\ProcessPaymentEventJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'));
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-dispatch-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('d', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    Http::preventStrayRequests();
});

function dispatchableEvent(): PaymentEvent
{
    $order = Order::factory()->create(['created_at' => '2026-10-28 19:00:00', 'payment_expires_at' => '2026-10-28 19:30:00', 'product_total' => 100001]);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
    $payment = Payment::factory()->for($order)->create();

    return PaymentEvent::factory()->for($payment)->create(['verified_at' => now()]);
}

test('scanner queues due pending and expired leases at night without claiming them', function () {
    $due = dispatchableEvent();
    $future = dispatchableEvent();
    $expired = dispatchableEvent();
    $claim = app(PaymentEventInbox::class)->claim($expired->id);
    $live = dispatchableEvent();
    app(PaymentEventInbox::class)->claim($live->id);
    $this->travelTo($claim->lease_until);
    $live->forceFill(['lease_until' => now()->addSecond()])->save();
    $future->forceFill(['next_attempt_at' => now()->addSecond()])->save();
    Queue::fake();

    expect(app(DispatchPaymentEvents::class)->dispatch())->toBe(2);

    Queue::assertPushed(ProcessPaymentEventJob::class, fn ($job) => $job->eventId === $due->id && $job->connection === 'database' && $job->queue === 'payments');
    Queue::assertPushed(ProcessPaymentEventJob::class, fn ($job) => $job->eventId === $expired->id);
    Queue::assertPushedTimes(ProcessPaymentEventJob::class, 2);
    expect($due->fresh()->attempts)->toBe(0);
    expect($expired->fresh()->claim_token)->toBe($claim->claim_token);
    Http::assertNothingSent();
});

test('scanner bounds its batch and excludes terminal inbox states', function () {
    dispatchableEvent();
    dispatchableEvent();
    $third = dispatchableEvent();
    foreach (['processed', 'review', 'failed'] as $status) {
        $event = dispatchableEvent();
        $event->forceFill(['processing_status' => $status, 'processed_at' => $status === 'processed' ? now() : null])->save();
    }
    Queue::fake();

    expect(app(DispatchPaymentEvents::class)->dispatch(2))->toBe(2);

    Queue::assertPushedTimes(ProcessPaymentEventJob::class, 2);
    Queue::assertNotPushed(ProcessPaymentEventJob::class, fn ($job) => $job->eventId === $third->id);
    Http::assertNothingSent();
});

test('duplicate jobs use durable backoff instead of multiplying provider calls', function () {
    $event = dispatchableEvent();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$event->provider_reference.'/status' => Http::response([], 503)]);
    $job = new ProcessPaymentEventJob($event->id);

    $job->handle(app(ProcessPaymentEvent::class));
    $job->handle(app(ProcessPaymentEvent::class));

    expect($event->fresh()->attempts)->toBe(1);
    expect($event->fresh()->processing_status->value)->toBe('pending');
    expect($event->fresh()->next_attempt_at->toDateTimeString())->toBe('2026-10-28 20:00:30');
    Http::assertSentCount(1);
    expect($event->payment->order->item->quota_released_at)->toBeNull();
});

test('scanner recovers repeated crashes into durable manual review at the claim limit', function () {
    $event = dispatchableEvent();
    $inbox = app(PaymentEventInbox::class);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $claim = $inbox->claim($event->id);
        $this->travelTo($claim->lease_until);
    }
    Queue::fake();
    expect(app(DispatchPaymentEvents::class)->dispatch())->toBe(1);

    (new ProcessPaymentEventJob($event->id))->handle(app(ProcessPaymentEvent::class));

    expect($event->fresh()->processing_status->value)->toBe('review');
    expect($event->fresh()->error)->toBe('retry_exhausted');
    expect(app(DispatchPaymentEvents::class)->dispatch())->toBe(0);
    expect($event->payment->order->item->quota_released_at)->toBeNull();
    Http::assertNothingSent();
});

test('job crashes redact the exception and leave a durable claim for lease recovery', function () {
    $event = dispatchableEvent();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$event->provider_reference.'/status' => Http::response([
        'order_id' => $event->provider_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100001.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-29 02:00:00', 'settlement_time' => '2026-10-29 02:29:59',
    ])]);
    NotificationDelivery::creating(fn () => throw new RuntimeException('private-provider-diagnostic'));
    try {
        expect(fn () => (new ProcessPaymentEventJob($event->id))->handle(app(ProcessPaymentEvent::class)))
            ->toThrow(RuntimeException::class, 'Payment event processing failed; durable inbox recovery pending.');
    } finally {
        NotificationDelivery::flushEventListeners();
    }

    expect($event->fresh()->processing_status->value)->toBe('processing');
    expect($event->fresh()->attempts)->toBe(1);
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    Http::assertSentCount(1);
});

test('scanner refuses invalid limits and caller transactions before queueing', function () {
    Queue::fake();
    expect(fn () => app(DispatchPaymentEvents::class)->dispatch(0))->toThrow(InvalidArgumentException::class);
    DB::beginTransaction();
    try {
        expect(fn () => app(DispatchPaymentEvents::class)->dispatch())->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
    Queue::assertNothingPushed();
});

test('recovery command validates limits and dispatches durable rows', function () {
    dispatchableEvent();
    Queue::fake();

    $this->artisan('payments:dispatch-events', ['--limit' => '1'])->assertSuccessful();
    $this->artisan('payments:dispatch-events', ['--limit' => 'invalid'])->assertFailed();

    Queue::assertPushedTimes(ProcessPaymentEventJob::class, 1);
});

test('webhook queues its durable event immediately without applying domain effects', function () {
    $event = dispatchableEvent();
    $payload = ['order_id' => $event->provider_reference, 'status_code' => '200', 'gross_amount' => '100001.00',
        'transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'transaction_status' => 'settlement',
        'currency' => 'IDR', 'payment_type' => 'bank_transfer'];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].'200100001.00synthetic-dispatch-key');
    Queue::fake();

    $this->postJson('/webhooks/midtrans', $payload)->assertOk()->assertExactJson(['received' => true]);

    $received = PaymentEvent::query()->latest('id')->firstOrFail();
    Queue::assertPushed(ProcessPaymentEventJob::class, fn ($job) => $job->eventId === $received->id);
    expect($received->attempts)->toBe(0);
    expect($event->payment->order->fresh()->status->value)->toBe('pending');
    Http::assertNothingSent();
});

test('broker failure after durable webhook receipt still acknowledges for scanner recovery', function () {
    $event = dispatchableEvent();
    $payload = ['order_id' => $event->provider_reference, 'status_code' => '200', 'gross_amount' => '100001.00',
        'transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'transaction_status' => 'settlement',
        'currency' => 'IDR', 'payment_type' => 'bank_transfer'];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].'200100001.00synthetic-dispatch-key');
    $broker = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $broker->shouldReceive('push')->once()->andThrow(new RuntimeException('private-broker-diagnostic'));
    Queue::shouldReceive('connection')->with('database')->andReturn($broker);

    $this->postJson('/webhooks/midtrans', $payload)->assertOk()->assertExactJson(['received' => true]);

    expect(PaymentEvent::query()->latest('id')->firstOrFail()->processing_status->value)->toBe('pending');
    $this->assertDatabaseCount('payment_movements', 0);
    Http::assertNothingSent();
});
