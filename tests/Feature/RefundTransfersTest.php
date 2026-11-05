<?php

use App\AssignRefund;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\RefundEvidence;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\PaymentMovementKey;
use App\PrivateUploadStorage;
use App\RefundPrivateData;
use App\RefundTransfers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
});

function operationalRefund(bool $store = false): RefundLog
{
    $order = Order::factory()->cancelled()->create(['created_at' => '2026-10-29 03:00:00', 'paid_at' => '2026-10-29 03:01:00',
        'cancelled_at' => '2026-10-29 03:05:00', 'product_total' => 100001]);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001, 'quota_released_at' => $order->cancelled_at, 'quota_release_reason' => 'cancelled']);
    $payment = Payment::factory()->for($order)->paid()->create(['expected_amount' => 100001,
        'provider_transaction_id' => (string) Str::uuid(), 'provider_paid_at' => '2026-10-29 03:01:00', 'created_at' => '2026-10-29 03:00:00']);
    $event = PaymentEvent::factory()->for($payment)->processed()->create(['verified_at' => now()]);
    PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id, 'amount' => 100001,
        'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$payment->provider_transaction_id]), 'occurred_at' => '2026-10-29 03:02:00']);
    $refund = RefundLog::factory()->for($payment)->for($order)->assigned()->create(['status' => 'processing', 'basis_amount' => 100001,
        'reason_type' => $store ? 'store_cancel' : 'customer_cancel', 'percentage' => $store ? 100 : 80, 'amount' => $store ? 100001 : 80000]);
    AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => $refund->assigned_admin_id, 'entity_type' => 'order', 'entity_id' => $order->id,
        'action' => 'order.paid_cancelled', 'after_redacted' => ['refund_log_id' => $refund->id, 'cause' => $store ? 'store_cancel' : 'customer_cancel',
            'basis_amount' => 100001, 'percentage' => $store ? 100 : 80, 'gross_obligation' => $store ? 100001 : 80000]]);

    return $refund;
}

function verifiedOperationalRefund(object $test, bool $store = false): RefundLog
{
    $refund = operationalRefund($store);
    $test->actingAs($refund->assignedAdmin);
    $service = app(RefundPrivateData::class);
    $service->saveBank($refund->id, $refund->assignedAdmin, ['bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => 'Pelanggan'], 1);
    $token = $service->bank($refund->id, $refund->assignedAdmin)['bank_token'];
    $service->verifyBank($refund->id, $refund->assignedAdmin, ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true]);

    return $refund->fresh();
}

function unknownOperationalAttempt(object $test, int $amount = 80000, bool $store = false): array
{
    $refund = verifiedOperationalRefund($test, $store);
    $service = app(RefundTransfers::class);
    $transfer = $service->prepare($refund->id, $refund->assignedAdmin, ['assignment_version' => 1, 'amount' => $amount, 'idempotency_key' => 'attempt-1']);
    $service->markUnknown($refund->id, $transfer->id, $refund->assignedAdmin, 1);
    $evidence = app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('receipt.png'));

    return [$refund->fresh(), $transfer->fresh(), $evidence];
}

function operationalCompletion(RefundEvidence $evidence): array
{
    return ['assignment_version' => 1, 'bank_statement_checked' => true, 'evidence_id' => $evidence->id, 'transfer_reference' => 'BCA-TEST-0001',
        'transferred_at' => '2026-10-30T10:00:00+07:00', 'verification_note' => 'Mutasi debit sesuai tujuan dan nominal'];
}

test('prepare rejects bankless obligations and creates one immutable reserved attempt for a verified destination', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = operationalRefund();
    $this->actingAs($refund->assignedAdmin)->postJson('/staff/refunds/'.$refund->id.'/transfers', ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'bankless'])->assertConflict();
    $this->assertDatabaseCount('refund_transfers', 0);
    $refund = verifiedOperationalRefund($this);
    $url = '/staff/refunds/'.$refund->id.'/transfers';
    $input = ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'prepare-1'];
    $id = $this->postJson($url, $input)->assertCreated()->json('data.id');
    $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.id', $id);
    expect(RefundTransfer::query()->sole()->account_number)->toBe('0012345678');
    expect(RefundTransfer::query()->sole()->status->value)->toBe('prepared');
});

