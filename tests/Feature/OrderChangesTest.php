<?php

use App\CancellationRequests;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\Payment;
use App\Models\User;
use App\OrderChanges;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
});

function personalizableOrder(bool $paid = true): Order
{
    $order = ($paid ? Order::factory()->paid() : Order::factory())->create(['pickup_date' => '2026-11-01', 'created_at' => '2026-10-29 02:00:00']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100000, 'line_total' => 100000,
        'flavor_code' => 'chocolate', 'flavor_label_snapshot' => 'Original chocolate label', 'filling_code' => 'strawberry',
        'filling_label_snapshot' => 'Original strawberry label', 'cover_color' => 'Putih', 'inscription' => 'Tulisan lama']);
    ($paid ? Payment::factory()->paid() : Payment::factory())->for($order)->create(['merchant_reference' => $order->order_number]);

    return $order;
}

function personalizationInput(array $overrides = []): array
{
    return array_replace(['reason' => 'Menyesuaikan acara', 'idempotency_key' => 'change-request-1', 'requested_changes' => ['cover_color' => 'Biru']], $overrides);
}

test('customer partial personalization request at H2 remains unapplied until staff decides', function () {
    Http::preventStrayRequests();
    $order = personalizableOrder();
    User::factory()->admin()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 16:59:59', 'UTC'));

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', personalizationInput(['status' => 'applied', 'pickup_time' => '21:00']))
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    expect(OrderRequest::query()->sole()->requested_changes)->toBe(['cover_color' => 'Biru']);
    expect(OrderRequest::query()->sole()->submitted_at->toDateTimeString())->toBe('2026-10-30 16:59:59');
    expect($order->item->fresh()->cover_color)->toBe('Putih');
    expect($order->fresh()->pickup_time)->toBe('09:00:00');
    expect(NotificationDelivery::query()->pluck('template_key')->unique()->values()->all())->toBe(['customer.order_change_submitted.v1']);
    $this->assertDatabaseCount('notification_deliveries', 2);
    $this->assertDatabaseCount('refund_logs', 0);
    Http::assertNothingSent();
});

test('owned courier note can be changed on pickup day outside staff hours without changing schedule or money', function () {
    $order = personalizableOrder();
    $before = $order->item->getRawOriginal();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 13:00:00', 'UTC'));

    $this->actingAs($order->user)->patchJson('/orders/'.$order->id.'/handover-note', ['handover_note' => 'Kode kurir ABC123', 'pickup_time' => '20:00', 'product_total' => 1])->assertOk();

    expect($order->fresh()->handover_note)->toBe('Kode kurir ABC123');
    expect($order->fresh()->pickup_time)->toBe('09:00:00');
    expect($order->fresh()->product_total)->toBe(100000);
    expect($order->item->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('order_requests', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    $this->assertDatabaseCount('audit_logs', 1);
    expect(json_encode(AuditLog::query()->sole()->after_redacted))->not->toContain('ABC123');
});

test('paid personalization submission after H2 is rejected using the current server date', function () {
    $order = personalizableOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 17:00:00', 'UTC'));
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', personalizationInput(['submitted_at' => '2026-10-29 00:00:00']))
        ->assertUnprocessable()->assertJsonValidationErrors('change');
    $this->assertDatabaseCount('order_requests', 0);
});

test('personalization cannot request mutable pricing quota or pickup fields', function (string $key) {
    $order = personalizableOrder();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', personalizationInput(['requested_changes' => [$key => '1']]))
        ->assertUnprocessable()->assertJsonValidationErrors('requested_changes');
    $this->assertDatabaseCount('order_requests', 0);
})->with(['product_id', 'product_variant_id', 'quantity', 'unit_price', 'pickup_date', 'pickup_time', 'pickup_method', 'flavor_label_snapshot']);

test('staff applies only the requested color while preserving removed historical flavor label and inscription', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput());
    config(['checkout.flavors' => [], 'checkout.fillings' => []]);
    $admin = User::factory()->admin()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'));

    $this->actingAs($admin)->postJson('/staff/change-requests/'.$request->id.'/approval', ['reason' => 'Disetujui'])->assertOk()->assertJsonPath('data.status', 'applied');

    $item = $order->item->fresh();
    expect($item->cover_color)->toBe('Biru');
    expect($item->flavor_code)->toBe('chocolate');
    expect($item->flavor_label_snapshot)->toBe('Original chocolate label');
    expect($item->filling_label_snapshot)->toBe('Original strawberry label');
    expect($item->inscription)->toBe('Tulisan lama');
    expect($item->line_total)->toBe(100000);
    expect($item->quota_released_at)->toBeNull();
    expect($request->fresh()->applied_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'order_change.applied')->sole()->before_redacted['cover_color'])->toBe('Putih');
});

