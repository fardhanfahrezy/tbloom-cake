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
use App\PaymentFunds;
use App\PaymentMovementKey;
use App\ResolvePaymentException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-resolution-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:40:00', 'UTC'));
    Http::preventStrayRequests();
});

function exceptionPayment(int $amount = 90000, bool $late = false): Payment
{
    $order = Order::factory()->create(['product_total' => 100001, 'created_at' => '2026-10-27 03:00:00', 'payment_expires_at' => '2026-10-27 03:30:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
    $payment = Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number, 'status' => 'paid',
        'provider_transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'verified_at' => now(),
        'review_reason' => $late ? 'actual_late_payment' : 'amount_anomaly',
        'provider_paid_at' => $late ? '2026-10-27 03:31:00' : null, 'is_late' => $late ? true : null]);
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now()]);
    PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id, 'amount' => $amount,
        'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$payment->provider_transaction_id]),
        'occurred_at' => $late ? '2026-10-27 03:35:00' : '2026-10-27 03:29:59']);

    return $payment;
}

/** @return array<string, mixed> */
function resolutionStatus(Payment $payment, array $changes = []): array
{
    $receipt = $payment->movements()->where('kind', 'receipt')->firstOrFail();

    return array_replace(['order_id' => $payment->merchant_reference, 'transaction_id' => $payment->provider_transaction_id,
        'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => $receipt->amount.'.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-27 10:00:00',
        'settlement_time' => $receipt->occurred_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s')], $changes);
}

function fakeResolutionStatus(Payment $payment, array $changes = []): void
{
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(resolutionStatus($payment, $changes))]);
}

test('Owner resolution creates a gross manual obligation for actual anomalous funds and releases quota once', function (int $amount) {
    $payment = exceptionPayment($amount);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'private-resolution-proof-123'))->toBeTrue();

    $refund = RefundLog::query()->sole();
    expect($refund->amount)->toBe($amount);
    expect($refund->basis_amount)->toBe($amount);
    expect($refund->percentage)->toBe(100);
    expect($refund->reason_type->value)->toBe('amount_anomaly');
    expect($refund->status->value)->toBe('pending');
    expect($refund->bank_verified_at)->toBeNull();
    expect($refund->assigned_admin_id)->toBeNull();
    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_release_reason->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at->toDateTimeString())->toBe('2026-10-27 03:40:00');
    expect($payment->fresh()->review_reason)->toBeNull();
    expect($payment->fresh()->status->value)->toBe('paid');
    $this->assertDatabaseCount('refund_transfers', 0);
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('notification_deliveries', 4);
    expect(AuditLog::query()->sole()->actor_user_id)->toBe($owner->id);
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain('private-resolution-proof-123', 'synthetic-resolution-key');
    Http::assertSentCount(1);
})->with([90000, 110000]);

test('verified actual late resolution creates a full obligation without converting delayed timely delivery', function () {
    $payment = exceptionPayment(100001, true);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'late-proof'))->toBeTrue();

    expect(RefundLog::query()->sole()->amount)->toBe(100001);
    expect(RefundLog::query()->sole()->reason_type->value)->toBe('late_payment');
    expect($payment->fresh()->provider_paid_at->toDateTimeString())->toBe('2026-10-27 03:31:00');
    expect($payment->order->fresh()->paid_at)->toBeNull();
    expect($payment->order->fresh()->status->value)->toBe('expired');
});

test('a pending automatic cancellation resolves cancelled together with the full obligation', function () {
    $payment = exceptionPayment();
    $request = OrderRequest::factory()->for($payment->order)->automaticCancellation()->create();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'cancel-proof');

    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    expect($payment->order->item->fresh()->quota_release_reason->value)->toBe('cancelled');
    expect($request->fresh()->status->value)->toBe('applied');
    expect($request->fresh()->applied_at)->not->toBeNull();
    expect(RefundLog::query()->sole()->percentage)->toBe(100);
});

