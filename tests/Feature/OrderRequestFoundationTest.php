<?php

use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\User;
use Database\Seeders\OrderRequestTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('stores a pending cancellation belonging to its order customer', function () {
    $order = Order::factory()->create();
    $request = OrderRequest::factory()->for($order)->create()->fresh();

    expect($request->order->is($order))->toBeTrue();
    expect($request->user_id)->toBe($order->user_id);
    expect($request->active_order_id)->toBe($order->id);
    expect($request->reviewed_at)->toBeNull();
});

test('casts request states and preserves submitted time independently from processing', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 0));
    $request = OrderRequest::factory()->create()->fresh();

    expect($request->type)->toBe(OrderRequestType::Cancellation);
    expect($request->decision_mode)->toBe(OrderRequestDecisionMode::Manual);
    expect($request->status)->toBe(OrderRequestStatus::Pending);
    expect($request->submitted_at->toDateTimeString())->toBe('2026-11-01 00:00:00');
    expect($request->requested_changes)->toBeNull();
});

test('builds synthetic change automatic applied and rejected states without applying domain effects', function () {
    $this->freezeTime();
    $change = OrderRequest::factory()->change()->create()->fresh();
    $automatic = OrderRequest::factory()->for(Order::factory())->automaticCancellation()->create()->fresh();
    $applied = OrderRequest::factory()->applied()->create()->fresh();
    $rejected = OrderRequest::factory()->rejected()->create()->fresh();

    expect($change->type)->toBe(OrderRequestType::Change);
    expect($change->requested_changes)->toBe(['inscription' => '[TEST] New inscription']);
    expect($automatic->decision_mode)->toBe(OrderRequestDecisionMode::Automatic);
    expect($automatic->status)->toBe(OrderRequestStatus::Pending);
    expect($automatic->order->status->value)->toBe('pending');
    expect($applied->status)->toBe(OrderRequestStatus::Applied);
    expect($applied->reviewed_at)->not->toBeNull();
    expect($applied->applied_at)->not->toBeNull();
    expect($applied->reviewedBy->role->value)->toBe('admin');
    expect($rejected->status)->toBe(OrderRequestStatus::Rejected);
    expect($rejected->decision_reason)->not->toBeNull();
    expect($rejected->reviewed_at)->not->toBeNull();
});

