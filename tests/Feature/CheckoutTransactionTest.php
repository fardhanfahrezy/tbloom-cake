<?php

use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

test('bounds lock timeout retries and leaves no partial checkout', function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Independent connection proof requires isolated MySQL.');
    }
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'UTC'));
    Sleep::fake();
    $first = DB::connection();
    $second = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    try {
        $customer = User::factory()->create();
        $variant = ProductVariant::factory()->create();
        $input = ['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'quantity' => 1,
            'flavor_code' => 'chocolate', 'filling_code' => 'strawberry', 'cover_color' => 'Putih',
            'inscription_choice' => 'none', 'pickup_date' => '2026-10-24', 'pickup_time' => '09:00',
            'pickup_method' => 'self_pickup', 'idempotency_key' => 'timeout-test-1'];
        expect($first->selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe($second->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $first->beginTransaction();
        User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        DB::setDefaultConnection($second->getName());

        $this->actingAs($customer)->postJson('/checkout', $input)->assertServiceUnavailable()->assertJsonPath('message', 'Checkout sedang sibuk. Coba lagi dengan detail pesanan yang sama.');

        Sleep::assertSleptTimes(2);
        expect($second->transactionLevel())->toBe(0);
        foreach (['orders', 'order_items', 'payments', 'audit_logs', 'notification_deliveries', 'daily_availabilities'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    } finally {
        DB::setDefaultConnection($original);
        while ($first->transactionLevel() > 0) {
            $first->rollBack();
        }
        while ($second->transactionLevel() > 0) {
            $second->rollBack();
        }
        $second->disconnect();
        Sleep::fake(false);
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
    }
});
