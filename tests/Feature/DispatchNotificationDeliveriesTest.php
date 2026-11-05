<?php

use App\DispatchNotificationDeliveries;
use App\Models\NotificationDelivery;
use App\ProcessNotificationDeliveryJob;
use Carbon\CarbonImmutable;
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
