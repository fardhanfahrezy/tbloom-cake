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
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-unpaid-closure-key']);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:40:00', 'UTC'));
    Http::preventStrayRequests();
});

function neverRequestedPayment(): Payment
{
    $order = Order::factory()->create(['product_total' => 100001, 'created_at' => '2026-10-28 03:00:00', 'payment_expires_at' => '2026-10-28 03:30:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);

    return Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number,
        'created_at' => '2026-10-28 03:00:00', 'updated_at' => '2026-10-28 03:00:00']);
}

test('a never requested payment expires with internal proof release and customer notice in one commit', function () {
    $payment = neverRequestedPayment();
    User::factory()->admin()->create();

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();

    $current = $payment->fresh();
    expect($current->status->value)->toBe('expired');
    expect($current->closure_result->value)->toBe('unpaid_closed');
    expect($current->closure_verified_at->toDateTimeString())->toBe('2026-10-28 03:40:00');
    expect($current->provider_status)->toBeNull();
    expect($current->snap_requested_at)->toBeNull();
    expect($current->order->status->value)->toBe('expired');
    expect($current->order->item->quota_release_reason->value)->toBe('expired');
    expect($current->order->item->quota_released_at->toDateTimeString())->toBe('2026-10-28 03:40:00');
    expect($current->closureEvent->payload_redacted['verification_source'])->toBe('application_no_session');
    expect($current->closureEvent->processing_status->value)->toBe('processed');
    expect(AuditLog::query()->sole()->actor_type->value)->toBe('system');
    expect(NotificationDelivery::query()->pluck('template_key')->unique()->all())->toBe(['customer.order_expired.v1']);
    $this->assertDatabaseCount('notification_deliveries', 2);
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertNothingSent();
});

test('never requested automatic cancellation applies without H2 or manual approval before deadline', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:10:00', 'UTC'));
    $payment = neverRequestedPayment();
    $payment->order->forceFill(['pickup_date' => '2026-10-29'])->save();
    $request = OrderRequest::factory()->for($payment->order)->automaticCancellation()->create();
    User::factory()->admin()->create();

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();

    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    expect($payment->fresh()->status->value)->toBe('cancelled');
    expect($payment->order->item->fresh()->quota_release_reason->value)->toBe('cancelled');
    expect($request->fresh()->status->value)->toBe('applied');
    expect($request->fresh()->decision_reason)->toBe('unpaid_closed');
    expect($request->fresh()->reviewed_by)->toBeNull();
    expect($request->fresh()->applied_at)->not->toBeNull();
    expect(NotificationDelivery::query()->where('template_key', 'admin.cancellation.v1')->count())->toBe(2);
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertNothingSent();
});

