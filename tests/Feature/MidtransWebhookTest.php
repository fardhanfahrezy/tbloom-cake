<?php

use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\ProcessPaymentEventJob;
use App\ReceiveMidtransNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['services.midtrans.server_key' => 'synthetic-webhook-key', 'services.midtrans.is_production' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 03:00:00', 'UTC'));
    Http::preventStrayRequests();
    Queue::fake();
});

/** @return array<string, mixed> */
function signedNotification(array $changes = []): array
{
    $payload = array_replace([
        'order_id' => 'TB-SYNTHETIC-123', 'status_code' => '200', 'gross_amount' => '400000.00',
        'transaction_id' => '123e4567-e89b-42d3-a456-426614174000', 'transaction_status' => 'settlement',
        'currency' => 'IDR', 'payment_type' => 'bank_transfer', 'fraud_status' => 'accept',
        'transaction_time' => '2026-10-26 10:00:00', 'settlement_time' => '2026-10-26 10:05:00',
        'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789012']],
        'customer_details' => ['email' => 'private@example.test'], 'custom_field1' => 'private-note',
    ], $changes);
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'synthetic-webhook-key');

    return $payload;
}

test('acknowledges only a durable redacted notification without applying payment effects', function () {
    $payment = Payment::factory()->create(['merchant_reference' => 'TB-SYNTHETIC-123', 'expected_amount' => 400000]);
    OrderItem::factory()->for($payment->order)->create();

    $this->postJson('/webhooks/midtrans', signedNotification())->assertOk()->assertExactJson(['received' => true]);

    $event = PaymentEvent::query()->sole();
    expect($event->payment_id)->toBe($payment->id);
    expect($event->processing_status->value)->toBe('pending');
    expect($event->attempts)->toBe(0);
    expect($event->received_at->format('Y-m-d H:i:s'))->toBe('2026-10-26 03:00:00');
    expect($event->payload_redacted['gross_amount'])->toBe('400000.00');
    expect(json_encode($event->payload_redacted))->not->toContain('signature_key', 'synthetic-webhook-key', '123456789012', 'private@example.test', 'private-note');
    expect($payment->fresh()->status->value)->toBe('pending');
    expect($payment->order->fresh()->status->value)->toBe('pending');
    expect($payment->order->item->fresh()->quota_released_at)->toBeNull();
    foreach (['payment_movements', 'refund_logs', 'audit_logs', 'notification_deliveries'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $other = DB::build(config('database.connections.mysql_testing'));
    expect($other->table('payment_events')->where('id', $event->id)->exists())->toBeTrue();
    $other->disconnect();
    Http::assertNothingSent();
    Queue::assertPushed(ProcessPaymentEventJob::class, fn ($job) => $job->eventId === $event->id);
});

test('unknown and case mismatched references are retained for review with the same acknowledgement', function (string $reference) {
    Payment::factory()->create(['merchant_reference' => 'TB-SYNTHETIC-123']);
    $this->postJson('/webhooks/midtrans', signedNotification(['order_id' => $reference]))->assertOk()->assertExactJson(['received' => true]);
    $event = PaymentEvent::query()->sole();
    expect($event->payment_id)->toBeNull();
    expect($event->processing_status->value)->toBe('review');
    expect($event->error)->toBe('unknown_reference');
})->with(['UNKNOWN-123', 'tb-synthetic-123']);

test('duplicates with reordered JSON and extra PII preserve original inbox state', function () {
    Payment::factory()->create(['merchant_reference' => 'TB-SYNTHETIC-123']);
    $payload = signedNotification();
    $this->postJson('/webhooks/midtrans', $payload)->assertOk();
    $event = PaymentEvent::query()->sole();
    $event->forceFill(['attempts' => 4, 'processing_status' => 'review', 'error' => 'retry_exhausted'])->save();
    $this->travel(10)->minutes();
    $payload['customer_details'] = ['email' => 'different@example.test'];
    $this->postJson('/webhooks/midtrans', array_reverse($payload, true))->assertOk();
    $this->assertDatabaseCount('payment_events', 1);
    $event->refresh();
    expect($event->attempts)->toBe(4);
    expect($event->error)->toBe('retry_exhausted');
    expect($event->received_at->format('Y-m-d H:i:s'))->toBe('2026-10-26 03:00:00');
});

test('distinct status evidence remains distinct without deciding order state', function () {
    $payment = Payment::factory()->create(['merchant_reference' => 'TB-SYNTHETIC-123']);
    $this->postJson('/webhooks/midtrans', signedNotification())->assertOk();
    $this->postJson('/webhooks/midtrans', signedNotification(['transaction_status' => 'pending', 'status_code' => '201']))->assertOk();
    $this->assertDatabaseCount('payment_events', 2);
    expect($payment->fresh()->status->value)->toBe('pending');
});

test('validly signed mismatches remain evidence without payment changes', function (array $changes) {
    $payment = Payment::factory()->create(['merchant_reference' => 'TB-SYNTHETIC-123', 'expected_amount' => 400000]);
    $this->postJson('/webhooks/midtrans', signedNotification($changes))->assertOk();
    expect(PaymentEvent::query()->sole()->payload_redacted)->toMatchArray($changes);
    expect($payment->fresh()->status->value)->toBe('pending');
})->with([
    'amount' => [['gross_amount' => '399999.00']], 'currency' => [['currency' => 'USD']],
    'transaction' => [['transaction_id' => '223e4567-e89b-42d3-a456-426614174000']],
]);

test('rejects forged notifications and preserves raw amount for signature verification', function (string $change) {
    $payload = signedNotification();
    if ($change === 'signature') {
        $payload['signature_key'] = str_repeat('0', 128);
    } else {
        $payload['gross_amount'] = '400000';
    }
    $this->postJson('/webhooks/midtrans', $payload)->assertForbidden()->assertJsonPath('message', 'Invalid notification signature.');
    $this->assertDatabaseCount('payment_events', 0);
})->with(['signature', 'amount-representation']);

test('rejects invalid signature inputs without retaining payloads', function (string $field, mixed $value) {
    $payload = signedNotification();
    $payload[$field] = $value;
    $this->postJson('/webhooks/midtrans', $payload)->assertUnprocessable();
    $this->assertDatabaseCount('payment_events', 0);
})->with([
    'no signature' => ['signature_key', null], 'numeric amount' => ['gross_amount', 400000],
    'exponent' => ['gross_amount', '4e5'], 'array reference' => ['order_id', []],
    'oversized reference' => ['order_id', str_repeat('x', 129)], 'reference whitespace' => ['order_id', 'order '],
    'numeric code' => ['status_code', 200], 'nested status' => ['transaction_status', []],
]);

test('does not acknowledge a failed durable insert', function () {
    $this->withoutExceptionHandling();
    PaymentEvent::creating(fn () => throw new RuntimeException('synthetic storage failure'));
    try {
        expect(fn () => $this->postJson('/webhooks/midtrans', signedNotification()))->toThrow(RuntimeException::class, 'synthetic storage failure');
        $this->assertDatabaseCount('payment_events', 0);
    } finally {
        PaymentEvent::flushEventListeners();
    }
});

test('returns unavailable when its verification key is missing', function () {
    config(['services.midtrans.server_key' => null]);
    $this->postJson('/webhooks/midtrans', signedNotification())->assertServiceUnavailable();
    $this->assertDatabaseCount('payment_events', 0);
});

test('rejects malformed non JSON and oversized requests', function (string $body, string $contentType, int $status) {
    $this->call('POST', '/webhooks/midtrans', [], [], [], ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'], $body)->assertStatus($status);
    $this->assertDatabaseCount('payment_events', 0);
})->with([
    'broken JSON' => ['{"order_id":', 'application/json', 422], 'list' => ['[]', 'application/json', 422],
    'form' => ['order_id=foo', 'application/x-www-form-urlencoded', 415],
    'oversized' => [str_repeat('x', 65537), 'application/json', 413],
]);

test('exempts only the webhook from real CSRF validation and still verifies its signature', function () {
    app()->instance('env', 'local');
    try {
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/webhooks/midtrans', signedNotification())->assertOk();
        $this->postJson('/login', ['email' => 'example@example.test', 'password' => 'synthetic'])->assertStatus(419);
        $payload = signedNotification();
        $payload['signature_key'] = str_repeat('0', 128);
        $this->postJson('/webhooks/midtrans', $payload)->assertForbidden();
    } finally {
        app()->instance('env', 'testing');
    }
    $this->assertDatabaseCount('payment_events', 1);
});

test('independent simultaneous receivers converge on the same durable inbox event', function () {
    Payment::factory()->create(['merchant_reference' => 'TB-SYNTHETIC-123']);
    $second = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $contended = false;
    $otherEvent = null;
    PaymentEvent::creating(function () use ($second, $original, &$contended, &$otherEvent) {
        if ($contended) {
            return;
        }
        $contended = true;
        DB::setDefaultConnection($second->getName());
        try {
            $otherEvent = app(ReceiveMidtransNotification::class)->receive(json_encode(signedNotification(), JSON_THROW_ON_ERROR));
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        $event = app(ReceiveMidtransNotification::class)->receive(json_encode(signedNotification(), JSON_THROW_ON_ERROR));
        expect($event->id)->toBe($otherEvent->id);
        $this->assertDatabaseCount('payment_events', 1);
        expect(DB::transactionLevel())->toBe(0);
    } finally {
        PaymentEvent::flushEventListeners();
        $second->disconnect();
    }
});

test('rejects caller transactions instead of acknowledging an uncommitted inbox', function () {
    DB::beginTransaction();
    try {
        expect(fn () => app(ReceiveMidtransNotification::class)->receive(json_encode(signedNotification(), JSON_THROW_ON_ERROR)))
            ->toThrow(LogicException::class, 'Durable inbox requires MySQL and no caller transaction.');
    } finally {
        DB::rollBack();
    }
    $this->assertDatabaseCount('payment_events', 0);
});

test('refuses dirty read acknowledgements when another receiver later rolls back', function () {
    $inbox = app(ReceiveMidtransNotification::class);
    $body = json_encode(signedNotification(), JSON_THROW_ON_ERROR);
    $seed = $inbox->receive($body);
    $attributes = $seed->getAttributes();
    DB::table('payment_events')->where('id', $seed->id)->delete();
    $other = DB::build(config('database.connections.mysql_testing'));
    try {
        $other->beginTransaction();
        $other->table('payment_events')->insert($attributes);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        expect(fn () => $inbox->receive($body))->toThrow(LogicException::class, 'Durable inbox requires READ COMMITTED and UTC.');
    } finally {
        $other->rollBack();
        $other->disconnect();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    $this->assertDatabaseCount('payment_events', 0);
});

test('refuses inbox ingestion in an unsafe timezone', function () {
    try {
        DB::statement("SET SESSION time_zone = '+07:00'");
        expect(fn () => app(ReceiveMidtransNotification::class)->receive(json_encode(signedNotification(), JSON_THROW_ON_ERROR)))
            ->toThrow(LogicException::class, 'Durable inbox requires READ COMMITTED and UTC.');
    } finally {
        DB::statement("SET SESSION time_zone = '+00:00'");
    }
    $this->assertDatabaseCount('payment_events', 0);
});

test('receives without touching session storage or setting browser cookies', function () {
    config(['session.driver' => 'unsupported-webhook-session-driver']);

    $this->postJson('/webhooks/midtrans', signedNotification())->assertOk()->assertExactJson(['received' => true])
        ->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');

    $this->assertDatabaseCount('payment_events', 1);
});
