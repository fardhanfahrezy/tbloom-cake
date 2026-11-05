<?php

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\PaymentMovementKey;
use App\ProcessPaymentEvent;
use App\ReconciledPaymentTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-process-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:40:00', 'UTC'));
    Http::preventStrayRequests();
});

function processablePayment(): Payment
{
    $order = Order::factory()->create(['product_total' => 100001, 'created_at' => '2026-10-27 03:00:00', 'payment_expires_at' => '2026-10-27 03:30:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);

    return Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number]);
}

function processableEvent(Payment $payment): PaymentEvent
{
    return PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => [
        'transaction_status' => 'settlement', 'settlement_time' => '2026-10-27 10:01:00',
    ]]);
}

/** @return array<string, mixed> */
function processStatus(Payment $payment, array $changes = []): array
{
    return array_replace(['order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100001.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-27 10:00:00',
        'settlement_time' => '2026-10-27 10:29:59'], $changes);
}

function fakeProcessStatus(Payment $payment, array $changes = []): void
{
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(processStatus($payment, $changes))]);
}

function paymentAttestation(string $time = '2026-10-27 03:29:58'): ReconciledPaymentTime
{
    return ReconciledPaymentTime::fromReconciliation('123e4567-e89b-42d3-a456-426614174000', CarbonImmutable::parse($time, 'UTC'), 'private-evidence-synthetic-123');
}

test('records verified settled funds while unknown customer timing stays review with its allocation held', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    fakeProcessStatus($payment);
    expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeTrue();

    $root = PaymentMovement::query()->sole();
    expect($root->amount)->toBe(100001);
    expect($root->occurred_at->toDateTimeString())->toBe('2026-10-27 03:29:59');
    expect($root->canonical_key)->toBe(PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', ['123e4567-e89b-42d3-a456-426614174000']));
    expect($payment->fresh()->provider_paid_at)->toBeNull();
    expect($payment->fresh()->review_reason)->toBe('payment_time_unverified');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($event->fresh()->processing_status->value)->toBe('review');
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertSentCount(1);
});

test('an authenticated fresh Owner can attest a timely action despite delayed delivery and reject automatic cancellation', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $cancel = OrderRequest::factory()->for($payment->order)->automaticCancellation()->create();
    fakeProcessStatus($payment);
    expect(app(ProcessPaymentEvent::class)->process($event->id, $owner, paymentAttestation()))->toBeTrue();

    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->order->fresh()->paid_at->toDateTimeString())->toBe('2026-10-27 03:29:58');
    expect($payment->fresh()->status->value)->toBe('paid');
    expect($payment->fresh()->is_late)->toBeFalse();
    expect($cancel->fresh()->status->value)->toBe('rejected');
    expect($cancel->fresh()->decision_reason)->toBe('payment_won');
    expect($event->fresh()->processing_status->value)->toBe('processed');
    $this->assertDatabaseCount('notification_deliveries', 2);
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain('private-evidence-synthetic-123', 'synthetic-process-key');
});

test('requires the actual authenticated Owner rather than a supplied privileged user object', function (string $actorKind) {
    $payment = processablePayment();
    $event = processableEvent($payment);
    $owner = User::factory()->owner()->create();
    if ($actorKind === 'admin') {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);
    } elseif ($actorKind === 'foreign') {
        $this->actingAs(User::factory()->create());
    } elseif ($actorKind === 'inactive') {
        $owner->forceFill(['is_active' => false])->save();
        $this->actingAs($owner);
    }
    expect(fn () => app(ProcessPaymentEvent::class)->process($event->id, $owner, paymentAttestation()))->toThrow(AuthorizationException::class);
    expect($event->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
})->with(['guest', 'foreign', 'admin', 'inactive']);

test('rereads Owner authorization after provider HTTP and rolls back when deactivated', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $owner) {
        expect(DB::transactionLevel())->toBe(0);
        User::query()->whereKey($owner->id)->update(['is_active' => false]);

        return Http::response(processStatus($payment));
    }]);
    expect(fn () => app(ProcessPaymentEvent::class)->process($event->id, $owner, paymentAttestation()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('payment_movements', 0);
    expect($payment->order->fresh()->status->value)->toBe('pending');
});