test('unknown is committed before manual bank work and verified evidence completion records one return atomically', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $refund = verifiedOperationalRefund($this);
    $base = '/staff/refunds/'.$refund->id.'/transfers';
    $id = $this->postJson($base, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'prepare-1'])->assertCreated()->json('data.id');
    $this->postJson($base.'/'.$id.'/unknown', ['assignment_version' => 1])->assertOk();
    $evidence = $this->postJson($base.'/'.$id.'/evidence', ['assignment_version' => 1, 'upload' => UploadedFile::fake()->image('receipt.png')])->assertCreated()->json('data.id');
    $this->postJson($base.'/'.$id.'/completion', ['assignment_version' => 1, 'bank_statement_checked' => true,
        'evidence_id' => $evidence, 'transfer_reference' => 'BCA-TEST-0001', 'transferred_at' => '2026-10-30T10:00:00+07:00', 'verification_note' => 'Mutasi debit sesuai tujuan dan nominal'])->assertOk();
    expect($refund->fresh()->status->value)->toBe('completed');
    expect($refund->payment->fresh()->status->value)->toBe('partially_refunded');
    expect(PaymentMovement::query()->where('kind', 'return')->sole()->amount)->toBe(80000);
    expect(RefundTransfer::query()->sole()->status->value)->toBe('succeeded');
});

test('verified actual success remains a money fact after the payment was frozen for review', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    [$refund, $transfer, $evidence] = unknownOperationalAttempt($this);
    $refund->payment->forceFill(['review_reason' => 'reconciliation_conflict'])->save();
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/completion', operationalCompletion($evidence))->assertOk();
    expect(PaymentMovement::query()->where('kind', 'return')->sole()->amount)->toBe(80000);
    expect($refund->fresh()->status->value)->toBe('review');
    expect($refund->payment->fresh()->review_reason)->toBe('reconciliation_conflict');
    expect(AuditLog::query()->where('action', 'refund.actual_success_incident')->exists())->toBeTrue();
});

test('prepare replay is payload bound and unknown attempts block every additional key', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer] = unknownOperationalAttempt($this);
    $before = $transfer->getRawOriginal();
    $url = '/staff/refunds/'.$refund->id.'/transfers';
    $this->postJson($url, ['assignment_version' => 1, 'amount' => 1, 'idempotency_key' => 'attempt-1'])->assertConflict();
    $this->postJson($url, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'attempt-2'])->assertConflict();
    $this->postJson($url.'/'.$transfer->id.'/unknown', ['assignment_version' => 1])->assertOk();
    expect($transfer->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('refund_transfers', 1);
    expect(AuditLog::query()->where('action', 'refund.transfer_unknown')->count())->toBe(1);
});

test('partial successes subtract returns once and complete gross80 without changing the original obligation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $first, $evidence] = unknownOperationalAttempt($this, 50000);
    $service = app(RefundTransfers::class);
    $actor = $refund->assignedAdmin;
    expect($service->complete($refund->id, $first->id, $actor, operationalCompletion($evidence))?->status->value)->toBe('succeeded');
    expect($refund->fresh()->status->value)->toBe('processing');
    expect($service->prepare($refund->id, $actor, ['assignment_version' => 1, 'amount' => 30001, 'idempotency_key' => 'too-much']))->toBeNull();
    $second = $service->prepare($refund->id, $actor, ['assignment_version' => 1, 'amount' => 30000, 'idempotency_key' => 'second']);
    expect($second?->attempt_number)->toBe(2);
    $service->markUnknown($refund->id, $second->id, $actor, 1);
    $proof = app(RefundPrivateData::class)->upload($refund->id, $second->id, $actor, 1, UploadedFile::fake()->image('second.png'));
    $input = array_replace(operationalCompletion($proof), ['transfer_reference' => 'BCA-SECOND']);
    expect($service->complete($refund->id, $second->id, $actor, $input)?->status->value)->toBe('succeeded');
    $counts = [PaymentMovement::count(), AuditLog::count(), NotificationDelivery::count()];
    expect($service->complete($refund->id, $second->id, $actor, $input)?->id)->toBe($second->id);
    expect([PaymentMovement::count(), AuditLog::count(), NotificationDelivery::count()])->toBe($counts);
    expect($service->complete($refund->id, $second->id, $actor, array_replace($input, ['transfer_reference' => 'DIFFERENT'])))->toBeNull();
    expect($refund->fresh()->amount)->toBe(80000);
    expect($refund->fresh()->status->value)->toBe('completed');
    expect($refund->payment->fresh()->status->value)->toBe('partially_refunded');
    expect(PaymentMovement::query()->where('kind', 'return')->sum('amount'))->toBe('80000');
});

