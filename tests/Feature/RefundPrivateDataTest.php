<?php

use App\AssignRefund;
use App\Models\AuditLog;
use App\Models\RefundEvidence;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\PrivateUploadPurpose;
use App\PrivateUploadStorage;
use App\RefundPrivateData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

function refundPrivateBank(): array
{
    return ['bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => 'Pemilik'];
}

function refundPrivateTime(object $test): void
{
    $test->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
}

test('customer provides private bank details and assigned staff verifies the current destination', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $refund = RefundLog::factory()->assigned()->create();
    $customer = $refund->order->user;
    $details = ['bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => 'Nama Berbeda'];
    $url = '/refunds/'.$refund->id.'/bank-details';

    $this->actingAs($customer)->putJson($url, $details + ['bank_verified_by' => $customer->id])->assertOk();
    expect($refund->fresh()->bank_verified_at)->toBeNull();
    expect($refund->fresh()->account_number)->toBe('0012345678');
    expect(DB::table('refund_logs')->where('id', $refund->id)->value('account_number'))->not->toContain('0012345678');
    $token = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.bank_token');
    $this->actingAs($refund->assignedAdmin)->postJson('/staff/refunds/'.$refund->id.'/bank-verification', [
        'assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true,
    ])->assertOk();
    expect($refund->fresh()->bank_verified_by)->toBe($refund->assigned_admin_id);
    expect($refund->fresh()->bank_verified_at)->not->toBeNull();
    expect(json_encode(AuditLog::query()->pluck('after_redacted')))->not->toContain('0012345678', 'Nama Berbeda');
});

test('assigned staff appends durable evidence and only authorized users download authenticated attachments', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    Storage::fake('private_uploads');
    $refund = RefundLog::factory()->processing()->create();
    $transfer = RefundTransfer::factory()->for($refund)->unknown()->create();
    $upload = UploadedFile::fake()->image('private-customer.png', 2, 2);
    $url = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence';
    $this->actingAs($refund->assignedAdmin)->postJson($url, ['assignment_version' => 1, 'upload' => $upload])->assertCreated();
    $evidence = RefundEvidence::query()->sole();
    Storage::disk('private_uploads')->assertExists($evidence->private_path);
    expect($evidence->private_path)->toStartWith('refund-evidence/');
    expect($evidence->original_name_ciphertext)->toBe('private-customer.png');
    $this->actingAs($refund->order->user)->get('/refund-evidence/'.$evidence->id)->assertOk()->assertDownload('refund-evidence-'.$evidence->id.'.png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs(User::factory()->admin()->create())->getJson('/refund-evidence/'.$evidence->id)->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson('/refund-evidence/'.$evidence->id)->assertNotFound();
});

test('editing bank details clears verification and a stale verification token cannot verify the replacement', function () {
    refundPrivateTime($this);
    $refund = RefundLog::factory()->processing()->create();
    $this->actingAs($refund->assignedAdmin);
    $token = $this->getJson('/refunds/'.$refund->id.'/bank-details')->json('data.bank_token');
    $this->actingAs($refund->order->user)->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank())->assertOk();
    $this->actingAs($refund->assignedAdmin)->postJson('/staff/refunds/'.$refund->id.'/bank-verification', [
        'assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true,
    ])->assertConflict();
    expect($refund->fresh()->bank_verified_at)->toBeNull();
    expect($refund->fresh()->bank_verified_by)->toBeNull();
});

test('bank verification requires customer confirmation and cannot verify missing data', function () {
    refundPrivateTime($this);
    $refund = RefundLog::factory()->assigned()->create();
    $this->actingAs($refund->assignedAdmin);
    $token = $this->getJson('/refunds/'.$refund->id.'/bank-details')->json('data.bank_token');
    $url = '/staff/refunds/'.$refund->id.'/bank-verification';
    $this->postJson($url, ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('customer_confirmed');
    $this->postJson($url, ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true])->assertConflict();
    expect($refund->fresh()->bank_verified_at)->toBeNull();
});

