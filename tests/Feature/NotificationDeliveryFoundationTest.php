<?php

use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use App\Models\User;
use App\NotificationChannel;
use App\NotificationRecipientIdentity;
use Carbon\CarbonImmutable;
use Database\Seeders\NotificationDeliveryTestSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('h', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
});

test('encrypts and hides delivery destination and casts minimal payload', function () {
    $delivery = NotificationDelivery::factory()->create()->fresh();
    expect($delivery->recipient_ciphertext)->toBe(['channel' => 'email', 'address' => 'synthetic-recipient@example.test']);
    expect($delivery->getRawOriginal('recipient_ciphertext'))->not->toContain('synthetic-recipient@example.test');
    expect($delivery->recipient_key)->toBe(app(NotificationRecipientIdentity::class)->key(NotificationChannel::Email, 'synthetic-recipient@example.test'));
    expect($delivery->toArray())->not->toHaveKeys(['recipient_ciphertext', 'recipient_key', 'claim_token', 'last_error']);
    expect($delivery->payload_minimal)->toBe(['status' => 'paid']);
    expect($delivery->status)->toBe(NotificationDeliveryStatus::Pending);
    expect($delivery->attempts)->toBe(0);
    expect(fn () => NotificationDelivery::create(['status' => 'sent']))->toThrow(MassAssignmentException::class);
});

test('roundtrips delivery status fixtures without sending messages', function (string $state) {
    Http::preventStrayRequests();
    Mail::fake();
    $delivery = NotificationDelivery::factory()->$state()->create()->fresh();
    expect($delivery->status->value)->toBe($state);
    if ($state === 'processing') {
        expect(Str::isUuid($delivery->claim_token))->toBeTrue();
        expect($delivery->lease_until)->toBeInstanceOf(CarbonImmutable::class);
    }
    if (in_array($state, ['sent', 'delivered'], true)) {
        expect($delivery->sent_at)->toBeInstanceOf(CarbonImmutable::class);
    }
    if ($state === 'delivered') {
        expect($delivery->delivered_at)->toBeInstanceOf(CarbonImmutable::class);
    }
    Mail::assertNothingSent();
})->with(['pending', 'processing', 'sent', 'delivered', 'unknown', 'failed', 'suppressed']);

test('rejects invalid outbox rows in MySQL', function (array $overrides, int $code) {
    $attributes = NotificationDelivery::factory()->make()->getAttributes();
    try {
        DB::table('notification_deliveries')->insert(array_replace($attributes, ['created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'], $overrides));
        $this->fail('MySQL accepted invalid delivery data.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code);
    }
})->with([
    'bad status' => [['status' => 'success'], 3819],
    'uppercase status' => [['status' => 'PENDING'], 3819],
    'padded status' => [['status' => 'pending '], 3819],
    'claim without lease' => [['claim_token' => '11111111-1111-4111-8111-111111111111'], 3819],
    'lease without claim' => [['lease_until' => '2026-11-01 00:01:00'], 3819],
    'sent without time' => [['status' => 'sent'], 3819],
    'delivered without sent time' => [['status' => 'delivered', 'delivered_at' => '2026-11-01 00:01:00'], 3819],
    'delivered without delivery time' => [['status' => 'delivered', 'sent_at' => '2026-11-01 00:00:00'], 3819],
    'negative attempts' => [['attempts' => -1], 1264],
    'null recipient ciphertext' => [['recipient_ciphertext' => null], 1048],
    'null recipient key' => [['recipient_key' => null], 1048],
    'null event key' => [['event_key' => null], 1048],
    'null payload' => [['payload_minimal' => null], 1048],
    'invalid JSON' => [['payload_minimal' => '{bad'], 3140],
    'null creation time' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
    'oversize error' => [['last_error' => str_repeat('a', 256)], 1406],
]);

test('deduplicates stable event recipient template triples and preserves distinct deliveries', function () {
    $first = NotificationDelivery::factory()->create();
    $attributes = $first->getAttributes();
    unset($attributes['id']);
    try {
        DB::table('notification_deliveries')->insert($attributes);
        $this->fail('MySQL accepted a duplicate delivery.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    foreach (['event_key' => 'test:other-event', 'recipient_key' => str_repeat('b', 64), 'template_key' => 'test.other-template'] as $column => $value) {
        DB::table('notification_deliveries')->insert(array_replace($attributes, [$column => $value]));
    }
    $this->assertDatabaseCount('notification_deliveries', 4);
});

test('finds an existing old HMAC identity after coordinated keyring rotation without duplicating history', function () {
    $old = config('notifications.recipient_hmac_key');
    $delivery = NotificationDelivery::factory()->create(['event_key' => 'order:1:paid:v1']);
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'notifications.previous_recipient_hmac_keys' => [$old]]);
    $candidates = app(NotificationRecipientIdentity::class)->candidateKeys(NotificationChannel::Email, 'synthetic-recipient@example.test');
    expect($candidates[0])->not->toBe($delivery->recipient_key);
    $existing = NotificationDelivery::query()->where('event_key', 'order:1:paid:v1')->where('template_key', $delivery->template_key)->whereIn('recipient_key', $candidates)->sole();
    expect($existing->is($delivery))->toBeTrue();
    $this->assertDatabaseCount('notification_deliveries', 1);
});

