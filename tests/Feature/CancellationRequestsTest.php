<?php

use App\CancellationRequests;
use App\CloseUnpaidPayment;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\ProcessPaymentEvent;
use App\ReconciledPaymentTime;
use App\ReconcilePayments;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));
});

function cancellationOrder(bool $paid = true): Order
{
    $order = ($paid ? Order::factory()->paid() : Order::factory())->create([
        'pickup_date' => $paid ? '2026-10-31' : '2026-10-30', 'created_at' => '2026-10-29 02:45:00',
    ]);
    OrderItem::factory()->for($order)->create(['unit_price' => 100000, 'line_total' => 100000]);
    ($paid ? Payment::factory()->paid() : Payment::factory())->for($order)->create(['merchant_reference' => $order->order_number,
        'created_at' => '2026-10-29 02:45:00', 'updated_at' => '2026-10-29 02:45:00']);

    return $order;
}

function cancellationInput(array $changes = []): array
{
    return array_replace(['reason' => 'Rencana acara berubah', 'idempotency_key' => 'cancel-request-1'], $changes);
}

test('paid cancellation at the final H2 second commits a manual pending request without financial effects', function () {
    Http::preventStrayRequests();
    $order = cancellationOrder();
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->inactive()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 16:59:59', 'UTC'));

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput([
        'user_id' => $admin->id, 'status' => 'applied', 'submitted_at' => '2026-10-28 00:00:00', 'decision_mode' => 'automatic',
    ]))->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.decision_mode', 'manual');

    $request = OrderRequest::query()->sole();
    expect($request->user_id)->toBe($order->user_id);
    expect($request->submitted_at->toDateTimeString())->toBe('2026-10-29 16:59:59');
    expect($order->fresh()->status->value)->toBe('paid');
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('audit_logs', 1);
    expect(NotificationDelivery::query()->pluck('template_key')->unique()->values()->all())->toEqualCanonicalizing(['customer.cancellation_submitted.v1', 'admin.cancellation.v1']);
    $this->assertDatabaseCount('notification_deliveries', 4);
    expect(json_encode(NotificationDelivery::query()->pluck('payload_minimal')))->not->toContain($request->reason, $order->email_snapshot);
    expect(json_encode(AuditLog::query()->sole()->after_redacted))->not->toContain($request->reason);
    Http::assertNothingSent();
});

test('paid cancellation after the Jakarta H2 date is rejected without accepting a forged submitted time', function () {
    $order = cancellationOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 17:00:00', 'UTC'));

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput(['submitted_at' => '2026-10-28 00:00:00']))
        ->assertUnprocessable()->assertJsonValidationErrors('cancellation');

    $this->assertDatabaseCount('order_requests', 0);
});

test('pending cancellation after H2 stays automatic and held until existing final closure is applied', function () {
    Http::preventStrayRequests();
    $order = cancellationOrder(false);

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())
        ->assertCreated()->assertJsonPath('data.decision_mode', 'automatic');

    expect($order->fresh()->status->value)->toBe('pending');
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    expect(app(CloseUnpaidPayment::class)->closeNeverRequested($order->payment->id))->toBeTrue();
    expect($order->fresh()->status->value)->toBe('cancelled');
    expect(OrderRequest::query()->sole()->status->value)->toBe('applied');
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertNothingSent();
});

test('the recovery scanner handles automatic no-session cancellation before the payment deadline', function () {
    $order = cancellationOrder(false);
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertCreated();

    expect(app(ReconcilePayments::class)->dispatch())->toBe(0);

    expect($order->fresh()->status->value)->toBe('cancelled');
    expect(OrderRequest::query()->sole()->decision_reason)->toBe('unpaid_closed');
});

test('same key replays even after the request was rejected while changed details or another order conflict', function () {
    $order = cancellationOrder();
    $this->actingAs($order->user);
    $service = app(CancellationRequests::class);
    $first = $service->submit($order->id, $order->user, cancellationInput());
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $service->reject($first->id, $admin, 'Tidak dapat dipenuhi');
    $count = NotificationDelivery::count();

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertOk()->assertJsonPath('data.id', $first->id);
    $this->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput(['reason' => 'Alasan berbeda']))->assertConflict();
    $other = cancellationOrder();
    $other->forceFill(['user_id' => $order->user_id])->save();
    $this->postJson('/orders/'.$other->id.'/cancellation-requests', cancellationInput())->assertConflict();
    $this->assertDatabaseCount('order_requests', 1);
    expect(NotificationDelivery::count())->toBe($count);
});