test('exactly repeated settlement deduplicates receipt audit matching and customer paid notices', function () {
    $payment = processablePayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeProcessStatus($payment);
    $first = processableEvent($payment);
    app(ProcessPaymentEvent::class)->process($first->id, $owner, paymentAttestation());
    $second = processableEvent($payment);
    expect(app(ProcessPaymentEvent::class)->process($second->id))->toBeTrue();
    expect(app(ProcessPaymentEvent::class)->process($second->id))->toBeFalse();
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    expect(AuditLog::query()->where('action', 'payment_event_matched_movement')->count())->toBe(1);
});

test('amount anomalies preserve actual funds without fulfillment top up or a fabricated refund', function (string $actual) {
    $payment = processablePayment();
    $event = processableEvent($payment);
    fakeProcessStatus($payment, ['gross_amount' => $actual]);
    app(ProcessPaymentEvent::class)->process($event->id);
    expect(PaymentMovement::query()->sole()->amount)->toBe((int) $actual);
    expect($payment->fresh()->review_reason)->toBe('amount_anomaly');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('refund_logs', 0);
})->with(['90000.00', '110000.00']);

test('terminal cancellation and eighty percent obligation survive duplicate settlement with existing returns', function () {
    $payment = processablePayment();
    $payment->order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
    $refund = RefundLog::factory()->for($payment)->create(['basis_amount' => 100001, 'amount' => 80000]);
    $source = processableEvent($payment);
    PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $source->id,
        'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', ['123e4567-e89b-42d3-a456-426614174000']),
        'amount' => 100001, 'occurred_at' => '2026-10-27 03:29:59']);
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $payment->id, 'source_event_id' => $source->id, 'amount' => 20000]);
    fakeProcessStatus($payment);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id);
    expect($payment->fresh()->status->value)->toBe('partially_refunded');
    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    expect($refund->fresh()->amount)->toBe(80000);
    expect($refund->fresh()->percentage)->toBe(80);
    $this->assertDatabaseCount('refund_logs', 1);
    $this->assertDatabaseCount('payment_movements', 2);
});

test('missing settlement time and unsupported returns stay review without invented ledger entries', function (array $changes, string $reason) {
    $payment = processablePayment();
    fakeProcessStatus($payment, $changes);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id);
    expect($payment->fresh()->review_reason)->toBe($reason);
    $this->assertDatabaseCount('payment_movements', 0);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([
    [['settlement_time' => null], 'funds_time_unverified'],
    [['transaction_status' => 'refund'], 'provider_evidence_unverified'],
    [['currency' => 'USD'], 'provider_context_conflict'],
]);

test('identity conflicts and correction heads never get overwritten by fresh settlement', function (bool $corrected) {
    $payment = processablePayment();
    $source = processableEvent($payment);
    $root = PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $source->id,
        'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', ['123e4567-e89b-42d3-a456-426614174000']),
        'amount' => $corrected ? 100001 : 90000, 'occurred_at' => '2026-10-27 03:29:59']);
    if ($corrected) {
        PaymentMovementCorrection::factory()->for($root, 'movement')->create(['effective_amount' => 90000]);
    }
    fakeProcessStatus($payment);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id);
    expect($payment->fresh()->review_reason)->toBe($corrected ? 'ledger_correction_review' : 'ledger_identity_conflict');
    expect($root->fresh()->amount)->toBe($corrected ? 100001 : 90000);
    expect($payment->order->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('payment_movements', 1);
})->with([false, true]);

test('invalid or boundary attestations never fulfill while retaining verified received money', function (string $time, string $reason) {
    $payment = processablePayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeProcessStatus($payment, ['settlement_time' => '2026-10-27 10:35:00']);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id, $owner, paymentAttestation($time));
    expect($payment->fresh()->review_reason)->toBe($reason);
    expect($payment->order->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('payment_movements', 1);
})->with([
    ['2026-10-27 03:30:00', 'payment_time_unverified'], ['2026-10-27 03:30:01', 'actual_late_payment'],
    ['2026-10-27 02:59:59', 'reconciliation_conflict'],
]);

