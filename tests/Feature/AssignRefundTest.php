<?php

use App\AssignRefund;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\PaymentFunds;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
});

test('active Admin claims an unassigned bankless refund once and another Admin cannot steal it', function () {
    Http::preventStrayRequests();
    $refund = RefundLog::factory()->create();
    $admin = User::factory()->admin()->create();
    User::factory()->owner()->create();
    $this->actingAs($admin)->postJson('/staff/refunds/'.$refund->id.'/claim', ['assignment_version' => 0, 'amount' => 1, 'assigned_admin_id' => 999])
        ->assertOk()->assertJsonPath('data.assigned_admin_id', $admin->id)->assertJsonPath('data.assignment_version', 1);

    expect($refund->fresh()->status->value)->toBe('processing');
    expect($refund->fresh()->account_number)->toBeNull();
    expect($refund->fresh()->amount)->toBe(80000);
    $counts = [AuditLog::count(), NotificationDelivery::count()];
    $this->postJson('/staff/refunds/'.$refund->id.'/claim', ['assignment_version' => 0])->assertOk();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/refunds/'.$refund->id.'/claim', ['assignment_version' => 1])->assertConflict();
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
    expect(AuditLog::query()->sole()->after_redacted['assignment_version'])->toBe(1);
    expect(NotificationDelivery::query()->pluck('recipient_ciphertext')->map(fn ($recipient) => $recipient['address'])->all())->not->toContain($refund->order->email_snapshot);
    $this->assertDatabaseCount('refund_transfers', 0);
    Http::assertNothingSent();
});

test('Owner takeover increments assignment once while unknown attempt bank snapshot and review remain intact', function () {
    Http::preventStrayRequests();
    $refund = RefundLog::factory()->processing()->create();
    $oldAdmin = $refund->assignedAdmin;
    $transfer = RefundTransfer::factory()->for($refund)->unknown()->create();
    $refund->forceFill(['status' => 'review', 'review_reason' => '[TEST] bank outcome unknown'])->save();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $before = $transfer->fresh()->getRawOriginal();
    $beforeBank = $refund->fresh()->account_number;

    $this->actingAs($owner)->postJson('/staff/refunds/'.$refund->id.'/takeover', ['assignment_version' => 1, 'assigned_admin_id' => $destination->id, 'reason' => 'Petugas berganti, mutasi tetap perlu dicek'])
        ->assertOk()->assertJsonPath('data.assignment_version', 2);

    expect($refund->fresh()->assigned_admin_id)->toBe($destination->id);
    expect($refund->fresh()->status->value)->toBe('review');
    expect($refund->fresh()->review_reason)->toBe('[TEST] bank outcome unknown');
    expect($refund->fresh()->account_number)->toBe($beforeBank);
    expect($transfer->fresh()->getRawOriginal())->toBe($before);
    expect(AuditLog::query()->sole()->reason)->toBe('Petugas berganti, mutasi tetap perlu dicek');
    expect(Gate::forUser($oldAdmin)->allows('work', [$refund->fresh(), 1]))->toBeFalse();
    expect(Gate::forUser($destination)->allows('work', [$refund->fresh(), 1]))->toBeFalse();
    expect(Gate::forUser($destination)->allows('work', [$refund->fresh(), 2]))->toBeTrue();
    expect(json_encode(NotificationDelivery::query()->pluck('payload_minimal')))->not->toContain('mutasi', $beforeBank, '[TEST] bank outcome');
    Http::assertNothingSent();
});

test('Owner can explicitly assign itself through takeover rather than losing the PRD refund capability', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);

    $current = app(AssignRefund::class)->takeOver($refund->id, $owner, $owner->id, 1, 'Owner menangani pengecualian');

    expect($current?->assigned_admin_id)->toBe($owner->id);
    expect(Gate::forUser($owner)->allows('work', [$current, 2]))->toBeTrue();
});

test('Admin can claim a review case for investigation without clearing its substantive reason', function () {
    $refund = RefundLog::factory()->review()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(AssignRefund::class)->claim($refund->id, $admin, 0);

    expect($refund->fresh()->status->value)->toBe('review');
    expect($refund->fresh()->review_reason)->toBe('[TEST] synthetic review reason');
    expect($refund->fresh()->assignment_version)->toBe(1);
});

test('takeover retries match original actor destination reason and expected version without incrementing again', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $next = User::factory()->admin()->create();
    $this->actingAs($owner);
    $service = app(AssignRefund::class);
    expect($service->takeOver($refund->id, $owner, $next->id, 1, 'Pindah petugas')?->assignment_version)->toBe(2);
    $counts = [AuditLog::count(), NotificationDelivery::count()];

    expect($service->takeOver($refund->id, $owner, $next->id, 1, 'Pindah petugas')?->assignment_version)->toBe(2);
    expect($service->takeOver($refund->id, $owner, $next->id, 1, 'Alasan berbeda'))->toBeNull();
    expect($service->takeOver($refund->id, $owner, $refund->assigned_admin_id, 1, 'Pindah petugas'))->toBeNull();
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
});