test('private data policy permits only active own customer assigned staff or Owner oversight', function (string $kind, bool $allowed) {
    $refund = RefundLog::factory()->assigned()->create();
    $user = match ($kind) {
        'customer', 'inactive_customer' => $refund->order->user,
        'assigned', 'inactive_assigned', 'demoted_assigned' => $refund->assignedAdmin,
        'admin' => User::factory()->admin()->create(),
        'owner' => User::factory()->owner()->create(),
        default => User::factory()->create(),
    };
    if (str_starts_with($kind, 'inactive')) {
        $user->forceFill(['is_active' => false])->save();
    }
    if ($kind === 'demoted_assigned') {
        $user->forceFill(['role' => 'customer'])->save();
    }
    expect(Gate::forUser($user)->allows('privateData', $refund))->toBe($allowed);
})->with([['customer', true], ['assigned', true], ['owner', true], ['foreign', false], ['admin', false],
    ['inactive_customer', false], ['inactive_assigned', false], ['demoted_assigned', false]]);

test('bank input is private from other customers and unassigned staff and guests', function () {
    $refund = RefundLog::factory()->processing()->create();
    $url = '/refunds/'.$refund->id.'/bank-details';
    $this->getJson($url)->assertUnauthorized();
    foreach ([User::factory()->create(), User::factory()->admin()->create()] as $outsider) {
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->putJson($url, refundPrivateBank())->assertNotFound();
    }
});

test('bank changes are blocked while any attempt is active and all terminal data remains immutable', function (string $state) {
    refundPrivateTime($this);
    $refund = RefundLog::factory()->processing()->create();
    $transfer = RefundTransfer::factory()->for($refund)->state(['status' => $state])->create();
    $before = $transfer->fresh()->getRawOriginal();
    $oldBank = $refund->account_number;
    $this->actingAs($refund->order->user)->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank())->assertConflict();
    expect($refund->fresh()->account_number)->toBe($oldBank);
    expect($transfer->fresh()->getRawOriginal())->toBe($before);
})->with(['prepared', 'unknown']);

test('bank writes require the current assignment version and Owner oversight alone cannot mutate', function () {
    refundPrivateTime($this);
    $refund = RefundLog::factory()->processing()->create();
    $this->actingAs($refund->assignedAdmin)->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank() + ['assignment_version' => 0])->assertConflict();
    $this->actingAs(User::factory()->owner()->create())->getJson('/refunds/'.$refund->id.'/bank-details')->assertOk();
    $this->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank() + ['assignment_version' => 1])->assertConflict();
});

test('bank details never flash to the session on browser validation errors', function () {
    $refund = RefundLog::factory()->assigned()->create();
    $this->actingAs($refund->order->user)->from('/account')->put('/refunds/'.$refund->id.'/bank-details', [
        'bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => '', 'bank_token' => 'private-token',
    ])->assertRedirect('/account')->assertSessionHasErrors('account_holder');
    expect(session()->getOldInput())->not->toHaveKeys(['bank_name', 'account_number', 'account_holder', 'bank_token']);
});

test('bank input rejects missing blank oversized and control data', function (array $change, string $field) {
    $refund = RefundLog::factory()->assigned()->create();
    $this->actingAs($refund->order->user)->putJson('/refunds/'.$refund->id.'/bank-details', array_replace(refundPrivateBank(), $change))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($refund->fresh()->account_number)->toBeNull();
})->with([
    [['bank_name' => '   '], 'bank_name'], [['bank_name' => str_repeat('a', 101)], 'bank_name'],
    [['account_number' => 123456], 'account_number'], [['account_number' => "001\n234"], 'account_number'],
    [['account_number' => str_repeat('1', 101)], 'account_number'], [['account_holder' => str_repeat('a', 256)], 'account_holder'],
]);

