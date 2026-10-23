<?php

use App\Models;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Exact enum integrity requires isolated MySQL.');
    }
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('h', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
});

test('rejects padded enum values before they can break model hydration', function (string $model, string $column) {
    $factory = $model::factory();
    if ($column === 'closure_result') {
        $factory = $factory->closedUnpaid();
    } elseif ($column === 'quota_release_reason') {
        $factory = $factory->released(Models\QuotaReleaseReason::Expired);
    }
    $record = $factory->create()->fresh();
    $original = $record->$column;
    expect($original)->toBeInstanceOf(BackedEnum::class);
    try {
        DB::table($record->getTable())->where('id', $record->id)->update([$column => $original->value.' ']);
        $this->fail('MySQL accepted a padded enum value.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(3819);
    }
    expect($record->fresh()->$column)->toBe($original);
})->with([
    'user role' => [Models\User::class, 'role'],
    'product category' => [Models\Product::class, 'category'],
    'testimonial publication' => [Models\Testimonial::class, 'publication_status'],
    'order status' => [Models\Order::class, 'status'],
    'pickup method' => [Models\Order::class, 'pickup_method'],
    'quota release reason' => [Models\OrderItem::class, 'quota_release_reason'],
    'payment status' => [Models\Payment::class, 'status'],
    'payment closure' => [Models\Payment::class, 'closure_result'],
    'event processing status' => [Models\PaymentEvent::class, 'processing_status'],
    'request type' => [Models\OrderRequest::class, 'type'],
    'request decision mode' => [Models\OrderRequest::class, 'decision_mode'],
    'request status' => [Models\OrderRequest::class, 'status'],
    'refund reason' => [Models\RefundLog::class, 'reason_type'],
    'refund status' => [Models\RefundLog::class, 'status'],
    'transfer status' => [Models\RefundTransfer::class, 'status'],
    'movement kind' => [Models\PaymentMovement::class, 'kind'],
    'movement source' => [Models\PaymentMovement::class, 'source'],
    'custom status' => [Models\CustomCakeRequest::class, 'status'],
    'audit actor' => [Models\AuditLog::class, 'actor_type'],
    'delivery status' => [Models\NotificationDelivery::class, 'status'],
]);