test('rejects invalid request states ownership and required fields in MySQL', function (array $overrides, int $errorCode) {
    $attributes = array_replace(OrderRequest::factory()->raw(), [
        'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
    ], $overrides);

    try {
        DB::table('order_requests')->insert($attributes);
        $this->fail('MySQL accepted invalid request fields.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($errorCode);
    }
})->with([
    'unknown type' => [['type' => 'refund'], 3819],
    'type case mismatch' => [['type' => 'CHANGE'], 3819],
    'unknown decision mode' => [['decision_mode' => 'gateway'], 3819],
    'decision mode case mismatch' => [['decision_mode' => 'MANUAL'], 3819],
    'unknown status' => [['status' => 'approved'], 3819],
    'status case mismatch' => [['status' => 'PENDING'], 3819],
    'change without changes' => [['type' => 'change', 'requested_changes' => null], 3819],
    'malformed JSON' => [['requested_changes' => '{broken'], 3140],
    'applied without application time' => [['status' => 'applied', 'reviewed_at' => '2026-11-01 01:00:00'], 3819],
    'applied without review time' => [['status' => 'applied', 'applied_at' => '2026-11-01 01:00:00'], 3819],
    'rejected without review time' => [['status' => 'rejected', 'decision_reason' => 'payment_won'], 3819],
    'rejected without reason' => [['status' => 'rejected', 'reviewed_at' => '2026-11-01 01:00:00'], 3819],
    'missing order' => [['order_id' => 0], 1452],
    'missing customer' => [['user_id' => 0], 1452],
    'missing reviewer' => [['reviewed_by' => 0], 1452],
    'null reason' => [['reason' => null], 1048],
    'null submission time' => [['submitted_at' => null], 1048],
    'null key' => [['idempotency_key' => null], 1048],
    'null hash' => [['request_hash' => null], 1048],
    'null creation time' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
]);

test('rejects an existing customer who does not own the request order', function () {
    $other = User::factory()->create();

    try {
        OrderRequest::factory()->create(['user_id' => $other->id]);
        $this->fail('MySQL accepted a request owned by another customer.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1452);
    }
    $this->assertDatabaseCount('order_requests', 0);
});

test('allows only one pending request across both types for an order', function () {
    $request = OrderRequest::factory()->create();
    try {
        OrderRequest::factory()->for($request->order)->create([
            'type' => 'change', 'requested_changes' => ['inscription' => '[TEST] Change'],
        ]);
        $this->fail('MySQL accepted competing pending requests.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    $this->assertDatabaseCount('order_requests', 1);
});

test('retains terminal request history while allowing a new pending slot', function () {
    $order = Order::factory()->paid()->create();
    $applied = OrderRequest::factory()->for($order)->applied()->create()->fresh();
    $rejected = OrderRequest::factory()->for($order)->rejected()->create()->fresh();
    $pending = OrderRequest::factory()->for($order)->create()->fresh();

    expect($applied->active_order_id)->toBeNull();
    expect($rejected->active_order_id)->toBeNull();
    expect($pending->active_order_id)->toBe($order->id);
    $this->assertDatabaseCount('order_requests', 3);
});

test('deduplicates request keys per customer even across different orders', function () {
    $request = OrderRequest::factory()->create();
    $order = Order::factory()->for($request->order->user)->create();

    try {
        OrderRequest::factory()->for($order)->create(['idempotency_key' => $request->idempotency_key]);
        $this->fail('MySQL accepted a duplicate customer request key.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
});

test('exposes customer order and reviewer relationships', function () {
    $request = OrderRequest::factory()->applied()->create();

    expect($request->user->is($request->order->user))->toBeTrue();
    expect($request->user->orderRequests->sole()->is($request))->toBeTrue();
    expect($request->order->requests->sole()->is($request))->toBeTrue();
});

test('recomputes the pending slot and rejects reopening into an occupied slot', function () {
    $request = OrderRequest::factory()->create();
    $request->forceFill(['status' => 'rejected', 'reviewed_at' => now(), 'decision_reason' => 'payment_won'])->save();
    expect($request->fresh()->active_order_id)->toBeNull();
    $replacement = OrderRequest::factory()->for($request->order)->create();

    try {
        DB::table('order_requests')->where('id', $request->id)->update(['status' => 'pending']);
        $this->fail('MySQL allowed two active slots after reopening.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    expect($request->fresh()->status)->toBe(OrderRequestStatus::Rejected);
    expect($replacement->fresh()->active_order_id)->toBe($request->order_id);
});

test('rejects spoofing the generated pending slot', function () {
    $request = OrderRequest::factory()->create();
    try {
        DB::table('order_requests')->where('id', $request->id)->update(['active_order_id' => null]);
        $this->fail('MySQL accepted an explicit active slot override.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3105);
    }
    expect($request->fresh()->active_order_id)->toBe($request->order_id);
});

test('scopes request identities by customer and preserves binary key distinctions', function () {
    $first = OrderRequest::factory()->create(['idempotency_key' => 'Key-A']);
    OrderRequest::factory()->create(['idempotency_key' => 'Key-A']);
    OrderRequest::factory()->for(Order::factory()->for($first->user))->create(['idempotency_key' => 'key-a']);

    $this->assertDatabaseCount('order_requests', 3);
});

test('preserves automatic decision evidence without requiring a fictitious reviewer', function () {
    $request = OrderRequest::factory()->automaticCancellation()->applied()->create(['reviewed_by' => null])->fresh();

    expect($request->reviewed_by)->toBeNull();
    expect($request->reviewed_at)->not->toBeNull();
    expect($request->applied_at)->not->toBeNull();
});

test('restricts changing a referenced order or its owner', function (string $operation) {
    $request = OrderRequest::factory()->create();
    $other = User::factory()->create();
    try {
        $order = DB::table('orders')->where('id', $request->order_id);
        match ($operation) {
            'delete' => $order->delete(),
            'renumber' => $order->update(['id' => $request->order_id + 1000000]),
            'change owner' => $order->update(['user_id' => $other->id]),
        };
        $this->fail('MySQL changed a request parent or ownership tuple.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($request);
})->with(['delete', 'renumber', 'change owner']);

test('restricts deletion and renumbering of a request reviewer', function (string $operation) {
    $request = OrderRequest::factory()->applied()->create();
    try {
        $reviewer = DB::table('users')->where('id', $request->reviewed_by);
        if ($operation === 'delete') {
            $reviewer->delete();
        } else {
            $reviewer->update(['id' => $request->reviewed_by + 1000000]);
        }
        $this->fail('MySQL changed a referenced request reviewer.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($request);
})->with(['delete', 'renumber']);

test('refuses rollback that would discard request history', function () {
    $request = OrderRequest::factory()->create();
    $migration = require database_path('migrations/2026_10_22_064425_create_order_requests_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($request);
});

test('seeds coherent synthetic request states only in the isolated database', function () {
    $this->seed(OrderRequestTestSeeder::class);

    $this->assertDatabaseCount('order_requests', 5);
    expect(OrderRequest::query()->where('status', 'pending')->count())->toBe(3);
    expect(OrderRequest::query()->where('decision_mode', 'automatic')->sole()->order->status->value)->toBe('pending');
    foreach (OrderRequest::with('order')->get() as $request) {
        expect($request->user_id)->toBe($request->order->user_id);
    }
});

test('refuses synthetic request seeding through the development connection', function () {
    config(['database.default' => 'mysql']);
    try {
        expect(fn () => app(OrderRequestTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('order_requests', 0);
});

test('refuses synthetic request seeding outside the testing environment', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    try {
        expect(fn () => app(OrderRequestTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }
    $this->assertDatabaseCount('order_requests', 0);
});

test('matches the request dictionary types nullability generated storage and indexes', function () {
    $metadata = DB::selectOne("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'");
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, DATA_TYPE, DATETIME_PRECISION, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests' ORDER BY ORDINAL_POSITION"));

    expect($metadata->ENGINE)->toBe('InnoDB');
    expect($metadata->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci');
    expect($columns->pluck('COLUMN_TYPE', 'COLUMN_NAME')->all())->toBe([
        'id' => 'bigint unsigned', 'order_id' => 'bigint unsigned', 'user_id' => 'bigint unsigned',
        'type' => 'varchar(16)', 'decision_mode' => 'varchar(16)', 'requested_changes' => 'json', 'reason' => 'text',
        'status' => 'varchar(16)', 'submitted_at' => 'datetime', 'reviewed_by' => 'bigint unsigned', 'reviewed_at' => 'datetime',
        'decision_reason' => 'text', 'applied_at' => 'datetime', 'idempotency_key' => 'varchar(128)', 'request_hash' => 'char(64)',
        'active_order_id' => 'bigint unsigned', 'created_at' => 'datetime', 'updated_at' => 'datetime',
    ]);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe([
        'requested_changes', 'reviewed_by', 'reviewed_at', 'decision_reason', 'applied_at', 'active_order_id',
    ]);
    expect($columns->whereNotNull('COLUMN_DEFAULT')->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')->all())->toBe(['status' => 'pending']);
    expect($columns->whereIn('COLUMN_NAME', ['type', 'decision_mode', 'status', 'idempotency_key', 'request_hash'])->pluck('COLLATION_NAME')->unique()->all())->toBe(['ascii_bin']);
    expect($columns->where('DATA_TYPE', 'datetime')->pluck('DATETIME_PRECISION')->unique()->all())->toBe([0]);
    expect($columns->firstWhere('COLUMN_NAME', 'active_order_id')->EXTRA)->toContain('STORED GENERATED');
    expect($columns->firstWhere('COLUMN_NAME', 'active_order_id')->GENERATION_EXPRESSION)->not->toBe('');

    $indexes = collect(DB::select("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests' ORDER BY INDEX_NAME, SEQ_IN_INDEX"))->groupBy('INDEX_NAME');
    foreach ([['order_id', 'submitted_at', 'id'], ['status', 'submitted_at', 'id']] as $index) {
        expect($indexes->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
    }
    foreach ([['id'], ['active_order_id'], ['user_id', 'idempotency_key']] as $index) {
        expect($indexes->filter(fn ($columns): bool => $columns->first()->NON_UNIQUE === 0)->map(fn ($columns): array => $columns->pluck('COLUMN_NAME')->all())->values()->all())->toContain($index);
    }
});