test('previous verified returns reduce the remaining obligation rather than its gross basis', function () {
    $payment = exceptionPayment();
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $payment->id, 'source_event_id' => $payment->events()->first()->id, 'amount' => 20000]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'return-proof');

    expect(RefundLog::query()->sole()->amount)->toBe(90000);
    $funds = DB::transaction(function () use ($payment) {
        Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        return app(PaymentFunds::class)->snapshot($payment->fresh());
    });
    expect($funds['remaining_obligation'])->toBe(70000);
    expect($payment->fresh()->status->value)->toBe('partially_refunded');
    $this->assertDatabaseCount('payment_movements', 2);
});

test('repeat resolution preserves its release timestamp obligation and outbox', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);
    app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'first-proof');
    $released = $payment->order->item->fresh()->quota_released_at->toDateTimeString();
    $this->travel(1)->minutes();

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'second-proof'))->toBeTrue();

    expect($payment->order->item->fresh()->quota_released_at->toDateTimeString())->toBe($released);
    $this->assertDatabaseCount('refund_logs', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 4);
});

test('resolution requires an actual active authenticated Owner', function (string $kind) {
    $payment = exceptionPayment();
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

    expect(fn () => app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toThrow(AuthorizationException::class);

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertNothingSent();
})->with(['guest', 'foreign', 'admin', 'inactive']);

test('uncertain provider observations never authorize exception closure', function (array $changes) {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment, $changes);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
})->with([
    [['transaction_status' => 'refund']], [['currency' => 'USD']], [['settlement_time' => null]],
    [['gross_amount' => '110000.00']], [['transaction_status' => 'pending', 'status_code' => '201', 'settlement_time' => null]],
]);

test('existing eighty percent obligations and completed history are never rewritten', function (string $status) {
    $payment = exceptionPayment();
    $payment->order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
    $factory = RefundLog::factory()->for($payment);
    $refund = match ($status) {
        'processing' => $factory->processing()->create(),
        'completed' => $factory->completed()->create(),
        default => $factory->create(),
    };
    $before = $refund->fresh()->getRawOriginal();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    expect($refund->fresh()->getRawOriginal())->toBe($before);
    expect($refund->fresh()->amount)->toBe(80000);
    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    $this->assertDatabaseCount('refund_logs', 1);
})->with(['pending', 'processing', 'completed']);

test('correction heads and unrelated incidents keep the allocation held', function (bool $corrected) {
    $payment = exceptionPayment();
    if ($corrected) {
        PaymentMovementCorrection::factory()->for($payment->movements()->sole(), 'movement')->create(['effective_amount' => 90000]);
    } else {
        $payment->forceFill(['review_reason' => 'ledger_identity_conflict'])->save();
    }
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
})->with([false, true]);

test('resolution rereads actor authorization after provider HTTP', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $owner) {
        expect(DB::transactionLevel())->toBe(0);
        User::query()->whereKey($owner->id)->update(['is_active' => false]);

        return Http::response(resolutionStatus($payment));
    }]);

    expect(fn () => app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('refund_logs', 0);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
});

test('resolution is held before deadline or outside manual operating hours', function (string $instant) {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertNothingSent();
})->with(['2026-10-27 03:10:00', '2026-10-27 11:00:00']);

