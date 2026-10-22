<?php

use App\Models\Payment;
use App\Models\PaymentClosureResult;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores a durable inbox event with a related bill and redacted fixture payload', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
    $payment = Payment::factory()->create();
    $event = PaymentEvent::factory()->for($payment)->create()->fresh();

    expect($event->payment->is($payment))->toBeTrue();
    expect($payment->events->sole()->is($event))->toBeTrue();
    expect($event->provider_reference)->toBe($payment->merchant_reference);
    expect($event->payload_redacted)->toBe(['fixture' => 'synthetic', 'transaction_status' => 'pending']);
    expect($event->received_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 00:00:00');
    expect($event->attempts)->toBe(0);
    expect($event->verified_at)->toBeNull();
});

test('links a closure event only to its own payment', function () {
    $payment = Payment::factory()->create();
    $event = PaymentEvent::factory()->for($payment)->create();
    $payment->forceFill([
        'closure_result' => PaymentClosureResult::UnpaidClosed,
        'closure_verified_at' => now(),
        'closure_event_id' => $event->id,
    ])->save();

    expect($payment->fresh()->closureEvent->is($event))->toBeTrue();
});

test('rejects an unsupported closure result even with matching evidence', function () {
    $event = PaymentEvent::factory()->create();

    try {
        DB::table('payments')->where('id', $event->payment_id)->update([
            'closure_result' => 'paid', 'closure_verified_at' => '2026-11-01 00:31:00', 'closure_event_id' => $event->id,
        ]);
        $this->fail('MySQL accepted an unsupported closure result.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
});

test('rejects closure evidence from another payment an unmatched event or a missing event', function (string $kind) {
    $payment = Payment::factory()->create();
    $eventId = match ($kind) {
        'other payment' => PaymentEvent::factory()->create()->id,
        'unmatched' => PaymentEvent::factory()->create(['payment_id' => null])->id,
        'missing' => 0,
    };

    try {
        DB::table('payments')->where('id', $payment->id)->update([
            'closure_result' => 'unpaid_closed', 'closure_verified_at' => '2026-11-01 00:31:00', 'closure_event_id' => $eventId,
        ]);
        $this->fail('MySQL accepted closure evidence outside this payment.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
})->with(['other payment', 'unmatched', 'missing']);

test('builds claimed processed and unmatched inbox fixtures with typed states', function () {
    $this->freezeTime();
    $claimed = PaymentEvent::factory()->claimed()->create()->fresh();
    $processed = PaymentEvent::factory()->processed()->create()->fresh();
    $unmatched = PaymentEvent::factory()->unmatched()->create()->fresh();

    expect($claimed->processing_status)->toBe(PaymentEventStatus::Processing);
    expect($claimed->claim_token)->not->toBeNull();
    expect($claimed->lease_until)->not->toBeNull();
    expect($processed->processing_status)->toBe(PaymentEventStatus::Processed);
    expect($processed->verified_at)->not->toBeNull();
    expect($processed->processed_at)->not->toBeNull();
    expect($unmatched->processing_status)->toBe(PaymentEventStatus::Review);
    expect($unmatched->payment)->toBeNull();
});

test('rejects invalid inbox processing and lease values in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(PaymentEvent::factory()->raw(), ['created_at' => '2026-11-01 00:00:00'], $overrides);
    if (is_array($attributes['payload_redacted'])) {
        $attributes['payload_redacted'] = json_encode($attributes['payload_redacted'], JSON_THROW_ON_ERROR);
    }

    try {
        DB::table('payment_events')->insert($attributes);
        $this->fail('MySQL accepted invalid inbox values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'unknown processing state' => [['processing_status' => 'paid'], 3819],
    'processing case mismatch' => [['processing_status' => 'PENDING'], 3819],
    'negative attempts' => [['attempts' => -1], 1264],
    'attempt overflow' => [['attempts' => 4294967296], 1264],
    'claim without lease' => [['claim_token' => '00000000-0000-0000-0000-000000000001'], 3819],
    'lease without claim' => [['lease_until' => '2026-11-01 00:05:00'], 3819],
    'processed without processed time' => [['processing_status' => 'processed', 'verified_at' => '2026-11-01 00:00:00'], 3819],
    'processed without verification' => [['processing_status' => 'processed', 'processed_at' => '2026-11-01 00:00:00'], 3819],
    'processed without payment' => [['processing_status' => 'processed', 'verified_at' => '2026-11-01 00:00:00', 'processed_at' => '2026-11-01 00:00:00', 'payment_id' => null], 3819],
    'missing payment' => [['payment_id' => 0], 1452],
    'null payload' => [['payload_redacted' => null], 1048],
    'malformed JSON' => [['payload_redacted' => '{broken'], 3140],
    'null reference' => [['provider_reference' => null], 1048],
    'null received time' => [['received_at' => null], 1048],
    'null creation time' => [['created_at' => null], 1048],
]);

test('deduplicates event fingerprints per provider while allowing repeated reference observations', function () {
    $event = PaymentEvent::factory()->create();
    PaymentEvent::factory()->for($event->payment)->create();
    PaymentEvent::factory()->create(['provider' => 'test-provider', 'event_fingerprint' => $event->event_fingerprint]);
    $this->assertDatabaseCount('payment_events', 3);

    try {
        PaymentEvent::factory()->create(['provider' => $event->provider, 'event_fingerprint' => $event->event_fingerprint]);
        $this->fail('MySQL accepted a duplicate transport fingerprint.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('keeps unmatched references durable without creating a payment', function () {
    $event = PaymentEvent::factory()->unmatched()->create();

    $this->assertDatabaseCount('payments', 0);
    $this->assertModelExists($event);
    expect($event->payment_id)->toBeNull();
});

test('hides inbox payload lease token and error while retaining CT-only updates', function () {
    $event = PaymentEvent::factory()->claimed()->create();
    $createdAt = $event->created_at->toDateTimeString();
    $event->forceFill(['error' => '[TEST] synthetic failure summary'])->save();

    expect($event->fresh()->created_at->toDateTimeString())->toBe($createdAt);
    expect(array_intersect(['payload_redacted', 'claim_token', 'error'], array_keys($event->fresh()->toArray())))->toBe([]);
});

test('restricts deleting or relinking closure evidence', function (string $operation) {
    $payment = Payment::factory()->create();
    $event = PaymentEvent::factory()->for($payment)->create();
    $payment->forceFill(['closure_result' => 'unpaid_closed', 'closure_verified_at' => now(), 'closure_event_id' => $event->id])->save();
    $other = Payment::factory()->create();

    try {
        $target = DB::table('payment_events')->where('id', $event->id);
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['payment_id' => $other->id]);
        }
        $this->fail('MySQL changed referenced closure evidence.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($event);
})->with(['delete', 'relink']);

test('restricts deleting and renumbering a payment referenced by an event', function (string $operation) {
    $event = PaymentEvent::factory()->create();
    try {
        $parent = DB::table('payments')->where('id', $event->payment_id);
        if ($operation === 'delete') {
            $parent->delete();
        } else {
            $parent->update(['id' => $event->payment_id + 1000000]);
        }
        $this->fail('MySQL changed a referenced inbox payment.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
})->with(['delete', 'update']);

test('refuses rollback that would discard inbox history', function () {
    $event = PaymentEvent::factory()->create();
    $migration = require database_path('migrations/2026_10_22_020009_create_payment_events_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($event);
});

test('refuses closure rollback before any DDL and preserves evidence ownership', function () {
    $event = PaymentEvent::factory()->create();
    $other = Payment::factory()->create();
    $migration = require database_path('migrations/2026_10_22_021723_add_payment_closure_event_foreign_key.php');
    Schema::shouldReceive('table')->never();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');

    try {
        DB::table('payments')->where('id', $other->id)->update([
            'closure_result' => 'unpaid_closed', 'closure_verified_at' => '2026-11-01 00:31:00', 'closure_event_id' => $event->id,
        ]);
        $this->fail('Closure ownership protection was removed after refused rollback.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
});
