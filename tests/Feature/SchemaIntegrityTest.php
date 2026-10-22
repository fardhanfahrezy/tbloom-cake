<?php

use App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 integrity requires phpunit.mysql.xml.');
    }
});

$businessModels = [
    'users' => Models\User::class,
    'products' => Models\Product::class,
    'product_variants' => Models\ProductVariant::class,
    'product_images' => Models\ProductImage::class,
    'daily_availabilities' => Models\DailyAvailability::class,
    'orders' => Models\Order::class,
    'order_items' => Models\OrderItem::class,
    'payments' => Models\Payment::class,
    'payment_events' => Models\PaymentEvent::class,
    'order_requests' => Models\OrderRequest::class,
    'refund_logs' => Models\RefundLog::class,
    'refund_transfers' => Models\RefundTransfer::class,
    'refund_evidence' => Models\RefundEvidence::class,
    'payment_movements' => Models\PaymentMovement::class,
    'payment_movement_corrections' => Models\PaymentMovementCorrection::class,
    'custom_cake_requests' => Models\CustomCakeRequest::class,
    'custom_cake_request_attachments' => Models\CustomCakeRequestAttachment::class,
    'audit_logs' => Models\AuditLog::class,
    'notification_deliveries' => Models\NotificationDelivery::class,
    'testimonials' => Models\Testimonial::class,
];

test('installs exactly twenty business and eight infrastructure InnoDB tables', function () use ($businessModels) {
    $expected = array_merge(array_keys($businessModels), ['migrations', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs']);
    sort($expected);
    $tables = collect(DB::select('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'));
    expect($tables->pluck('TABLE_NAME')->all())->toBe($expected);
    expect($tables->pluck('ENGINE')->unique()->values()->all())->toBe(['InnoDB']);
    expect($tables->pluck('TABLE_COLLATION')->unique()->values()->all())->toBe(['utf8mb4_unicode_ci']);
});

test('gives every business model a matching table and discoverable factory', function () use ($businessModels) {
    $discovered = collect(File::files(app_path('Models')))->map(fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getBasename('.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class))->sort()->values()->all();
    $expected = array_values($businessModels);
    sort($expected);
    expect($discovered)->toBe($expected);
    foreach ($businessModels as $table => $class) {
        expect((new $class)->getTable())->toBe($table);
        expect($class::factory()->modelName())->toBe($class);
    }
});

test('preserves required second-precision business timestamps and integer money storage', function () use ($businessModels) {
    $columns = collect(DB::select('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, DATETIME_PRECISION FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE()'))->whereIn('TABLE_NAME', array_keys($businessModels));
    expect($columns->whereIn('DATA_TYPE', ['float', 'double', 'decimal']))->toBeEmpty();
    foreach (array_keys($businessModels) as $table) {
        $created = $columns->where('TABLE_NAME', $table)->firstWhere('COLUMN_NAME', 'created_at');
        expect($created)->not->toBeNull();
        expect($created->COLUMN_TYPE)->toBe('datetime');
        expect($created->IS_NULLABLE)->toBe('NO');
    }
    foreach ($columns->where('DATA_TYPE', 'datetime') as $column) {
        expect($column->DATETIME_PRECISION)->toBe(0);
    }
});

test('enforces all declared checks and restricts every business foreign key', function () {
    $checks = collect(DB::select("SELECT ENFORCED FROM information_schema.table_constraints WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"));
    expect($checks->count())->toBeGreaterThan(60);
    expect($checks->pluck('ENFORCED')->unique()->values()->all())->toBe(['YES']);
    $foreignKeys = collect(DB::select('SELECT UPDATE_RULE, DELETE_RULE FROM information_schema.referential_constraints WHERE CONSTRAINT_SCHEMA = DATABASE()'));
    expect($foreignKeys->count())->toBeGreaterThan(30);
    expect($foreignKeys->pluck('UPDATE_RULE')->unique()->values()->all())->toBe(['RESTRICT']);
    expect($foreignKeys->pluck('DELETE_RULE')->unique()->values()->all())->toBe(['RESTRICT']);
});

test('retains stored nullable unique slots for pending requests active transfers and initial corrections', function () {
    $generated = collect(DB::select("SELECT TABLE_NAME, COLUMN_NAME, EXTRA, IS_NULLABLE FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND GENERATION_EXPRESSION <> '' ORDER BY TABLE_NAME, COLUMN_NAME"));
    expect($generated->map(fn (object $column): string => $column->TABLE_NAME.'.'.$column->COLUMN_NAME)->all())->toBe(['order_requests.active_order_id', 'payment_movement_corrections.initial_movement_id', 'refund_transfers.active_refund_id']);
    expect($generated->pluck('EXTRA')->unique()->values()->all())->toBe(['STORED GENERATED']);
    expect($generated->pluck('IS_NULLABLE')->unique()->values()->all())->toBe(['YES']);
    foreach ($generated as $column) {
        $unique = DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0', [$column->TABLE_NAME, $column->COLUMN_NAME]);
        expect((int) $unique->aggregate)->toBeGreaterThan(0);
    }
});
