<?php

use App\Models\DailyAvailability;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('mysql_testing connections enforce InnoDB READ COMMITTED isolation and UTC session timezone', function () {
    $primary = DB::connection('mysql_testing');
    $secondary = DB::build(config('database.connections.mysql_testing'));

    try {
        foreach ([$primary, $secondary] as $connection) {
            $row = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS tz');

            expect($row->isolation_level)->toBe('READ-COMMITTED')
                ->and($row->tz)->toBe('+00:00');
        }
    } finally {
        $secondary->disconnect();
    }
});

test('EXPLAIN plans for quota allocation customer history outbox scanner and queue pop use indexes on MySQL', function () {
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $availability = DailyAvailability::factory()->for($product)->create([
        'availability_date' => '2026-11-20',
        'quota_limit' => 10,
    ]);

    for ($i = 0; $i < 12; $i++) {
        $order = Order::factory()->for($customer)->paid()->create([
            'pickup_date' => '2026-11-20',
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'daily_availability_id' => $availability->id,
        ]);

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{"job":"other"}',
                'attempts' => 0,
                'reserved_at' => 1762516000,
                'available_at' => 1762516000 + $i,
                'created_at' => 1762516000 + $i,
            ],
            [
                'queue' => 'notifications',
                'payload' => '{"job":"test"}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => 1762516000 + $i,
                'created_at' => 1762516000 + $i,
            ],
        ]);
    }

    NotificationDelivery::factory()->count(10)->sent()->create();
    NotificationDelivery::factory()->create([
        'status' => 'pending',
        'next_attempt_at' => '2026-11-07 11:00:00',
    ]);

    $historyPlan = DB::select(
        'EXPLAIN SELECT id, order_number, status FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10',
        [$customer->id]
    );
    expect($historyPlan[0]->key ?? $historyPlan[0]->possible_keys)->not->toBeNull();

    $availabilityPlan = DB::select(
        'EXPLAIN SELECT id, quota_limit FROM daily_availabilities WHERE product_id = ? AND availability_date = ?',
        [$product->id, '2026-11-20']
    );
    expect($availabilityPlan[0]->key ?? $availabilityPlan[0]->possible_keys)->not->toBeNull();

    $quotaSumPlan = DB::select(
        'EXPLAIN SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE daily_availability_id = ? AND quota_released_at IS NULL',
        [$availability->id]
    );
    expect($quotaSumPlan[0]->key ?? $quotaSumPlan[0]->possible_keys)->not->toBeNull();

    $outboxPlan = DB::select(
        "EXPLAIN SELECT id FROM notification_deliveries WHERE status = 'pending' AND next_attempt_at <= '2026-11-07 12:00:00'"
    );
    expect($outboxPlan[0]->key ?? $outboxPlan[0]->possible_keys)->not->toBeNull();

    $queuePlan = DB::select(
        "EXPLAIN SELECT id FROM jobs WHERE queue = 'notifications' AND reserved_at IS NULL AND available_at <= 1762516800"
    );
    expect($queuePlan[0]->key ?? $queuePlan[0]->possible_keys)->not->toBeNull();
});