test('new changed flavor gets its server label while omitted filling retains the original label', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput(['requested_changes' => ['flavor_code' => 'vanilla']]));
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(OrderChanges::class)->approve($request->id, $admin, 'Disetujui');

    expect($order->item->fresh()->flavor_label_snapshot)->toBe(config('checkout.flavors.vanilla'));
    expect($order->item->fresh()->filling_label_snapshot)->toBe('Original strawberry label');
});

test('inscription none clears text and explicit text is normalized without persisting the choice control', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $service = app(OrderChanges::class);
    $request = $service->submit($order->id, $order->user, personalizationInput(['requested_changes' => ['inscription_choice' => 'none']]));
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $service->approve($request->id, $admin, 'Disetujui');
    expect($order->item->fresh()->inscription)->toBe('');

    $this->actingAs($order->user);
    $next = $service->submit($order->id, $order->user, personalizationInput(['idempotency_key' => 'change-2',
        'requested_changes' => ['inscription_choice' => 'text', 'inscription' => " Selamat\u{00a0} ulang\t tahun "]]));
    $this->actingAs($admin);
    $service->approve($next->id, $admin, 'Disetujui');
    expect($order->item->fresh()->inscription)->toBe('Selamat ulang tahun');
});

test('inscription changes require the explicit paired text API and the checkout word and length limits', function (array $changes) {
    $order = personalizableOrder();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', personalizationInput(['requested_changes' => $changes]))->assertUnprocessable();
    $this->assertDatabaseCount('order_requests', 0);
})->with([
    [['inscription_choice' => 'text']], [['inscription' => 'Tanpa pilihan']],
    [['inscription_choice' => 'none', 'inscription' => 'Teks bertentangan']],
    [['inscription_choice' => 'text', 'inscription' => 'satu dua tiga empat lima enam tujuh delapan sembilan sepuluh sebelas']],
    [['inscription_choice' => 'text', 'inscription' => str_repeat('a', 151)]],
]);

test('cancellation priority uses the shared active slot and cannot be replaced by a later change', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput());
    app(CancellationRequests::class)->submit($order->id, $order->user, ['reason' => 'Batal', 'idempotency_key' => 'cancel-1']);

    expect($request->fresh()->decision_reason)->toBe('superseded_by_cancellation');
    $this->postJson('/orders/'.$order->id.'/change-requests', personalizationInput(['idempotency_key' => 'change-after-cancel']))->assertConflict();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/staff/change-requests/'.$request->id.'/approval', ['reason' => 'Disetujui'])->assertConflict();
    expect($order->item->fresh()->cover_color)->toBe('Putih');
});