test('a later handoff back cannot replay an old assignment version', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $firstAdmin = $refund->assignedAdmin;
    $next = User::factory()->admin()->create();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    $service = app(AssignRefund::class);
    $service->takeOver($refund->id, $owner, $next->id, 1, 'Pindah');
    $service->takeOver($refund->id, $owner, $firstAdmin->id, 2, 'Kembali');

    expect($service->takeOver($refund->id, $owner, $next->id, 1, 'Pindah'))->toBeNull();
    expect($refund->fresh()->assigned_admin_id)->toBe($firstAdmin->id);
    expect($refund->fresh()->assignment_version)->toBe(3);
});

test('completed refunds reject new assignment and version cannot overflow uint32', function (string $case) {
    $refund = $case === 'completed' ? RefundLog::factory()->completed()->create() : RefundLog::factory()->assigned()->create(['assignment_version' => 4294967295]);
    $owner = User::factory()->owner()->create();
    $next = User::factory()->admin()->create();
    $this->actingAs($owner);

    expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $next->id, $refund->assignment_version, 'Pindah'))->toBeNull();

    expect($refund->fresh()->assignment_version)->toBe($refund->assignment_version);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['completed', 'max_version']);

test('only active authorized roles can claim or take over and target staff must remain active', function () {
    $refund = RefundLog::factory()->create();
    $this->postJson('/staff/refunds/'.$refund->id.'/claim', ['assignment_version' => 0])->assertUnauthorized();
    $this->actingAs($refund->order->user)->postJson('/staff/refunds/'.$refund->id.'/claim', ['assignment_version' => 0])->assertForbidden();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/staff/refunds/'.$refund->id.'/takeover', ['assignment_version' => 0, 'assigned_admin_id' => $admin->id, 'reason' => 'Pindah'])->assertForbidden();
    $owner = User::factory()->owner()->create();
    $admin->forceFill(['is_active' => false])->save();
    $this->actingAs($owner)->postJson('/staff/refunds/'.$refund->id.'/takeover', ['assignment_version' => 0, 'assigned_admin_id' => $admin->id, 'reason' => 'Pindah'])->assertConflict();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('takeover never routes staff refund assignment notices to a historical assignee demoted to Customer', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $old = $refund->assignedAdmin;
    $old->forceFill(['role' => 'customer'])->save();
    $owner = User::factory()->owner()->create();
    $next = User::factory()->admin()->create();
    $this->actingAs($owner);

    expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $next->id, 1, 'Petugas telah berganti peran'))->not->toBeNull();

    $addresses = NotificationDelivery::query()->pluck('recipient_ciphertext')->map(fn ($recipient) => $recipient['address'])->all();
    expect($addresses)->not->toContain($old->email, $old->whatsapp_number);
    expect($addresses)->toContain($next->email, $owner->email);
});

