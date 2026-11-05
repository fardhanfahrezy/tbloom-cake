<?php

use App\CancellationRequests;
use App\CancelPaidOrder;
use App\CompleteHandover;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CommittedMySqlDatabase;
use Tests\HandoverFixture;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
});

function completeHandoverInput(Order $order, array $overrides = []): array
{
    return array_replace(['order_number' => $order->order_number, 'recipient_confirmed' => true, 'handover_confirmed' => true,
        'idempotency_key' => 'handover-1', 'expected_version' => app(CompleteHandover::class)->version($order->fresh(['item']))], $overrides);
}

test('staff completes verified paid handover atomically without releasing quota or changing money', function () {
    Http::preventStrayRequests();
    $order = HandoverFixture::paid();
    $staff = User::factory()->admin()->create();
    $allocation = $order->item->getRawOriginal();
    $money = $order->payment->getRawOriginal();
    $this->actingAs($staff)->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order, ['handed_over_by' => $order->user_id,
        'completed_at' => '2020-01-01 00:00:00', 'status' => 'pending']))
        ->assertOk()->assertJsonPath('data.status', 'completed');
    expect($order->fresh()->handed_over_by)->toBe($staff->id);
    expect($order->fresh()->completed_at->format('Y-m-d H:i:s'))->toBe('2026-11-05 03:00:00');
    expect($order->item->fresh()->getRawOriginal())->toBe($allocation);
    expect($order->payment->fresh()->getRawOriginal())->toBe($money);
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('payment_movements', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    expect(NotificationDelivery::query()->pluck('template_key')->unique()->all())->toBe(['customer.order_completed.v1']);
    Http::assertNothingSent();
});

test('handover POST hides order existence from customers as the read policy does', function () {
    $order = HandoverFixture::paid();
    $data = completeHandoverInput($order);
    $this->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertUnauthorized();
    $this->actingAs($order->user)->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertNotFound();
    $this->actingAs(User::factory()->create())->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertNotFound();
});

test('recipient confirmation actual delivery and matching order number are required', function (array $overrides, int $status) {
    $order = HandoverFixture::paid();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order, $overrides))->assertStatus($status);
    expect($order->fresh()->status->value)->toBe('paid');
})->with([
    [['recipient_confirmed' => false], 422], [['handover_confirmed' => false], 422], [['order_number' => 'wrong-order'], 409],
    [['expected_version' => 'invalid'], 422], [['idempotency_key' => '../invalid'], 422],
]);

