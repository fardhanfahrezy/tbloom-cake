<?php

use App\CloseUnpaidPayment;
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
use App\ProcessPaymentEvent;
use App\ReconciledPaymentTime;
use App\ReconciledUnpaidClosure;
use App\RecoverTimelyPayment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:40:00', 'UTC'));
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-recovery-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('i', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    Http::preventStrayRequests();
});

/** @return array{Payment, User} */
function timelyPaymentIncident(bool $cancelled = false, ?string $confirmedAt = null, bool $attested = true): array
{
    $order = Order::factory()->create(['product_total' => 100000, 'created_at' => '2026-10-28 03:00:00', 'payment_expires_at' => '2026-10-28 03:30:00']);
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000]);
    $item->dailyAvailability->forceFill(['quota_limit' => 2])->save();
    $payment = Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number, 'created_at' => '2026-10-28 03:00:00']);
    $owner = User::factory()->owner()->create();
    test()->actingAs($owner);
    if ($cancelled) {
        OrderRequest::factory()->for($order)->automaticCancellation()->create(['submitted_at' => '2026-10-28 03:05:00']);
    }
    $settled = ['order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100000.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-28 10:00:02', 'settlement_time' => '2026-10-28 10:29:59'];
    $url = 'https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status';
    if ($confirmedAt !== null) {
        $payment->forceFill(['snap_requested_at' => '2026-10-28 03:00:01', 'snap_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])->save();
        AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => $order->user_id, 'entity_type' => 'payment', 'entity_id' => $payment->id,
            'action' => 'payment.snap_requested', 'created_at' => '2026-10-28 03:00:01']);
        $expired = array_replace($settled, ['status_code' => '407', 'transaction_status' => 'expire']);
        unset($expired['settlement_time']);
        Http::fake([$url => Http::sequence()->push($expired)->push($settled)->push($settled)->push($settled)]);
        expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, ReconciledUnpaidClosure::fromFinalReconciliation(
            $settled['transaction_id'], CarbonImmutable::parse($confirmedAt, 'UTC'), 'synthetic-original-finality-mistake',
        )))->toBeTrue();
    } else {
        expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();
        Http::fake([$url => Http::response($settled)]);
    }
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);
    $time = ReconciledPaymentTime::fromReconciliation($settled['transaction_id'], CarbonImmutable::parse('2026-10-28 03:29:58', 'UTC'), 'private-original-timing-evidence');
    expect(app(ProcessPaymentEvent::class)->process($event->id, $attested ? $owner : null, $attested ? $time : null))->toBeTrue();

    return [$payment->fresh(), $owner];
}

test('timely receipt after wrong closure freezes final state before explicit recovery', function () {
    [$payment] = timelyPaymentIncident();

    expect($payment->order->status->value)->toBe('expired');
    expect($payment->review_reason)->toBe('terminal_receipt_review');
    expect($payment->order->item->quota_released_at)->not->toBeNull();
    expect($payment->provider_paid_at->toDateTimeString())->toBe('2026-10-28 03:29:58');
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertSentCount(1);
});

test('duplicate canonical receipt cannot normalize a contradictory unpaid closure as processed', function () {
    [$payment] = timelyPaymentIncident();
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);

    expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeTrue();

    expect($event->fresh()->processing_status->value)->toBe('review');
    expect($event->fresh()->error)->toBe('terminal_receipt_review');
    expect($payment->order->fresh()->status->value)->toBe('expired');
    $this->assertDatabaseCount('payment_movements', 1);
    Http::assertSentCount(2);
});

test('Owner can attest timing after the automatic receipt was already frozen then recover capacity', function () {
    [$payment, $owner] = timelyPaymentIncident(attested: false);
    expect($payment->provider_paid_at)->toBeNull();

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'observe',
        'transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'paid_at_utc' => '2026-10-28 03:29:58',
        'evidence_reference' => 'private-later-timing-evidence'])->assertOk();

    expect($payment->fresh()->provider_paid_at?->toDateTimeString())->toBe('2026-10-28 03:29:58');
    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-operational-after-attestation'))->toBeTrue();
    expect($payment->order->fresh()->status->value)->toBe('paid');
    $this->assertDatabaseCount('payment_movements', 1);
    Http::assertSentCount(3);
});

