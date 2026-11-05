<?php

use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use App\NotificationChannel;
use App\NotificationOutbox;
use App\NotificationRecipientIdentity;
use App\NotificationSendResult;
use App\NotificationTransport;
use App\PreparedNotificationTransport;
use App\ProcessNotificationDeliveryJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

test('outbox acknowledges the prepared Fonnte adapter once or preserves an ambiguous send without retry', function ($ambiguous) {
    config(['services.fonnte.token' => 'synthetic-fonnte-token']);
    $row = NotificationDelivery::factory()->create(['template_key' => 'customer.order_created.v1', 'payload_minimal' => ['order_number' => 'TST-123'],
        'recipient_ciphertext' => ['channel' => 'whatsapp', 'address' => '+628111111111'],
        'recipient_key' => app(NotificationRecipientIdentity::class)->key(NotificationChannel::WhatsApp, '+628111111111')]);
    Http::preventStrayRequests();
    $calls = 0;
    Http::fake(['https://api.fonnte.com/send' => function () use ($row, $ambiguous, &$calls) {
        $calls++;
        expect(DB::transactionLevel())->toBe(0);
        expect($row->fresh()->status)->toBe(NotificationDeliveryStatus::Unknown);
        if ($ambiguous) {
            throw new ConnectionException('synthetic-private-token');
        }

        return Http::response(['status' => true, 'id' => ['80367170'], 'target' => ['628111111111']]);
    }]);
    $outbox = app(NotificationOutbox::class);
    $transport = app(PreparedNotificationTransport::class);

    expect($outbox->deliver($row->id, $transport))->toBeTrue();
    expect($row->fresh()->status)->toBe($ambiguous ? NotificationDeliveryStatus::Unknown : NotificationDeliveryStatus::Sent);
    expect($row->fresh()->provider_message_id)->toBe($ambiguous ? null : '80367170');
    expect($row->fresh()->attempts)->toBe(1);
    expect($outbox->deliver($row->id, $transport))->toBeFalse();
    expect($calls)->toBe(1);
})->with([false, true]);