test('bank verification and no-op bank submissions are idempotent without duplicate audit', function () {
    refundPrivateTime($this);
    $refund = RefundLog::factory()->assigned()->create();
    $this->actingAs($refund->order->user)->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank())->assertOk();
    $this->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank())->assertOk();
    $this->actingAs($refund->assignedAdmin);
    $token = $this->getJson('/refunds/'.$refund->id.'/bank-details')->json('data.bank_token');
    $input = ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true];
    $this->postJson('/staff/refunds/'.$refund->id.'/bank-verification', $input)->assertOk();
    $verifiedAt = $refund->fresh()->bank_verified_at->toISOString();
    $this->travel(1)->minutes();
    $this->postJson('/staff/refunds/'.$refund->id.'/bank-verification', $input)->assertOk();
    expect(AuditLog::query()->where('action', 'refund.bank_verified')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'refund.bank_updated')->count())->toBe(1);
    expect($refund->fresh()->bank_verified_at->toISOString())->toBe($verifiedAt);
});

test('current assignee uploads to inherited unknown attempt without rewriting its original assignment version', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $refund = RefundLog::factory()->processing()->create();
    $transfer = RefundTransfer::factory()->for($refund)->unknown()->create();
    $before = $transfer->fresh()->getRawOriginal();
    $owner = User::factory()->owner()->create();
    $next = User::factory()->admin()->create();
    $this->actingAs($owner);
    app(AssignRefund::class)->takeOver($refund->id, $owner, $next->id, 1, 'Pergantian petugas');
    $url = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence';
    $this->actingAs($next)->postJson($url, ['assignment_version' => 1, 'upload' => UploadedFile::fake()->image('a.png')])->assertConflict();
    $this->postJson($url, ['assignment_version' => 2, 'upload' => UploadedFile::fake()->image('b.png')])->assertCreated();
    $this->actingAs($refund->assignedAdmin)->postJson($url, ['assignment_version' => 1, 'upload' => UploadedFile::fake()->image('c.png')])->assertNotFound();
    expect($transfer->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('refund_evidence', 1);
});

test('upload retry reuses identical durable evidence and differing files append without overwriting', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    $transfer = RefundTransfer::factory()->unknown()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    $url = '/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence';
    $upload = UploadedFile::fake()->image('same.png', 2, 2);
    $first = $this->postJson($url, ['assignment_version' => 1, 'upload' => $upload])->assertCreated()->json('data.id');
    $this->postJson($url, ['assignment_version' => 1, 'upload' => $upload])->assertCreated()->assertJsonPath('data.id', $first);
    $this->postJson($url, ['assignment_version' => 1, 'upload' => UploadedFile::fake()->image('different.png', 3, 3)])->assertCreated();
    $this->assertDatabaseCount('refund_evidence', 2);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(2);
    expect(AuditLog::query()->where('action', 'refund.evidence_uploaded')->count())->toBe(2);
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    Http::assertNothingSent();
});

test('cross refund evidence uploads and terminal attempt uploads are refused', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->succeeded()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin)->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence', [
        'assignment_version' => 1, 'upload' => UploadedFile::fake()->image('a.png'),
    ])->assertConflict();
    $other = RefundTransfer::factory()->unknown()->create();
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$other->id.'/evidence', [
        'assignment_version' => 1, 'upload' => UploadedFile::fake()->image('a.png'),
    ])->assertNotFound();
    $this->assertDatabaseCount('refund_evidence', 0);
    Storage::disk('private_uploads')->assertDirectoryEmpty('');
});

test('uploads reject spoofed active and oversized files before persisting evidence', function (string $kind) {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $image = UploadedFile::fake()->image('a.png');
    $upload = match ($kind) {
        'svg' => UploadedFile::fake()->createWithContent('file.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'html' => UploadedFile::fake()->createWithContent('file.pdf', '<html><script>alert(1)</script></html>'),
        'truncated' => UploadedFile::fake()->createWithContent('a.png', substr(file_get_contents($image->getRealPath()), 0, 33)),
        default => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
    };
    $this->actingAs($refund->assignedAdmin)->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence', [
        'assignment_version' => 1, 'upload' => $upload,
    ])->assertUnprocessable()->assertJsonValidationErrors('upload');
    $this->assertDatabaseCount('refund_evidence', 0);
    Storage::disk('private_uploads')->assertDirectoryEmpty('');
})->with(['svg', 'html', 'truncated', 'oversized']);