test('failure after domain effects rolls back receipt order audit and outbox together', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeProcessStatus($payment);
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic outbox failure'));
    try {
        expect(fn () => app(ProcessPaymentEvent::class)->process($event->id, $owner, paymentAttestation()))->toThrow(RuntimeException::class);
        foreach (['payment_movements', 'audit_logs', 'notification_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        expect($payment->order->fresh()->status->value)->toBe('pending');
        expect($event->fresh()->processing_status->value)->toBe('processing');
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('temporary provider failure reschedules only the inbox without releasing its allocation', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response([], 503)]);
    expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeFalse();
    expect($event->fresh()->processing_status->value)->toBe('pending');
    expect($event->fresh()->next_attempt_at->toDateTimeString())->toBe('2026-10-27 03:40:30');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_movements', 0);
    Http::assertSentCount(1);
});

test('observed pending or expiry cannot downgrade paid funds or release quota', function (array $changes) {
    $payment = processablePayment();
    $payment->forceFill(['status' => 'paid'])->save();
    fakeProcessStatus($payment, $changes);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id);
    expect($payment->fresh()->status->value)->toBe('paid');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_movements', 0);
})->with([
    [['transaction_status' => 'pending', 'status_code' => '201', 'settlement_time' => null]],
    [['transaction_status' => 'expire', 'status_code' => '407', 'settlement_time' => null]],
]);

test('reclaimed inbox token from another connection fences every consumer effect after HTTP', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    $other = DB::build(config('database.connections.mysql_testing'));
    try {
        Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $event, $other) {
            expect(DB::transactionLevel())->toBe(0);
            $other->table('payment_events')->where('id', $event->id)->update(['claim_token' => '00000000-0000-4000-8000-000000000001', 'attempts' => 2]);

            return Http::response(processStatus($payment));
        }]);
        expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeFalse();
        expect($event->fresh()->attempts)->toBe(2);
        foreach (['payment_movements', 'audit_logs', 'notification_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        expect($payment->order->fresh()->status->value)->toBe('pending');
    } finally {
        $other->disconnect();
    }
});

test('provider context changed by another connection during HTTP is reread before applying funds', function () {
    $payment = processablePayment();
    $other = DB::build(config('database.connections.mysql_testing'));
    try {
        Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $other) {
            $other->table('payments')->where('id', $payment->id)->update(['expected_amount' => 200000]);

            return Http::response(processStatus($payment));
        }]);
        app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id);
        expect($payment->fresh()->review_reason)->toBe('provider_context_conflict');
        $this->assertDatabaseCount('payment_movements', 0);
    } finally {
        $other->disconnect();
    }
});

test('an unresolved transfer keeps its reserved money assignment and obligation frozen', function () {
    $payment = processablePayment();
    $refund = RefundLog::factory()->for($payment)->processing()->create(['basis_amount' => 100001, 'amount' => 80000]);
    $transfer = RefundTransfer::factory()->for($refund, 'refundLog')->unknown()->create(['amount' => 60000]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeProcessStatus($payment);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id, $owner, paymentAttestation());
    expect($payment->fresh()->review_reason)->toBe('return_requires_review');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($transfer->fresh()->status->value)->toBe('unknown');
    expect($refund->fresh()->amount)->toBe(80000);
    expect($refund->fresh()->assigned_admin_id)->toBe($refund->assigned_admin_id);
    expect($refund->fresh()->status->value)->toBe('review');
    $this->assertDatabaseCount('refund_transfers', 1);
});

test('a later unknown timing observation cannot erase an existing ledger incident and unblock fulfillment', function () {
    $payment = processablePayment();
    $payment->forceFill(['review_reason' => 'ledger_identity_conflict'])->save();
    fakeProcessStatus($payment);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id);
    expect($payment->fresh()->review_reason)->toBe('ledger_identity_conflict');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id, $owner, paymentAttestation());
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->fresh()->review_reason)->toBe('ledger_identity_conflict');
});