test('Owner reacquires free original capacity and restores paid once while retaining closure evidence', function () {
    [$payment, $owner] = timelyPaymentIncident();
    $originalProof = $payment->closureEvent->getRawOriginal();
    $closureId = $payment->closure_event_id;

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-operational-recovery-evidence'))->toBeTrue();
    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-operational-recovery-evidence'))->toBeTrue();

    $current = $payment->fresh();
    expect($current->order->status->value)->toBe('paid');
    expect($current->order->paid_at->toDateTimeString())->toBe('2026-10-28 03:29:58');
    expect($current->order->expired_at)->toBeNull();
    expect($current->order->item->quota_released_at)->toBeNull();
    expect($current->order->item->quota_release_reason)->toBeNull();
    expect($current->closure_event_id)->toBe($closureId);
    expect($current->closureEvent->getRawOriginal())->toBe($originalProof);
    expect($current->review_reason)->toBeNull();
    expect(AuditLog::query()->where('action', 'payment.timely_recovery_applied')->count())->toBe(1);
    expect(NotificationDelivery::query()->where('template_key', 'customer.payment_paid.v1')->count())->toBe(2);
    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('private-operational-recovery-evidence', 'synthetic-recovery-key');
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertSentCount(3);
});

test('capacity reassigned to another order stays protected without overbooking or automatic refund', function () {
    [$payment, $owner] = timelyPaymentIncident();
    $item = $payment->order->item;
    $otherOrder = Order::factory()->create(['product_total' => 100000, 'pickup_date' => $payment->order->pickup_date]);
    $otherItem = OrderItem::factory()->for($otherOrder)->create(['product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id,
        'daily_availability_id' => $item->daily_availability_id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000]);
    $before = $otherItem->fresh()->getRawOriginal();

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-capacity-review'))->toBeFalse();
    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-capacity-review'))->toBeFalse();

    expect($payment->fresh()->review_reason)->toBe('capacity_reassigned');
    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($item->fresh()->quota_released_at)->not->toBeNull();
    expect($otherItem->fresh()->getRawOriginal())->toBe($before);
    expect($item->dailyAvailability->fresh()->quota_limit)->toBe(2);
    expect(AuditLog::query()->where('action', 'payment.recovery_capacity_unavailable')->count())->toBe(1);
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('refund_transfers', 0);
    Http::assertSentCount(3);
});

test('only the automatic cancellation tied to the mistaken closure is corrected by a payment before closure', function () {
    [$payment, $owner] = timelyPaymentIncident(true);
    $request = OrderRequest::query()->sole();
    expect($request->status->value)->toBe('applied');

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-recovery-cancellation'))->toBeTrue();

    expect($request->fresh()->status->value)->toBe('rejected');
    expect($request->fresh()->decision_reason)->toBe('payment_won_recovery');
    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect(AuditLog::query()->where('action', 'payment.recovery_cancellation_corrected')->count())->toBe(1);
    Http::assertSentCount(2);
});

test('payment before deadline but at or after original gateway close is retained for chronology review', function (string $confirmedAt) {
    [$payment, $owner] = timelyPaymentIncident(true, $confirmedAt);
    $request = OrderRequest::query()->sole();

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-close-chronology-review'))->toBeFalse();

    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    expect($request->fresh()->status->value)->toBe('applied');
    expect($request->fresh()->decision_reason)->toBe('unpaid_closed');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    Http::assertSentCount(3);
})->with(['2026-10-28 03:10:00', '2026-10-28 03:29:58']);

test('raw paid timestamp without a durable matching Owner attestation cannot authorize recovery', function () {
    [$payment, $owner] = timelyPaymentIncident(attested: false);
    $payment->forceFill(['provider_paid_at' => '2026-10-28 03:29:58', 'is_late' => false, 'review_reason' => 'terminal_receipt_review'])->save();

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-unverified-time'))->toBeFalse();

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    Http::assertSentCount(2);
});

