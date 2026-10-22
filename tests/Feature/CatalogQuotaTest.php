<?php

use App\CatalogManager;
use App\Models\AuditLog;
use App\Models\DailyAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuotaReleaseReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Quota management requires isolated MySQL.');
    }
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));
});

test('changes only the default template and keeps materialized capacity intact with an audit', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $row = DailyAvailability::factory()->for($product)->create(['availability_date' => '2026-10-23', 'quota_limit' => 5]);
    $this->actingAs($admin)->get('/staff/products/'.$product->id.'/quota')->assertOk()->assertSee('Kuota default');
    $this->patch('/staff/products/'.$product->id.'/default-quota', ['default_daily_quota' => 8, 'default_reason' => 'Kapasitas produksi baru'])->assertRedirect()->assertSessionHasNoErrors();
    expect($product->fresh()->default_daily_quota)->toBe(8);
    expect($row->fresh()->quota_limit)->toBe(5);
    $this->get('/catalog?pickup_date=2026-10-23')->assertOk()->assertSee('Tersedia 5 dari 5 unit');
    $this->get('/catalog?pickup_date=2026-10-24')->assertOk()->assertSee('Tersedia 8 dari 8 unit');
    $this->assertDatabaseCount('daily_availabilities', 1);
    expect(AuditLog::query()->sole()->reason)->toBe('Kapasitas produksi baru');
});

test('creates and updates a dated override with the current actor and protects idempotent writes', function () {
    $owner = User::factory()->owner()->create();
    $product = Product::factory()->create();
    $url = '/staff/products/'.$product->id.'/availability';
    $this->actingAs($owner)->put($url, ['availability_date' => '2026-10-23', 'quota_limit' => 0, 'reason' => 'Tanggal ditutup'])->assertRedirect()->assertSessionHasNoErrors();
    $row = DailyAvailability::query()->sole();
    expect($row->quota_limit)->toBe(0);
    expect($row->updated_by)->toBe($owner->id);
    $this->get('/catalog?pickup_date=2026-10-23')->assertOk()->assertSee('Penuh');
    $this->put($url, ['availability_date' => '2026-10-23', 'quota_limit' => 0, 'reason' => 'Tidak ada perubahan'])->assertRedirect();
    $this->assertDatabaseCount('audit_logs', 1);
    $this->put($url, ['availability_date' => '2026-10-23', 'quota_limit' => 7, 'reason' => 'Kapasitas dibuka'])->assertRedirect()->assertSessionHasNoErrors();
    expect($row->fresh()->quota_limit)->toBe(7);
    $this->assertDatabaseCount('daily_availabilities', 1);
    $this->assertDatabaseCount('audit_logs', 2);
});

test('rejects a capacity below allocated units including overdue pending paid and completed', function (string $state) {
    $admin = User::factory()->admin()->create();
    $factory = Order::factory()->state(['pickup_date' => '2026-10-23', 'created_at' => '2026-10-22 05:00:00']);
    if ($state !== 'pending') {
        $factory = $factory->$state();
    }
    $order = $factory->create();
    $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 3]);
    $this->actingAs($admin)->putJson('/staff/products/'.$item->product_id.'/availability', ['availability_date' => '2026-10-23', 'quota_limit' => 2, 'reason' => 'Kapasitas turun'])->assertUnprocessable()->assertJsonValidationErrors('quota_limit');
    expect($item->dailyAvailability->fresh()->quota_limit)->toBe(5);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->put('/staff/products/'.$item->product_id.'/availability', ['availability_date' => '2026-10-23', 'quota_limit' => 3, 'reason' => 'Sama dengan alokasi'])->assertRedirect()->assertSessionHasNoErrors();
    expect($item->dailyAvailability->fresh()->quota_limit)->toBe(3);
})->with(['pending', 'paid', 'completed']);

test('allows capacity reduction after an explicit release and ignores other dates', function () {
    $variant = ProductVariant::factory()->create();
    $cancelled = Order::factory()->cancelled()->create(['pickup_date' => '2026-10-23']);
    OrderItem::factory()->released(QuotaReleaseReason::Cancelled)->create(['product_variant_id' => $variant->id, 'order_id' => $cancelled->id, 'quantity' => 3]);
    $other = Order::factory()->create(['pickup_date' => '2026-10-24']);
    OrderItem::factory()->create(['product_variant_id' => $variant->id, 'order_id' => $other->id, 'quantity' => 2]);
    $this->actingAs(User::factory()->admin()->create())->put('/staff/products/'.$variant->product_id.'/availability', ['availability_date' => '2026-10-23', 'quota_limit' => 0, 'reason' => 'Alokasi sudah dilepas'])->assertRedirect()->assertSessionHasNoErrors();
    expect(DailyAvailability::query()->where('availability_date', '2026-10-23')->sole()->quota_limit)->toBe(0);
});

