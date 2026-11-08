<?php

use App\CustomRequestEvents;
use App\DispatchNotificationDeliveries;
use App\Models\CustomCakeRequest;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\NotificationOutbox;
use App\NotificationSendResult;
use App\NotificationTransport;
use App\PaymentNotices;
use App\ProcessNotificationDeliveryJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.enabled' => true, 'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
});

test('scanner queues due pending and pre send expired claims but never retries uncertain sends', function () {
    Queue::fake();
    $pending = NotificationDelivery::factory()->create();
    $stale = NotificationDelivery::factory()->processing()->create(['lease_until' => now()->subSecond()]);
    $unknown = NotificationDelivery::factory()->processing()->create(['status' => 'unknown', 'lease_until' => now()->subSecond()]);
    NotificationDelivery::factory()->create(['next_attempt_at' => now()->addMinute()]);
    NotificationDelivery::factory()->processing()->create();
    NotificationDelivery::factory()->sent()->create();
    NotificationDelivery::factory()->failed()->create();
    expect(app(DispatchNotificationDeliveries::class)->dispatch())->toBe(2);
    Queue::assertPushed(ProcessNotificationDeliveryJob::class, fn ($job) => $job->deliveryId === $pending->id && $job->connection === 'database' && $job->queue === 'notifications' && $job->afterCommit === true);
    Queue::assertPushed(ProcessNotificationDeliveryJob::class, fn ($job) => $job->deliveryId === $stale->id);
    Queue::assertPushedTimes(ProcessNotificationDeliveryJob::class, 2);
    expect($pending->fresh()->attempts)->toBe(0);
    expect($unknown->fresh()->status->value)->toBe('unknown');
    expect($unknown->fresh()->claim_token)->toBeNull();
    expect($unknown->fresh()->last_error)->toBe('send_outcome_unknown');
});

test('enqueue failure retains the durable row for the next scan with a redacted diagnostic', function () {
    $delivery = NotificationDelivery::factory()->create();
    $original = Bus::getFacadeRoot();
    Log::spy();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Private queue detail must not be logged'));
    try {
        expect(app(DispatchNotificationDeliveries::class)->dispatch())->toBe(0);
    } finally {
        Bus::swap($original);
    }
    Log::shouldHaveReceived('warning')->with('Notification enqueue failed; durable row retained.', ['delivery_id' => $delivery->id])->once();
    expect($delivery->fresh()->status->value)->toBe('pending');
    expect($delivery->fresh()->attempts)->toBe(0);
    Queue::fake();
    expect(app(DispatchNotificationDeliveries::class)->dispatch())->toBe(1);
    Queue::assertPushed(ProcessNotificationDeliveryJob::class, 1);
});

test('notification scanner has an explicit bounded command and is inactive when delivery is disabled', function () {
    Queue::fake();
    NotificationDelivery::factory()->count(2)->create();
    $this->artisan('notifications:dispatch', ['--limit' => '1'])->expectsOutput('Queued notification deliveries: 1')->assertSuccessful();
    Queue::assertPushedTimes(ProcessNotificationDeliveryJob::class, 1);
    config(['notifications.enabled' => false]);
    expect(app(DispatchNotificationDeliveries::class)->dispatch())->toBe(0);
    Queue::assertPushedTimes(ProcessNotificationDeliveryJob::class, 1);
});