test('existing refunds returns corrections or bank attempts keep incident recovery frozen', function (string $kind) {
    [$payment, $owner] = timelyPaymentIncident();
    if (in_array($kind, ['refund', 'unknown'], true)) {
        $factory = RefundLog::factory()->for($payment);
        $refund = ($kind === 'unknown' ? $factory->processing() : $factory)->create(['basis_amount' => 100000, 'amount' => 80000]);
        if ($kind === 'unknown') {
            RefundTransfer::factory()->for($refund, 'refundLog')->unknown()->create(['amount' => 80000]);
        }
    } elseif ($kind === 'return') {
        PaymentMovement::factory()->providerRefund()->create(['payment_id' => $payment->id, 'source_event_id' => PaymentMovement::query()->sole()->source_event_id, 'amount' => 1000]);
    } else {
        PaymentMovementCorrection::factory()->for(PaymentMovement::query()->sole(), 'movement')->create(['effective_amount' => 0]);
    }

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-existing-financial-history'))->toBeFalse();

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    Http::assertSentCount(2);
})->with(['refund', 'unknown', 'return', 'correction']);

test('recovery requires actual active Owner and business hours before another GET', function (string $actorKind) {
    [$payment, $owner] = timelyPaymentIncident();
    if ($actorKind === 'admin') {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);
    } elseif ($actorKind === 'foreign') {
        $this->actingAs(User::factory()->create());
    } elseif ($actorKind === 'inactive') {
        $owner->forceFill(['is_active' => false])->save();
    } else {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:01', 'UTC'));
    }

    expect(fn () => app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-authority-review'))->toThrow(AuthorizationException::class);

    expect($payment->order->fresh()->status->value)->toBe('expired');
    Http::assertSentCount(1);
})->with(['admin', 'foreign', 'inactive', 'hours']);

test('recovery outbox failure rolls back allocation state and audit', function () {
    [$payment, $owner] = timelyPaymentIncident();
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic recovery rollback'));
    try {
        expect(fn () => app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-rollback-review'))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'payment.timely_recovery_applied')->count())->toBe(0);
    Http::assertSentCount(2);
});

test('Owner recovery endpoint uses established timing rather than accepting a paid status override', function () {
    [$payment] = timelyPaymentIncident();

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'recover_timely',
        'evidence_reference' => 'private-operational-evidence', 'status' => 'paid', 'quota_limit' => 999])
        ->assertOk()->assertExactJson(['processed' => true]);

    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->order->item->dailyAvailability->fresh()->quota_limit)->toBe(2);
    Http::assertSentCount(2);
});

test('historical closure after recovery cannot turn a legitimate paid cancellation duplicate into a new incident', function () {
    [$payment, $owner] = timelyPaymentIncident();
    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-before-paid-cancellation'))->toBeTrue();
    $refund = DB::transaction(function () use ($payment) {
        $payment->order->fresh()->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $payment->order->item->fresh()->forceFill(['quota_released_at' => now(), 'quota_release_reason' => 'cancelled'])->save();

        return RefundLog::factory()->for($payment)->create(['basis_amount' => 100000, 'amount' => 80000]);
    });
    $before = $refund->fresh()->getRawOriginal();
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);

    expect(app(ProcessPaymentEvent::class)->process($event->id))->toBeTrue();

    expect($event->fresh()->processing_status->value)->toBe('processed');
    expect($refund->fresh()->getRawOriginal())->toBe($before);
    expect($payment->fresh()->review_reason)->toBeNull();
    $this->assertDatabaseCount('payment_movements', 1);
    Http::assertSentCount(3);
});