test('a second cancellation key cannot create a second active request', function () {
    $order = cancellationOrder();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertCreated();
    $this->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput(['idempotency_key' => 'other-key']))->assertConflict();

    $this->assertDatabaseCount('order_requests', 1);
});

test('a valid cancellation atomically supersedes a pending change without deleting its history', function () {
    $order = cancellationOrder();
    $change = OrderRequest::factory()->for($order)->change()->create();

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertCreated();

    expect($change->fresh()->status->value)->toBe('rejected');
    expect($change->fresh()->decision_reason)->toBe('superseded_by_cancellation');
    expect($change->fresh()->requested_changes)->toBe($change->requested_changes);
    expect(OrderRequest::query()->where('status', 'pending')->sole()->type->value)->toBe('cancellation');
    $this->assertDatabaseCount('audit_logs', 2);
});

test('ineligible cancellation cannot supersede a valid pending change', function () {
    $order = cancellationOrder();
    $change = OrderRequest::factory()->for($order)->change()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertUnprocessable();

    expect($change->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('audit_logs', 0);
});

test('terminal orders cannot receive cancellation requests', function (string $state) {
    $order = cancellationOrder();
    $fields = ['status' => $state, $state.'_at' => now()];
    if ($state === 'completed') {
        $fields['handed_over_by'] = User::factory()->admin()->create()->id;
    }
    $order->forceFill($fields)->save();

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertUnprocessable();

    $this->assertDatabaseCount('order_requests', 0);
})->with(['completed', 'cancelled', 'expired']);

test('submit endpoint protects guest role ownership and inactive account boundaries', function () {
    $order = cancellationOrder();
    $this->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertNotFound();
    $order->user->forceFill(['is_active' => false])->save();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertUnauthorized();
    $this->assertDatabaseCount('order_requests', 0);
});

test('submission rejects empty reason and invalid idempotency keys', function (array $input, string $field) {
    $order = cancellationOrder();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput($input))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('order_requests', 0);
})->with([
    [['reason' => '   '], 'reason'],
    [['reason' => str_repeat('a', 1001)], 'reason'],
    [['idempotency_key' => ''], 'idempotency_key'],
    [['idempotency_key' => 'a key'], 'idempotency_key'],
]);

test('staff rejection after H2 uses the original eligible submission and is idempotent without a refund', function () {
    $order = cancellationOrder();
    $request = OrderRequest::factory()->for($order)->create();
    $admin = User::factory()->admin()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 03:00:00', 'UTC'));

    $this->actingAs($admin)->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Persiapan telah berjalan'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
    $count = NotificationDelivery::count();
    $this->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Persiapan telah berjalan'])->assertOk();
    $this->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Alasan lain'])->assertConflict();

    expect($request->fresh()->reviewed_by)->toBe($admin->id);
    expect($request->fresh()->decision_reason)->toBe('Persiapan telah berjalan');
    expect($order->fresh()->status->value)->toBe('paid');
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('audit_logs', 1);
    expect(NotificationDelivery::count())->toBe($count);
});

test('staff cannot reject an automatic pending-unpaid cancellation as a discretionary decision', function () {
    $order = cancellationOrder(false);
    $request = OrderRequest::factory()->for($order)->automaticCancellation()->create();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Tolak'])
        ->assertConflict();
    expect($request->fresh()->status->value)->toBe('pending');
});

test('customer cannot reject a request and staff must supply a reason during business hours', function () {
    $order = cancellationOrder();
    $request = OrderRequest::factory()->for($order)->create();
    $this->actingAs($order->user)->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Tolak'])->assertForbidden();
    $this->actingAs(User::factory()->owner()->create())->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:01', 'UTC'));
    $this->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Tolak'])->assertForbidden();
    expect($request->fresh()->status->value)->toBe('pending');
});

