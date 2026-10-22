<?php

use App\Models;
use App\OwnedResourceAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Resource policy fixtures require isolated MySQL.');
    }
});

function ownedPolicyFixture(string $type, Models\User $owner): Model
{
    $order = Models\Order::factory()->for($owner)->paid();
    $payment = Models\Payment::factory()->for($order)->paid();
    $refund = Models\RefundLog::factory()->processing()->state(['payment_id' => $payment]);
    $transfer = Models\RefundTransfer::factory()->state(['refund_log_id' => $refund]);
    $custom = Models\CustomCakeRequest::factory()->for($owner);

    return match ($type) {
        'order' => $order->create(),
        'payment' => $payment->create(),
        'order_request' => Models\OrderRequest::factory()->for($order)->create(),
        'custom' => $custom->create(),
        'custom_attachment' => Models\CustomCakeRequestAttachment::factory()->state(['custom_cake_request_id' => $custom])->create(),
        'refund' => $refund->create(),
        'transfer' => $transfer->create(),
        'evidence' => Models\RefundEvidence::factory()->state(['refund_transfer_id' => $transfer])->create(),
    };
}

test('allows each own resource and hides it from a different customer through registered policies', function (string $type) {
    $owner = Models\User::factory()->create();
    $other = Models\User::factory()->create();
    $resource = ownedPolicyFixture($type, $owner);
    expect(Gate::forUser($owner)->allows('view', $resource))->toBeTrue();
    $denial = Gate::forUser($other)->inspect('view', $resource);
    expect($denial->denied())->toBeTrue();
    expect($denial->status())->toBe(404);
    expect(Gate::forUser($other)->allows('update', $resource))->toBeFalse();
})->with(['order', 'payment', 'order_request', 'custom', 'custom_attachment', 'refund', 'transfer', 'evidence']);

test('applies the shared active role matrix without granting financial mutations', function (Models\UserRole $role, bool $active, bool $own, bool $allowed) {
    $user = Models\User::factory()->make(['id' => 10, 'role' => $role, 'is_active' => $active]);
    $decision = OwnedResourceAccess::view($user, $own ? 10 : 11);
    expect($decision->allowed())->toBe($allowed);
    if (! $allowed) {
        expect($decision->status())->toBe(404);
    }
})->with([
    'own active customer' => [Models\UserRole::Customer, true, true, true],
    'other customer' => [Models\UserRole::Customer, true, false, false],
    'inactive own customer' => [Models\UserRole::Customer, false, true, false],
    'active Admin' => [Models\UserRole::Admin, true, false, true],
    'active Owner' => [Models\UserRole::Owner, true, false, true],
    'inactive Admin' => [Models\UserRole::Admin, false, false, false],
    'inactive Owner' => [Models\UserRole::Owner, false, false, false],
]);

test('denies guest access and keeps generic domain mutations closed to staff', function () {
    $owner = Models\User::factory()->owner()->create();
    $order = Models\Order::factory()->create();
    expect(Gate::forUser(null)->allows('view', $order))->toBeFalse();
    expect(Gate::forUser($owner)->allows('view', $order))->toBeTrue();
    expect(Gate::forUser($owner)->allows('update', $order))->toBeFalse();
    expect(Gate::forUser($owner)->allows('delete', $order))->toBeFalse();
});