test('failure in refund outbox rolls back release order obligation and audit', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic refund outbox failure'));
    try {
        expect(fn () => app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toThrow(RuntimeException::class);
        expect($payment->order->fresh()->status->value)->toBe('pending');
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        expect($payment->fresh()->review_reason)->toBe('amount_anomaly');
        $this->assertDatabaseCount('refund_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('a nested caller transaction is refused before any provider request', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);

    expect(fn () => DB::transaction(fn () => app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof')))->toThrow(LogicException::class);

    Http::assertNothingSent();
});

test('anomalous funds with contradictory known payment chronology remain held', function (string $paidAt) {
    $payment = exceptionPayment();
    $payment->forceFill(['provider_paid_at' => $paidAt])->save();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment, ['transaction_time' => '2026-10-27 10:01:00']);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    $this->assertDatabaseCount('refund_logs', 0);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->review_reason)->toBe('amount_anomaly');
})->with(['2026-10-27 03:00:00', '2026-10-27 03:31:00']);

test('unresolved bank attempts retain their obligation assignment and reserved amount', function (bool $unknown) {
    $payment = exceptionPayment();
    $refund = RefundLog::factory()->for($payment)->processing()->create();
    $factory = RefundTransfer::factory()->for($refund, 'refundLog');
    $transfer = ($unknown ? $factory->unknown() : $factory)->create(['amount' => 60000]);
    $before = $refund->fresh()->getRawOriginal();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    expect($refund->fresh()->getRawOriginal())->toBe($before);
    expect($transfer->fresh()->status->value)->toBe($unknown ? 'unknown' : 'prepared');
    expect($transfer->fresh()->amount)->toBe(60000);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([false, true]);

test('terminal paid or completed order cannot be expired by exception resolution', function (bool $completed) {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $payment->order->forceFill($completed
        ? ['status' => 'completed', 'paid_at' => now(), 'completed_at' => now(), 'handed_over_by' => $owner->id]
        : ['status' => 'paid', 'paid_at' => now()])->save();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    expect($payment->order->fresh()->status->value)->toBe($completed ? 'completed' : 'paid');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
})->with([false, true]);

test('a competing committed resolution is reread and does not repeat release or notifications', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $competed = false;
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $owner, $other, $original, &$competed) {
        expect(DB::transactionLevel())->toBe(0);
        if (! $competed) {
            $competed = true;
            DB::setDefaultConnection($other->getName());
            try {
                expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'competing-proof'))->toBeTrue();
            } finally {
                DB::setDefaultConnection($original);
            }
        }

        return Http::response(resolutionStatus($payment));
    }]);
    try {
        expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'outer-proof'))->toBeTrue();
        $this->assertDatabaseCount('refund_logs', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('notification_deliveries', 4);
        Http::assertSentCount(2);
    } finally {
        $other->disconnect();
    }
});

test('a DB failure after release and obligation retries the whole transaction without repeating GET', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic resolution retry'");
        }
    });
    try {
        expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeTrue();
        $this->assertDatabaseCount('refund_logs', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('notification_deliveries', 4);
        expect($payment->order->fresh()->status->value)->toBe('expired');
        Http::assertSentCount(1);
        Sleep::assertSleptTimes(1);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
});

test('a late label without verifiable actual late action time cannot create a refund', function (?string $paidAt) {
    $payment = exceptionPayment(100001, true);
    $payment->forceFill(['provider_paid_at' => $paidAt])->save();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    $this->assertDatabaseCount('refund_logs', 0);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([null, '2026-10-27 03:29:59', '2026-10-27 03:30:00', '2026-10-27 03:36:00']);

test('fully returned or excess funds before an obligation stay held for reconciliation', function (int $returned) {
    $payment = exceptionPayment();
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $payment->id, 'source_event_id' => $payment->events()->first()->id, 'amount' => $returned]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    fakeResolutionStatus($payment);

    expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();

    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    $this->assertDatabaseCount('payment_movements', 2);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
})->with([90000, 100000]);

test('context modified on another connection during GET cannot release the original allocation', function () {
    $payment = exceptionPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $other = DB::build(config('database.connections.mysql_testing'));
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $other) {
        $status = resolutionStatus($payment);
        $other->table('payments')->where('id', $payment->id)->update(['expected_amount' => 200000]);

        return Http::response($status);
    }]);
    try {
        expect(app(ResolvePaymentException::class)->resolve($payment->id, $owner, 'proof'))->toBeFalse();
        $this->assertDatabaseCount('refund_logs', 0);
        expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
        Http::assertSentCount(1);
    } finally {
        $other->disconnect();
    }
});