test('a full store refund projects payment refunded while the order stays cancelled and quota stays released', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this, 100001, true);
    $before = $refund->order->item->getRawOriginal();
    app(RefundTransfers::class)->complete($refund->id, $transfer->id, $refund->assignedAdmin, operationalCompletion($proof));
    expect($refund->payment->fresh()->status->value)->toBe('refunded');
    expect($refund->order->fresh()->status->value)->toBe('cancelled');
    expect($refund->order->item->fresh()->getRawOriginal())->toBe($before);
    expect(json_encode(NotificationDelivery::query()->pluck('payload_minimal')))->not->toContain('0012345678', 'BCA-TEST-0001', 'Mutasi debit', 'receipt.png');
    Http::assertNothingSent();
});

test('confirmed failure requires durable checked evidence then frees the reserve without reusing an old key as a new transfer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $url = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/confirmed-failure';
    $data = ['assignment_version' => 1, 'bank_statement_checked' => true, 'evidence_id' => $proof->id, 'verification_note' => 'Mutasi dan status bank mengonfirmasi tidak ada debit sukses'];
    $this->postJson($url, array_replace($data, ['bank_statement_checked' => false]))->assertUnprocessable();
    $this->postJson($url, array_replace($data, ['evidence_id' => 99999]))->assertConflict();
    $this->postJson($url, $data)->assertOk();
    $this->postJson($url, $data)->assertOk();
    expect($transfer->fresh()->status->value)->toBe('failed_confirmed');
    expect($refund->fresh()->status->value)->toBe('processing');
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'refund.transfer_failed_confirmed')->count())->toBe(1);
    $base = '/staff/refunds/'.$refund->id.'/transfers';
    $this->postJson($base, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'attempt-1'])->assertCreated()->assertJsonPath('data.id', $transfer->id)->assertJsonPath('data.status', 'failed_confirmed');
    $this->postJson($base, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'retry-after-bank-confirmation'])->assertCreated()->assertJsonPath('data.attempt_number', 2);
});

test('prepare freezes corrections unsupported returns review flags pending events and altered gross obligations', function (string $kind) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = verifiedOperationalRefund($this);
    $root = PaymentMovement::query()->sole();
    match ($kind) {
        'correction' => PaymentMovementCorrection::factory()->for($root, 'movement')->create(['effective_amount' => 90000]),
        'provider_return' => PaymentMovement::factory()->providerRefund()->create(['payment_id' => $refund->payment_id, 'source_event_id' => $root->source_event_id, 'amount' => 10000]),
        'review' => $refund->payment->forceFill(['review_reason' => 'ledger_identity_conflict'])->save(),
        'pending_event' => PaymentEvent::factory()->for($refund->payment)->create(),
        'gross' => $refund->forceFill(['reason_type' => 'store_cancel', 'percentage' => 100, 'amount' => 100001])->save(),
        'unverified_bank' => $refund->forceFill(['bank_verified_at' => null, 'bank_verified_by' => null])->save(),
    };
    expect(app(RefundTransfers::class)->prepare($refund->id, $refund->assignedAdmin, ['assignment_version' => 1, 'amount' => 70000, 'idempotency_key' => 'blocked']))->toBeNull();
    $this->assertDatabaseCount('refund_transfers', 0);
})->with(['correction', 'provider_return', 'review', 'pending_event', 'gross', 'unverified_bank']);

