<?php

use App\CloseUnpaidPayment;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\ProcessPaymentEvent;
use App\ProcessPaymentEventJob;
use App\ReconciledUnpaidClosure;
use App\ReconcilePayments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:40:00', 'UTC'));
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-reconcile-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    Http::preventStrayRequests();
});

function paymentToReconcile(bool $issued = true): Payment
{
    $order = Order::factory()->create(['product_total' => 100001, 'created_at' => '2026-10-28 03:00:00', 'payment_expires_at' => '2026-10-28 03:30:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
    $payment = Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number, 'created_at' => '2026-10-28 03:00:00']);
    if ($issued) {
        $payment->forceFill(['snap_requested_at' => '2026-10-28 03:00:01', 'snap_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'review_reason' => 'closure_verification_required'])->save();
        AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => $order->user_id, 'entity_type' => 'payment',
            'entity_id' => $payment->id, 'action' => 'payment.snap_requested', 'created_at' => '2026-10-28 03:00:01']);
    }

    return $payment;
}

function reconciliationStatus(Payment $payment, string $status = 'pending'): array
{
    return ['order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => $status === 'expire' ? '407' : '201', 'transaction_status' => $status,
        'gross_amount' => '100001.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-28 10:00:02'];
}

test('reconciliation creates one durable internal kickoff and preserves its due time on replay', function () {
    $payment = paymentToReconcile();
    $reconciler = app(ReconcilePayments::class);

    $event = $reconciler->prepare($payment->id);

    expect($event)->toBeInstanceOf(PaymentEvent::class);
    expect($event->payload_redacted)->toBe(['verification_source' => 'application_reconciliation', 'cycle' => 1]);
    expect($event->processing_status->value)->toBe('pending');
    expect($payment->fresh()->reconciliation_attempts)->toBe(1);
    expect($payment->fresh()->next_reconcile_at->toDateTimeString())->toBe('2026-10-28 03:40:30');
    expect($reconciler->prepare($payment->id))->toBeNull();
    $this->assertDatabaseCount('payment_events', 1);
    expect($payment->order->item->quota_released_at)->toBeNull();
    Http::assertNothingSent();
});

test('reconciliation closes a never requested expired order before creating any kickoff', function () {
    $payment = paymentToReconcile(false);

    expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeNull();

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    expect($payment->fresh()->reconciliation_attempts)->toBe(0);
    expect(PaymentEvent::query()->sole()->payload_redacted['verification_source'])->toBe('application_no_session');
    Http::assertNothingSent();
});

test('reconciliation leaves a never requested order before deadline available for its original session', function () {
    $payment = paymentToReconcile(false);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:29:59', 'UTC'));

    expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeNull();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_events', 0);
});

test('reconciliation waits for associated or reference matched active inbox work', function (bool $unmatched) {
    $payment = paymentToReconcile();
    PaymentEvent::factory()->create(['payment_id' => $unmatched ? null : $payment->id, 'provider_reference' => $payment->merchant_reference,
        'verified_at' => now(), 'next_attempt_at' => now()->addMinutes(10)]);

    expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeNull();

    expect($payment->fresh()->reconciliation_attempts)->toBe(0);
    $this->assertDatabaseCount('payment_events', 1);
})->with([false, true]);

test('non final provider observations retain allocation and stop after five bounded cycles', function (string $status) {
    $payment = paymentToReconcile();
    User::factory()->admin()->create();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(reconciliationStatus($payment, $status))]);
    $reconciler = app(ReconcilePayments::class);
    foreach ([30, 120, 600, 1800, 1800] as $index => $seconds) {
        $start = now()->toImmutable();
        $event = $reconciler->prepare($payment->id);
        expect($event->payload_redacted['cycle'])->toBe($index + 1);
        expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeTrue();
        expect($payment->fresh()->next_reconcile_at->getTimestamp() - $start->getTimestamp())->toBe($seconds);
        $this->travelTo($payment->fresh()->next_reconcile_at);
    }

    expect($reconciler->prepare($payment->id))->toBeNull();
    expect($reconciler->prepare($payment->id))->toBeNull();

    expect($payment->fresh()->reconciliation_attempts)->toBe(5);
    expect($payment->fresh()->next_reconcile_at)->toBeNull();
    expect($payment->fresh()->review_reason)->toBe('closure_verification_required');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'payment.reconciliation_escalated')->count())->toBe(1);
    expect(NotificationDelivery::query()->where('template_key', 'admin.payment_exception.v1')->count())->toBe(2);
    expect(NotificationDelivery::query()->where('template_key', 'customer.payment_exception.v1')->count())->toBe(0);
    Http::assertSentCount(5);
})->with(['pending', 'expire']);

test('provider outage exhaustion escalates without a replacement recovery event or reset budget', function () {
    $payment = paymentToReconcile();
    User::factory()->owner()->create();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response([], 503)]);
    $reconciler = app(ReconcilePayments::class);
    $event = $reconciler->prepare($payment->id);
    for ($i = 0; $i < 5; $i++) {
        expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeFalse();
        if ($event->fresh()->next_attempt_at !== null) {
            $this->travelTo($event->fresh()->next_attempt_at);
        }
    }
    $this->travelTo(now()->addHour());

    expect($reconciler->prepare($payment->id))->toBeNull();

    $this->assertDatabaseCount('payment_events', 1);
    expect($payment->fresh()->reconciliation_attempts)->toBe(1);
    expect($payment->fresh()->next_reconcile_at)->toBeNull();
    expect($event->fresh()->error)->toBe('retry_exhausted');
    Http::assertSentCount(5);
});