test('PDF evidence is an authenticated attachment with a server generated name', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $upload = UploadedFile::fake()->createWithContent('unsafe-name.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $this->actingAs($refund->assignedAdmin)->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence', ['assignment_version' => 1, 'upload' => $upload])->assertCreated();
    $evidence = RefundEvidence::query()->sole();
    $this->get('/refund-evidence/'.$evidence->id)->assertOk()->assertDownload('refund-evidence-'.$evidence->id.'.pdf')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
});

test('missing tampered and wrong purpose evidence cannot be downloaded or acknowledged as a durable retry', function (string $kind) {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    $upload = UploadedFile::fake()->image('proof.png');
    app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, $upload);
    $evidence = RefundEvidence::query()->sole();
    if ($kind === 'missing') {
        Storage::disk('private_uploads')->delete($evidence->private_path);
    } elseif ($kind === 'tampered') {
        Storage::disk('private_uploads')->put($evidence->private_path, 'changed');
    } else {
        $metadata = app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::CustomReference);
        $evidence->forceFill(['private_path' => $metadata['private_path']])->save();
    }
    expect(fn () => app(RefundPrivateData::class)->download($evidence->id, $refund->assignedAdmin))->toThrow(RuntimeException::class);
    expect(fn () => app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, $upload))->toThrow(RuntimeException::class);
    expect(AuditLog::query()->where('action', 'refund.evidence_downloaded')->exists())->toBeFalse();
})->with(['missing', 'tampered', 'wrong_purpose']);

test('evidence metadata and audit rollback together while failed transactions leave only an inaccessible private orphan', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    AuditLog::creating(function (AuditLog $audit): void {
        if ($audit->action === 'refund.evidence_uploaded') {
            throw new RuntimeException('synthetic audit failure');
        }
    });
    try {
        expect(fn () => app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('a.png')))->toThrow(RuntimeException::class);
    } finally {
        AuditLog::flushEventListeners();
    }
    $this->assertDatabaseCount('refund_evidence', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
});

test('manual bank work obeys operating hours but own customer input remains available', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:01', 'UTC'));
    $refund = RefundLog::factory()->assigned()->create();
    $this->actingAs($refund->assignedAdmin)->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank() + ['assignment_version' => 1])->assertForbidden();
    $this->actingAs($refund->order->user)->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank())->assertOk();
});

test('private evidence file storage and readback execute outside domain transactions', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    $real = app(PrivateUploadStorage::class);
    $storage = Mockery::mock(PrivateUploadStorage::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function ($upload, $purpose) use ($real) {
        expect(DB::transactionLevel())->toBe(0);

        return $real->store($upload, $purpose);
    });
    $storage->shouldReceive('assertReadable')->andReturnUsing(function ($metadata) use ($real): void {
        expect(DB::transactionLevel())->toBe(0);
        $real->assertReadable($metadata);
    });
    $this->app->instance(PrivateUploadStorage::class, $storage);
    $service = app(RefundPrivateData::class);
    $evidence = $service->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('a.png'));
    expect($evidence)->toBeInstanceOf(RefundEvidence::class);
    expect($service->download($evidence->id, $refund->assignedAdmin)['mime_type'])->toBe('image/png');
});

test('private refund operations reject nested caller transactions without file or audit effects', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    DB::transaction(function () use ($refund, $transfer): void {
        expect(fn () => app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('a.png')))->toThrow(LogicException::class);
    });
    $this->assertDatabaseCount('refund_evidence', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Storage::disk('private_uploads')->assertDirectoryEmpty('');
});

