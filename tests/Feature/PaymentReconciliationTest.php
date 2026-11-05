<?php

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\ReconcilePayments;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 03:40:00', 'UTC'));
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-manual-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('m', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    Http::preventStrayRequests();
});

function manualReconciliationPayment(): Payment
{
    $order = Order::factory()->create(['product_total' => 100001, 'created_at' => '2026-10-28 03:00:00', 'payment_expires_at' => '2026-10-28 03:30:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
    $payment = Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number, 'created_at' => '2026-10-28 03:00:00',
        'snap_requested_at' => '2026-10-28 03:00:01', 'snap_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'review_reason' => 'closure_verification_required', 'reconciliation_attempts' => 5]);
    AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => $order->user_id, 'entity_type' => 'payment', 'entity_id' => $payment->id,
        'action' => 'payment.snap_requested', 'created_at' => '2026-10-28 03:00:01']);

    return $payment;
}

function manualProviderStatus(Payment $payment, string $status = 'pending'): array
{
    $payload = ['order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => match ($status) {
            'expire' => '407', 'settlement' => '200', default => '201'
        },
        'transaction_status' => $status, 'gross_amount' => '100001.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-28 10:00:02'];
    if ($status === 'settlement') {
        $payload['settlement_time'] = '2026-10-28 10:29:59';
    }

    return $payload;
}

test('Owner may manually recheck after exhaustion without resetting the automatic budget or closing an ambiguous order', function () {
    $payment = manualReconciliationPayment();
    $this->actingAs(User::factory()->owner()->create());
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(manualProviderStatus($payment))]);

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'observe', 'reconciliation_attempts' => 0, 'status' => 'expired'])
        ->assertOk()->assertExactJson(['processed' => true]);

    expect($payment->fresh()->reconciliation_attempts)->toBe(5);
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect(PaymentEvent::query()->sole()->payload_redacted['verification_source'])->toBe('owner_reconciliation');
    Http::assertSentCount(1);
});

test('manual timing attestation applies verified timely funds without accepting browser paid flags', function () {
    $payment = manualReconciliationPayment();
    $this->actingAs(User::factory()->owner()->create());
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(manualProviderStatus($payment, 'settlement'))]);

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'observe',
        'transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'paid_at_utc' => '2026-10-28 03:29:58',
        'evidence_reference' => 'private-timing-evidence-123'])->assertOk()->assertExactJson(['processed' => true]);

    expect($payment->order->fresh()->status->value)->toBe('paid');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->reconciliation_attempts)->toBe(5);
    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('private-timing-evidence-123', 'synthetic-manual-key');
    Http::assertSentCount(1);
});

test('Owner exception endpoint creates a gross obligation from reconciled actual funds without transferring money', function () {
    $payment = manualReconciliationPayment();
    $this->actingAs(User::factory()->owner()->create());
    $payload = manualProviderStatus($payment, 'settlement');
    $payload['gross_amount'] = '90000.00';
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response($payload)]);
    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'observe'])->assertOk();

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'resolve_exception', 'evidence_reference' => 'private-actual-funds-evidence'])
        ->assertOk()->assertExactJson(['processed' => true]);

    $this->assertDatabaseHas('refund_logs', ['payment_id' => $payment->id, 'basis_amount' => 90000, 'amount' => 90000, 'percentage' => 100, 'status' => 'pending']);
    $this->assertDatabaseCount('refund_transfers', 0);
    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    Http::assertSentCount(2);
});

test('explicit Owner finality confirmation invokes guarded unpaid closure', function () {
    $payment = manualReconciliationPayment();
    $this->actingAs(User::factory()->owner()->create());
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(manualProviderStatus($payment, 'expire'))]);

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', ['action' => 'confirm_unpaid',
        'transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'confirmed_at_utc' => '2026-10-28 03:39:00',
        'evidence_reference' => 'private-finality-and-no-pending-credit'])->assertOk()->assertExactJson(['processed' => true]);

    expect($payment->order->fresh()->status->value)->toBe('expired');
    expect($payment->order->item->fresh()->quota_released_at)->not->toBeNull();
    expect($payment->fresh()->closureEvent->payload_redacted['verification_source'])->toBe('owner_final_reconciliation');
    Http::assertSentCount(1);
});

