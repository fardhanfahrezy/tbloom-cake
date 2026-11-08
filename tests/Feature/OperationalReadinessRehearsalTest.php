<?php

use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

test('ops:status command reports redacted operational alert counts without leaking secrets', function () {
    $paymentInReview = Payment::factory()->create([
        'review_reason' => 'amount_mismatch',
        'snap_token' => 'SNAP-SECRET-DO-NOT-PRINT',
    ]);

    PaymentEvent::factory()->for($paymentInReview)->create([
        'processing_status' => 'review',
    ]);
    PaymentEvent::factory()->for($paymentInReview)->create([
        'processing_status' => 'failed',
    ]);

    $refundInReview = RefundLog::factory()->assigned()->verifiedBank()->review()->create([
        'account_number' => '998877665544',
    ]);
    RefundTransfer::factory()->unknown()->create([
        'refund_log_id' => $refundInReview->id,
        'payment_id' => $refundInReview->payment_id,
        'account_number' => '998877665544',
    ]);

    NotificationDelivery::factory()->failed()->create();
    NotificationDelivery::factory()->unknown()->create();

    $exitCode = Artisan::call('ops:status');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Payment reviews: 1')
        ->and($output)->toContain('Payment event alerts: 2')
        ->and($output)->toContain('Refund alerts: 2')
        ->and($output)->toContain('Notification alerts: 2')
        ->and($output)->not->toContain('SNAP-SECRET-DO-NOT-PRINT')
        ->and($output)->not->toContain('998877665544');
});

test('scheduled recovery commands execute cleanly in rehearsal without stray external calls', function () {
    Queue::fake();
    config(['notifications.enabled' => true]);

    $this->artisan('payments:dispatch-events', ['--limit' => '10'])->assertExitCode(0);
    $this->artisan('payments:reconcile', ['--limit' => '10'])->assertExitCode(0);
    $this->artisan('notifications:dispatch', ['--limit' => '10'])->assertExitCode(0);
});
