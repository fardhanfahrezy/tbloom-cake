<?php

use App\CancelPaidOrder;
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
use App\ProcessPaymentEventJob;
use App\ReconciledPaymentTime;
use App\ReconcilePayments;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
});

function paidCancellationPayment(int $total = 100001, int $quantity = 1): Payment
{
    $order = Order::factory()->paid()->create(['pickup_date' => '2026-10-31', 'product_total' => $total,
        'created_at' => '2026-10-28 03:00:00', 'paid_at' => '2026-10-28 03:09:59']);
    OrderItem::factory()->for($order)->create(['quantity' => $quantity, 'unit_price' => intdiv($total, $quantity), 'line_total' => $total]);
    $payment = Payment::factory()->paid()->for($order)->create(['merchant_reference' => $order->order_number,
        'provider_transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'provider_paid_at' => '2026-10-28 03:09:59', 'created_at' => '2026-10-28 03:00:00']);
    $event = PaymentEvent::factory()->for($payment)->processed()->create(['verified_at' => now()]);
    PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id, 'amount' => $total,
        'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$payment->provider_transaction_id]), 'occurred_at' => '2026-10-28 03:10:00']);
    $owner = User::factory()->owner()->create();
    AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => $owner->id, 'entity_type' => 'payment', 'entity_id' => $payment->id,
        'action' => 'payment.time_attested', 'after_redacted' => ['event_id' => $event->id, 'paid_at' => '2026-10-28T03:09:59+00:00', 'evidence_sha256' => hash('sha256', 'synthetic-timing-proof')]]);

    return $payment;
}