test('assignment activity and operating hours are rechecked after durable file staging', function (string $change) {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $actor = $refund->assignedAdmin;
    $next = User::factory()->admin()->create();
    $this->actingAs($actor);
    $other = DB::build(config('database.connections.mysql_testing'));
    $real = app(PrivateUploadStorage::class);
    $storage = Mockery::mock(PrivateUploadStorage::class);
    $storage->shouldReceive('store')->once()->andReturnUsing(function ($upload, $purpose) use ($real, $change, $other, $refund, $actor, $next) {
        expect(DB::transactionLevel())->toBe(0);
        $metadata = $real->store($upload, $purpose);
        if ($change === 'assignment') {
            $other->table('refund_logs')->where('id', $refund->id)->update(['assigned_admin_id' => $next->id, 'assignment_version' => 2]);
        } elseif ($change === 'activity') {
            $other->table('users')->where('id', $actor->id)->update(['is_active' => false]);
        } else {
            $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:01', 'UTC'));
        }

        return $metadata;
    });
    $storage->shouldReceive('assertReadable')->andReturnUsing(fn ($metadata) => $real->assertReadable($metadata));
    $this->app->instance(PrivateUploadStorage::class, $storage);
    try {
        expect(fn () => app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $actor, 1, UploadedFile::fake()->image('a.png')))
            ->toThrow($change === 'assignment' ? AuthorizationException::class : HttpException::class);
    } finally {
        $other->disconnect();
    }
    $this->assertDatabaseCount('refund_evidence', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
})->with(['assignment', 'activity', 'hours']);

test('download rechecks authorization after private bytes were read outside locks', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $actor = $refund->assignedAdmin;
    $this->actingAs($actor);
    $evidence = app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $actor, 1, UploadedFile::fake()->image('a.png'));
    $other = DB::build(config('database.connections.mysql_testing'));
    $real = app(PrivateUploadStorage::class);
    $storage = Mockery::mock(PrivateUploadStorage::class);
    $storage->shouldReceive('assertReadable')->once()->andReturnUsing(function ($metadata) use ($real, $other, $actor): void {
        expect(DB::transactionLevel())->toBe(0);
        $real->assertReadable($metadata);
        $other->table('users')->where('id', $actor->id)->update(['is_active' => false]);
    });
    $this->app->instance(PrivateUploadStorage::class, $storage);
    try {
        expect(fn () => app(RefundPrivateData::class)->download($evidence->id, $actor))->toThrow(HttpException::class);
    } finally {
        $other->disconnect();
    }
    expect(AuditLog::query()->where('action', 'refund.evidence_downloaded')->count())->toBe(0);
});

test('a bounded deadlock retry reuses staged evidence and does not duplicate metadata or audit', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    Sleep::fake();
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    $attempts = 0;
    AuditLog::creating(function (AuditLog $audit) use (&$attempts): void {
        if ($audit->action === 'refund.evidence_uploaded' && ++$attempts === 1) {
            DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'synthetic evidence retry'");
        }
    });
    try {
        expect(app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('a.png')))->toBeInstanceOf(RefundEvidence::class);
    } finally {
        AuditLog::flushEventListeners();
    }
    $this->assertDatabaseCount('refund_evidence', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
    Sleep::assertSleptTimes(1);
});

test('completed refund blocks new bank verification edits and uploads while preserving authorized evidence downloads', function () {
    refundPrivateTime($this);
    Storage::fake('private_uploads');
    $transfer = RefundTransfer::factory()->create();
    $refund = $transfer->refundLog;
    $this->actingAs($refund->assignedAdmin);
    $evidence = app(RefundPrivateData::class)->upload($refund->id, $transfer->id, $refund->assignedAdmin, 1, UploadedFile::fake()->image('a.png'));
    $refund->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $refund->assigned_admin_id])->save();
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers/'.$transfer->id.'/evidence', ['assignment_version' => 1, 'upload' => UploadedFile::fake()->image('b.png')])->assertConflict();
    $this->putJson('/refunds/'.$refund->id.'/bank-details', refundPrivateBank() + ['assignment_version' => 1])->assertConflict();
    $token = $this->getJson('/refunds/'.$refund->id.'/bank-details')->json('data.bank_token');
    $this->postJson('/staff/refunds/'.$refund->id.'/bank-verification', ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true])->assertConflict();
    $this->actingAs($refund->order->user)->get('/refund-evidence/'.$evidence->id)->assertOk();
    expect(DB::table('refund_evidence')->where('id', $evidence->id)->value('original_name_ciphertext'))->not->toContain('a.png');
    expect($evidence->toArray())->not->toHaveKeys(['private_path', 'original_name_ciphertext']);
});