test('completion rejects missing facts unconfirmed statements and invalid times before changing money', function (array $change, int $status) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $response = $this->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/completion', array_replace(operationalCompletion($proof), $change));
    $response->assertStatus($status);
    expect($transfer->fresh()->status->value)->toBe('unknown');
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
})->with([
    [['bank_statement_checked' => false], 422], [['transfer_reference' => ' '], 422], [['transfer_reference' => "bad\nreference"], 422],
    [['transfer_reference' => str_repeat('a', 129)], 422], [['evidence_id' => 99999], 409], [['verification_note' => ''], 422],
    [['transferred_at' => '2026-10-30 10:00:00'], 422], [['transferred_at' => '2026-02-31T10:00:00+07:00'], 422],
    [['transferred_at' => '2026-10-30T10:00:01+07:00'], 409], [['transferred_at' => '2026-10-29T10:00:00+07:00'], 409],
]);

test('prepared state cannot complete or confirm failure before an unknown marker was committed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    $refund = verifiedOperationalRefund($this);
    $transfer = app(RefundTransfers::class)->prepare($refund->id, $refund->assignedAdmin, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'prepared']);
    $proof = app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('a.png'));
    $data = operationalCompletion($proof);
    expect(app(RefundTransfers::class)->complete($refund->id, $transfer->id, $refund->assignedAdmin, $data))->toBeNull();
    expect(app(RefundTransfers::class)->confirmFailure($refund->id, $transfer->id, $refund->assignedAdmin, $data))->toBeNull();
    expect($transfer->fresh()->status->value)->toBe('prepared');
});

test('takeover current version can resolve an inherited unknown while the old assignee and stale version remain fenced', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $original = $transfer->getRawOriginal();
    $owner = User::factory()->owner()->create();
    $next = User::factory()->admin()->create();
    $this->actingAs($owner);
    app(AssignRefund::class)->takeOver($refund->id, $owner, $next->id, 1, 'Petugas baru memeriksa mutasi existingattempt');
    $base = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id;
    $this->actingAs($next)->postJson($base.'/completion', operationalCompletion($proof))->assertConflict();
    $this->actingAs($refund->assignedAdmin)->postJson($base.'/completion', operationalCompletion($proof))->assertNotFound();
    $this->actingAs($next)->postJson($base.'/completion', array_replace(operationalCompletion($proof), ['assignment_version' => 2]))->assertOk();
    expect($transfer->fresh()->assignment_version)->toBe(1);
    expect($transfer->fresh()->prepared_by)->toBe($original['prepared_by']);
    expect($transfer->fresh()->getRawOriginal('account_number'))->toBe($original['account_number']);
    expect($transfer->fresh()->verified_by)->toBe($next->id);
});

test('ambiguous external return requires assigned Owner independence confirmation and actual excess success is recorded as an incident', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $receipt = PaymentMovement::query()->sole();
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $refund->payment_id, 'source_event_id' => $receipt->source_event_id, 'amount' => 100000]);
    $base = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id;
    $this->postJson($base.'/completion', operationalCompletion($proof))->assertConflict();
    $this->getJson($base.'/completion-context?assignment_version=1')->assertForbidden();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    app(AssignRefund::class)->takeOver($refund->id, $owner, $owner->id, 1, 'Owner merekonsiliasi dua debit independen');
    $token = $this->getJson($base.'/completion-context?assignment_version=2')->assertOk()->assertJsonPath('data.ambiguous_facts', true)->json('data.financial_context_token');
    $data = array_replace(operationalCompletion($proof), ['assignment_version' => 2, 'financial_context_token' => $token,
        'independence_confirmed' => true, 'independence_note' => 'Bukti mutasi menunjukkan debit BCA-TEST-0001 berbeda dari pengembalian lain; identitas dan tujuan diperiksa']);
    $this->postJson($base.'/completion', $data)->assertOk();
    expect(PaymentMovement::query()->where('kind', 'return')->sum('amount'))->toBe('180000');
    expect($refund->fresh()->status->value)->toBe('review');
    expect($refund->payment->fresh()->review_reason)->toBe('excess_return');
    expect($refund->payment->fresh()->status->value)->toBe('refunded');
    expect($refund->fresh()->amount)->toBe(80000);
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers', ['assignment_version' => 2, 'amount' => 1, 'idempotency_key' => 'forbidden-after-excess'])->assertConflict();
    expect(AuditLog::query()->where('action', 'refund.actual_success_incident')->count())->toBe(1);
    expect(json_encode(AuditLog::query()->pluck('after_redacted')))->not->toContain($token, $data['independence_note']);
    expect(AuditLog::query()->where('action', 'refund.actual_success_incident')->sole()->reason)->toBe($data['independence_note']);
});