test('manual endpoint rejects unauthenticated and unauthorized roles before payment lookup', function (string $role) {
    if ($role !== 'guest') {
        $user = User::factory()->create(['role' => $role === 'inactive' ? 'owner' : $role, 'is_active' => $role !== 'inactive']);
        $this->actingAs($user);
    }

    $this->postJson('/staff/payments/999999999/reconciliation', ['action' => 'observe'])->assertStatus(in_array($role, ['guest', 'inactive'], true) ? 401 : 403);

    $this->assertDatabaseCount('payment_events', 0);
    Http::assertNothingSent();
})->with(['guest', 'customer', 'admin', 'inactive']);

test('manual endpoint validates evidence without any provider request', function (array $payload, array $errors) {
    $payment = manualReconciliationPayment();
    $this->actingAs(User::factory()->owner()->create());

    $this->postJson('/staff/payments/'.$payment->id.'/reconciliation', $payload)->assertUnprocessable()->assertJsonValidationErrors($errors);

    $this->assertDatabaseCount('payment_events', 0);
    Http::assertNothingSent();
})->with([
    'missing action' => [[], ['action']],
    'missing finality evidence' => [['action' => 'confirm_unpaid'], ['transaction_id', 'confirmed_at_utc', 'evidence_reference']],
    'missing paired time' => [['action' => 'observe', 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000'], ['paid_at_utc', 'evidence_reference']],
    'invalid time' => [['action' => 'observe', 'transaction_id' => 'not-uuid', 'paid_at_utc' => '2026-02-30 03:00:00', 'evidence_reference' => "line\nbreak"], ['transaction_id', 'paid_at_utc', 'evidence_reference']],
]);

test('manual checks are denied outside operating hours before event creation', function (string $time) {
    $payment = manualReconciliationPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $this->travelTo(CarbonImmutable::parse($time, 'UTC'));

    expect(fn () => app(ReconcilePayments::class)->manually($payment->id, $owner))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('payment_events', 0);
    Http::assertNothingSent();
})->with(['2026-10-28 01:59:59', '2026-10-28 10:00:01']);

test('manual authorization and operating window are reread after provider HTTP', function (bool $outsideHours) {
    $payment = manualReconciliationPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    if ($outsideHours) {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'));
    }
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment, $owner, $outsideHours) {
        if ($outsideHours) {
            $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:01', 'UTC'));
        } else {
            User::query()->whereKey($owner->id)->update(['is_active' => false]);
        }

        return Http::response(manualProviderStatus($payment, 'settlement'));
    }]);

    expect(fn () => app(ReconcilePayments::class)->manually($payment->id, $owner))->toThrow(AuthorizationException::class);

    expect($payment->order->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('payment_movements', 0);
    Http::assertSentCount(1);
})->with([false, true]);

test('manual window is checked after domain locks before money effects', function () {
    $payment = manualReconciliationPayment();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'));
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => function () use ($payment) {
        Payment::retrieved(function (Payment $current) use ($payment): void {
            if ($current->id === $payment->id && DB::transactionLevel() > 0) {
                $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:01', 'UTC'));
            }
        });

        return Http::response(manualProviderStatus($payment, 'settlement'));
    }]);
    try {
        expect(fn () => app(ReconcilePayments::class)->manually($payment->id, $owner))->toThrow(AuthorizationException::class);
    } finally {
        Payment::flushEventListeners();
    }

    $this->assertDatabaseCount('payment_movements', 0);
    expect($payment->order->fresh()->status->value)->toBe('pending');
    Http::assertSentCount(1);
});

test('manual observation preserves pristine no session proof before and after deadline', function (bool $expired) {
    $order = Order::factory()->create(['created_at' => '2026-10-28 03:00:00', 'payment_expires_at' => '2026-10-28 03:30:00']);
    OrderItem::factory()->for($order)->create();
    $payment = Payment::factory()->for($order)->create(['created_at' => '2026-10-28 03:00:00']);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    if (! $expired) {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 03:29:59', 'UTC'));
    }
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response(['status_code' => '404'], 404)]);

    expect(app(ReconcilePayments::class)->manually($payment->id, $owner))->toBe($expired);

    expect($order->fresh()->status->value)->toBe($expired ? 'expired' : 'pending');
    expect(PaymentEvent::query()->where('payload_redacted->verification_source', 'owner_reconciliation')->count())->toBe(0);
    if ($expired) {
        expect(PaymentEvent::query()->sole()->payload_redacted['verification_source'])->toBe('application_no_session');
    } else {
        $this->assertDatabaseCount('payment_events', 0);
    }
    Http::assertNothingSent();
})->with([false, true]);