test('a never requested payment remains held before its deadline without cancellation', function () {
    $payment = neverRequestedPayment();
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:29:59', 'UTC'));

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_events', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('automatic unpaid closure runs outside manual hours and is idempotent', function () {
    $payment = neverRequestedPayment();
    $this->travelTo(CarbonImmutable::parse('2026-10-28 18:00:00', 'UTC'));
    app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id);
    $released = $payment->order->item->fresh()->quota_released_at->toDateTimeString();
    $this->travel(1)->minutes();

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();

    expect($payment->order->item->fresh()->quota_released_at->toDateTimeString())->toBe($released);
    $this->assertDatabaseCount('payment_events', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('any evidence of a session or payment prevents the no session shortcut', function (string $field, mixed $value) {
    $payment = neverRequestedPayment();
    $payment->forceFill([$field => $value])->save();

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->closure_result)->toBeNull();
    $this->assertDatabaseCount('notification_deliveries', 0);
    Http::assertNothingSent();
})->with([
    ['snap_requested_at', '2026-10-28 03:00:01'], ['snap_token', 'synthetic-token'],
    ['provider_transaction_id', '123e4567-e89b-42d3-a456-426614174000'], ['provider_status', 'expire'],
    ['provider_paid_at', '2026-10-28 03:20:00'], ['verified_at', '2026-10-28 03:35:00'],
    ['review_reason', 'snap_creation_unknown'], ['is_late', false], ['status', 'paid'], ['provider', 'unsupported'], ['fraud_status', 'accept'],
]);

test('a committed creation audit cannot be erased by a missing request marker', function () {
    $payment = neverRequestedPayment();
    AuditLog::factory()->create(['entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => 'payment.snap_requested']);

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_events', 0);
});

test('associated or reference matched inbox evidence prevents automatic no session closure', function (bool $unmatched) {
    $payment = neverRequestedPayment();
    PaymentEvent::factory()->create(['payment_id' => $unmatched ? null : $payment->id, 'provider' => 'midtrans',
        'provider_reference' => $payment->merchant_reference, 'verified_at' => now()]);

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->closure_event_id)->toBeNull();
})->with([false, true]);

test('reconciled closure can establish the first transaction binding from authenticated matching evidence', function () {
    $payment = issuedUnpaidPayment();
    $payment->forceFill(['provider_transaction_id' => null])->save();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeTrue();

    expect($payment->fresh()->provider_transaction_id)->toBe('123e4567-e89b-42d3-a456-426614174000');
    expect($payment->order->fresh()->status->value)->toBe('expired');
    $this->assertDatabaseCount('payment_movements', 0);
    Http::assertSentCount(1);
});

test('reconciled closure rolls back a racing transaction binding without repeating GET', function () {
    $payment = issuedUnpaidPayment();
    $payment->forceFill(['provider_transaction_id' => null])->save();
    $competitor = neverRequestedPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);
    $other = DB::build(config('database.connections.mysql_testing'));
    $injected = false;
    Payment::saving(function (Payment $current) use ($payment, $competitor, $other, &$injected): void {
        if ($injected || $current->id !== $payment->id || $current->closure_result === null) {
            return;
        }
        $injected = true;
        $other->table('payments')->where('id', $competitor->id)->update(['provider_transaction_id' => $current->provider_transaction_id]);
    });
    try {
        expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();
        expect($injected)->toBeTrue();
        expect($payment->fresh()->provider_transaction_id)->toBeNull();
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        expect($payment->fresh()->closure_result)->toBeNull();
        $this->assertDatabaseCount('payment_events', 0);
        expect(AuditLog::query()->where('action', 'payment.unpaid_closed')->count())->toBe(0);
        Http::assertSentCount(1);
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }
});

test('money history and existing obligations prevent unpaid closure', function (bool $obligation) {
    $payment = neverRequestedPayment();
    if ($obligation) {
        RefundLog::factory()->for($payment)->create();
    } else {
        $event = PaymentEvent::factory()->for($payment)->processed()->create();
        PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id]);
    }

    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('notification_deliveries', 0);
})->with([false, true]);

test('a committed session claim between pre read and locks wins over no session closure', function () {
    $payment = neverRequestedPayment();
    $other = DB::build(config('database.connections.mysql_testing'));
    $injected = false;
    Payment::retrieved(function (Payment $current) use ($payment, $other, &$injected): void {
        if ($injected || $current->id !== $payment->id) {
            return;
        }
        $injected = true;
        $other->table('payments')->where('id', $payment->id)->update(['snap_requested_at' => '2026-10-28 03:29:59', 'review_reason' => 'snap_creation_unknown']);
    });
    try {
        expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeFalse();
        expect($injected)->toBeTrue();
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        $this->assertDatabaseCount('payment_events', 0);
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }
});