test('reused bank identity freezes the second obligation rather than adding a second return', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$firstRefund, $first, $firstProof] = unknownOperationalAttempt($this);
    app(RefundTransfers::class)->complete($firstRefund->id, $first->id, $firstRefund->assignedAdmin, operationalCompletion($firstProof));
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $base = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/completion';
    $this->postJson($base, operationalCompletion($proof))->assertConflict();
    $this->postJson($base, operationalCompletion($proof))->assertConflict();
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(1);
    expect($transfer->fresh()->status->value)->toBe('unknown');
    expect($refund->payment->fresh()->review_reason)->toBe('ledger_identity_conflict');
    expect(AuditLog::query()->where('action', 'refund.transfer_identity_conflict')->count())->toBe(1);
    expect($firstRefund->fresh()->status->value)->toBe('completed');
});

test('evidence loss prevents completion without deleting the unknown reserve or original history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    Storage::disk('private_uploads')->delete($proof->private_path);
    expect(fn () => app(RefundTransfers::class)->complete($refund->id, $transfer->id, $refund->assignedAdmin, operationalCompletion($proof)))->toThrow(RuntimeException::class);
    expect($transfer->fresh()->status->value)->toBe('unknown');
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
});

test('completion outbox failure rolls back all money projections and a deadlock retry emits one effect', function (bool $deadlock) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    Sleep::fake();
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $calls = 0;
    NotificationDelivery::creating(function () use ($deadlock, &$calls): void {
        if ($calls++ === 0) {
            if ($deadlock) {
                DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic transfer completion retry'");
            }
            throw new RuntimeException('synthetic outbox failure');
        }
    });
    try {
        $operation = fn () => app(RefundTransfers::class)->complete($refund->id, $transfer->id, $refund->assignedAdmin, operationalCompletion($proof));
        if ($deadlock) {
            expect($operation()?->status->value)->toBe('succeeded');
        } else {
            expect($operation)->toThrow(RuntimeException::class);
        }
    } finally {
        NotificationDelivery::flushEventListeners();
    }
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe($deadlock ? 1 : 0);
    expect($transfer->fresh()->status->value)->toBe($deadlock ? 'succeeded' : 'unknown');
    expect($refund->fresh()->status->value)->toBe($deadlock ? 'completed' : 'review');
    Sleep::assertSleptTimes($deadlock ? 1 : 0);
})->with([false, true]);

test('prepare validates money version and key boundaries before writing an attempt', function (array $change, string $field) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = verifiedOperationalRefund($this);
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers', array_replace(['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'key'], $change))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('refund_transfers', 0);
})->with([[['amount' => 0], 'amount'], [['amount' => -1], 'amount'], [['amount' => '9223372036854775808'], 'amount'],
    [['assignment_version' => -1], 'assignment_version'], [['idempotency_key' => 'with space'], 'idempotency_key']]);

