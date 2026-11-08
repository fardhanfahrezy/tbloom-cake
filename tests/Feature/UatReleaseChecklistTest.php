<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

test('all Must Have F-001 to F-010 and Should Have F-008 routes are registered and protected appropriately', function () {
    $expectedNamedRoutes = [
        // F-001 Catalog & Availability
        'home',
        'catalog.index',
        'catalog.show',
        // F-002 Account, Checkout & Order History
        'register',
        'register.store',
        'login',
        'login.store',
        'logout',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'account.show',
        'profile.edit',
        'profile.update',
        'checkout.create',
        'checkout.store',
        'orders.show',
        // F-003 Custom Cake Requests & Attachments
        'custom-requests.create',
        'custom-requests.store',
        'custom-requests.show',
        'custom-attachments.show',
        // F-004 & F-009 Catalog & Quota Management
        'staff.products.index',
        'staff.products.create',
        'staff.products.store',
        'staff.products.edit',
        'staff.products.update',
        'staff.products.quota.edit',
        'staff.products.quota.default',
        'staff.products.quota.daily',
        // F-005 Pickup & Handover
        'pickup.show',
        'orders.handover-note.update',
        'staff.orders.handover.show',
        'staff.orders.handover.store',
        // F-006 Payment & Webhook
        'orders.payment-session.store',
        'webhooks.midtrans',
        'staff.payments.reconciliation.store',
        // F-007 Cancellation, Changes & Refund
        'orders.cancellation-requests.store',
        'orders.change-requests.store',
        'staff.cancellation-requests.approval.store',
        'staff.cancellation-requests.rejection.store',
        'staff.change-requests.approval.store',
        'staff.change-requests.rejection.store',
        'staff.orders.cancellation.store',
        'refunds.bank.show',
        'refunds.bank.update',
        'staff.refunds.claim.store',
        'staff.refunds.takeover.store',
        'staff.refunds.bank-verification.store',
        'staff.refunds.transfers.store',
        'staff.refunds.transfers.completion-context.show',
        'staff.refunds.transfers.unknown.store',
        'staff.refunds.transfers.completion.store',
        'staff.refunds.transfers.confirmed-failure.store',
        'staff.refunds.evidence.store',
        'refund-evidence.show',
        // F-008 Store Information & Testimonials
        'staff.testimonials.index',
        'staff.testimonials.store',
        'staff.testimonials.update',
        // F-010 Operational Dashboard & Staff Accounts
        'staff.dashboard',
        'staff.custom-requests.index',
        'staff.custom-requests.edit',
        'staff.custom-requests.status',
        'staff.custom-requests.design',
        'staff.accounts.index',
        'staff.accounts.store',
        'staff.accounts.update',
    ];

    foreach ($expectedNamedRoutes as $routeName) {
        expect(Route::has($routeName))->toBeTrue("Expected route [{$routeName}] to be registered.");
    }

    $checkoutRoute = Route::getRoutes()->getByName('checkout.store');
    expect($checkoutRoute)->not->toBeNull()
        ->and($checkoutRoute->gatherMiddleware())->toContain('auth');
});

test('release checklist enforces K-20 no-auto-purge policy and registers all operational Artisan commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain(
        'payments:dispatch-events',
        'payments:reconcile',
        'notifications:dispatch',
        'ops:status',
    );

    $scheduledCommands = array_map(
        fn ($event): string => (string) $event->command,
        Schedule::events()
    );

    expect($scheduledCommands)->toHaveCount(3);
    foreach ($scheduledCommands as $commandString) {
        expect($commandString)->not->toContain('prune')
            ->and($commandString)->not->toContain('purge')
            ->and($commandString)->not->toContain('clear');
    }
});