test('two committed Admin claim attempts have one winner after the first snapshot became stale', function () {
    $refund = RefundLog::factory()->create();
    $first = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();
    $this->actingAs($first);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    RefundLog::retrieved(function (RefundLog $current) use ($refund, $first, $second, $other, $original, &$injected): void {
        if ($injected || $current->id !== $refund->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        $this->actingAs($second);
        try {
            expect(app(AssignRefund::class)->claim($refund->id, $second, 0)?->assigned_admin_id)->toBe($second->id);
        } finally {
            DB::setDefaultConnection($original);
            $this->actingAs($first);
        }
    });
    try {
        expect(app(AssignRefund::class)->claim($refund->id, $first, 0))->toBeNull();
    } finally {
        RefundLog::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect($refund->fresh()->assigned_admin_id)->toBe($second->id);
    expect($refund->fresh()->assignment_version)->toBe(1);
    expect(AuditLog::query()->where('action', 'refund.claimed')->count())->toBe(1);
});

test('duplicate committed Owner takeover is acknowledged once without a second version or audit', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    RefundLog::retrieved(function (RefundLog $current) use ($refund, $owner, $destination, $other, $original, &$injected): void {
        if ($injected || $current->id !== $refund->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah')?->assignment_version)->toBe(2);
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah')?->assignment_version)->toBe(2);
    } finally {
        RefundLog::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect(AuditLog::query()->where('action', 'refund.assignment_taken_over')->count())->toBe(1);
});

test('an assignee changed outside the prelocked user set cannot be overwritten with a guessed next version', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $winner = User::factory()->admin()->create();
    $loser = User::factory()->admin()->create();
    $this->actingAs($owner);
    $other = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $injected = false;
    RefundLog::retrieved(function (RefundLog $current) use ($refund, $owner, $winner, $other, $original, &$injected): void {
        if ($injected || $current->id !== $refund->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        DB::setDefaultConnection($other->getName());
        try {
            app(AssignRefund::class)->takeOver($refund->id, $owner, $winner->id, 1, 'Pemenang');
        } finally {
            DB::setDefaultConnection($original);
        }
    });
    try {
        expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $loser->id, 2, 'Versi ditebak'))->toBeNull();
    } finally {
        RefundLog::flushEventListeners();
        $other->disconnect();
    }
    expect($refund->fresh()->assigned_admin_id)->toBe($winner->id);
    expect($refund->fresh()->assignment_version)->toBe(2);
    expect(AuditLog::count())->toBe(1);
});

test('claim exact replay survives completed history but cannot authorize new work', function () {
    $refund = RefundLog::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    app(AssignRefund::class)->claim($refund->id, $admin, 0);
    $refund->refresh()->forceFill(['status' => 'completed', 'completed_by' => $admin->id, 'completed_at' => now()])->save();
    $before = $refund->fresh()->getRawOriginal();
    $counts = [AuditLog::count(), NotificationDelivery::count()];

    expect(app(AssignRefund::class)->claim($refund->id, $admin, 0)?->status->value)->toBe('completed');

    expect($refund->fresh()->getRawOriginal())->toBe($before);
    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
    expect(Gate::forUser($admin)->allows('work', [$refund->fresh(), 1]))->toBeFalse();
});

test('takeover exact replay acknowledges history after destination deactivation without changing assignment', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);
    app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah');
    $destination->forceFill(['is_active' => false])->save();
    $counts = [AuditLog::count(), NotificationDelivery::count()];

    expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah')?->assignment_version)->toBe(2);

    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
    expect(Gate::forUser($destination->fresh())->allows('work', [$refund->fresh(), 2]))->toBeFalse();
});

test('assignment outbox failure rolls back assignee version status and audit', function () {
    $refund = RefundLog::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    NotificationDelivery::creating(fn () => throw new RuntimeException('synthetic assignment outbox failure'));
    try {
        expect(fn () => app(AssignRefund::class)->claim($refund->id, $admin, 0))->toThrow(RuntimeException::class);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect($refund->fresh()->assigned_admin_id)->toBeNull();
    expect($refund->fresh()->assignment_version)->toBe(0);
    expect($refund->fresh()->status->value)->toBe('pending');
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
});

test('bounded assignment retry commits one version audit and recipient set', function () {
    $refund = RefundLog::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Sleep::fake();
    $writes = 0;
    NotificationDelivery::creating(function () use (&$writes): void {
        if ($writes++ === 0) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic assignment retry'");
        }
    });
    try {
        expect(app(AssignRefund::class)->claim($refund->id, $admin, 0)?->assignment_version)->toBe(1);
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    Sleep::assertSleptTimes(1);
});

test('takeover preserves the prepared unknown reserve in the locked funds projection', function () {
    $refund = RefundLog::factory()->processing()->create();
    RefundTransfer::factory()->for($refund)->unknown()->create();
    $event = PaymentEvent::factory()->for($refund->payment)->processed()->create(['verified_at' => now()]);
    PaymentMovement::factory()->create(['payment_id' => $refund->payment_id, 'source_event_id' => $event->id, 'amount' => $refund->basis_amount]);
    $snapshot = function () use ($refund): array {
        return DB::transaction(function () use ($refund): array {
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            RefundLog::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            RefundTransfer::query()->where('refund_log_id', $refund->id)->lockForUpdate()->get();

            return app(PaymentFunds::class)->snapshot($payment);
        });
    };
    $before = $snapshot();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);

    app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Cek mutasi dahulu');

    expect($snapshot())->toBe($before);
    expect($before['reserved'])->toBe(80000);
    expect($before['available'])->toBe(20000);
    expect($before['remaining_obligation'])->toBe(0);
    $this->assertDatabaseCount('refund_transfers', 1);
});