test('scanner refuses invalid batch sizes and nested caller transactions', function () {
    expect(fn () => app(DispatchNotificationDeliveries::class)->dispatch(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DispatchNotificationDeliveries::class)->dispatch(101))->toThrow(InvalidArgumentException::class);
    DB::beginTransaction();
    try {
        expect(fn () => app(DispatchNotificationDeliveries::class)->dispatch())->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
});

test('scheduler registers notifications dispatch every minute without overlap on one server and schedules no automatic purge', function () {
    $events = collect(app(Schedule::class)->events());
    $notificationEvent = $events->first(fn ($event) => str_contains((string) $event->command, 'notifications:dispatch'));

    expect($notificationEvent)->not->toBeNull();
    expect($notificationEvent->expression)->toBe('* * * * *');
    expect($notificationEvent->withoutOverlapping)->toBeTrue();
    expect($notificationEvent->expiresAt)->toBe(5);
    expect($notificationEvent->onOneServer)->toBeTrue();
    expect($events->contains(fn ($event) => str_contains(strtolower((string) $event->command), 'prune')
        || str_contains(strtolower((string) $event->command), 'purge')))->toBeFalse();
});

test('worker timeout queue retry after and outbox lease invariant fences premature duplicate jobs and rejects misconfiguration', function (mixed $invalidRetryAfter) {
    $job = new ProcessNotificationDeliveryJob(1);
    expect(ProcessNotificationDeliveryJob::TIMEOUT_SECONDS)->toBe(60);
    expect($job->timeout)->toBe(60);
    expect($job->tries)->toBe(1);
    expect($job->failOnTimeout)->toBeTrue();
    expect(config('queue.connections.database.retry_after'))->toBe(90);
    expect(NotificationOutbox::LEASE_SECONDS)->toBe(120);

    $row = NotificationDelivery::factory()->create();
    $outbox = app(NotificationOutbox::class);
    $firstClaim = $outbox->claim($row->id);
    expect($firstClaim)->not->toBeNull();

    // At 90s (queue retry_after), a duplicate worker released by the database queue must still be fenced by the 120s outbox lease.
    $this->travel(90)->seconds();
    $sender = Mockery::mock(NotificationTransport::class)->shouldNotReceive('send')->getMock();
    (new ProcessNotificationDeliveryJob($row->id))->handle($outbox, $sender);
    expect($row->fresh()->attempts)->toBe(1);
    expect($row->fresh()->claim_token)->toBe($firstClaim->claim_token);

    // At 120s (lease_until), the scanner re-queues the pre-send crashed claim and the new worker fences the old claim token.
    $this->travel(30)->seconds();
    Queue::fake();
    expect(app(DispatchNotificationDeliveries::class)->dispatch())->toBe(1);
    $secondSender = new class implements NotificationTransport
    {
        public int $calls = 0;

        public function send(NotificationDelivery $delivery): NotificationSendResult
        {
            $this->calls++;

            return new NotificationSendResult(NotificationDeliveryStatus::Sent, 'reclaimed-msg-1');
        }
    };
    (new ProcessNotificationDeliveryJob($row->id))->handle($outbox, $secondSender);
    expect($secondSender->calls)->toBe(1);
    expect($row->fresh()->status)->toBe(NotificationDeliveryStatus::Sent);
    expect($row->fresh()->attempts)->toBe(2);
    expect($outbox->begin($row->id, $firstClaim->claim_token))->toBeNull();
    expect($outbox->finish($row->id, $firstClaim->claim_token, new NotificationSendResult(NotificationDeliveryStatus::Sent)))->toBeFalse();

    config(['queue.connections.database.retry_after' => $invalidRetryAfter]);
    expect(fn () => $outbox->claim($row->id))->toThrow(LogicException::class);
    expect(fn () => app(DispatchNotificationDeliveries::class)->dispatch())->toThrow(LogicException::class);
})->with([60, 120, '90', null]);

test('domain producers enqueue notification jobs only after commit and retain durable rows if after commit enqueue fails', function () {
    Queue::fake();
    $payment = Payment::factory()->create();
    $order = $payment->order;

    expect(fn () => DB::transaction(function () use ($order): void {
        Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        app(PaymentNotices::class)->paid($order);
        Queue::assertNothingPushed();
        throw new RuntimeException('Rollback before commit');
    }))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('notification_deliveries', 0);
    Queue::assertNothingPushed();

    DB::transaction(function () use ($order): void {
        Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        Payment::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        app(PaymentNotices::class)->paid($order);
        Queue::assertNothingPushed();
    });

    $this->assertDatabaseCount('notification_deliveries', 2);
    Queue::assertPushedTimes(ProcessNotificationDeliveryJob::class, 2);

    $customer = User::factory()->create();
    $custom = CustomCakeRequest::factory()->submitted()->for($customer)->create();
    $originalBus = Bus::getFacadeRoot();
    Log::spy();
    Bus::shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('Private broker failure after commit'));
    try {
        DB::transaction(function () use ($custom, $customer): void {
            app(CustomRequestEvents::class)->record($custom, $customer, 'submitted', [], ['status' => $custom->status->value]);
        });
    } finally {
        Bus::swap($originalBus);
    }

    $customDeliveries = NotificationDelivery::query()->where('entity_type', 'custom_request')->where('entity_id', $custom->id)->orderBy('id')->get();
    expect($customDeliveries)->toHaveCount(2);
    foreach ($customDeliveries as $delivery) {
        expect($delivery->status)->toBe(NotificationDeliveryStatus::Pending);
        expect($delivery->attempts)->toBe(0);
        Log::shouldHaveReceived('warning')->with('Notification enqueue failed; durable row retained.', ['delivery_id' => $delivery->id])->once();
    }

    Queue::fake();
    expect(app(DispatchNotificationDeliveries::class)->dispatch())->toBe(4);
    Queue::assertPushedTimes(ProcessNotificationDeliveryJob::class, 4);
});