test('rolls back the domain audit and outbox rows together without external side effects', function () {
    Http::preventStrayRequests();
    Mail::fake();
    try {
        DB::transaction(function (): void {
            $user = User::factory()->create();
            AuditLog::factory()->create(['entity_id' => $user->id]);
            NotificationDelivery::factory()->create(['entity_id' => $user->id, 'entity_type' => 'user']);
            throw new RuntimeException('Synthetic commit failure.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Synthetic commit failure.');
    }
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    Mail::assertNothingSent();
});

test('refuses rollback that would discard delivery history', function () {
    $delivery = NotificationDelivery::factory()->create();
    $migration = require database_path('migrations/2026_10_22_101800_create_notification_deliveries_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($delivery);
});

test('seeds only synthetic delivery fixtures without provider calls', function () {
    Http::preventStrayRequests();
    Mail::fake();
    $this->seed(NotificationDeliveryTestSeeder::class);
    expect(NotificationDelivery::query()->orderBy('id')->pluck('status')->all())->toBe(NotificationDeliveryStatus::cases());
    $this->assertDatabaseCount('notification_deliveries', 7);
    Mail::assertNothingSent();
});

test('refuses delivery seeding outside isolated testing', function (string $boundary) {
    if ($boundary === 'environment') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config(['database.default' => 'mysql']);
    }
    try {
        expect(fn () => app(NotificationDeliveryTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('notification_deliveries', 0);
})->with(['environment', 'connection']);

test('matches the delivery dictionary nullability defaults and indexes', function () {
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, DATETIME_PRECISION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notification_deliveries' ORDER BY ORDINAL_POSITION"));
    expect($columns->pluck('COLUMN_NAME')->all())->toBe(['id', 'event_key', 'recipient_ciphertext', 'recipient_key', 'template_key', 'entity_type', 'entity_id', 'payload_minimal', 'status', 'provider_message_id', 'attempts', 'next_attempt_at', 'claim_token', 'lease_until', 'last_error', 'sent_at', 'delivered_at', 'created_at', 'updated_at']);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->values()->all())->toBe(['provider_message_id', 'next_attempt_at', 'claim_token', 'lease_until', 'last_error', 'sent_at', 'delivered_at']);
    expect($columns->firstWhere('COLUMN_NAME', 'recipient_ciphertext')->COLUMN_TYPE)->toBe('text');
    expect($columns->firstWhere('COLUMN_NAME', 'status')->COLUMN_DEFAULT)->toBe('pending');
    expect($columns->firstWhere('COLUMN_NAME', 'attempts')->COLUMN_DEFAULT)->toBe('0');
    expect($columns->whereNotNull('DATETIME_PRECISION')->pluck('DATETIME_PRECISION')->unique()->values()->all())->toBe([0]);
    $indexes = collect(DB::select("SELECT NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'notification_deliveries' GROUP BY INDEX_NAME, NON_UNIQUE"));
    expect($indexes->where('NON_UNIQUE', 0)->pluck('columns_list')->sort()->values()->all())->toBe(['event_key,recipient_key,template_key', 'id']);
    expect($indexes->where('NON_UNIQUE', 1)->pluck('columns_list')->sort()->values()->all())->toBe(['entity_type,entity_id,id', 'provider_message_id', 'status,lease_until,id', 'status,next_attempt_at,id']);
});