beforeEach(function () {
    config(['notifications.enabled' => true, 'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)),
        'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
});

test('outbox marks the uncertain boundary before external I O and acknowledges one accepted send', function () {
    $delivery = NotificationDelivery::factory()->create();
    $sender = new class implements NotificationTransport
    {
        public function send(NotificationDelivery $delivery): NotificationSendResult
        {
            expect(DB::transactionLevel())->toBe(0);
            expect($delivery->fresh()->status)->toBe(NotificationDeliveryStatus::Unknown);

            return new NotificationSendResult(NotificationDeliveryStatus::Sent, 'synthetic-message-id');
        }
    };
    expect(app(NotificationOutbox::class)->deliver($delivery->id, $sender))->toBeTrue();
    expect($delivery->fresh()->status)->toBe(NotificationDeliveryStatus::Sent);
    expect($delivery->fresh()->attempts)->toBe(1);
    expect($delivery->fresh()->claim_token)->toBeNull();
    expect($delivery->fresh()->provider_message_id)->toBe('synthetic-message-id');
});

test('an ambiguous transport exception remains unknown without copying provider error data', function () {
    $delivery = NotificationDelivery::factory()->create();
    $sender = new class implements NotificationTransport
    {
        public function send(NotificationDelivery $delivery): NotificationSendResult
        {
            throw new RuntimeException('Private recipient or provider credentials must never enter the durable error.');
        }
    };
    expect(app(NotificationOutbox::class)->deliver($delivery->id, $sender))->toBeTrue();
    expect($delivery->fresh()->status)->toBe(NotificationDeliveryStatus::Unknown);
    expect($delivery->fresh()->last_error)->toBe('send_outcome_unknown');
    expect($delivery->fresh()->claim_token)->toBeNull();
});

test('expired pre send claim is reclaimable and the old token cannot start or acknowledge a send', function () {
    $row = NotificationDelivery::factory()->create();
    $outbox = app(NotificationOutbox::class);
    $first = $outbox->claim($row->id);
    expect($outbox->claim($row->id))->toBeNull();
    $this->travel(120)->seconds();
    $second = $outbox->claim($row->id);
    expect($second->claim_token)->not->toBe($first->claim_token);
    expect($second->attempts)->toBe(2);
    expect($outbox->begin($row->id, $first->claim_token))->toBeNull();
    expect($outbox->finish($row->id, $first->claim_token, new NotificationSendResult(NotificationDeliveryStatus::Sent)))->toBeFalse();
    expect($outbox->begin($row->id, $second->claim_token))->not->toBeNull();
    expect($outbox->finish($row->id, $second->claim_token, new NotificationSendResult(NotificationDeliveryStatus::Sent)))->toBeTrue();
});

test('a crash after the uncertain boundary cannot be reclaimed or accepted after lease expiry', function () {
    $row = NotificationDelivery::factory()->create();
    $outbox = app(NotificationOutbox::class);
    $claim = $outbox->claim($row->id);
    $outbox->begin($row->id, $claim->claim_token);
    $this->travel(120)->seconds();
    expect($outbox->claim($row->id))->toBeNull();
    expect($outbox->finish($row->id, $claim->claim_token, new NotificationSendResult(NotificationDeliveryStatus::Sent)))->toBeFalse();
    $outbox->recoverUnknown();
    expect($row->fresh()->status)->toBe(NotificationDeliveryStatus::Unknown);
    expect($row->fresh()->claim_token)->toBeNull();
    expect($row->fresh()->attempts)->toBe(1);
});

test('only a known rejected send is retried with bounded delays and then escalated', function () {
    $row = NotificationDelivery::factory()->create();
    $sender = new class implements NotificationTransport
    {
        public function send(NotificationDelivery $delivery): NotificationSendResult
        {
            return new NotificationSendResult(NotificationDeliveryStatus::Pending);
        }
    };
    $outbox = app(NotificationOutbox::class);
    foreach ([30, 120, 600, 1800] as $index => $delay) {
        expect($outbox->deliver($row->id, $sender))->toBeTrue();
        expect($row->fresh()->attempts)->toBe($index + 1);
        expect($row->fresh()->next_attempt_at->getTimestamp() - now()->getTimestamp())->toBe($delay);
        expect($outbox->claim($row->id))->toBeNull();
        $this->travel($delay)->seconds();
    }
    expect($outbox->deliver($row->id, $sender))->toBeTrue();
    expect($row->fresh()->status)->toBe(NotificationDeliveryStatus::Failed);
    expect($row->fresh()->attempts)->toBe(5);
    expect($row->fresh()->last_error)->toBe('retry_exhausted');
    expect($outbox->deliver($row->id, $sender))->toBeFalse();
});

test('delivery is opt in and terminal rows or future retry dates do not invoke transport', function (string $status) {
    $row = NotificationDelivery::factory()->create(['status' => $status,
        'sent_at' => in_array($status, ['sent', 'delivered'], true) ? now() : null,
        'delivered_at' => $status === 'delivered' ? now() : null]);
    $sender = Mockery::mock(NotificationTransport::class)->shouldNotReceive('send')->getMock();
    expect(app(NotificationOutbox::class)->deliver($row->id, $sender))->toBeFalse();
})->with(['unknown', 'failed', 'suppressed', 'sent', 'delivered']);

test('disabled delivery does not claim a row and duplicate jobs cannot send a completed delivery again', function () {
    $row = NotificationDelivery::factory()->create();
    $sender = new class implements NotificationTransport
    {
        public int $calls = 0;

        public function send(NotificationDelivery $delivery): NotificationSendResult
        {
            $this->calls++;

            return new NotificationSendResult(NotificationDeliveryStatus::Sent);
        }
    };
    config(['notifications.enabled' => false]);
    (new ProcessNotificationDeliveryJob($row->id))->handle(app(NotificationOutbox::class), $sender);
    expect($row->fresh()->attempts)->toBe(0);
    config(['notifications.enabled' => true]);
    (new ProcessNotificationDeliveryJob($row->id))->handle(app(NotificationOutbox::class), $sender);
    (new ProcessNotificationDeliveryJob($row->id))->handle(app(NotificationOutbox::class), $sender);
    expect($sender->calls)->toBe(1);
    expect(serialize(new ProcessNotificationDeliveryJob($row->id)))->not->toContain('synthetic-recipient@example.test', 'recipient_ciphertext');
});

test('transport results never claim delivered and reject invalid provider identifiers', function () {
    expect(fn () => new NotificationSendResult(NotificationDeliveryStatus::Delivered))->toThrow(InvalidArgumentException::class);
    expect(fn () => new NotificationSendResult(NotificationDeliveryStatus::Sent, str_repeat('x', 129)))->toThrow(InvalidArgumentException::class);
    expect(fn () => new NotificationSendResult(NotificationDeliveryStatus::Sent, "bad\nidentifier"))->toThrow(InvalidArgumentException::class);
});

test('nested transactions cannot enclose notification I O', function () {
    $row = NotificationDelivery::factory()->create();
    DB::beginTransaction();
    try {
        expect(fn () => app(NotificationOutbox::class)->claim($row->id))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
    expect($row->fresh()->attempts)->toBe(0);
});

test('an acknowledgement deadlock retries database work without repeating the external call', function () {
    $row = NotificationDelivery::factory()->create();
    $failOnce = true;
    NotificationDelivery::updating(function (NotificationDelivery $delivery) use (&$failOnce): void {
        if ($failOnce && $delivery->status === NotificationDeliveryStatus::Sent) {
            $failOnce = false;
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Synthetic notification acknowledgement deadlock'");
        }
    });
    $sender = new class implements NotificationTransport
    {
        public int $calls = 0;

        public function send(NotificationDelivery $delivery): NotificationSendResult
        {
            $this->calls++;

            return new NotificationSendResult(NotificationDeliveryStatus::Sent, 'one-accepted-message');
        }
    };
    expect(app(NotificationOutbox::class)->deliver($row->id, $sender))->toBeTrue();
    expect($sender->calls)->toBe(1);
    expect($row->fresh()->attempts)->toBe(1);
    expect($row->fresh()->status)->toBe(NotificationDeliveryStatus::Sent);
});

test('pre send crash exhaustion becomes failed without spending an extra attempt', function () {
    $row = NotificationDelivery::factory()->processing()->create(['attempts' => 5, 'lease_until' => now()->subSecond()]);
    expect(app(NotificationOutbox::class)->claim($row->id))->toBeNull();
    expect($row->fresh()->status)->toBe(NotificationDeliveryStatus::Failed);
    expect($row->fresh()->attempts)->toBe(5);
    expect($row->fresh()->last_error)->toBe('retry_exhausted');
});

test('job failures serialize only an identifier and expose no underlying exception chain', function () {
    $outbox = Mockery::mock(NotificationOutbox::class);
    $sender = Mockery::mock(NotificationTransport::class);
    $outbox->shouldReceive('deliver')->once()->with(17, $sender)->andThrow(new RuntimeException('Private provider response'));
    $failure = null;
    try {
        (new ProcessNotificationDeliveryJob(17))->handle($outbox, $sender);
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeInstanceOf(RuntimeException::class);
    expect($failure->getMessage())->toBe('Notification delivery failed; inspect the durable outbox.');
    expect($failure->getPrevious())->toBeNull();
});