test('exact replay is acknowledged after hours while new keys or another staff cannot recomplete', function () {
    $order = HandoverFixture::paid();
    $admin = User::factory()->admin()->create();
    $data = completeHandoverInput($order);
    $this->actingAs($admin)->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertOk();
    $stamp = $order->fresh()->completed_at;
    $this->travelTo(CarbonImmutable::parse('2026-11-05 16:00:00', 'UTC'));
    $data['recipient_confirmed'] = 'on';
    $this->postJson('/staff/orders/'.$order->id.'/handover', array_reverse($data, true))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 03:00:00', 'UTC'));
    $this->postJson('/staff/orders/'.$order->id.'/handover', array_replace($data, ['idempotency_key' => 'another-key']))->assertConflict();
    $this->actingAs(User::factory()->owner()->create())->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertConflict();
    expect($order->fresh()->completed_at->equalTo($stamp))->toBeTrue();
    expect(AuditLog::query()->where('action', 'order.completed')->count())->toBe(1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('pending cancellation holds handover and an actual rejection opens completion', function () {
    $order = HandoverFixture::paid();
    $request = OrderRequest::factory()->for($order)->create(['submitted_at' => now()]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertConflict();
    $this->get('/staff/orders/'.$order->id.'/handover')->assertOk()->assertSee('Penyerahan ditahan')->assertDontSee('name="handover_confirmed"', false);
    app(CancellationRequests::class)->reject($request->id, $admin, 'Pembatalan ditolak setelah komunikasi.');
    $this->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertOk();
    expect($request->fresh()->status->value)->toBe('rejected');
    $this->assertDatabaseCount('refund_logs', 0);
});

test('approved cancellation blocks handover while its refund is still pending', function () {
    $order = HandoverFixture::paid();
    $request = OrderRequest::factory()->for($order)->create(['submitted_at' => now()]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $refund = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Disetujui');
    expect($refund)->not->toBeNull();
    $this->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertConflict();
    expect($order->fresh()->status->value)->toBe('cancelled');
    expect($refund->fresh()->status->value)->toBe('pending');
});

test('pending expired and cancelled orders cannot complete', function (string $status) {
    $order = HandoverFixture::paid();
    $order->forceFill(['status' => $status] + match ($status) {
        'expired' => ['expired_at' => now()], 'cancelled' => ['cancelled_at' => now()], default => [],
    })->save();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertConflict();
    expect($order->fresh()->status->value)->toBe($status);
})->with(['pending', 'expired', 'cancelled']);

test('completion honors the full handover window including after production hours', function (string $localTime, int $status) {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 '.$localTime, 'Asia/Jakarta'));
    $order = HandoverFixture::paid();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertStatus($status);
})->with([['08:59:59', 403], ['09:00:00', 200], ['17:00:01', 200], ['20:59:59', 200], ['21:00:00', 200], ['21:00:01', 403]]);

test('stale identity notes or cake specifications require a refreshed checklist', function (bool $changeItem) {
    $order = HandoverFixture::paid();
    $data = completeHandoverInput($order);
    if ($changeItem) {
        $order->item->forceFill(['cover_color' => 'Merah'])->save();
    } else {
        $order->forceFill(['handover_note' => 'Kurir baru, booking berbeda'])->save();
    }
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertConflict();
    expect($order->fresh()->status->value)->toBe('paid');
})->with([false, true]);

test('audit or outbox failure rolls back completion and preserves the allocation', function (bool $auditFailure) {
    $order = HandoverFixture::paid();
    $data = completeHandoverInput($order);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    if ($auditFailure) {
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'order.completed') {
                throw new RuntimeException('Synthetic handover audit failure');
            }
        });
    } else {
        NotificationDelivery::creating(fn () => throw new RuntimeException('Synthetic handover outbox failure'));
    }
    expect(fn () => app(CompleteHandover::class)->complete($order->id, $admin, $data))->toThrow(RuntimeException::class);
    expect($order->fresh()->completed_at)->toBeNull();
    expect($order->fresh()->handed_over_by)->toBeNull();
    expect($order->fresh()->status->value)->toBe('paid');
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    expect(AuditLog::query()->where('action', 'order.completed')->count())->toBe(0);
    $this->assertDatabaseCount('notification_deliveries', 0);
})->with([false, true]);

test('financial review returned funds or missing timing proof hold handover', function (string $case) {
    $order = HandoverFixture::paid();
    if ($case === 'review') {
        $order->payment->forceFill(['review_reason' => 'time_unverified'])->save();
    } elseif ($case === 'return') {
        $event = PaymentEvent::factory()->for($order->payment)->processed()->create(['verified_at' => now()]);
        PaymentMovement::factory()->providerRefund()->create(['payment_id' => $order->payment->id, 'source_event_id' => $event->id]);
    } else {
        DB::table('audit_logs')->where('action', 'payment.time_attested')->delete();
    }
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertConflict();
    expect($order->fresh()->status->value)->toBe('paid');
})->with(['review', 'return', 'missing-proof']);

test('current staff identity activity and independent transaction are mandatory in the domain', function () {
    $order = HandoverFixture::paid();
    $admin = User::factory()->admin()->create();
    $data = completeHandoverInput($order);
    $this->actingAs(User::factory()->owner()->create());
    expect(fn () => app(CompleteHandover::class)->complete($order->id, $admin, $data))->toThrow(AuthorizationException::class);
    $this->actingAs($admin);
    User::query()->whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(CompleteHandover::class)->complete($order->id, $admin, $data))->toThrow(AuthorizationException::class);
    User::query()->whereKey($admin->id)->update(['is_active' => true]);
    DB::beginTransaction();
    try {
        expect(fn () => app(CompleteHandover::class)->complete($order->id, $admin, $data))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
});

test('whole transaction deadlock retry emits one completion and customer outbox', function () {
    $order = HandoverFixture::paid();
    $staff = User::factory()->admin()->create();
    $data = completeHandoverInput($order);
    $this->actingAs($staff);
    $failOnce = true;
    NotificationDelivery::creating(function () use (&$failOnce): void {
        if ($failOnce) {
            $failOnce = false;
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Synthetic handover deadlock'");
        }
    });
    expect(app(CompleteHandover::class)->complete($order->id, $staff, $data)->status->value)->toBe('completed');
    expect(AuditLog::query()->where('action', 'order.completed')->count())->toBe(1);
    $this->assertDatabaseCount('notification_deliveries', 2);
});

test('closing time is checked after the final item lock and released allocations cannot complete', function (bool $released) {
    $order = HandoverFixture::paid();
    $staff = User::factory()->admin()->create();
    $this->actingAs($staff);
    $data = completeHandoverInput($order);
    if ($released) {
        $order->item->forceFill(['quota_released_at' => now(), 'quota_release_reason' => 'cancelled'])->save();
        $this->postJson('/staff/orders/'.$order->id.'/handover', $data)->assertConflict();
    } else {
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_contains($query->sql, 'from `order_items`') && str_contains($query->sql, 'for update')) {
                $injected = true;
                $this->travelTo(CarbonImmutable::parse('2026-11-05 14:00:01', 'UTC'));
            }
        });
        expect(fn () => app(CompleteHandover::class)->complete($order->id, $staff, $data))->toThrow(AuthorizationException::class);
        expect($injected)->toBeTrue();
    }
    expect($order->fresh()->status->value)->toBe('paid');
})->with([false, true]);

test('pending personalization does not act as cancellation and handover checkboxes are explicit', function () {
    $order = HandoverFixture::paid();
    $request = OrderRequest::factory()->for($order)->change()->create(['submitted_at' => now()]);
    $staff = User::factory()->admin()->create();
    $this->actingAs($staff);
    $html = $this->get('/staff/orders/'.$order->id.'/handover')->assertOk()->getContent();
    foreach (['recipient_confirmed', 'handover_confirmed'] as $name) {
        expect(preg_match('/<input[^>]*name="'.$name.'"[^>]*>/', $html, $matches))->toBe(1);
        expect($matches[0])->not->toContain('checked');
    }
    $this->postJson('/staff/orders/'.$order->id.'/handover', completeHandoverInput($order))->assertOk();
    expect($request->fresh()->status->value)->toBe('pending');
});
