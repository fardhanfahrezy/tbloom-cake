<?php

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PickupMethod;
use App\Models\User;
use Database\Seeders\OrderTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores a pending order with historical contacts and an exact thirty minute UTC deadline', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(17, 15));
    $customer = User::factory()->create();
    $order = Order::factory()->for($customer)->create()->fresh();

    expect($order->user->is($customer))->toBeTrue();
    expect($order->customer_name_snapshot)->toBe($customer->name);
    expect($order->email_snapshot)->toBe($customer->email);
    expect($order->whatsapp_snapshot)->toBe($customer->whatsapp_number);
    expect($order->created_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 17:15:00');
    expect($order->payment_expires_at->format('Y-m-d H:i:s'))->toBe('2026-11-01 17:45:00');
    expect($order->product_total)->toBe(100000);
    expect($order->paid_at)->toBeNull();
});

test('casts pending status and pickup method to domain enums', function () {
    $order = Order::factory()->create()->fresh();

    expect($order->status)->toBe(OrderStatus::Pending);
    expect($order->pickup_method)->toBe(PickupMethod::SelfPickup);
});

test('builds complete synthetic paid completed expired cancelled and courier states', function () {
    $paid = Order::factory()->paid()->create()->fresh();
    $completed = Order::factory()->completed()->create()->fresh();
    $expired = Order::factory()->expired()->create()->fresh();
    $cancelled = Order::factory()->cancelled()->create()->fresh();
    $courier = Order::factory()->onlineCourier()->create()->fresh();

    expect($paid->status)->toBe(OrderStatus::Paid);
    expect($paid->paid_at)->not->toBeNull();
    expect($completed->status)->toBe(OrderStatus::Completed);
    expect($completed->completed_at)->not->toBeNull();
    expect($completed->handedOverBy->role->value)->toBe('admin');
    expect($expired->expired_at)->not->toBeNull();
    expect($cancelled->cancelled_at)->not->toBeNull();
    expect($courier->pickup_method)->toBe(PickupMethod::OnlineCourier);
    expect($courier->policy_text_snapshot)->toBe('[TEST] synthetic courier terms');
    expect($courier->courier_terms_accepted_at)->not->toBeNull();
});