test('receipt overflow records the verified outgoing fact without guessing payment fully refunded', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $receipt = PaymentMovement::query()->sole();
    PaymentMovement::factory()->create(['payment_id' => $refund->payment_id, 'source_event_id' => $receipt->source_event_id, 'amount' => PHP_INT_MAX]);
    expect(app(RefundTransfers::class)->complete($refund->id, $transfer->id, $refund->assignedAdmin, operationalCompletion($proof))?->status->value)->toBe('succeeded');
    expect($refund->payment->fresh()->status->value)->toBe('paid');
    expect($refund->payment->fresh()->review_reason)->toBe('ledger_overflow');
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(1);
});

test('supported encryption key rotation preserves durable prepared proof and inherited unknown completion', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $oldKey = config('app.key');
    $rawOld = str_starts_with($oldKey, 'base64:') ? base64_decode(substr($oldKey, 7), true) : $oldKey;
    $rawNew = Encrypter::generateKey(config('app.cipher'));
    $encrypter = new Encrypter($rawNew, config('app.cipher'));
    $encrypter->previousKeys([$rawOld]);
    config(['app.key' => 'base64:'.base64_encode($rawNew), 'app.previous_keys' => [$oldKey]]);
    Model::encryptUsing($encrypter);
    $original = $transfer->getRawOriginal('account_number');
    try {
        expect(app(RefundTransfers::class)->complete($refund->id, $transfer->id, $refund->assignedAdmin, operationalCompletion($proof))?->status->value)->toBe('succeeded');
        expect($transfer->fresh()->account_number)->toBe('0012345678');
        expect($transfer->fresh()->getRawOriginal('account_number'))->toBe($original);
    } finally {
        Model::encryptUsing(null);
    }
});

test('Owner independence confirmation is bound to unchanged financial context before and after file read', function (bool $afterRead) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $receipt = PaymentMovement::query()->sole();
    PaymentMovement::factory()->providerRefund()->create(['payment_id' => $refund->payment_id, 'source_event_id' => $receipt->source_event_id, 'amount' => 20000]);
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    app(AssignRefund::class)->takeOver($refund->id, $owner, $owner->id, 1, 'Owner memeriksa independensi pengembalian');
    $token = app(RefundTransfers::class)->completionContext($refund->id, $transfer->id, $owner, 2)['financial_context_token'];
    $other = DB::build(config('database.connections.mysql_testing'));
    $change = fn () => $other->table('payments')->where('id', $refund->payment_id)->update(['review_reason' => 'new_identity_fact']);
    if ($afterRead) {
        $real = app(PrivateUploadStorage::class);
        $storage = Mockery::mock(PrivateUploadStorage::class);
        $storage->shouldReceive('assertReadable')->once()->andReturnUsing(function ($metadata) use ($real, $change): void {
            expect(DB::transactionLevel())->toBe(0);
            $real->assertReadable($metadata);
            $change();
        });
        $this->app->instance(PrivateUploadStorage::class, $storage);
    } else {
        $change();
    }
    $data = array_replace(operationalCompletion($proof), ['assignment_version' => 2, 'financial_context_token' => $token,
        'independence_confirmed' => true, 'independence_note' => 'Mutasi dua transaksi independen telah dibandingkan']);
    try {
        expect(app(RefundTransfers::class)->complete($refund->id, $transfer->id, $owner, $data))->toBeNull();
    } finally {
        $other->disconnect();
    }
    expect($transfer->fresh()->status->value)->toBe('unknown');
    expect(PaymentMovement::query()->where('source', 'manual_refund')->count())->toBe(0);
})->with([false, true]);

test('completion rechecks staff activity version and hours after reading evidence outside locks', function (string $change) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $actor = $refund->assignedAdmin;
    $other = DB::build(config('database.connections.mysql_testing'));
    $real = app(PrivateUploadStorage::class);
    $storage = Mockery::mock(PrivateUploadStorage::class);
    $storage->shouldReceive('assertReadable')->once()->andReturnUsing(function ($metadata) use ($real, $other, $change, $refund, $actor): void {
        expect(DB::transactionLevel())->toBe(0);
        $real->assertReadable($metadata);
        if ($change === 'activity') {
            $other->table('users')->where('id', $actor->id)->update(['is_active' => false]);
        } elseif ($change === 'version') {
            $other->table('refund_logs')->where('id', $refund->id)->update(['assignment_version' => 2]);
        } else {
            $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:01', 'UTC'));
        }
    });
    $this->app->instance(PrivateUploadStorage::class, $storage);
    try {
        expect(fn () => app(RefundTransfers::class)->complete($refund->id, $transfer->id, $actor, operationalCompletion($proof)))
            ->toThrow(HttpException::class);
    } finally {
        $other->disconnect();
    }
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
    expect($transfer->fresh()->status->value)->toBe('unknown');
})->with(['activity', 'version', 'hours']);