test('competing no session closures commit one release proof audit and outbox', function () {
    $payment = neverRequestedPayment();
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $competed = false;
    Payment::retrieved(function (Payment $current) use ($payment, $other, $original, &$competed): void {
        if ($competed || $current->id !== $payment->id) {
            return;
        }
        $competed = true;
        DB::setDefaultConnection($other->getName());
        try {
            expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();
        expect($competed)->toBeTrue();
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }
});

test('outbox failure rolls back final closure proof release order payment and cancellation together', function () {
    $payment = neverRequestedPayment();
    $request = OrderRequest::factory()->for($payment->order)->automaticCancellation()->create();
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic closure outbox failure'));
    try {
        expect(fn () => app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toThrow(RuntimeException::class);
        expect($payment->order->fresh()->status->value)->toBe('pending');
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        expect($payment->fresh()->closure_event_id)->toBeNull();
        expect($request->fresh()->status->value)->toBe('pending');
        foreach (['payment_events', 'audit_logs', 'notification_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('DB concurrency retry rolls back partial closure effects and commits once', function () {
    $payment = neverRequestedPayment();
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic closure retry'");
        }
    });
    try {
        expect(app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id))->toBeTrue();
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
        Sleep::assertSleptTimes(1);
        Http::assertNothingSent();
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('caller transactions cannot hide an uncommitted no session closure', function () {
    $payment = neverRequestedPayment();

    expect(fn () => DB::transaction(fn () => app(CloseUnpaidPayment::class)->closeNeverRequested($payment->id)))->toThrow(LogicException::class);

    $this->assertDatabaseCount('payment_events', 0);
});

function issuedUnpaidPayment(): Payment
{
    $payment = neverRequestedPayment();
    $payment->forceFill(['snap_requested_at' => '2026-10-28 03:00:01', 'snap_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'provider_transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'review_reason' => 'closure_verification_required'])->save();
    AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => $payment->order->user_id,
        'entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => 'payment.snap_requested', 'created_at' => '2026-10-28 03:00:01']);

    return $payment;
}

function finalUnpaidEvidence(string $time = '2026-10-28 03:39:00'): ReconciledUnpaidClosure
{
    return ReconciledUnpaidClosure::fromFinalReconciliation('123e4567-e89b-42d3-a456-426614174000', CarbonImmutable::parse($time, 'UTC'), 'private-finality-no-pending-credit-case');
}

/** @return array<string, mixed> */
function unpaidStatus(Payment $payment, array $changes = []): array
{
    return array_replace(['order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '407', 'transaction_status' => 'expire', 'gross_amount' => '100001.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-28 10:00:02', 'expiry_time' => '2026-10-28 10:30:00'], $changes);
}

function fakeUnpaidStatus(Payment $payment, array $changes = []): void
{
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(unpaidStatus($payment, $changes))]);
}

test('reconciled issued instrument closes only with active Owner attestation and authenticated matching observation', function () {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeTrue();

    $current = $payment->fresh();
    expect($current->closure_result->value)->toBe('unpaid_closed');
    expect($current->order->status->value)->toBe('expired');
    expect($current->order->item->quota_released_at)->not->toBeNull();
    expect($current->provider_status)->toBe('expire');
    expect($current->snap_requested_at->toDateTimeString())->toBe('2026-10-28 03:00:01');
    expect($current->snap_token)->toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    expect($current->review_reason)->toBeNull();
    $payload = $current->closureEvent->payload_redacted;
    expect($payload['verification_source'])->toBe('owner_final_reconciliation');
    expect($payload['owner_id'])->toBe($owner->id);
    expect($payload['evidence_sha256'])->toBe(hash('sha256', 'private-finality-no-pending-credit-case'));
    expect(json_encode($payload))->not->toContain('private-finality-no-pending-credit-case', 'synthetic-unpaid-closure-key', 'aaaaaaaa-aaaa');
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('notification_deliveries', 2);
    Http::assertSentCount(1);
});

test('reconciled closure is an exact idempotent replay preserving its provenance and release time', function () {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);
    $evidence = finalUnpaidEvidence();
    app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, $evidence);
    $released = $payment->order->item->fresh()->quota_released_at->toDateTimeString();
    $this->travel(1)->minutes();

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, $evidence))->toBeTrue();

    expect($payment->order->item->fresh()->quota_released_at->toDateTimeString())->toBe($released);
    $this->assertDatabaseCount('payment_events', 1);
    expect(AuditLog::query()->where('action', 'payment.unpaid_closed')->count())->toBe(1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('reconciled closure applies a pending automatic cancellation without a refund obligation', function () {
    $payment = issuedUnpaidPayment();
    $request = OrderRequest::factory()->for($payment->order)->automaticCancellation()->create();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeTrue();

    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    expect($request->fresh()->status->value)->toBe('applied');
    expect($request->fresh()->decision_reason)->toBe('unpaid_closed');
    $this->assertDatabaseCount('refund_logs', 0);
});

test('reconciled closure requires the actual authenticated active Owner', function (string $kind) {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    if ($kind === 'foreign') {
        $this->actingAs(User::factory()->create());
    } elseif ($kind === 'admin') {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);
    } elseif ($kind === 'inactive') {
        $owner->forceFill(['is_active' => false])->save();
        $this->actingAs($owner);
    }

    expect(fn () => app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toThrow(AuthorizationException::class);

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    Http::assertNothingSent();
})->with(['guest', 'foreign', 'admin', 'inactive']);

test('reconciled closure never treats pending success conflict or ambiguous return as unpaid final', function (array $changes) {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment, $changes);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->closure_result)->toBeNull();
})->with([
    [['status_code' => '201', 'transaction_status' => 'pending']],
    [['status_code' => '200', 'transaction_status' => 'settlement', 'settlement_time' => '2026-10-28 10:29:59']],
    [['gross_amount' => '90000.00']], [['currency' => 'USD']], [['transaction_status' => 'refund']],
]);

test('reconciled closure cannot use a single not found or timeout response as final proof', function (bool $timeout) {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => $timeout ? Http::failedConnection() : Http::response([], 404)]);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    Http::assertSentCount(1);
})->with([false, true]);