test('canonical change replay is independent of key order whitespace current options and later terminal history', function () {
    $order = personalizableOrder();
    $input = personalizationInput(['requested_changes' => ['flavor_code' => 'vanilla', 'inscription_choice' => 'text', 'inscription' => " Selamat\t ulang tahun "]]);
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, $input);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    app(OrderChanges::class)->approve($request->id, $admin, 'Disetujui');
    $order->forceFill(['status' => 'completed', 'completed_at' => now(), 'handed_over_by' => $admin->id])->save();
    config(['checkout.flavors' => [], 'checkout.fillings' => []]);
    $itemBefore = $order->item->fresh()->getRawOriginal();
    $counts = [AuditLog::count(), NotificationDelivery::count()];
    $input['requested_changes'] = ['inscription' => "Selamat\u{00a0}ulang tahun", 'inscription_choice' => 'text', 'flavor_code' => 'vanilla'];
    $this->travelTo(CarbonImmutable::parse('2026-11-02 03:00:00', 'UTC'));

    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', $input)->assertOk()->assertJsonPath('data.id', $request->id);
    $this->actingAs($admin)->postJson('/staff/change-requests/'.$request->id.'/approval', ['reason' => 'Disetujui'])->assertOk();

    expect($order->item->fresh()->getRawOriginal())->toBe($itemBefore);
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
});

test('a completed order permits rejection of a pending change but never applies it and exact rejection replay is harmless', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput());
    $admin = User::factory()->admin()->create();
    $order->forceFill(['status' => 'completed', 'completed_at' => now(), 'handed_over_by' => $admin->id])->save();
    $this->actingAs($admin)->postJson('/staff/change-requests/'.$request->id.'/approval', ['reason' => 'Disetujui'])->assertConflict();
    $this->postJson('/staff/change-requests/'.$request->id.'/rejection', ['reason' => 'Sudah diserahkan'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $counts = [AuditLog::count(), NotificationDelivery::count()];

    $this->postJson('/staff/change-requests/'.$request->id.'/rejection', ['reason' => 'Sudah diserahkan'])->assertOk();
    $this->postJson('/staff/change-requests/'.$request->id.'/rejection', ['reason' => 'Alasan lain'])->assertConflict();

    expect($order->item->fresh()->cover_color)->toBe('Putih');
    expect($request->fresh()->applied_at)->toBeNull();
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
});

test('an unavailable changed choice at review stays pending without corrupting old snapshots', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput(['requested_changes' => ['flavor_code' => 'vanilla']]));
    config(['checkout.flavors' => []]);

    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/change-requests/'.$request->id.'/approval', ['reason' => 'Disetujui'])->assertUnprocessable();

    expect($request->fresh()->status->value)->toBe('pending');
    expect($order->item->fresh()->flavor_label_snapshot)->toBe('Original chocolate label');
});

test('shared cancellation and change idempotency keys cannot be reused across types', function (bool $changeFirst) {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $change = personalizationInput(['idempotency_key' => 'shared-key']);
    $cancel = ['idempotency_key' => 'shared-key', 'reason' => 'Batal'];
    if ($changeFirst) {
        $this->postJson('/orders/'.$order->id.'/change-requests', $change)->assertCreated();
        $this->postJson('/orders/'.$order->id.'/cancellation-requests', $cancel)->assertConflict();
    } else {
        $this->postJson('/orders/'.$order->id.'/cancellation-requests', $cancel)->assertCreated();
        $this->postJson('/orders/'.$order->id.'/change-requests', $change)->assertConflict();
    }

    $this->assertDatabaseCount('order_requests', 1);
    expect(OrderRequest::query()->sole()->status->value)->toBe('pending');
})->with([true, false]);