test('prepared proof detects alteration of immutable destination source or amount and blocks bank workflow', function (string $field, mixed $value) {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = verifiedOperationalRefund($this);
    $service = app(RefundTransfers::class);
    $transfer = $service->prepare($refund->id, $refund->assignedAdmin, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'prepared']);
    $transfer->forceFill([$field => $value])->save();
    expect($service->markUnknown($refund->id, $transfer->id, $refund->assignedAdmin, 1))->toBeNull();
    expect($transfer->fresh()->status->value)->toBe('prepared');
    expect(AuditLog::query()->where('action', 'refund.transfer_unknown')->count())->toBe(0);
})->with([['account_number', '9999999999'], ['source_account_key', 'OTHER_SOURCE'], ['amount', 70000]]);

test('stable bank reference preserves exact Unicode case and whitespace identity through HTTP middleware', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $reference = '  BCA-é-RefCase  ';
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/completion', array_replace(operationalCompletion($proof), ['transfer_reference' => $reference]))->assertOk();
    expect($transfer->fresh()->transfer_reference)->toBe($reference);
    expect(PaymentMovement::query()->where('kind', 'return')->sole()->canonical_key)->toBe(PaymentMovementKey::fromIdentity('manual:bca:return:v1', ['REK_UTAMA_TBLOOM_BCA', $reference]));
});

test('transfer operations reject caller transactions and invalid proof key history without creating an attempt', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = verifiedOperationalRefund($this);
    $input = ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'prepared'];
    DB::transaction(function () use ($refund, $input): void {
        expect(fn () => app(RefundTransfers::class)->prepare($refund->id, $refund->assignedAdmin, $input))->toThrow(LogicException::class);
    });
    config(['app.previous_keys' => ['invalid-key']]);
    expect(fn () => app(RefundTransfers::class)->prepare($refund->id, $refund->assignedAdmin, $input))->toThrow(LogicException::class);
    $this->assertDatabaseCount('refund_transfers', 0);
});

test('confirmed failure preserves unrelated financial review and cannot authorize another transfer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    [$refund, $transfer, $proof] = unknownOperationalAttempt($this);
    $refund->forceFill(['review_reason' => 'ledger_identity_conflict'])->save();
    $refund->payment->forceFill(['review_reason' => 'ledger_identity_conflict'])->save();
    $service = app(RefundTransfers::class);
    expect($service->confirmFailure($refund->id, $transfer->id, $refund->assignedAdmin, operationalCompletion($proof))?->status->value)->toBe('failed_confirmed');
    expect($refund->fresh()->review_reason)->toBe('ledger_identity_conflict');
    expect($service->prepare($refund->id, $refund->assignedAdmin, ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'next']))->toBeNull();
});

test('transfer endpoints enforce authenticated assigned staff and the domain rejects an impersonated actor', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = operationalRefund();
    $input = ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'auth-boundary'];
    $url = '/staff/refunds/'.$refund->id.'/transfers';
    $this->postJson($url, $input)->assertUnauthorized();
    $this->actingAs($refund->order->user)->postJson($url, $input)->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->postJson($url, $input)->assertNotFound();
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->postJson($url, $input)->assertConflict();
    expect(fn () => app(RefundTransfers::class)->prepare($refund->id, $refund->assignedAdmin, $input))
        ->toThrow(HttpException::class);
    $this->assertDatabaseCount('refund_transfers', 0);
});
