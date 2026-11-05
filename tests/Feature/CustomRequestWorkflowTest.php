<?php

use App\CustomRequestWorkflow;
use App\Models\AuditLog;
use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use App\Models\NotificationDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CommittedMySqlDatabase;
use Tests\TestCase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
});

function customWorkflowFixture(array $overrides = []): CustomCakeRequest
{
    $custom = CustomCakeRequest::factory()->create(array_replace(['pickup_date' => '2026-11-12', 'flavor_code' => 'chocolate',
        'filling_code' => 'strawberry', 'option_labels_snapshot' => ['flavor' => 'Historic chocolate', 'filling' => 'Historic strawberry']], $overrides));
    CustomCakeRequestAttachment::factory()->create(['custom_cake_request_id' => $custom->id]);

    return $custom->fresh();
}

test('staff advances a custom request to contacted with atomic audit outbox and no money', function () {
    $admin = User::factory()->admin()->create();
    $custom = customWorkflowFixture();
    $this->actingAs($admin)->postJson('/staff/custom-requests/'.$custom->id.'/status', [
        'expected_version' => app(CustomRequestWorkflow::class)->version($custom), 'idempotency_key' => 'transition-1',
        'status' => 'contacted', 'reason' => 'Customer sudah dihubungi.',
    ])->assertOk()->assertJsonPath('data.status', 'contacted');
    expect($custom->fresh()->assigned_admin_id)->toBe($admin->id);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 4);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('staff edits design before H2 without replacing historic option labels', function () {
    $custom = customWorkflowFixture();
    $this->actingAs(User::factory()->admin()->create())->patchJson('/staff/custom-requests/'.$custom->id.'/design', [
        'expected_version' => app(CustomRequestWorkflow::class)->version($custom), 'idempotency_key' => 'design-1',
        'reason' => 'Customer meminta warna merah.', 'changes' => ['color' => 'Merah'],
    ])->assertOk();
    expect($custom->fresh()->color)->toBe('Merah');
    expect($custom->fresh()->option_labels_snapshot['flavor'])->toBe('Historic chocolate');
    $this->assertDatabaseCount('audit_logs', 1);
});

function customOperationInput(CustomCakeRequest $custom, array $overrides = []): array
{
    return array_replace(['expected_version' => app(CustomRequestWorkflow::class)->version($custom->fresh()),
        'idempotency_key' => 'operation-1', 'reason' => 'Hasil komunikasi customer.'], $overrides);
}

test('the custom lifecycle enforces forward transitions and early rejection with reasons', function (string $initial, string $target, int $status) {
    $custom = customWorkflowFixture(['status' => $initial, 'status_reason' => $initial === 'rejected' ? 'Previous reason' : null]);
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/custom-requests/'.$custom->id.'/status', customOperationInput($custom, ['status' => $target]))
        ->assertStatus($status);
    expect($custom->fresh()->status->value)->toBe($status === 200 ? $target : $initial);
    if ($status === 200 && $target === 'rejected') {
        expect($custom->fresh()->status_reason)->toBe('Hasil komunikasi customer.');
        expect(AuditLog::query()->sole()->reason)->toBe('Hasil komunikasi customer.');
    }
})->with([
    ['submitted', 'quoted', 409], ['submitted', 'accepted', 409], ['contacted', 'quoted', 200],
    ['quoted', 'accepted', 200], ['submitted', 'rejected', 200], ['contacted', 'rejected', 200], ['quoted', 'rejected', 200],
    ['accepted', 'contacted', 409], ['rejected', 'quoted', 409], ['quoted', 'contacted', 409], ['contacted', 'contacted', 409],
]);

test('custom status and design replays stay no op after acceptance and outside staff hours', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    $workflow = app(CustomRequestWorkflow::class);
    $design = customOperationInput($custom, ['changes' => ['color' => 'Merah'], 'idempotency_key' => 'design']);
    $workflow->change($custom->id, $admin, $design);
    $first = customOperationInput($custom, ['status' => 'contacted', 'idempotency_key' => 'status-1']);
    $workflow->transition($custom->id, $admin, $first);
    $workflow->transition($custom->id, $admin, customOperationInput($custom, ['status' => 'quoted', 'idempotency_key' => 'status-2']));
    $workflow->transition($custom->id, $admin, customOperationInput($custom, ['status' => 'accepted', 'idempotency_key' => 'status-3']));
    $this->travelTo(CarbonImmutable::parse('2026-12-25 20:00:00', 'UTC'));
    config(['checkout.flavors' => [], 'checkout.fillings' => []]);
    expect($workflow->transition($custom->id, $admin, array_reverse($first, true))->status->value)->toBe('accepted');
    expect($workflow->change($custom->id, $admin, $design)->color)->toBe('Merah');
    $this->assertDatabaseCount('audit_logs', 4);
    $this->assertDatabaseCount('notification_deliveries', 16);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('operation key conflicts and stale versions cannot overwrite another staff update', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    $other = User::factory()->owner()->create();
    $input = customOperationInput($custom, ['changes' => ['color' => 'Merah']]);
    $workflow = app(CustomRequestWorkflow::class);
    $workflow->change($custom->id, $admin, $input);
    expect(fn () => $workflow->change($custom->id, $other, $input))->toThrow(HttpException::class);
    expect(fn () => $workflow->change($custom->id, $admin, array_replace($input, ['changes' => ['color' => 'Biru']])))->toThrow(HttpException::class);
    expect(fn () => $workflow->change($custom->id, $other, array_replace($input, ['idempotency_key' => 'new-key'])))->toThrow(HttpException::class);
    expect($custom->fresh()->color)->toBe('Merah');
    $this->assertDatabaseCount('audit_logs', 1);
});

test('design changes preserve the H2 boundary and edible print needs H7 when newly added', function (string $pickup, array $changes, bool $allowed) {
    $custom = customWorkflowFixture(['pickup_date' => $pickup]);
    $response = $this->actingAs(User::factory()->admin()->create())->patchJson('/staff/custom-requests/'.$custom->id.'/design', customOperationInput($custom, ['changes' => $changes]));
    $response->assertStatus($allowed ? 200 : 422);
    $this->assertDatabaseCount('audit_logs', $allowed ? 1 : 0);
})->with([
    ['2026-11-07', ['color' => 'Merah'], true],
    ['2026-11-06', ['color' => 'Merah'], false],
    ['2026-11-11', ['edible_print' => true], false],
    ['2026-11-12', ['edible_print' => true], true],
]);

test('retaining existing print near pickup is allowed and terminal designs remain immutable', function () {
    $custom = customWorkflowFixture(['pickup_date' => '2026-11-07', 'edible_print' => true]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->patchJson('/staff/custom-requests/'.$custom->id.'/design', customOperationInput($custom, ['changes' => ['edible_print' => true, 'color' => 'Merah']]))->assertOk();
    $custom->forceFill(['status' => 'accepted'])->save();
    $this->patchJson('/staff/custom-requests/'.$custom->id.'/design', customOperationInput($custom, ['idempotency_key' => 'terminal-edit', 'changes' => ['color' => 'Biru']]))->assertConflict();
});

test('design payloads reject immutable fields invalid pairings and unsupported options', function (array $changes, string $field) {
    $custom = customWorkflowFixture();
    $this->actingAs(User::factory()->admin()->create())->patchJson('/staff/custom-requests/'.$custom->id.'/design', customOperationInput($custom, ['changes' => $changes]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    [['pickup_date' => '2026-11-20'], 'changes'], [['product_total' => 1], 'changes'],
    [['size' => str_repeat('x', 101)], 'size'], [['notes' => str_repeat('x', 2001)], 'notes'],
    [['inscription' => 'New text'], 'changes.inscription_choice'],
    [['inscription_choice' => 'text'], 'inscription'],
    [['inscription_choice' => 'none', 'inscription' => 'Invalid'], 'inscription'],
    [['fondant' => true], 'fondant_details'], [['fondant_details' => 'Flowers'], 'changes.fondant'],
    [['flavor_code' => 'unknown'], 'flavor_code'],
]);

test('new options get current labels while unchanged historic codes survive option removal', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->patchJson('/staff/custom-requests/'.$custom->id.'/design', customOperationInput($custom, ['changes' => ['flavor_code' => 'vanilla',
        'fondant' => true, 'fondant_details' => '  Bunga  putih  ', 'inscription_choice' => 'text', 'inscription' => str_repeat('kata ', 11)]]))->assertOk();
    expect($custom->fresh()->option_labels_snapshot)->toBe(['flavor' => 'Vanilla (sponge cake)', 'filling' => 'Historic strawberry']);
    config(['checkout.flavors' => [], 'checkout.fillings' => []]);
    $this->patchJson('/staff/custom-requests/'.$custom->id.'/design', customOperationInput($custom, ['idempotency_key' => 'keep-options',
        'changes' => ['flavor_code' => 'vanilla', 'filling_code' => 'strawberry', 'fondant' => false, 'inscription_choice' => 'none']]))->assertOk();
    expect($custom->fresh()->inscription)->toBe('');
    expect($custom->fresh()->fondant_details)->toBeNull();
    expect($custom->fresh()->option_labels_snapshot['filling'])->toBe('Historic strawberry');
});

test('manual staff hours are checked on new mutations', function (string $localTime, bool $allowed) {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 '.$localTime, 'Asia/Jakarta'));
    $custom = customWorkflowFixture();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/custom-requests/'.$custom->id.'/status', customOperationInput($custom, ['status' => 'contacted']))
        ->assertStatus($allowed ? 200 : 403);
})->with([['08:59:59', false], ['09:00:00', true], ['17:00:00', true], ['17:00:01', false]]);

test('domain rechecks staff activity and customer cannot mutate custom lifecycle', function () {
    $custom = customWorkflowFixture();
    $input = customOperationInput($custom, ['status' => 'contacted']);
    $this->postJson('/staff/custom-requests/'.$custom->id.'/status', $input)->assertUnauthorized();
    $this->actingAs($custom->user)->postJson('/staff/custom-requests/'.$custom->id.'/status', $input)->assertForbidden();
    $admin = User::factory()->admin()->create();
    User::query()->whereKey($admin->id)->update(['is_active' => false]);
    expect(fn () => app(CustomRequestWorkflow::class)->transition($custom->id, $admin, $input))->toThrow(AuthorizationException::class);
    expect(Gate::forUser($custom->user)->allows('manage', $custom))->toBeFalse();
    expect(Gate::forUser(User::factory()->owner()->create())->allows('manage', $custom))->toBeTrue();
});

test('outbox failure rolls back lifecycle assignment audit and request state', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    NotificationDelivery::creating(fn () => throw new RuntimeException('Synthetic outbox failure'));
    expect(fn () => app(CustomRequestWorkflow::class)->transition($custom->id, $admin, customOperationInput($custom, ['status' => 'contacted'])))->toThrow(RuntimeException::class);
    expect($custom->fresh()->status->value)->toBe('submitted');
    expect($custom->fresh()->assigned_admin_id)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('staff pages render private management forms escape specifications and respect terminal state', function () {
    $custom = customWorkflowFixture(['color' => '<script>alert(1)</script>']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get('/staff/custom-requests')->assertOk()->assertSee($custom->request_number);
    $this->get('/staff/custom-requests/'.$custom->id.'/edit')->assertOk()->assertSee('expected_version', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $custom->forceFill(['status' => 'accepted'])->save();
    $this->get('/staff/custom-requests/'.$custom->id.'/edit')->assertOk()->assertSee('Request ini sudah terminal.')->assertDontSee('data-custom-design', false);
    $this->actingAs($custom->user)->get('/staff/custom-requests')->assertForbidden();
});

test('authorized custom detail exposes the contact snapshot for staff follow up', function () {
    $custom = customWorkflowFixture();
    $this->actingAs(User::factory()->admin()->create())->get('/custom-requests/'.$custom->id)->assertOk()
        ->assertSee($custom->customer_name_snapshot)->assertSee($custom->whatsapp_snapshot);
});

test('zero inscription is preserved in the detail and selected text choice when staff edits', function () {
    $custom = customWorkflowFixture(['inscription' => '0', 'notes' => '0']);
    $this->actingAs(User::factory()->admin()->create())->get('/custom-requests/'.$custom->id)->assertOk()->assertDontSee('Tanpa tulisan');
    $this->get('/staff/custom-requests/'.$custom->id.'/edit')->assertOk()->assertSee('value="text" required checked', false);
});

test('a committed competing edit invalidates the first staff snapshot on an independent connection', function () {
    $custom = customWorkflowFixture();
    $first = User::factory()->admin()->create();
    $second = User::factory()->owner()->create();
    $firstInput = customOperationInput($custom, ['changes' => ['color' => 'Merah']]);
    $winnerInput = array_replace($firstInput, ['changes' => ['color' => 'Biru'], 'idempotency_key' => 'winner']);
    $other = DB::build(config('database.connections.mysql_testing'));
    TestCase::assertSafeDatabaseConfiguration($other->getConfig());
    expect($other->selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe(DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
    $original = DB::getDefaultConnection();
    $injected = false;
    CustomCakeRequest::retrieved(function (CustomCakeRequest $current) use ($custom, $second, $other, $original, $winnerInput, &$injected): void {
        if ($injected || $current->id !== $custom->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            app(CustomRequestWorkflow::class)->change($custom->id, $second, $winnerInput);
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(fn () => app(CustomRequestWorkflow::class)->change($custom->id, $first, $firstInput))->toThrow(HttpException::class);
    } finally {
        CustomCakeRequest::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect($custom->fresh()->color)->toBe('Biru');
    $this->assertDatabaseCount('audit_logs', 1);
});

test('the audit version prevents stale edits even when the visible design returns to its old value', function () {
    $custom = customWorkflowFixture(['color' => 'Putih']);
    $admin = User::factory()->admin()->create();
    $workflow = app(CustomRequestWorkflow::class);
    $oldInput = customOperationInput($custom, ['changes' => ['color' => 'Merah'], 'idempotency_key' => 'old']);
    $workflow->change($custom->id, $admin, array_replace($oldInput, ['idempotency_key' => 'first']));
    $workflow->change($custom->id, $admin, customOperationInput($custom, ['changes' => ['color' => 'Putih'], 'idempotency_key' => 'second']));
    expect(fn () => $workflow->change($custom->id, $admin, $oldInput))->toThrow(HttpException::class);
    expect($custom->fresh()->color)->toBe('Putih');
    $this->assertDatabaseCount('audit_logs', 2);
});

test('deadlock retry rolls back the whole custom operation without duplicate effects', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    $failOnce = true;
    NotificationDelivery::creating(function () use (&$failOnce): void {
        if ($failOnce) {
            $failOnce = false;
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Synthetic custom deadlock'");
        }
    });
    expect(app(CustomRequestWorkflow::class)->transition($custom->id, $admin, customOperationInput($custom, ['status' => 'contacted']))->status->value)->toBe('contacted');
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 4);
});

test('staff hours are rechecked after the final request lock', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    $data = customOperationInput($custom, ['status' => 'contacted']);
    $injected = false;
    DB::listen(function ($query) use (&$injected): void {
        if (! $injected && str_contains($query->sql, 'from `custom_cake_requests`') && str_contains($query->sql, 'for update')) {
            $injected = true;
            $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:01', 'UTC'));
        }
    });
    expect(fn () => app(CustomRequestWorkflow::class)->transition($custom->id, $admin, $data))->toThrow(AuthorizationException::class);
    expect($injected)->toBeTrue();
    expect($custom->fresh()->status->value)->toBe('submitted');
    $this->assertDatabaseCount('audit_logs', 0);
});

test('rejection reason and operation version are mandatory and nested calls cannot mutate', function () {
    $custom = customWorkflowFixture();
    $admin = User::factory()->admin()->create();
    $input = customOperationInput($custom, ['status' => 'rejected']);
    $this->actingAs($admin)->postJson('/staff/custom-requests/'.$custom->id.'/status', array_replace($input, ['reason' => '   ']))
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/staff/custom-requests/'.$custom->id.'/status', array_replace($input, ['expected_version' => 'invalid']))
        ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    DB::beginTransaction();
    try {
        expect(fn () => app(CustomRequestWorkflow::class)->transition($custom->id, $admin, $input))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
    $this->assertDatabaseCount('audit_logs', 0);
});