test('notes require a present field and explicit clear is idempotent without deleting another field', function () {
    $order = personalizableOrder();
    $order->forceFill(['handover_note' => 'Catatan awal'])->save();
    $this->actingAs($order->user)->patchJson('/orders/'.$order->id.'/handover-note', [])->assertUnprocessable()->assertJsonValidationErrors('handover_note');
    expect($order->fresh()->handover_note)->toBe('Catatan awal');
    $this->patchJson('/orders/'.$order->id.'/handover-note', ['handover_note' => null])->assertOk();
    $this->patchJson('/orders/'.$order->id.'/handover-note', ['handover_note' => '  '])->assertOk();

    expect($order->fresh()->handover_note)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('notes reject oversized text and terminal states including a handover marker', function (string $state) {
    $order = personalizableOrder();
    $admin = User::factory()->admin()->create();
    if ($state === 'oversized') {
        $payload = str_repeat('a', 256);
        $expected = 422;
    } else {
        $fields = ['status' => $state, $state.'_at' => now(), 'handover_note' => 'Keep'];
        if ($state === 'completed') {
            $fields['handed_over_by'] = $admin->id;
        }
        $order->forceFill($fields)->save();
        $payload = 'New';
        $expected = 409;
    }

    $this->actingAs($order->user)->patchJson('/orders/'.$order->id.'/handover-note', ['handover_note' => $payload])->assertStatus($expected);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['oversized', 'completed', 'cancelled', 'expired']);

test('customer endpoints enforce authentication ownership active roles and deny pending personalization', function () {
    $order = personalizableOrder();
    $this->postJson('/orders/'.$order->id.'/change-requests', personalizationInput())->assertUnauthorized();
    $this->actingAs(User::factory()->create())->patchJson('/orders/'.$order->id.'/handover-note', ['handover_note' => 'Other'])->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->postJson('/orders/'.$order->id.'/change-requests', personalizationInput())->assertNotFound();
    $order->user->forceFill(['is_active' => false])->save();
    $this->actingAs($order->user)->patchJson('/orders/'.$order->id.'/handover-note', ['handover_note' => 'Other'])->assertUnauthorized();
    $pending = personalizableOrder(false);
    $this->actingAs($pending->user)->postJson('/orders/'.$pending->id.'/change-requests', personalizationInput())->assertUnprocessable();
    $this->patchJson('/orders/'.$pending->id.'/handover-note', ['handover_note' => 'Pending note'])->assertOk();
    $this->assertDatabaseCount('order_requests', 0);
});

test('approval outbox failure atomically rolls back the item request and decision audit', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput());
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $counts = [AuditLog::count(), NotificationDelivery::count()];
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic change outbox failure'));
    try {
        expect(fn () => app(OrderChanges::class)->approve($request->id, $admin, 'Disetujui'))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect($order->item->fresh()->cover_color)->toBe('Putih');
    expect($request->fresh()->status->value)->toBe('pending');
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
});

test('note audit failure rolls back the customer note', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    AuditLog::creating(fn () => throw new RuntimeException('synthetic note audit failure'));
    try {
        expect(fn () => app(OrderChanges::class)->updateNote($order->id, $order->user, 'New note'))->toThrow(RuntimeException::class);
    } finally {
        AuditLog::flushEventListeners();
    }
    expect($order->fresh()->handover_note)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('bounded approval retry cannot duplicate effects or change another item field', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput());
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic change retry'");
        }
    });
    try {
        app(OrderChanges::class)->approve($request->id, $admin, 'Disetujui');
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect(AuditLog::query()->where('action', 'order_change.applied')->count())->toBe(1);
    expect(NotificationDelivery::query()->where('template_key', 'customer.order_change_applied.v1')->count())->toBe(2);
    expect($order->item->fresh()->cover_color)->toBe('Biru');
    expect($order->item->fresh()->inscription)->toBe('Tulisan lama');
    Sleep::assertSleptTimes(1);
});

test('approval rechecks staff hours after the final item lock', function () {
    $order = personalizableOrder();
    $this->actingAs($order->user);
    $request = app(OrderChanges::class)->submit($order->id, $order->user, personalizationInput());
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    OrderItem::retrieved(function () {
        if (DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:01', 'UTC'));
        }
    });
    try {
        expect(fn () => app(OrderChanges::class)->approve($request->id, $admin, 'Disetujui'))->toThrow(AuthorizationException::class);
    } finally {
        OrderItem::flushEventListeners();
    }
    expect($request->fresh()->status->value)->toBe('pending');
    expect($order->item->fresh()->cover_color)->toBe('Putih');
});

