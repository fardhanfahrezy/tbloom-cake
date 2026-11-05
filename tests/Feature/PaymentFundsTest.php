<?php

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\RefundTransfer;
use App\PaymentFunds;
use Illuminate\Support\Facades\DB;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

function lockedFunds(Payment $payment, ?int $excludingTransferId = null): array
{
    return DB::transaction(function () use ($payment, $excludingTransferId): array {
        $current = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        return app(PaymentFunds::class)->snapshot($current, $excludingTransferId);
    });
}

test('uses only the latest effective correction rather than adding roots and revisions', function () {
    $root = PaymentMovement::factory()->create();
    $first = PaymentMovementCorrection::factory()->for($root, 'movement')->create(['effective_amount' => 90000]);
    PaymentMovementCorrection::factory()->after($first)->create(['effective_amount' => 85000]);
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $root->payment_id, 'source_event_id' => $root->source_event_id, 'amount' => 20000]);

    expect(lockedFunds($root->payment))->toBe(['receipts' => 85000, 'returns' => 20000, 'reserved' => 0,
        'available' => 65000, 'remaining_obligation' => 0, 'excess_returns' => false]);
    expect($root->fresh()->amount)->toBe(100001);
});

test('subtracts one actual return and an unknown reserve from the gross obligation', function () {
    $transfer = RefundTransfer::factory()->unknown()->create(['amount' => 50000]);
    $payment = $transfer->payment;
    $event = PaymentEvent::factory()->for($payment)->processed()->create();
    PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id, 'amount' => 100001]);
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id, 'amount' => 20000]);
    $obligation = $transfer->refundLog;
    $obligation->forceFill(['basis_amount' => 100001, 'percentage' => 80, 'amount' => 80000, 'reason_type' => 'customer_cancel'])->save();

    expect(lockedFunds($payment))->toBe(['receipts' => 100001, 'returns' => 20000, 'reserved' => 50000,
        'available' => 30001, 'remaining_obligation' => 10000, 'excess_returns' => false]);
    expect(lockedFunds($payment, $transfer->id)['remaining_obligation'])->toBe(60000);
    expect($obligation->fresh()->amount)->toBe(80000);
});

test('counts prepared and unknown attempts but never completed or confirmed failed attempts as reserves', function (string $state, int $reserved) {
    $factory = RefundTransfer::factory();
    if ($state === 'succeeded') {
        $factory = $factory->succeeded();
    } elseif ($state === 'failed_confirmed') {
        $factory = $factory->failedConfirmed();
    }
    $transfer = $factory->create(['status' => $state, 'amount' => 20000]);
    expect(lockedFunds($transfer->payment)['reserved'])->toBe($reserved);
})->with([['prepared', 20000], ['unknown', 20000], ['succeeded', 0], ['failed_confirmed', 0]]);

test('voided receipts and corrected returns use their effective amount without fabricating money', function () {
    $receipt = PaymentMovement::factory()->create();
    PaymentMovementCorrection::factory()->for($receipt, 'movement')->voided()->create();
    $return = PaymentMovement::factory()->reversal()->create(['payment_id' => $receipt->payment_id, 'source_event_id' => $receipt->source_event_id]);
    PaymentMovementCorrection::factory()->for($return, 'movement')->create(['effective_amount' => 15000]);

    expect(lockedFunds($receipt->payment))->toBe(['receipts' => 0, 'returns' => 15000, 'reserved' => 0,
        'available' => 0, 'remaining_obligation' => 0, 'excess_returns' => true]);
    $this->assertDatabaseCount('payment_movements', 2);
});

test('isolates payments and cannot exclude a reserve belonging to another payment', function () {
    $own = RefundTransfer::factory()->unknown()->create(['amount' => 20000]);
    $other = RefundTransfer::factory()->unknown()->create(['amount' => 60000]);
    expect(lockedFunds($own->payment, $other->id)['reserved'])->toBe(20000);
});

test('fails closed on aggregate integer overflow rather than using floating money', function () {
    $root = PaymentMovement::factory()->create(['amount' => PHP_INT_MAX]);
    PaymentMovement::factory()->create(['payment_id' => $root->payment_id, 'source_event_id' => $root->source_event_id, 'amount' => 1]);
    expect(fn () => lockedFunds($root->payment))->toThrow(OverflowException::class);
});

test('requires the callers parent lock transaction for financial decisions', function () {
    $payment = Payment::factory()->create();
    expect(fn () => app(PaymentFunds::class)->snapshot($payment))->toThrow(LogicException::class);
});
