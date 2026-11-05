<?php

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\PaymentEventInbox;
use App\PaymentReviewReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 03:00:00', 'UTC'));
});

function verifiedInboxEvent(array $attributes = []): PaymentEvent
{
    return PaymentEvent::factory()->create($attributes + ['verified_at' => now()]);
}

function syntheticInboxEffect(PaymentEvent $event): void
{
    $payment = Payment::query()->whereKey($event->payment_id)->lockForUpdate()->firstOrFail();
    $payment->forceFill(['provider_status' => 'synthetic-applied'])->save();
    $audit = new AuditLog;
    $audit->forceFill(['actor_type' => 'system', 'entity_type' => 'payment', 'entity_id' => $payment->id,
        'action' => 'test.inbox_effect', 'correlation_id' => (string) Str::uuid()])->save();
}

test('claims only due verified events with a committed fresh fencing token', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);

    expect($claim->processing_status->value)->toBe('processing');
    expect($claim->attempts)->toBe(1);
    expect(Str::isUuid($claim->claim_token))->toBeTrue();
    expect($claim->lease_until->format('Y-m-d H:i:s'))->toBe('2026-10-26 03:02:00');
    $other = DB::build(config('database.connections.mysql_testing'));
    expect($other->table('payment_events')->where('id', $event->id)->value('claim_token'))->toBe($claim->claim_token);
    $other->disconnect();
    expect($inbox->claim($event->id))->toBeNull();
});

test('commits effects and processed atomically then makes replay a no op', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    expect($inbox->complete($claim, syntheticInboxEffect(...)))->toBeTrue();
    expect($inbox->complete($claim, syntheticInboxEffect(...)))->toBeFalse();
    expect($event->fresh()->processing_status->value)->toBe('processed');
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($event->fresh()->claim_token)->toBeNull();
    expect($event->payment->fresh()->provider_status)->toBe('synthetic-applied');
    $this->assertDatabaseCount('audit_logs', 1);
});

test('rolls back all effects on crash before completion and can resume the same live claim', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    expect(fn () => $inbox->complete($claim, function (PaymentEvent $event) {
        syntheticInboxEffect($event);
        throw new RuntimeException('synthetic crash');
    }))->toThrow(RuntimeException::class, 'synthetic crash');
    expect($event->fresh()->processing_status->value)->toBe('processing');
    expect($event->payment->fresh()->provider_status)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
    expect($inbox->complete($claim, syntheticInboxEffect(...)))->toBeTrue();
});

test('reclaims only at lease expiry and fences every operation by the old worker', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $old = $inbox->claim($event->id);
    $this->travelTo($old->lease_until->subSecond());
    expect($inbox->claim($event->id))->toBeNull();
    $this->travelTo($old->lease_until);
    $current = $inbox->claim($event->id);
    expect($current->claim_token)->not->toBe($old->claim_token);
    expect($current->attempts)->toBe(2);
    expect($inbox->complete($old, syntheticInboxEffect(...)))->toBeFalse();
    expect($inbox->retry($old, 'provider_unavailable'))->toBeFalse();
    expect($event->fresh()->claim_token)->toBe($current->claim_token);
    expect($inbox->complete($current, syntheticInboxEffect(...)))->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 1);
});

test('rolls back domain writes when an independent worker reclaims during processing', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $old = $inbox->claim($event->id);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    try {
        $completed = $inbox->complete($old, function (PaymentEvent $event) use ($other, $original, $inbox, $old) {
            syntheticInboxEffect($event);
            $this->travelTo($old->lease_until);
            DB::setDefaultConnection($other->getName());
            try {
                expect($inbox->claim($event->id)->attempts)->toBe(2);
            } finally {
                DB::setDefaultConnection($original);
            }
        });
        expect($completed)->toBeFalse();
        expect($event->fresh()->attempts)->toBe(2);
        expect($event->payment->fresh()->provider_status)->toBeNull();
        $this->assertDatabaseCount('audit_logs', 0);
    } finally {
        $other->disconnect();
    }
});

test('lease expiry during a transaction rolls back effects even without reclaim', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    expect($inbox->complete($claim, function (PaymentEvent $event) use ($claim) {
        syntheticInboxEffect($event);
        $this->travelTo($claim->lease_until);
    }))->toBeFalse();
    expect($event->fresh()->processing_status->value)->toBe('processing');
    expect($event->payment->fresh()->provider_status)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('bounds retries with durable due times and manual review after the fifth failure', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    foreach ([30, 120, 600, 1800] as $index => $delay) {
        $claim = $inbox->claim($event->id);
        expect($claim->attempts)->toBe($index + 1);
        $expectedDue = now()->addSeconds($delay)->format('Y-m-d H:i:s');
        expect($inbox->retry($claim, 'provider_unavailable'))->toBeTrue();
        $event->refresh();
        expect($event->processing_status->value)->toBe('pending');
        expect($event->next_attempt_at->format('Y-m-d H:i:s'))->toBe($expectedDue);
        expect($event->claim_token)->toBeNull();
        $this->travelTo($event->next_attempt_at->subSecond());
        expect($inbox->claim($event->id))->toBeNull();
        $this->travelTo($event->next_attempt_at);
    }
    $last = $inbox->claim($event->id);
    expect($last->attempts)->toBe(5);
    expect($inbox->retry($last, 'provider_unavailable'))->toBeTrue();
    $event->refresh();
    expect($event->processing_status->value)->toBe('review');
    expect($event->error)->toBe('retry_exhausted');
    expect($event->next_attempt_at)->toBeNull();
    expect($inbox->claim($event->id))->toBeNull();
    expect($event->payment->fresh()->status->value)->toBe('pending');
});

