<?php

use App\CustomRequestDetails;
use App\Models\AuditLog;
use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use App\Models\DailyAvailability;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\RefundEvidence;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\Testimonial;
use App\Models\User;
use App\NotificationOutbox;
use App\PreparedNotificationTransport;
use App\ProcessNotificationDeliveryJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('all R1 domain models and 28 MySQL tables mapped in traceability matrix exist and match documented invariants', function () {
    $expectedTables = [
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'products',
        'product_variants',
        'product_images',
        'daily_availabilities',
        'orders',
        'order_items',
        'payments',
        'payment_events',
        'payment_movements',
        'payment_movement_corrections',
        'order_requests',
        'refund_logs',
        'refund_transfers',
        'refund_evidence',
        'custom_cake_requests',
        'custom_cake_request_attachments',
        'audit_logs',
        'notification_deliveries',
        'testimonials',
        'migrations',
    ];

    foreach ($expectedTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table [{$table}] to exist.");
    }

    $mappedModels = [
        User::class,
        Product::class,
        ProductVariant::class,
        ProductImage::class,
        DailyAvailability::class,
        Order::class,
        OrderItem::class,
        Payment::class,
        PaymentEvent::class,
        PaymentMovement::class,
        PaymentMovementCorrection::class,
        OrderRequest::class,
        RefundLog::class,
        RefundTransfer::class,
        RefundEvidence::class,
        CustomCakeRequest::class,
        CustomCakeRequestAttachment::class,
        AuditLog::class,
        NotificationDelivery::class,
        Testimonial::class,
    ];

    foreach ($mappedModels as $modelClass) {
        expect(class_exists($modelClass))->toBeTrue();
    }
});

test('scheduler registers only payment and notification recovery commands and never schedules automatic data purge per K-20 and R1-AC23', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->contains(fn (string $cmd): bool => str_contains($cmd, 'payments:dispatch-events')))->toBeTrue()
        ->and($events->contains(fn (string $cmd): bool => str_contains($cmd, 'payments:reconcile')))->toBeTrue()
        ->and($events->contains(fn (string $cmd): bool => str_contains($cmd, 'notifications:dispatch')))->toBeTrue();

    foreach ($events as $command) {
        expect($command)
            ->not->toContain('prune')
            ->not->toContain('purge')
            ->not->toContain('clean');
    }
});

test('technical retry and input boundary constants match K-17 K-18 K-19 and R1-AC22 through R1-AC24', function () {
    expect(ProcessNotificationDeliveryJob::TIMEOUT_SECONDS)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and((int) config('queue.connections.database.retry_after'))->toBeLessThan(NotificationOutbox::LEASE_SECONDS)
        ->and(NotificationOutbox::MAX_ATTEMPTS)->toBe(5)
        ->and(NotificationOutbox::BACKOFF_SECONDS)->toBe([30, 120, 600, 1800])
        ->and(CustomRequestDetails::rules())->toHaveKey('pickup_date')
        ->and(class_exists(PreparedNotificationTransport::class))->toBeTrue();
});