test('resolved internal expire observation does not permanently prevent genuine Owner final closure', function () {
    $payment = paymentToReconcile();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(reconciliationStatus($payment, 'expire'))]);
    $event = app(ReconcilePayments::class)->prepare($payment->id);
    expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeTrue();
    $evidence = ReconciledUnpaidClosure::fromFinalReconciliation('123e4567-e89b-42d3-a456-426614174000',
        CarbonImmutable::parse('2026-10-28 03:39:00', 'UTC'), 'private-finality-no-credit-reference');

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, $evidence))->toBeTrue();

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    expect($event->fresh()->payload_redacted)->toEqual(['verification_source' => 'application_reconciliation', 'cycle' => 1]);
    Http::assertSentCount(2);
});

test('scanner includes locally final issued instruments and bounds dispatch without provider HTTP', function () {
    $payment = paymentToReconcile();
    $payment->order->forceFill(['status' => 'expired', 'expired_at' => now()])->save();
    $payment->forceFill(['status' => 'expired'])->save();
    Queue::fake();

    expect(app(ReconcilePayments::class)->dispatch(1))->toBe(1);

    Queue::assertPushedTimes(ProcessPaymentEventJob::class, 1);
    expect($payment->order->fresh()->status->value)->toBe('expired');
    Http::assertNothingSent();
});

test('kickoff persistence failure rolls back counters and never schedules the next cycle', function () {
    $payment = paymentToReconcile();
    PaymentEvent::creating(fn () => throw new RuntimeException('synthetic persistence failure'));
    try {
        expect(fn () => app(ReconcilePayments::class)->prepare($payment->id))->toThrow(RuntimeException::class);
    } finally {
        PaymentEvent::flushEventListeners();
    }

    expect($payment->fresh()->reconciliation_attempts)->toBe(0);
    expect($payment->fresh()->next_reconcile_at)->toBeNull();
    $this->assertDatabaseCount('payment_events', 0);
});

test('batch skips completed no session closures so older orders cannot starve due issued payments', function () {
    $closed = paymentToReconcile(false);
    app(CloseUnpaidPayment::class)->closeNeverRequested($closed->id);
    $due = paymentToReconcile();
    Queue::fake();

    expect(app(ReconcilePayments::class)->dispatch(1))->toBe(1);

    Queue::assertPushed(ProcessPaymentEventJob::class, fn ($job) => PaymentEvent::query()->findOrFail($job->eventId)->payment_id === $due->id);
});

test('batch skips unmatched active reference work so it cannot starve later due payments', function () {
    $blocked = paymentToReconcile();
    PaymentEvent::factory()->create(['payment_id' => null, 'provider_reference' => $blocked->merchant_reference, 'verified_at' => now()]);
    $due = paymentToReconcile();
    Queue::fake();

    expect(app(ReconcilePayments::class)->dispatch(1))->toBe(1);

    Queue::assertPushed(ProcessPaymentEventJob::class, fn ($job) => PaymentEvent::query()->findOrFail($job->eventId)->payment_id === $due->id);
});

test('internal provenance alone or an unrelated audit cannot certify a resolved observation', function (string $mismatch) {
    $payment = paymentToReconcile();
    $event = PaymentEvent::factory()->for($payment)->processed()->create(['payload_redacted' => ['verification_source' => 'application_reconciliation', 'cycle' => 1]]);
    if ($mismatch !== 'absent') {
        AuditLog::factory()->create(['entity_type' => $mismatch === 'entity' ? 'order' : 'payment',
            'entity_id' => $mismatch === 'payment' ? $payment->id + 999 : $payment->id,
            'action' => 'payment.non_success_observed',
            'after_redacted' => ['event_id' => $mismatch === 'event' ? $event->id + 999 : $event->id,
                'outcome' => $mismatch === 'outcome' ? 'unknown' : 'expired_observed']]);
    }
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(reconciliationStatus($payment, 'expire'))]);
    $evidence = ReconciledUnpaidClosure::fromFinalReconciliation('123e4567-e89b-42d3-a456-426614174000',
        CarbonImmutable::parse('2026-10-28 03:39:00', 'UTC'), 'private-finality-reference');

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, $evidence))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    Http::assertSentCount(1);
})->with(['absent', 'entity', 'payment', 'event', 'outcome']);

test('a competing committed scanner cannot increment the cycle or create a second kickoff', function () {
    $payment = paymentToReconcile();
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    Payment::retrieved(function (Payment $current) use ($payment, $other, $original, &$injected): void {
        if ($current->id !== $payment->id || $injected || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeInstanceOf(PaymentEvent::class);
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeNull();
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }

    expect($injected)->toBeTrue();
    expect($payment->fresh()->reconciliation_attempts)->toBe(1);
    $this->assertDatabaseCount('payment_events', 1);
    Http::assertNothingSent();
});

test('escalation outbox failure rolls back stop state and audit before a safe retry', function () {
    $payment = paymentToReconcile();
    $payment->forceFill(['reconciliation_attempts' => 5, 'next_reconcile_at' => now()])->save();
    User::factory()->admin()->create();
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic escalation failure'));
    try {
        expect(fn () => app(ReconcilePayments::class)->prepare($payment->id))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect($payment->fresh()->next_reconcile_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'payment.reconciliation_escalated')->count())->toBe(0);
    expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeNull();
    expect($payment->fresh()->next_reconcile_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'payment.reconciliation_escalated')->count())->toBe(1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    Http::assertNothingSent();
});
