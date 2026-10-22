<?php

use App\CatalogManager;
use App\Models\AuditLog;
use App\Models\DailyAvailability;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('serializes quota changes with another writer or a synthetic allocation protocol', function (string $contender) {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Quota lock proof requires isolated MySQL.');
    }
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $first = DB::connection();
    $second = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    try {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $availability = DailyAvailability::factory()->for($product)->create(['availability_date' => '2026-10-25', 'quota_limit' => 5]);
        $order = Order::factory()->create(['pickup_date' => '2026-10-25', 'product_total' => $variant->price * 3]);
        $admin = User::factory()->admin()->create();
        $writer = $contender === 'quota writer' ? User::factory()->admin()->create() : null;
        if ($writer) {
            OrderItem::factory()->create(['product_variant_id' => $variant->id, 'order_id' => $order->id, 'quantity' => 3]);
        }
        $manager = app(CatalogManager::class);
        expect($first->selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe($second->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $first->beginTransaction();
        if ($writer) {
            $manager->setDailyQuota($writer, $product, '2026-10-25', 4, 'First quota writer');
        } else {
            User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            DailyAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            OrderItem::factory()->create(['product_variant_id' => $variant->id, 'order_id' => $order->id, 'quantity' => 3]);
        }
        DB::setDefaultConnection($second->getName());
        try {
            $manager->setDailyQuota($admin, $product, '2026-10-25', 2, 'Competing capacity reduction');
            $this->fail('Capacity reduction bypassed the product/availability locks.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }
        DB::setDefaultConnection($original);
        $first->commit();
        DB::setDefaultConnection($second->getName());
        expect(fn () => $manager->setDailyQuota($admin, $product, '2026-10-25', 2, 'Recheck after commit'))->toThrow(ValidationException::class);
        DB::setDefaultConnection($original);
        expect($availability->fresh()->quota_limit)->toBe($writer ? 4 : 5);
        expect(OrderItem::query()->sum('quantity'))->toBe('3');
        expect(AuditLog::query()->count())->toBe($writer ? 1 : 0);
    } finally {
        DB::setDefaultConnection($original);
        while ($first->transactionLevel() > 0) {
            $first->rollBack();
        }
        while ($second->transactionLevel() > 0) {
            $second->rollBack();
        }
        $second->disconnect();
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
    }
})->with(['quota writer', 'allocation protocol']);