test('identical accepted Owner timing replays preserve terminal history without new exceptions', function (string $status) {
    $payment = processablePayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeProcessStatus($payment);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id, $owner, paymentAttestation());
    if ($status === 'completed') {
        $payment->order->forceFill(['status' => 'completed', 'completed_at' => now(), 'handed_over_by' => $owner->id])->save();
    } elseif ($status === 'cancelled') {
        $payment->order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        RefundLog::factory()->for($payment)->create(['basis_amount' => 100001, 'amount' => 80000]);
    }
    $event = processableEvent($payment);
    app(ProcessPaymentEvent::class)->process($event->id, $owner, paymentAttestation());
    expect($payment->fresh()->review_reason)->toBeNull();
    expect($payment->order->fresh()->status->value)->toBe($status);
    expect($event->fresh()->processing_status->value)->toBe('processed');
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    if ($status === 'cancelled') {
        expect(RefundLog::query()->sole()->amount)->toBe(80000);
        expect(RefundLog::query()->sole()->percentage)->toBe(80);
    }
})->with(['paid', 'completed', 'cancelled']);

test('a cross payment transaction identity race rolls back the loser without replaying HTTP', function () {
    $payment = processablePayment();
    $competitor = processablePayment();
    $source = processableEvent($competitor);
    $event = processableEvent($payment);
    $other = DB::build(config('database.connections.mysql_testing'));
    $injected = false;
    Payment::saving(function (Payment $current) use ($payment, $competitor, $source, $other, &$injected): void {
        if ($injected || $current->id !== $payment->id || $current->provider_transaction_id === null) {
            return;
        }
        $injected = true;
        $other->transaction(function () use ($current, $competitor, $source, $other): void {
            $other->table('payments')->where('id', $competitor->id)->update(['provider_transaction_id' => $current->provider_transaction_id]);
            $other->table('payment_movements')->insert(['payment_id' => $competitor->id,
                'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$current->provider_transaction_id]),
                'kind' => 'receipt', 'source' => 'gateway_receipt', 'amount' => 100001, 'source_event_id' => $source->id,
                'occurred_at' => '2026-10-27 03:29:59', 'verified_at' => now(), 'created_at' => now()]);
        });
    });
    try {
        fakeProcessStatus($payment);
        expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeTrue();
        expect($injected)->toBeTrue();
        expect($payment->fresh()->review_reason)->toBe('provider_context_conflict');
        expect($payment->fresh()->provider_transaction_id)->toBeNull();
        expect(PaymentMovement::query()->sole()->payment_id)->toBe($competitor->id);
        expect($event->fresh()->processing_status->value)->toBe('review');
        Http::assertSentCount(1);
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }
});

test('a domain DB retry rolls back partial effects and never repeats provider HTTP', function () {
    $payment = processablePayment();
    $event = processableEvent($payment);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeProcessStatus($payment);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic consumer retry'");
        }
    });
    try {
        expect(app(ProcessPaymentEvent::class)->process($event->id, $owner, paymentAttestation()))->toBeTrue();
        expect($payment->order->fresh()->status->value)->toBe('paid');
        expect($event->fresh()->processing_status->value)->toBe('processed');
        $this->assertDatabaseCount('payment_movements', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
        expect(AuditLog::query()->where('action', 'payment.timely_paid')->count())->toBe(1);
        Http::assertSentCount(1);
        Sleep::assertSleptTimes(1);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('terminal replay detects fresh provider chronology contradicting accepted payment timing', function (bool $attested) {
    $payment = processablePayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::sequence()
        ->push(processStatus($payment))->push(processStatus($payment, ['transaction_time' => '2026-10-27 10:29:59']))]);
    app(ProcessPaymentEvent::class)->process(processableEvent($payment)->id, $owner, paymentAttestation());
    $event = processableEvent($payment);
    app(ProcessPaymentEvent::class)->process($event->id, $attested ? $owner : null, $attested ? paymentAttestation() : null);
    expect($payment->fresh()->review_reason)->toBe('reconciliation_conflict');
    expect($event->fresh()->processing_status->value)->toBe('review');
    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->fresh()->provider_paid_at->toDateTimeString())->toBe('2026-10-27 03:29:58');
    $this->assertDatabaseCount('payment_movements', 1);
    Http::assertSentCount(2);
})->with([false, true]);