test('a committed customer cancellation before staff acquires locks wins over the stale change snapshot', function () {
    $order = personalizableOrder();
    $customer = $order->user;
    $this->actingAs($customer);
    $request = app(OrderChanges::class)->submit($order->id, $customer, personalizationInput());
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    OrderRequest::retrieved(function (OrderRequest $current) use ($request, $order, $customer, $admin, $other, $original, &$injected): void {
        if ($injected || $current->id !== $request->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        $this->actingAs($customer);
        try {
            app(CancellationRequests::class)->submit($order->id, $customer, ['reason' => 'Batal', 'idempotency_key' => 'competing-cancel']);
        } finally {
            DB::setDefaultConnection($original);
            $this->actingAs($admin);
        }
    });
    try {
        expect(fn () => app(OrderChanges::class)->approve($request->id, $admin, 'Disetujui'))
            ->toThrow(HttpException::class);
    } finally {
        OrderRequest::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect($request->fresh()->decision_reason)->toBe('superseded_by_cancellation');
    expect($order->item->fresh()->cover_color)->toBe('Putih');
    expect(OrderRequest::query()->where('status', 'pending')->sole()->type->value)->toBe('cancellation');
});

test('submission reevaluates H2 after its last item lock', function () {
    $order = personalizableOrder();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 16:59:59', 'UTC'));
    OrderItem::retrieved(function () {
        if (DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-30 17:00:00', 'UTC'));
        }
    });
    try {
        $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', personalizationInput())->assertUnprocessable();
    } finally {
        OrderItem::flushEventListeners();
    }
    $this->assertDatabaseCount('order_requests', 0);
});

test('service operations reject stale inactive demoted or mismatched actors', function (string $operation, string $state) {
    $order = personalizableOrder();
    $customer = $order->user;
    $this->actingAs($customer);
    $request = app(OrderChanges::class)->submit($order->id, $customer, personalizationInput());
    $actor = $operation === 'approve' ? User::factory()->admin()->create() : $customer;
    $this->actingAs($actor);
    if ($state === 'inactive') {
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
    } elseif ($state === 'demoted') {
        DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']);
    } else {
        $this->actingAs(User::factory()->create());
    }

    $call = match ($operation) {
        'approve' => fn () => app(OrderChanges::class)->approve($request->id, $actor, 'Disetujui'),
        'note' => fn () => app(OrderChanges::class)->updateNote($order->id, $actor, 'New'),
        default => fn () => app(OrderChanges::class)->submit($order->id, $actor, personalizationInput(['idempotency_key' => 'another'])),
    };
    if ($operation === 'approve') {
        expect($call)->toThrow(AuthorizationException::class);
    } else {
        expect($call)->toThrow(HttpException::class);
    }
    expect($order->fresh()->handover_note)->toBeNull();
    expect($order->item->fresh()->cover_color)->toBe('Putih');
    expect($request->fresh()->status->value)->toBe('pending');
})->with([['submit', 'inactive'], ['note', 'mismatched'], ['approve', 'demoted'], ['approve', 'mismatched']]);

test('change policy is restricted to the active owning customer', function (string $role, bool $active, bool $own, bool $allowed) {
    $order = personalizableOrder();
    $actor = User::factory()->make(['id' => $own ? $order->user_id : $order->user_id + 100, 'role' => $role, 'is_active' => $active]);
    $decision = Gate::forUser($actor)->inspect('change', $order);
    expect($decision->allowed())->toBe($allowed);
    if (! $allowed) {
        expect($decision->status())->toBe(404);
    }
})->with([
    ['customer', true, true, true], ['customer', true, false, false], ['customer', false, true, false],
    ['admin', true, false, false], ['owner', true, false, false], ['admin', false, false, false], ['owner', false, false, false],
]);

test('partial fields still enforce current choices and color length limits', function (array $changes) {
    $order = personalizableOrder();
    $this->actingAs($order->user)->postJson('/orders/'.$order->id.'/change-requests', personalizationInput(['requested_changes' => $changes]))->assertUnprocessable();
    $this->assertDatabaseCount('order_requests', 0);
})->with([[['flavor_code' => 'unapproved']], [['filling_code' => 'unapproved']], [['cover_color' => str_repeat('a', 101)]]]);