test('actor deactivation committed after the snapshot is denied by the final locked authorization', function () {
    $refund = RefundLog::factory()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $other = DB::build(config('database.connections.mysql_testing'));
    $injected = false;
    RefundLog::retrieved(function (RefundLog $current) use ($refund, $admin, $other, &$injected): void {
        if ($injected || $current->id !== $refund->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        $other->table('users')->where('id', $admin->id)->update(['is_active' => false]);
    });
    try {
        expect(fn () => app(AssignRefund::class)->claim($refund->id, $admin, 0))->toThrow(AuthorizationException::class);
    } finally {
        RefundLog::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect($refund->fresh()->assigned_admin_id)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
});

test('destination deactivation before the user locks keeps the old assignment intact', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);
    $other = DB::build(config('database.connections.mysql_testing'));
    $injected = false;
    RefundLog::retrieved(function (RefundLog $current) use ($refund, $destination, $other, &$injected): void {
        if ($injected || $current->id !== $refund->id || DB::transactionLevel() > 0) {
            return;
        }
        $injected = true;
        $other->table('users')->where('id', $destination->id)->update(['is_active' => false]);
    });
    try {
        expect(app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah'))->toBeNull();
    } finally {
        RefundLog::flushEventListeners();
        $other->disconnect();
    }
    expect($injected)->toBeTrue();
    expect($refund->fresh()->assigned_admin_id)->toBe($refund->assigned_admin_id);
    expect($refund->fresh()->assignment_version)->toBe(1);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('takeover rechecks manual hours after its last attempt lock', function () {
    $refund = RefundLog::factory()->processing()->create();
    RefundTransfer::factory()->for($refund)->unknown()->create();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);
    RefundTransfer::retrieved(function () {
        if (DB::transactionLevel() > 0) {
            $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:01', 'UTC'));
        }
    });
    try {
        expect(fn () => app(AssignRefund::class)->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah'))->toThrow(AuthorizationException::class);
    } finally {
        RefundTransfer::flushEventListeners();
    }
    expect($refund->fresh()->assignment_version)->toBe(1);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('valid takeover at the final uint32 version replays even after completion but no newer assignment can wrap', function () {
    $refund = RefundLog::factory()->assigned()->create(['assignment_version' => 4294967294]);
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);
    $service = app(AssignRefund::class);
    expect($service->takeOver($refund->id, $owner, $destination->id, 4294967294, 'Pindah')?->assignment_version)->toBe(4294967295);
    $refund->refresh()->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $destination->id])->save();
    $counts = [AuditLog::count(), NotificationDelivery::count()];

    expect($service->takeOver($refund->id, $owner, $destination->id, 4294967294, 'Pindah')?->status->value)->toBe('completed');
    expect($service->takeOver($refund->id, $owner, $owner->id, 4294967295, 'Baru'))->toBeNull();

    expect([AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
    expect($refund->fresh()->assignment_version)->toBe(4294967295);
});

test('same-assignee no-op and another Owner cannot impersonate the first takeover replay', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $owner = User::factory()->owner()->create();
    $destination = User::factory()->admin()->create();
    $this->actingAs($owner);
    $service = app(AssignRefund::class);
    $service->takeOver($refund->id, $owner, $destination->id, 1, 'Pindah');
    expect($service->takeOver($refund->id, $owner, $destination->id, 2, 'Alasan baru'))->toBeNull();
    $otherOwner = User::factory()->owner()->create();
    $this->actingAs($otherOwner);
    expect($service->takeOver($refund->id, $otherOwner, $destination->id, 1, 'Pindah'))->toBeNull();
    expect($refund->fresh()->assignment_version)->toBe(2);
    $this->assertDatabaseCount('audit_logs', 1);
});

test('work policy fences assignment version staff role activity ownership and completed history', function (string $role, bool $active, bool $assigned, int $version, bool $completed, bool $allowed) {
    $actor = User::factory()->create(['role' => $role, 'is_active' => $active]);
    $refund = RefundLog::factory()->assigned()->create($assigned ? ['assigned_admin_id' => $actor->id] : []);
    if ($completed) {
        $refund->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id])->save();
    }

    expect(Gate::forUser($actor)->allows('work', [$refund, $version]))->toBe($allowed);
})->with([
    ['admin', true, true, 1, false, true], ['owner', true, true, 1, false, true],
    ['admin', true, true, 0, false, false], ['admin', true, false, 1, false, false],
    ['customer', true, true, 1, false, false], ['admin', false, true, 1, false, false],
    ['owner', false, true, 1, false, false], ['admin', true, true, 1, true, false],
]);

test('version destination and reason inputs are mandatory and bounded', function (array $changes, string $field) {
    $refund = RefundLog::factory()->create();
    $owner = User::factory()->owner()->create();
    $payload = array_replace(['assignment_version' => 0, 'assigned_admin_id' => $owner->id, 'reason' => 'Pindah'], $changes);
    $this->actingAs($owner)->postJson('/staff/refunds/'.$refund->id.'/takeover', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    [['assignment_version' => null], 'assignment_version'], [['assignment_version' => -1], 'assignment_version'],
    [['assignment_version' => 4294967296], 'assignment_version'], [['assignment_version' => 1.5], 'assignment_version'],
    [['assigned_admin_id' => 0], 'assigned_admin_id'], [['reason' => '  '], 'reason'],
]);