test('outbox failure rolls back cancellation submission and superseded change together', function () {
    $order = cancellationOrder();
    $change = OrderRequest::factory()->for($order)->change()->create();
    $this->actingAs($order->user);
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic cancellation outbox failure'));
    try {
        expect(fn () => app(CancellationRequests::class)->submit($order->id, $order->user, cancellationInput()))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect($change->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('order_requests', 1);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('submitted automatic cancellation loses to timely payment and a new paid cancellation must satisfy H2', function () {
    $order = cancellationOrder(false);
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertCreated();
    $request = OrderRequest::query()->sole();
    $payment = $order->payment;
    $owner = User::factory()->owner()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 03:05:00', 'UTC'));
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-cancellation-key']);
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response([
        'order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100000.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-29 09:46:00', 'settlement_time' => '2026-10-29 10:02:00',
    ])]);
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now()]);
    $this->actingAs($owner);
    app(ProcessPaymentEvent::class)->process($event->id, $owner, ReconciledPaymentTime::fromReconciliation(
        '123e4567-e89b-42d3-a456-426614174000', CarbonImmutable::parse('2026-10-29 03:01:59', 'UTC'), 'private-bank-time',
    ));

    expect($order->fresh()->status->value)->toBe('paid');
    expect($request->fresh()->decision_reason)->toBe('payment_won');
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput(['idempotency_key' => 'paid-new-key']))->assertUnprocessable();
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertSentCount(1);
});

test('an issued pending instrument keeps submitted automatic cancellation and allocation held', function () {
    $order = cancellationOrder(false);
    $payment = $order->payment;
    $payment->forceFill(['snap_requested_at' => CarbonImmutable::parse('2026-10-29 02:46:00', 'UTC')])->save();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertCreated();
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-cancellation-key']);
    Http::preventStrayRequests();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status' => Http::response([
        'order_id' => $payment->merchant_reference, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'status_code' => '201', 'transaction_status' => 'pending', 'gross_amount' => '100000.00', 'currency' => 'IDR',
        'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-29 09:46:00',
    ])]);

    $event = app(ReconcilePayments::class)->prepare($payment->id);
    expect($event)->not->toBeNull();
    app(ProcessPaymentEvent::class)->process($event->id);

    expect($order->fresh()->status->value)->toBe('pending');
    expect(OrderRequest::query()->sole()->status->value)->toBe('pending');
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    expect($payment->fresh()->closure_result)->toBeNull();
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertSentCount(1);
});

test('service submit rereads a stale or mismatched actor instead of trusting the caller object', function (string $change) {
    $order = cancellationOrder();
    $actor = $order->user;
    $this->actingAs($actor);
    if ($change === 'inactive') {
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
    } elseif ($change === 'role') {
        DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']);
    } else {
        $this->actingAs(User::factory()->create());
    }

    expect(fn () => app(CancellationRequests::class)->submit($order->id, $actor, cancellationInput()))->toThrow(HttpException::class);

    $this->assertDatabaseCount('order_requests', 0);
})->with(['inactive', 'role', 'mismatched']);