test('a competing committed allocation before availability lock prevents stale recovery', function () {
    [$payment, $owner] = timelyPaymentIncident();
    $item = $payment->order->item;
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    User::retrieved(function (User $current) use ($payment, $owner, $item, $other, $original, &$injected): void {
        if ($injected || $current->id !== $owner->id || DB::transactionLevel() === 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            $order = Order::factory()->create(['product_total' => 100000, 'pickup_date' => $payment->order->pickup_date]);
            OrderItem::factory()->for($order)->create(['product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id,
                'daily_availability_id' => $item->daily_availability_id, 'quantity' => 2, 'unit_price' => 50000, 'line_total' => 100000]);
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-current-capacity'))->toBeFalse();
    } finally {
        User::flushEventListeners();
        $other->disconnect();
    }

    expect($injected)->toBeTrue();
    expect($item->fresh()->quota_released_at)->not->toBeNull();
    expect(OrderItem::query()->where('daily_availability_id', $item->daily_availability_id)->whereNull('quota_released_at')->sum('quantity'))->toBe('2');
    expect($item->dailyAvailability->fresh()->quota_limit)->toBe(2);
    Http::assertSentCount(2);
});

test('a competing Owner recovery is replayed without a second allocation audit or outbox', function () {
    [$payment, $owner] = timelyPaymentIncident();
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    Payment::retrieved(function (Payment $current) use ($payment, $owner, $other, $original, &$injected): void {
        if ($injected || $current->id !== $payment->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-competing-recovery'))->toBeTrue();
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-competing-recovery'))->toBeTrue();
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }

    expect($injected)->toBeTrue();
    expect(AuditLog::query()->where('action', 'payment.timely_recovery_applied')->count())->toBe(1);
    expect(NotificationDelivery::query()->where('template_key', 'customer.payment_paid.v1')->count())->toBe(2);
    Http::assertSentCount(3);
});

test('DB retry rolls back partial recovery without repeating provider HTTP', function () {
    [$payment, $owner] = timelyPaymentIncident();
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic recovery retry'");
        }
    });
    try {
        expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-retry-recovery'))->toBeTrue();
    } finally {
        NotificationDelivery::flushEventListeners();
    }

    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect(AuditLog::query()->where('action', 'payment.timely_recovery_applied')->count())->toBe(1);
    expect(NotificationDelivery::query()->where('template_key', 'customer.payment_paid.v1')->count())->toBe(2);
    Sleep::assertSleptTimes(1);
    Http::assertSentCount(2);
});

test('recovery rechecks manual hours after its last domain lock', function () {
    [$payment, $owner] = timelyPaymentIncident();
    $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'));
    OrderItem::retrieved(function (OrderItem $item) use ($payment): void {
        if ($item->order_id === $payment->order_id && DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:01', 'UTC'));
        }
    });
    try {
        expect(fn () => app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-window-recovery'))->toThrow(AuthorizationException::class);
    } finally {
        OrderItem::flushEventListeners();
    }

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    Http::assertSentCount(2);
});

test('Owner deactivation after initial authorization is reread before recovery effects', function () {
    [$payment, $owner] = timelyPaymentIncident();
    $injected = false;
    Payment::retrieved(function (Payment $current) use ($payment, $owner, &$injected): void {
        if ($current->id === $payment->id && ! $injected && DB::transactionLevel() === 0) {
            $injected = true;
            User::query()->whereKey($owner->id)->update(['is_active' => false]);
        }
    });
    try {
        expect(fn () => app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-revoked-owner'))->toThrow(AuthorizationException::class);
    } finally {
        Payment::flushEventListeners();
    }

    expect($injected)->toBeTrue();
    expect($payment->order->fresh()->status->value)->toBe('expired');
    Http::assertSentCount(2);
});

test('unrelated applied cancellation history is never rewritten during recovery', function (string $mode) {
    [$payment, $owner] = timelyPaymentIncident();
    $request = OrderRequest::factory()->for($payment->order)->applied()->create(['decision_mode' => $mode,
        'submitted_at' => '2026-10-28 03:10:00', 'applied_at' => '2026-10-28 03:39:00', 'reviewed_at' => '2026-10-28 03:39:00']);
    $before = $request->fresh()->getRawOriginal();

    expect(app(RecoverTimelyPayment::class)->recover($payment->id, $owner, 'private-unrelated-cancellation'))->toBeFalse();

    expect($request->fresh()->getRawOriginal())->toBe($before);
    expect($payment->order->fresh()->status->value)->toBe('expired');
    Http::assertSentCount(2);
})->with(['manual', 'automatic']);