test('counts crashed claims toward exhaustion without requiring a worker failure handler', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $claim = $inbox->claim($event->id);
        expect($claim->attempts)->toBe($attempt);
        $this->travelTo($claim->lease_until);
    }
    expect($inbox->claim($event->id))->toBeNull();
    expect($event->fresh()->processing_status->value)->toBe('review');
    expect($event->fresh()->error)->toBe('retry_exhausted');
    expect($event->fresh()->claim_token)->toBeNull();
});

test('never claims terminal review failed or processed events', function (string $state) {
    $event = verifiedInboxEvent(['processing_status' => $state, 'processed_at' => $state === 'processed' ? now() : null]);
    expect(app(PaymentEventInbox::class)->claim($event->id))->toBeNull();
    expect($event->fresh()->attempts)->toBe(0);
})->with(['review', 'failed', 'processed']);

test('refuses unverified events instead of promoting synthetic transport into evidence', function () {
    $event = PaymentEvent::factory()->create();
    expect(app(PaymentEventInbox::class)->claim($event->id))->toBeNull();
    expect($event->fresh()->processing_status->value)->toBe('review');
    expect($event->fresh()->error)->toBe('unverified_event');
});

test('rejects arbitrary retry error text without persisting secrets', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    expect(fn () => $inbox->retry($claim, 'secret account payload'))->toThrow(InvalidArgumentException::class);
    expect($event->fresh()->error)->toBeNull();
});

test('rejects nested transactions to preserve claim durability', function () {
    DB::beginTransaction();
    try {
        expect(fn () => app(PaymentEventInbox::class)->claim(1))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
});

test('commits domain effects and a typed manual review outcome atomically', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    expect($inbox->complete($claim, function (PaymentEvent $event): PaymentReviewReason {
        syntheticInboxEffect($event);

        return PaymentReviewReason::AmountAnomaly;
    }))->toBeTrue();
    expect($event->fresh()->processing_status->value)->toBe('review');
    expect($event->fresh()->error)->toBe('amount_anomaly');
    expect($event->fresh()->processed_at)->toBeNull();
    expect($event->fresh()->claim_token)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 1);
});

test('rejects an untyped completion outcome and rolls back effects instead of persisting secrets', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    expect(fn () => $inbox->complete($claim, function (PaymentEvent $event): string {
        syntheticInboxEffect($event);

        return 'private bank data';
    }))->toThrow(LogicException::class);
    $this->assertDatabaseCount('audit_logs', 0);
    expect($event->fresh()->processing_status->value)->toBe('processing');
});

test('concurrent completion with the same claim commits effects once', function () {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    try {
        $result = $inbox->complete($claim, function (PaymentEvent $event) use ($inbox, $claim, $other, $original) {
            DB::setDefaultConnection($other->getName());
            try {
                expect($inbox->complete($claim, syntheticInboxEffect(...)))->toBeTrue();
            } finally {
                DB::setDefaultConnection($original);
            }
            syntheticInboxEffect($event);
        });
        expect($result)->toBeFalse();
        expect($event->fresh()->processing_status->value)->toBe('processed');
        $this->assertDatabaseCount('audit_logs', 1);
    } finally {
        $other->disconnect();
    }
});

test('bounds real MySQL lock timeouts without consuming a claim or duplicating effects', function () {
    $event = verifiedInboxEvent();
    $first = DB::connection();
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    Sleep::fake();
    try {
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $first->beginTransaction();
        PaymentEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
        DB::setDefaultConnection($other->getName());
        expect(fn () => app(PaymentEventInbox::class)->claim($event->id))->toThrow(QueryException::class);
        expect($other->transactionLevel())->toBe(0);
        Sleep::assertSleptTimes(2);
    } finally {
        DB::setDefaultConnection($original);
        $first->rollBack();
        $other->disconnect();
        Sleep::fake(false);
    }
    expect($event->fresh()->attempts)->toBe(0);
    expect($event->fresh()->claim_token)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('fails closed for an unsafe session isolation or timezone', function (string $setting) {
    try {
        DB::statement($setting);
        expect(fn () => app(PaymentEventInbox::class)->claim(1))->toThrow(LogicException::class, 'Inbox processing requires READ COMMITTED and UTC.');
    } finally {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        DB::statement("SET SESSION time_zone = '+00:00'");
    }
})->with(['SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', "SET SESSION time_zone = '+07:00'"]);

test('retries the whole completion after a database concurrency error following partial effects', function (int $errorCode) {
    $event = verifiedInboxEvent();
    $inbox = app(PaymentEventInbox::class);
    $claim = $inbox->claim($event->id);
    $calls = 0;
    Sleep::fake();
    try {
        expect($inbox->complete($claim, function (PaymentEvent $event) use (&$calls, $errorCode) {
            syntheticInboxEffect($event);
            $calls++;
            if ($calls === 1) {
                DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = ".$errorCode.", MESSAGE_TEXT = 'synthetic concurrency failure'");
            }
        }))->toBeTrue();
        expect($calls)->toBe(2);
        Sleep::assertSleptTimes(1);
        expect($event->fresh()->processing_status->value)->toBe('processed');
        expect($event->fresh()->attempts)->toBe(1);
        $this->assertDatabaseCount('audit_logs', 1);
    } finally {
        Sleep::fake(false);
    }
})->with([1205, 1213]);