test('rejects invalid order contract values in MySQL', function (array $overrides, int $errorCode) {
    $attributes = Order::factory()->raw();
    $attributes = array_replace($attributes, ['handed_over_by' => $attributes['user_id']], $overrides);

    try {
        DB::table('orders')->insert($attributes);
        $this->fail('MySQL accepted invalid order values.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'unknown status' => [['status' => 'processing'], 3819],
    'status case mismatch' => [['status' => 'PENDING'], 3819],
    'unknown pickup method' => [['pickup_method' => 'shipping'], 3819],
    'before opening' => [['pickup_time' => '08:59:00'], 3819],
    'after closing' => [['pickup_time' => '21:01:00'], 3819],
    'non minute pickup time' => [['pickup_time' => '09:00:01'], 3819],
    'zero total' => [['product_total' => 0], 3819],
    'negative total' => [['product_total' => -1], 1264],
    'total exceeds PHP integer' => [['product_total' => '9223372036854775808'], 3819],
    'deadline shifted by one second' => [['created_at' => '2026-11-01 00:00:00', 'payment_expires_at' => '2026-11-01 00:30:01'], 3819],
    'paid without paid time' => [['status' => 'paid', 'paid_at' => null], 3819],
    'completed without paid time' => [['status' => 'completed', 'paid_at' => null, 'completed_at' => '2026-11-01 01:00:00'], 3819],
    'completed without completion time' => [['status' => 'completed', 'paid_at' => '2026-11-01 00:10:00'], 3819],
    'completed without actor' => [['status' => 'completed', 'paid_at' => '2026-11-01 00:10:00', 'completed_at' => '2026-11-01 01:00:00', 'handed_over_by' => null], 3819],
    'expired without expiry time' => [['status' => 'expired'], 3819],
    'cancelled without cancellation time' => [['status' => 'cancelled'], 3819],
    'courier without acceptance' => [['pickup_method' => 'online_courier', 'policy_version' => 'test-v1', 'policy_text_snapshot' => 'Synthetic terms'], 3819],
    'courier without policy version' => [['pickup_method' => 'online_courier', 'policy_text_snapshot' => 'Synthetic terms', 'courier_terms_accepted_at' => '2026-11-01 00:00:00'], 3819],
    'courier without policy text' => [['pickup_method' => 'online_courier', 'policy_version' => 'test-v1', 'courier_terms_accepted_at' => '2026-11-01 00:00:00'], 3819],
    'missing customer' => [['user_id' => 0], 1452],
    'missing handover actor' => [['handed_over_by' => 0], 1452],
    'null customer snapshot' => [['customer_name_snapshot' => null], 1048],
    'null creation time' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
]);

test('allows both pickup boundaries and the maximum safe rupiah total', function () {
    $opening = Order::factory()->create(['pickup_time' => '09:00:00', 'product_total' => PHP_INT_MAX]);
    $closing = Order::factory()->create(['pickup_time' => '21:00:00']);

    expect($opening->fresh()->product_total)->toBe(9223372036854775807);
    expect($closing->fresh()->pickup_time)->toBe('21:00:00');
});

test('retains contact snapshots when the account changes', function () {
    $customer = User::factory()->create(['name' => 'Nama Lama', 'email' => 'old@example.test', 'whatsapp_number' => '+6281234567890']);
    $order = Order::factory()->for($customer)->create();
    $customer->update(['name' => 'Nama Baru', 'email' => 'new@example.test', 'whatsapp_number' => '+6281234567891']);

    expect($order->fresh()->only(['customer_name_snapshot', 'email_snapshot', 'whatsapp_snapshot']))->toBe([
        'customer_name_snapshot' => 'Nama Lama', 'email_snapshot' => 'old@example.test', 'whatsapp_snapshot' => '+6281234567890',
    ]);
});

test('restricts reuse of an order number or the same customer idempotency key', function (string $column) {
    $order = Order::factory()->create();

    try {
        Order::factory()->for($order->user)->create([$column => $order->getAttribute($column)]);
        $this->fail('MySQL accepted a duplicate order identity.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
})->with(['order_number', 'idempotency_key']);

test('scopes idempotency keys by customer and preserves binary case distinctions', function () {
    $order = Order::factory()->create(['idempotency_key' => 'Key-A']);
    Order::factory()->create(['idempotency_key' => 'Key-A']);
    Order::factory()->for($order->user)->create(['idempotency_key' => 'key-a']);

    $this->assertDatabaseCount('orders', 3);
});

test('refuses rollback that would discard order history', function () {
    $order = Order::factory()->create();
    $migration = require database_path('migrations/2026_10_21_194129_create_orders_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($order);
});

test('restricts deletion and renumbering of both customer and handover actor', function (string $column, string $operation) {
    $order = Order::factory()->completed()->create();
    $parentId = $order->getAttribute($column);

    try {
        $parent = DB::table('users')->where('id', $parentId);
        if ($operation === 'delete') {
            $parent->delete();
        } else {
            $parent->update(['id' => $parentId + 1000000]);
        }
        $this->fail('MySQL changed a referenced order account.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($order);
})->with([
    ['user_id', 'delete'], ['user_id', 'update'], ['handed_over_by', 'delete'], ['handed_over_by', 'update'],
]);

test('matches the orders and items dictionary types defaults precision and required indexes', function () {
    $expectedTypes = [
        'orders' => [
            'id' => 'bigint unsigned', 'order_number' => 'varchar(40)', 'user_id' => 'bigint unsigned', 'status' => 'varchar(32)',
            'customer_name_snapshot' => 'varchar(100)', 'email_snapshot' => 'varchar(254)', 'whatsapp_snapshot' => 'varchar(16)',
            'pickup_date' => 'date', 'pickup_time' => 'time', 'pickup_method' => 'varchar(16)', 'handover_note' => 'varchar(255)',
            'product_total' => 'bigint unsigned', 'payment_expires_at' => 'datetime', 'paid_at' => 'datetime', 'expired_at' => 'datetime',
            'cancelled_at' => 'datetime', 'completed_at' => 'datetime', 'handed_over_by' => 'bigint unsigned', 'cancellation_reason' => 'text',
            'policy_version' => 'varchar(64)', 'policy_text_snapshot' => 'text', 'courier_terms_accepted_at' => 'datetime',
            'idempotency_key' => 'varchar(128)', 'request_hash' => 'char(64)', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ],
        'order_items' => [
            'id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'product_id' => 'bigint unsigned',
            'product_variant_id' => 'bigint unsigned', 'daily_availability_id' => 'bigint unsigned',
            'product_name_snapshot' => 'varchar(150)', 'size_snapshot' => 'varchar(100)', 'sales_unit_snapshot' => 'varchar(32)',
            'flavor_code' => 'varchar(64)', 'flavor_label_snapshot' => 'varchar(100)', 'filling_code' => 'varchar(64)',
            'filling_label_snapshot' => 'varchar(100)', 'cover_color' => 'varchar(100)', 'inscription' => 'varchar(150)',
            'quantity' => 'int unsigned', 'unit_price' => 'bigint unsigned', 'line_total' => 'bigint unsigned',
            'quota_released_at' => 'datetime', 'quota_release_reason' => 'varchar(32)', 'created_at' => 'datetime',
        ],
    ];
    $nullableColumns = [
        'orders' => ['handover_note', 'paid_at', 'expired_at', 'cancelled_at', 'completed_at', 'handed_over_by', 'cancellation_reason', 'policy_version', 'policy_text_snapshot', 'courier_terms_accepted_at'],
        'order_items' => ['quota_released_at', 'quota_release_reason'],
    ];
    $technicalColumns = [
        'orders' => ['order_number', 'status', 'pickup_method', 'policy_version', 'idempotency_key', 'request_hash'],
        'order_items' => ['flavor_code', 'filling_code', 'quota_release_reason'],
    ];
    $requiredIndexes = [
        'orders' => [['user_id', 'status', 'id'], ['user_id', 'created_at', 'id'], ['status', 'payment_expires_at', 'id'], ['pickup_date', 'status', 'id']],
        'order_items' => [['daily_availability_id', 'quota_released_at', 'order_id']],
    ];
    $requiredUnique = [
        'orders' => [['id'], ['order_number'], ['user_id', 'idempotency_key'], ['id', 'user_id']],
        'order_items' => [['id'], ['order_id']],
    ];

    foreach ($expectedTypes as $table => $types) {
        $metadata = DB::selectOne('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        $columns = collect(DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, DATA_TYPE, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]));
        expect($metadata->ENGINE)->toBe('InnoDB');
        expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
        expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe($types);
        expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe($nullableColumns[$table]);
        expect($columns->whereNotNull('COLUMN_DEFAULT')->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')->all())
            ->toBe($table === 'orders' ? ['status' => 'pending'] : ['inscription' => '']);
        expect($columns->whereIn('COLUMN_NAME', $technicalColumns[$table])->pluck('COLLATION_NAME')->unique()->all())->toBe(['ascii_bin']);
        expect($columns->whereIn('DATA_TYPE', ['datetime', 'time'])->pluck('DATETIME_PRECISION')->unique()->all())->toBe([0]);

        $indexes = collect(DB::select('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]))->groupBy('INDEX_NAME');
        foreach ($requiredIndexes[$table] as $index) {
            expect($indexes->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
        }
        foreach ($requiredUnique[$table] as $index) {
            expect($indexes->filter(fn ($columns): bool => $columns->first()->NON_UNIQUE === 0)->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
        }
    }
});

test('seeds coherent synthetic order states and allocations only in the isolated database', function () {
    $this->seed(OrderTestSeeder::class);

    $this->assertDatabaseCount('orders', 6);
    $this->assertDatabaseCount('order_items', 6);
    expect(Order::query()->where('status', 'pending')->count())->toBe(2);

    foreach (Order::with(['user.orders', 'item.dailyAvailability'])->get() as $order) {
        expect($order->user->orders->contains($order))->toBeTrue();
        expect($order->item->line_total)->toBe($order->product_total);
        expect($order->item->dailyAvailability->availability_date->toDateString())->toBe($order->pickup_date->toDateString());
        expect($order->item->quota_released_at !== null)->toBe(in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true));
    }
});

test('refuses synthetic order seeding through the development connection', function () {
    config(['database.default' => 'mysql']);
    try {
        expect(fn () => app(OrderTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('orders', 0);
});

test('refuses synthetic order seeding outside the testing environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(OrderTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
    $this->assertDatabaseCount('orders', 0);
});