test('validates quota ranges dates and mandatory reasons before writes', function (array $invalid, string $field) {
    $product = Product::factory()->create();
    $this->actingAs(User::factory()->admin()->create())->putJson('/staff/products/'.$product->id.'/availability', array_replace(['availability_date' => '2026-10-23', 'quota_limit' => 5, 'reason' => 'Uji kapasitas'], $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('daily_availabilities', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    [['quota_limit' => -1], 'quota_limit'], [['quota_limit' => '1.5'], 'quota_limit'], [['quota_limit' => '4294967296'], 'quota_limit'],
    [['availability_date' => '2026-02-30'], 'availability_date'], [['availability_date' => '0001-01-01'], 'availability_date'], [['availability_date' => ['2026-10-23']], 'availability_date'],
    [['reason' => ''], 'reason'], [['reason' => str_repeat('x', 1001)], 'reason'],
]);

test('denies customers guests inactive and stale staff from changing quotas', function () {
    $product = Product::factory()->create();
    $url = '/staff/products/'.$product->id.'/availability';
    $payload = ['availability_date' => '2026-10-23', 'quota_limit' => 0, 'reason' => 'Tidak sah'];
    $this->put($url, $payload)->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->putJson($url, $payload)->assertForbidden();
    $this->actingAs(User::factory()->admin()->inactive()->create())->put($url, $payload)->assertRedirect('/login');
    $admin = User::factory()->admin()->create();
    User::query()->whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(CatalogManager::class)->setDailyQuota($admin, $product, '2026-10-23', 0, 'Stale actor'))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('rolls back quota and default changes when their audit fails', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic audit failure.'));
    try {
        $manager = app(CatalogManager::class);
        expect(fn () => $manager->setDailyQuota($admin, $product, '2026-10-23', 0, 'Test daily rollback'))->toThrow(RuntimeException::class);
        expect(fn () => $manager->setDefaultQuota($admin, $product, 0, 'Test default rollback'))->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('daily_availabilities', 0);
        expect($product->fresh()->default_daily_quota)->toBe(5);
        $this->assertDatabaseCount('audit_logs', 0);
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
});

test('rejects invalid defaults and missing default reasons without updating the product', function (array $invalid, string $field) {
    $product = Product::factory()->create();
    $this->actingAs(User::factory()->admin()->create())->patchJson('/staff/products/'.$product->id.'/default-quota', array_replace(['default_daily_quota' => 5, 'default_reason' => 'Uji default'], $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($product->fresh()->default_daily_quota)->toBe(5);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([[['default_daily_quota' => -1], 'default_daily_quota'], [['default_daily_quota' => '4294967296'], 'default_daily_quota'], [['default_reason' => ''], 'default_reason']]);

test('allows a zero default while preserving existing allocated dates and closes only new dates', function () {
    $item = OrderItem::factory()->create(['quantity' => 3]);
    $product = $item->product;
    $this->actingAs(User::factory()->admin()->create())->patch('/staff/products/'.$product->id.'/default-quota', ['default_daily_quota' => 0, 'default_reason' => 'Tutup tanggal baru'])->assertRedirect()->assertSessionHasNoErrors();
    expect($product->fresh()->default_daily_quota)->toBe(0);
    expect($item->dailyAvailability->fresh()->quota_limit)->toBe(5);
    $this->assertDatabaseCount('daily_availabilities', 1);
    $this->get('/catalog?pickup_date=2026-10-23')->assertOk()->assertSee('Penuh');
});

test('reads inactive catalog quota without materializing and recovers invalid date filters', function () {
    $product = Product::factory()->create(['is_active' => false]);
    $this->actingAs(User::factory()->admin()->create())->get('/staff/products/'.$product->id.'/quota?date=2026-10-23')->assertOk()->assertSee('Tanggal 2026-10-23');
    $this->assertDatabaseCount('daily_availabilities', 0);
    $this->getJson('/staff/products/'.$product->id.'/quota?date=bad')->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->from('/staff/products/'.$product->id.'/quota?date=bad')->get('/staff/products/'.$product->id.'/quota?date=bad')->assertRedirect('/staff/products/'.$product->id.'/quota');
    $this->withCookie(config('session.cookie'), session()->getId())->get('/staff/products/'.$product->id.'/quota')->assertOk();
});