test('rejection rechecks business hours after its last domain lock', function () {
    $order = cancellationOrder();
    $request = OrderRequest::factory()->for($order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    OrderRequest::retrieved(function () {
        if (DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:01', 'UTC'));
        }
    });
    try {
        expect(fn () => app(CancellationRequests::class)->reject($request->id, $admin, 'Tidak dapat dipenuhi'))->toThrow(AuthorizationException::class);
    } finally {
        OrderRequest::flushEventListeners();
    }
    expect($request->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('audit_logs', 0);
});

test('rejection outbox failure leaves the request pending and all effects rolled back', function () {
    $order = cancellationOrder();
    $request = OrderRequest::factory()->for($order)->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic rejection outbox failure'));
    try {
        expect(fn () => app(CancellationRequests::class)->reject($request->id, $admin, 'Tidak dapat dipenuhi'))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect($request->fresh()->status->value)->toBe('pending');
    expect($request->fresh()->reviewed_at)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('rejection refuses a change applied request or terminal order without overwriting history', function (string $state) {
    $order = cancellationOrder();
    $factory = OrderRequest::factory()->for($order);
    $request = match ($state) {
        'change' => $factory->change()->create(),
        'applied' => $factory->applied()->create(),
        default => $factory->create(),
    };
    if ($state === 'terminal') {
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
    }
    $before = $request->fresh()->getRawOriginal();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Tolak'])->assertConflict();

    expect($request->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['change', 'applied', 'terminal']);

test('canonical reason replay and independent users sharing a key retain separate requests', function () {
    $first = cancellationOrder();
    $second = cancellationOrder();
    $this->actingAs($first->user)->postJson('/orders/'.$first->id.'/cancellation-requests', cancellationInput(['reason' => '  Rencana acara berubah  ']))->assertCreated();
    $this->postJson('/orders/'.$first->id.'/cancellation-requests', cancellationInput())->assertOk();
    $this->actingAs($second->user)->postJson('/orders/'.$second->id.'/cancellation-requests', cancellationInput())->assertCreated();

    $this->assertDatabaseCount('order_requests', 2);
    expect(AuditLog::query()->where('action', 'cancellation.submitted')->count())->toBe(2);
    expect(AuditLog::query()->oldest('id')->first()->after_redacted['reason_sha256'])->toBe(hash('sha256', 'Rencana acara berubah'));
});

test('rejection refuses a stale inactive demoted or mismatched staff actor', function (string $change) {
    $order = cancellationOrder();
    $request = OrderRequest::factory()->for($order)->create();
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    if ($change === 'inactive') {
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
    } elseif ($change === 'role') {
        DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']);
    } else {
        $this->actingAs(User::factory()->owner()->create());
    }

    expect(fn () => app(CancellationRequests::class)->reject($request->id, $actor, 'Tidak dapat dipenuhi'))->toThrow(AuthorizationException::class);

    expect($request->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['inactive', 'role', 'mismatched']);

test('staff rejection is permitted exactly at the documented end of business hours', function () {
    $order = cancellationOrder();
    $request = OrderRequest::factory()->for($order)->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'));

    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/cancellation-requests/'.$request->id.'/rejection', ['reason' => 'Tidak dapat dipenuhi'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

test('whole transaction retry cannot duplicate submission audit or supersession', function () {
    $order = cancellationOrder();
    $change = OrderRequest::factory()->for($order)->change()->create();
    $this->actingAs($order->user);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic cancellation retry'");
        }
    });
    try {
        $result = app(CancellationRequests::class)->submit($order->id, $order->user, cancellationInput());
    } finally {
        NotificationDelivery::flushEventListeners();
    }

    expect($result->status->value)->toBe('pending');
    expect($change->fresh()->status->value)->toBe('rejected');
    $this->assertDatabaseCount('order_requests', 2);
    $this->assertDatabaseCount('audit_logs', 2);
    $this->assertDatabaseCount('notification_deliveries', 2);
    Sleep::assertSleptTimes(1);
});

test('a locked parent order blocks submission even while no request row exists', function () {
    $order = cancellationOrder();
    $this->actingAs($order->user);
    $other = DB::build(config('database.connections.mysql_testing'));
    $timeout = DB::selectOne('SELECT @@innodb_lock_wait_timeout AS timeout_seconds')->timeout_seconds;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    Sleep::fake();
    $other->beginTransaction();
    $other->table('orders')->where('id', $order->id)->lockForUpdate()->first();
    try {
        expect(fn () => app(CancellationRequests::class)->submit($order->id, $order->user, cancellationInput()))->toThrow(QueryException::class);
    } finally {
        $other->rollBack();
        $other->disconnect();
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
    }

    $this->assertDatabaseCount('order_requests', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Sleep::assertSleptTimes(2);
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertCreated();
});

test('submission evaluates H2 after the parent locks rather than at request arrival', function () {
    $order = cancellationOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-29 16:59:59', 'UTC'));
    Payment::retrieved(function () {
        if (DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-29 17:00:00', 'UTC'));
        }
    });
    try {
        $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/cancellation-requests', cancellationInput())->assertUnprocessable();
    } finally {
        Payment::flushEventListeners();
    }

    $this->assertDatabaseCount('order_requests', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('cancellation policy only grants the active owning customer and conceals denied orders', function (string $role, bool $active, bool $own, bool $allowed) {
    $order = cancellationOrder();
    $actor = User::factory()->make(['id' => $own ? $order->user_id : $order->user_id + 100, 'role' => $role, 'is_active' => $active]);

    $decision = Gate::forUser($actor)->inspect('cancel', $order);

    expect($decision->allowed())->toBe($allowed);
    if (! $allowed) {
        expect($decision->status())->toBe(404);
    }
})->with([
    ['customer', true, true, true], ['customer', true, false, false], ['customer', false, true, false],
    ['admin', true, false, false], ['owner', true, false, false], ['admin', false, false, false], ['owner', false, false, false],
]);