test('customer cancellation approval commits gross80 release request audit and outbox without transferring funds', function () {
    Http::preventStrayRequests();
    $payment = paidCancellationPayment();
    $order = $payment->order;
    $request = OrderRequest::factory()->for($order)->create(['submitted_at' => '2026-10-29 03:00:00']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/staff/cancellation-requests/'.$request->id.'/approval', ['reason' => 'Pembatalan disetujui', 'amount' => 1, 'percentage' => 100])
        ->assertOk()->assertJsonPath('data.amount', 80000)->assertJsonPath('data.percentage', 80);

    $refund = RefundLog::query()->sole();
    expect($refund->basis_amount)->toBe(100001);
    expect($refund->status->value)->toBe('pending');
    expect($refund->assigned_admin_id)->toBeNull();
    expect($refund->account_number)->toBeNull();
    expect($order->fresh()->status->value)->toBe('cancelled');
    expect($order->item->fresh()->quota_release_reason->value)->toBe('cancelled');
    expect($request->fresh()->status->value)->toBe('applied');
    expect($request->fresh()->reviewed_by)->toBe($admin->id);
    expect($request->fresh()->applied_at->equalTo($order->fresh()->cancelled_at))->toBeTrue();
    expect($payment->fresh()->status->value)->toBe('paid');
    $this->assertDatabaseCount('refund_transfers', 0);
    $this->assertDatabaseCount('payment_movements', 1);
    expect(NotificationDelivery::count())->toBeGreaterThan(0);
    Http::assertNothingSent();
});

test('store cancellation after H2 creates gross100 and rejects a pending customer request without applying its80', function () {
    Http::preventStrayRequests();
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create(['submitted_at' => '2026-10-29 03:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-31 03:00:00', 'UTC'));

    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/orders/'.$payment->order_id.'/cancellation', ['reason' => 'Toko tidak dapat memenuhi pesanan'])
        ->assertOk()->assertJsonPath('data.amount', 100001)->assertJsonPath('data.percentage', 100);

    expect(RefundLog::query()->sole()->reason_type->value)->toBe('store_cancel');
    expect($request->fresh()->status->value)->toBe('rejected');
    expect($request->fresh()->decision_reason)->toBe('superseded_by_store_cancellation');
    expect($request->fresh()->applied_at)->toBeNull();
    expect($payment->order->fresh()->status->value)->toBe('cancelled');
    $this->assertDatabaseCount('refund_transfers', 0);
    Http::assertNothingSent();
});

test('gross80 is floored once over the whole order and supports the maximum money domain', function (int $total, int $quantity, int $expected) {
    $payment = paidCancellationPayment($total, $quantity);
    $request = OrderRequest::factory()->for($payment->order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $result = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Pembatalan disetujui');

    expect($result?->amount)->toBe($expected);
    expect($result?->basis_amount)->toBe($total);
})->with([[300003, 3, 240002], [PHP_INT_MAX, 1, 7378697629483820645]]);

test('eligible original submission survives late review and exact replay cannot create another obligation', function () {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    $admin = User::factory()->admin()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 03:00:00', 'UTC'));
    $this->actingAs($admin);
    $first = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui');
    $effects = [AuditLog::count(), NotificationDelivery::count()];

    $again = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui');

    expect($again?->id)->toBe($first?->id);
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($effects);
    $this->assertDatabaseCount('refund_logs', 1);
    expect(app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Ubah menjadi100'))->toBeNull();
    expect(RefundLog::query()->sole()->amount)->toBe(80000);
});

test('unresolved financial proof prevents approval without changing request allocation or obligation', function (string $case) {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    if ($case === 'unsupported_return') {
        PaymentMovement::factory()->reversal()->create(['payment_id' => $payment->id, 'source_event_id' => $payment->events()->sole()->id]);
    } elseif ($case === 'inbox') {
        PaymentEvent::factory()->for($payment)->create();
    } elseif ($case === 'review') {
        $payment->forceFill(['review_reason' => 'canonical_fact_correction_review'])->save();
    } elseif ($case === 'correction') {
        PaymentMovementCorrection::factory()->for($payment->movements()->sole(), 'movement')->create();
    } elseif ($case === 'missing_time_audit') {
        DB::table('audit_logs')->where('action', 'payment.time_attested')->delete();
    } elseif ($case === 'wrong_key') {
        DB::table('payment_movements')->where('payment_id', $payment->id)->update(['canonical_key' => 'test:v1:unproven-receipt']);
    }
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui'))->toBeNull();

    expect($request->fresh()->status->value)->toBe('pending');
    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
})->with(['unsupported_return', 'inbox', 'review', 'correction', 'missing_time_audit', 'wrong_key']);

test('a finalized paid cancellation does not start automatic reconciliation cycles or poison its legitimate obligation', function () {
    $payment = paidCancellationPayment();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    expect(app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Toko batal'))->not->toBeNull();
    $count = PaymentEvent::count();
    Queue::fake([ProcessPaymentEventJob::class]);

    expect(app(ReconcilePayments::class)->dispatch())->toBe(0);
    expect(app(ReconcilePayments::class)->prepare($payment->id))->toBeNull();

    expect(PaymentEvent::count())->toBe($count);
    expect($payment->fresh()->reconciliation_attempts)->toBe(0);
    expect($payment->fresh()->review_reason)->toBeNull();
    Queue::assertNothingPushed();
});

test('an original receipt review followed by a later real Owner attestation remains valid cancellation evidence', function () {
    $order = Order::factory()->create(['pickup_date' => '2026-10-31', 'product_total' => 100001, 'created_at' => '2026-10-28 03:00:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
    $payment = Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number]);
    $owner = User::factory()->owner()->create();
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-paid-cancel-key']);
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response([
        'order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100001.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-28 10:00:01', 'settlement_time' => '2026-10-28 10:10:00',
    ])]);
    $first = PaymentEvent::factory()->for($payment)->create(['verified_at' => now()]);
    app(ProcessPaymentEvent::class)->process($first->id);
    expect($first->fresh()->error)->toBe('payment_time_unverified');
    $second = PaymentEvent::factory()->for($payment)->create(['verified_at' => now()]);
    $this->actingAs($owner);
    app(ProcessPaymentEvent::class)->process($second->id, $owner, ReconciledPaymentTime::fromReconciliation(
        '123e4567-e89b-42d3-a456-426614174000', CarbonImmutable::parse('2026-10-28 03:09:59', 'UTC'), 'private-timing-proof',
    ));
    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->movements()->sole()->source_event_id)->toBe($first->id);
    $request = OrderRequest::factory()->for($order)->create();
    $this->actingAs($owner);

    expect(app(CancelPaidOrder::class)->approve($request->id, $owner, 'Disetujui')?->amount)->toBe(80000);

    Http::assertSentCount(2);
});

test('the scanner still includes a finalized cancellation with a current review or missing finalization audit', function (string $case) {
    $payment = paidCancellationPayment();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    expect(app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Toko batal'))->not->toBeNull();
    if ($case === 'review') {
        $payment->forceFill(['review_reason' => 'unsupported_gateway_return'])->save();
    } else {
        DB::table('audit_logs')->where('action', 'order.paid_cancelled')->delete();
    }
    Queue::fake([ProcessPaymentEventJob::class]);

    expect(app(ReconcilePayments::class)->dispatch())->toBe(1);

    Queue::assertPushed(ProcessPaymentEventJob::class, 1);
})->with(['review', 'unproven']);

test('finalized cancellation is excluded before the batch limit so another due unpaid order closes', function () {
    $payment = paidCancellationPayment();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    expect(app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Toko batal'))->not->toBeNull();
    $pending = Order::factory()->create(['created_at' => '2026-10-29 02:00:00', 'product_total' => 100001]);
    OrderItem::factory()->for($pending)->create(['unit_price' => 100001, 'line_total' => 100001]);
    Payment::factory()->for($pending)->create(['merchant_reference' => $pending->order_number]);

    expect(app(ReconcilePayments::class)->dispatch(1))->toBe(0);

    expect($pending->fresh()->status->value)->toBe('expired');
});

test('zero entitlement completes without a fabricated transfer or a refund-pending notice', function () {
    $payment = paidCancellationPayment(1);
    $request = OrderRequest::factory()->for($payment->order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $refund = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui');

    expect($refund?->amount)->toBe(0);
    expect($refund?->status->value)->toBe('completed');
    expect($refund?->completed_by)->toBe($admin->id);
    $this->assertDatabaseCount('refund_transfers', 0);
    expect(NotificationDelivery::query()->where('template_key', 'customer.refund_pending.v1')->count())->toBe(0);
});

test('store cancellation of maximum supported gross does not truncate or subtract fees', function () {
    $payment = paidCancellationPayment(PHP_INT_MAX);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Toko batal')?->amount)->toBe(PHP_INT_MAX);
});

test('whole cancellation rolls back order allocation request obligation audit and notices together', function (string $mode) {
    $payment = paidCancellationPayment();
    $factory = OrderRequest::factory()->for($payment->order);
    $request = $mode === 'store_change' ? $factory->change()->create() : $factory->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $auditCount = AuditLog::count();
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic paid cancellation outbox failure'));
    try {
        expect(fn () => $mode === 'customer'
            ? app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui')
            : app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Toko batal'))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }

    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($request->fresh()->status->value)->toBe('pending');
    expect(AuditLog::count())->toBe($auditCount);
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
})->with(['customer', 'store_change', 'store_cancel']);

test('bounded retry replays the entire cancellation without duplicate release obligation or audit', function () {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic paid cancellation retry'");
        }
    });
    try {
        expect(app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui')?->amount)->toBe(80000);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    $this->assertDatabaseCount('refund_logs', 1);
    expect(AuditLog::query()->where('action', 'order.paid_cancelled')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'cancellation.applied')->count())->toBe(1);
    expect($request->fresh()->applied_at->equalTo($payment->order->item->fresh()->quota_released_at))->toBeTrue();
    Sleep::assertSleptTimes(1);
});

test('a competing committed paid cancellation wins without converting its original80 or100 obligation', function (bool $customerWins) {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    Payment::retrieved(function (Payment $current) use ($payment, $request, $admin, $other, $original, $customerWins, &$injected): void {
        if ($injected || $current->id !== $payment->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            $winner = $customerWins ? app(CancelPaidOrder::class)->approve($request->id, $admin, 'Pemenang')
                : app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Pemenang');
            expect($winner?->amount)->toBe($customerWins ? 80000 : 100001);
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        $loser = $customerWins ? app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Keputusan lain')
            : app(CancelPaidOrder::class)->approve($request->id, $admin, 'Keputusan lain');
        expect($loser)->toBeNull();
    } finally {
        Payment::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect(RefundLog::query()->sole()->amount)->toBe($customerWins ? 80000 : 100001);
    expect(AuditLog::query()->where('action', 'order.paid_cancelled')->count())->toBe(1);
})->with([true, false]);

test('cancellation replay preserves later unknown or completed refund private and financial history', function (string $state) {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $refund = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui');
    $refund->forceFill(['status' => 'processing', 'assigned_admin_id' => $admin->id, 'assignment_version' => 1,
        'bank_name' => '[TEST] Bank', 'account_number' => '0012345678', 'account_holder' => '[TEST] Receiver', 'bank_verified_at' => now(), 'bank_verified_by' => $admin->id])->save();
    $transfer = ($state === 'unknown' ? RefundTransfer::factory()->unknown() : RefundTransfer::factory()->succeeded())->for($refund)->create();
    if ($state === 'completed') {
        PaymentMovement::factory()->manualRefund()->create(['refund_transfer_id' => $transfer->id]);
        $refund->forceFill(['status' => 'completed', 'completed_by' => $admin->id, 'completed_at' => now()])->save();
        $payment->forceFill(['status' => 'partially_refunded'])->save();
    }
    $beforeRefund = $refund->fresh()->getRawOriginal();
    $beforeTransfer = $transfer->fresh()->getRawOriginal();
    $counts = [AuditLog::count(), NotificationDelivery::count(), PaymentMovement::count()];

    expect(app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui')?->id)->toBe($refund->id);

    expect($refund->fresh()->getRawOriginal())->toBe($beforeRefund);
    expect($transfer->fresh()->getRawOriginal())->toBe($beforeTransfer);
    expect([AuditLog::count(), NotificationDelivery::count(), PaymentMovement::count()])->toBe($counts);
    $this->assertDatabaseCount('refund_logs', 1);
})->with(['unknown', 'completed']);

test('decision endpoints enforce guest role activity and a mandatory reason before financial mutation', function () {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    $this->postJson('/staff/cancellation-requests/'.$request->id.'/approval', ['reason' => 'Disetujui'])->assertUnauthorized();
    $this->actingAs($payment->order->user)->postJson('/staff/orders/'.$payment->order_id.'/cancellation', ['reason' => 'Batal'])->assertForbidden();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/staff/cancellation-requests/'.$request->id.'/approval', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $admin->forceFill(['is_active' => false])->save();
    $this->actingAs($admin)->postJson('/staff/orders/'.$payment->order_id.'/cancellation', ['reason' => 'Batal'])->assertUnauthorized();
    $this->assertDatabaseCount('refund_logs', 0);
});

test('paid cancellation rereads inactive demoted or mismatched staff and manual hours', function (string $state) {
    $payment = paidCancellationPayment();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    if ($state === 'inactive') {
        DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);
    } elseif ($state === 'demoted') {
        DB::table('users')->where('id', $admin->id)->update(['role' => 'customer']);
    } elseif ($state === 'mismatched') {
        $this->actingAs(User::factory()->owner()->create());
    } else {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:01', 'UTC'));
    }

    expect(fn () => app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Batal'))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('refund_logs', 0);
})->with(['inactive', 'demoted', 'mismatched', 'outside_hours']);

test('paid cancellation checks the manual window after its last domain lock', function () {
    $payment = paidCancellationPayment();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    OrderItem::retrieved(function () {
        if (DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:01', 'UTC'));
        }
    });
    try {
        expect(fn () => app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Batal'))->toThrow(AuthorizationException::class);
    } finally {
        OrderItem::flushEventListeners();
    }
    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
});

test('store cancellation exact replay acknowledges the original effect while changed reason conflicts', function () {
    $payment = paidCancellationPayment();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/staff/orders/'.$payment->order_id.'/cancellation', ['reason' => 'Toko batal'])->assertOk();
    $refund = RefundLog::query()->sole();
    $counts = [AuditLog::count(), NotificationDelivery::count()];

    $this->postJson('/staff/orders/'.$payment->order_id.'/cancellation', ['reason' => 'Toko batal'])->assertOk()->assertJsonPath('data.id', $refund->id);
    $this->postJson('/staff/orders/'.$payment->order_id.'/cancellation', ['reason' => 'Alasan berbeda'])->assertConflict();

    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
    $this->assertDatabaseCount('refund_logs', 1);
});

test('approval cannot apply a different request kind ineligible submission or terminal order', function (string $state) {
    $payment = paidCancellationPayment();
    $factory = OrderRequest::factory()->for($payment->order);
    $request = match ($state) {
        'change' => $factory->change()->create(),
        'automatic' => $factory->automaticCancellation()->create(),
        'applied' => $factory->applied()->create(),
        'late_submission' => $factory->create(['submitted_at' => '2026-10-30 03:00:00']),
        default => $factory->create(),
    };
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-31 03:00:00', 'UTC'));
    if ($state === 'completed') {
        $payment->order->forceFill(['status' => 'completed', 'completed_at' => now(), 'handed_over_by' => $admin->id])->save();
    }
    $before = $request->fresh()->getRawOriginal();

    expect(app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui'))->toBeNull();

    expect($request->fresh()->getRawOriginal())->toBe($before);
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
})->with(['change', 'automatic', 'applied', 'late_submission', 'completed']);

test('mismatched source time audit or unbound reference inbox keeps financial application frozen', function (string $state) {
    $payment = paidCancellationPayment();
    $request = OrderRequest::factory()->for($payment->order)->create();
    if ($state === 'wrong_source') {
        $payment->events()->sole()->forceFill(['provider_reference' => 'other-payment-reference'])->save();
    } elseif ($state === 'wrong_time_audit') {
        DB::table('audit_logs')->where('action', 'payment.time_attested')->update(['after_redacted' => json_encode([
            'event_id' => $payment->events()->sole()->id, 'paid_at' => '2026-10-28T03:05:00+00:00',
        ], JSON_THROW_ON_ERROR)]);
    } else {
        PaymentEvent::factory()->create(['payment_id' => null, 'provider_reference' => $payment->merchant_reference]);
    }
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui'))->toBeNull();

    expect($request->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('refund_logs', 0);
})->with(['wrong_source', 'wrong_time_audit', 'unbound_inbox']);

test('store cancellation supersedes a manual change but freezes an inconsistent automatic pending request on paid', function (bool $automatic) {
    $payment = paidCancellationPayment();
    $factory = OrderRequest::factory()->for($payment->order);
    $request = $automatic ? $factory->automaticCancellation()->create() : $factory->change()->create();
    $original = $request->requested_changes;
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $result = app(CancelPaidOrder::class)->byStore($payment->order_id, $admin, 'Toko batal');

    if ($automatic) {
        expect($result)->toBeNull();
        expect($request->fresh()->status->value)->toBe('pending');
        $this->assertDatabaseCount('refund_logs', 0);
    } else {
        expect($result?->amount)->toBe(100001);
        expect($request->fresh()->decision_reason)->toBe('superseded_by_store_cancellation');
        expect($request->fresh()->requested_changes)->toBe($original);
        expect($request->fresh()->applied_at)->toBeNull();
    }
})->with([true, false]);