test('reconciled closure refuses unresolved associated or unmatched inbox evidence', function (string $state, bool $unmatched) {
    $payment = issuedUnpaidPayment();
    PaymentEvent::factory()->create(['payment_id' => $unmatched ? null : $payment->id, 'provider' => 'midtrans',
        'provider_reference' => $payment->merchant_reference, 'verified_at' => now(), 'processing_status' => $state,
        'claim_token' => $state === 'processing' ? 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' : null,
        'lease_until' => $state === 'processing' ? now()->addMinute() : null]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([['pending', false], ['processing', false], ['review', false], ['pending', true]]);

test('reconciled closure may follow processed non monetary status observations', function () {
    $payment = issuedUnpaidPayment();
    PaymentEvent::factory()->for($payment)->processed()->create(['payload_redacted' => ['transaction_status' => 'expire']]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeTrue();

    expect($payment->order->fresh()->status->value)->toBe('expired');
    $this->assertDatabaseCount('payment_events', 2);
});

test('reconciled closure leaves unknown creation or malformed token history held', function (string $condition) {
    $payment = issuedUnpaidPayment();
    if ($condition === 'missing_token') {
        $payment->forceFill(['snap_token' => null])->save();
    } elseif ($condition === 'missing_audit') {
        DB::table('audit_logs')->where('entity_id', $payment->id)->delete();
    } else {
        DB::table('payments')->where('id', $payment->id)->update(['snap_token' => 'invalid-ciphertext']);
    }
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with(['missing_token', 'missing_audit', 'malformed_ciphertext']);

test('reconciled closure refuses future stale or out of hours finality attestations', function (string $confirmedAt, string $now) {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence($confirmedAt)))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([
    ['2026-10-28 03:41:00', '2026-10-28 03:40:00'],
    ['2026-10-28 03:00:00', '2026-10-28 03:40:00'],
    ['2026-10-28 03:39:00', '2026-10-28 11:00:00'],
]);

test('reconciled closure rereads Owner authorization after HTTP', function () {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $owner) {
        expect(DB::transactionLevel())->toBe(0);
        User::query()->whereKey($owner->id)->update(['is_active' => false]);

        return Http::response(unpaidStatus($payment));
    }]);

    expect(fn () => app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toThrow(AuthorizationException::class);

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
});

test('reconciled closure loses to timely payment committed on another connection during GET', function () {
    $payment = issuedUnpaidPayment();
    $request = OrderRequest::factory()->for($payment->order)->automaticCancellation()->create();
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $competed = false;
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $event, $owner, $other, $original, &$competed) {
        if ($competed) {
            return Http::response(unpaidStatus($payment, ['status_code' => '200', 'transaction_status' => 'settlement', 'settlement_time' => '2026-10-28 10:29:59']));
        }
        $competed = true;
        DB::setDefaultConnection($other->getName());
        try {
            $time = ReconciledPaymentTime::fromReconciliation('123e4567-e89b-42d3-a456-426614174000',
                CarbonImmutable::parse('2026-10-28 03:29:58', 'UTC'), 'private-timely-credit-evidence');
            expect(app(ProcessPaymentEvent::class)->process($event->id, $owner, $time))->toBeTrue();
        } finally {
            DB::setDefaultConnection($original);
        }

        return Http::response(unpaidStatus($payment));
    }]);
    try {
        expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();
        expect($payment->order->fresh()->status->value)->toBe('paid');
        expect($request->fresh()->status->value)->toBe('rejected');
        expect($request->fresh()->decision_reason)->toBe('payment_won');
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        expect($payment->fresh()->closure_result)->toBeNull();
        $this->assertDatabaseCount('payment_movements', 1);
        expect(AuditLog::query()->where('action', 'payment.unpaid_closed')->count())->toBe(0);
        Http::assertSentCount(2);
    } finally {
        $other->disconnect();
    }
});

test('reconciled closure rolls back its proof and release if manual outbox fails', function () {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic manual closure failure'));
    try {
        expect(fn () => app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toThrow(RuntimeException::class);
        expect($payment->order->fresh()->status->value)->toBe('pending');
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        expect($payment->fresh()->closure_result)->toBeNull();
        $this->assertDatabaseCount('payment_events', 0);
        expect(AuditLog::query()->where('action', 'payment.unpaid_closed')->count())->toBe(0);
        Http::assertSentCount(1);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('reconciled closure DB retry never repeats provider GET or duplicate proof', function () {
    $payment = issuedUnpaidPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic manual closure retry'");
        }
    });
    try {
        expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeTrue();
        $this->assertDatabaseCount('payment_events', 1);
        expect(AuditLog::query()->where('action', 'payment.unpaid_closed')->count())->toBe(1);
        $this->assertDatabaseCount('notification_deliveries', 2);
        Http::assertSentCount(1);
        Sleep::assertSleptTimes(1);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('a non final expired observation yields to verified receipt timing review', function () {
    $payment = issuedUnpaidPayment();
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);
    fakeUnpaidStatus($payment, ['status_code' => '200', 'transaction_status' => 'settlement', 'settlement_time' => '2026-10-28 10:29:59']);

    app(ProcessPaymentEvent::class)->process($event->id);

    expect($payment->fresh()->review_reason)->toBe('payment_time_unverified');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_movements', 1);
});

test('reconciled closure refuses monetary history including a zero correction or substantive incident', function (string $condition) {
    $payment = issuedUnpaidPayment();
    if ($condition === 'incident') {
        $payment->forceFill(['review_reason' => 'ledger_identity_conflict'])->save();
    } else {
        $event = PaymentEvent::factory()->for($payment)->processed()->create();
        $movement = PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id]);
        if ($condition === 'corrected_zero') {
            PaymentMovementCorrection::factory()->for($movement, 'movement')->create(['effective_amount' => 0]);
        }
    }
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->closure_event_id)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
})->with(['receipt', 'corrected_zero', 'incident']);

test('reconciled closure cannot replace an existing obligation or unresolved bank attempt', function (bool $unknown) {
    $payment = issuedUnpaidPayment();
    $refund = RefundLog::factory()->for($payment)->processing()->create();
    $factory = RefundTransfer::factory()->for($refund, 'refundLog');
    $transfer = ($unknown ? $factory->unknown() : $factory)->create(['amount' => 60000]);
    $before = $refund->fresh()->getRawOriginal();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeUnpaidStatus($payment);

    expect(app(CloseUnpaidPayment::class)->closeReconciled($payment->id, $owner, finalUnpaidEvidence()))->toBeFalse();

    expect($refund->fresh()->getRawOriginal())->toBe($before);
    expect($transfer->fresh()->status->value)->toBe($unknown ? 'unknown' : 'prepared');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([false, true]);
