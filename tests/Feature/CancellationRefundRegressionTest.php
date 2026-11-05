<?php

use App\AssignRefund;
use App\CancellationRequests;
use App\CancelPaidOrder;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\User;
use App\PaymentFunds;
use App\PrivateUploadStorage;
use App\ProcessPaymentEvent;
use App\ProcessPaymentEventJob;
use App\ReconciledPaymentTime;
use App\ReconcilePayments;
use App\RefundPrivateData;
use App\RefundTransfers;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'app.previous_keys' => [],
        'services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-regression-key',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    Model::encryptUsing(new Encrypter(str_repeat('t', 32), config('app.cipher')));
});

afterEach(function () {
    Model::encryptUsing(null);
});

/** @param array<string, array<string, mixed>> $responses */
function refundRegressionGateway(array &$responses): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$responses) {
        expect(DB::transactionLevel())->toBe(0);

        return isset($responses[$request->url()]) ? Http::response($responses[$request->url()]) : null;
    });
}

/** @param array<string, array<string, mixed>> $responses
 * @return array<string, mixed>
 */
function refundRegressionObligation(object $test, array &$responses, bool $claim = true): array
{
    $test->travelTo(CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
    $order = Order::factory()->create(['product_total' => 100001, 'created_at' => '2026-10-29 03:00:00',
        'payment_expires_at' => '2026-10-29 03:30:00', 'pickup_date' => '2026-11-01']);
    OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
    $payment = Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number, 'created_at' => '2026-10-29 03:00:00']);
    $transactionId = (string) Str::uuid();
    $url = 'https://api.sandbox.midtrans.com/v2/'.$payment->merchant_reference.'/status';
    $responses[$url] = ['order_id' => $payment->merchant_reference, 'transaction_id' => $transactionId, 'status_code' => '200',
        'transaction_status' => 'settlement', 'gross_amount' => '100001.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'va_numbers' => [['bank' => 'bca']], 'transaction_time' => '2026-10-29 10:00:00', 'settlement_time' => '2026-10-29 10:10:00'];
    $event = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);
    $owner = User::factory()->owner()->create();
    $test->actingAs($owner);
    expect(app(ProcessPaymentEvent::class)->process($event->id, $owner,
        ReconciledPaymentTime::fromReconciliation($transactionId, CarbonImmutable::parse('2026-10-29 03:09:59', 'UTC'), 'synthetic-timely-bank-record')))->toBeTrue();
    expect($order->fresh()->status->value)->toBe('paid');
    $customer = $order->user;
    $test->actingAs($customer);
    $request = app(CancellationRequests::class)->submit($order->id, $customer, ['idempotency_key' => 'regression-cancellation', 'reason' => 'Customer membatalkan sesuai H-2']);
    $admin = User::factory()->admin()->create();
    $test->actingAs($admin);
    $refund = app(CancelPaidOrder::class)->approve($request->id, $admin, 'Pembatalan disetujui');
    expect($refund?->amount)->toBe(80000);
    $context = ['order' => $order->fresh(), 'payment' => $payment->fresh(), 'refund' => $refund->fresh(), 'admin' => $admin,
        'owner' => $owner, 'request' => $request->fresh(), 'url' => $url];
    if (! $claim) {
        return $context;
    }
    app(AssignRefund::class)->claim($refund->id, $admin, 0);
    $test->actingAs($customer);
    $private = app(RefundPrivateData::class);
    $private->saveBank($refund->id, $customer, ['bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => 'Pemilik synthetic']);
    $test->actingAs($admin);
    $token = $private->bank($refund->id, $admin)['bank_token'];
    expect($private->verifyBank($refund->id, $admin, ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true]))->toBeTrue();

    return array_replace($context, ['refund' => $refund->fresh()]);
}

/** @param array<string, array<string, mixed>> $responses
 * @return array<string, mixed>
 */
function refundRegressionAttempt(object $test, array &$responses): array
{
    $context = refundRegressionObligation($test, $responses);
    $transfers = app(RefundTransfers::class);
    $transfer = $transfers->prepare($context['refund']->id, $context['admin'], ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'regression-prepare']);
    expect($transfer)->toBeInstanceOf(RefundTransfer::class);
    $transfers->markUnknown($context['refund']->id, $transfer->id, $context['admin'], 1);
    $proof = app(RefundPrivateData::class)->upload($context['refund']->id, $transfer->id, $context['admin'], 1, UploadedFile::fake()->image('bank-proof.png', 2, 2));

    return $context + ['transfer' => $transfer->fresh(), 'evidence' => $proof, 'completion' => ['assignment_version' => 1,
        'bank_statement_checked' => true, 'evidence_id' => $proof->id, 'transfer_reference' => 'BCA-P6-07-REF',
        'transferred_at' => '2026-10-30T10:00:00+07:00', 'verification_note' => 'Mutasi bank sesuai tujuan, identitas dan nominal']];
}

/** @param array<string, mixed> $task
 * @return array{process: Process, stream: InputStream}
 */
function refundRegressionWorker(array $task): array
{
    $code = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! $app->environment('testing') || $app->configurationIsCached()) { exit(91); }
        $db = Illuminate\Support\Facades\DB::connection();
        Tests\TestCase::assertSafeDatabaseConfiguration($db->getConfig());
        if ($db->selectOne('SELECT DATABASE() AS name')->name !== 'tbloom_testing') { exit(92); }
        $db->statement('SET SESSION innodb_lock_wait_timeout = 10');
        $task = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        config(['filesystems.disks.private_uploads.root' => $task['private_root']]);
        Illuminate\Support\Facades\Date::setTestNow(Carbon\CarbonImmutable::parse('2026-10-30 03:00:00', 'UTC'));
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Sleep::fake();
        $id = $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $gated = false;
        if ($task['write_barrier'] ?? false) {
            Illuminate\Support\Facades\Event::listen('eloquent.saving: '.App\Models\RefundTransfer::class, function ($transfer) use (&$gated, $task): void {
                if (! $gated && $transfer->status->value === ($task['write_status'] ?? 'succeeded')) {
                    $gated = true;
                    echo json_encode(['writing' => true]).PHP_EOL;
                    fflush(STDOUT);
                    if (trim(fgets(STDIN)) !== 'commit') { throw new RuntimeException('Missing test write release.'); }
                }
            });
        }
        echo json_encode(['ready' => $id]).PHP_EOL;
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'go') { exit(93); }
        try {
            $actor = App\Models\User::findOrFail($task['actor_id']);
            Illuminate\Support\Facades\Auth::setUser($actor);
            $result = match ($task['action']) {
                'takeover' => $app->make(App\AssignRefund::class)->takeOver($task['refund_id'], $actor, $task['destination_id'], 1, 'Owner takeover during evidence read'),
                'prepare' => $app->make(App\RefundTransfers::class)->prepare($task['refund_id'], $actor, $task['input']),
                default => $app->make(App\RefundTransfers::class)->complete($task['refund_id'], $task['transfer_id'], $actor, $task['input']),
            };
            Illuminate\Support\Facades\Http::assertNothingSent();
            echo json_encode(['result' => $result === null ? 'conflict' : 'ok', 'id' => $result?->id, 'status' => $result?->status->value,
                'connection_id' => $id, 'transaction_level' => $db->transactionLevel()]).PHP_EOL;
        } catch (Throwable $exception) {
            echo json_encode(['result' => 'error', 'type' => get_class($exception), 'code' => $exception->getCode(),
                'driver_code' => $exception instanceof Illuminate\Database\QueryException ? ($exception->errorInfo[1] ?? null) : null]).PHP_EOL;
        }
        PHP;
    $task['private_root'] = Storage::disk('private_uploads')->path('');
    $stream = new InputStream;
    $process = new Process([PHP_BINARY, '-r', $code, base_path(), base64_encode(json_encode($task, JSON_THROW_ON_ERROR))], base_path(), [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'APP_PREVIOUS_KEYS' => '',
        'DB_CONNECTION' => 'mysql_testing', 'DB_DATABASE' => 'tbloom_testing', 'DB_URL' => '', 'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'NOTIFICATION_RECIPIENT_HMAC_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)), 'NOTIFICATION_PREVIOUS_RECIPIENT_HMAC_KEYS' => '',
    ]);
    $process->setInput($stream)->setTimeout(60)->start();

    return ['process' => $process, 'stream' => $stream];
}

/** @param list<array{process: Process, stream: InputStream}> $workers */
function refundRegressionBarrier(array $workers, string $marker, int $count): void
{
    $deadline = microtime(true) + 20;
    do {
        $found = count(array_filter($workers, fn (array $worker): bool => str_contains($worker['process']->getOutput(), $marker)));
        if ($found >= $count) {
            return;
        }
        foreach ($workers as $worker) {
            if (! $worker['process']->isRunning() && ! str_contains($worker['process']->getOutput(), $marker)) {
                throw new RuntimeException('Refund worker did not reach barrier: '.$worker['process']->getOutput().$worker['process']->getErrorOutput());
            }
        }
        usleep(2000);
    } while (microtime(true) < $deadline);
    $locks = DB::select("SELECT requested.OBJECT_NAME AS table_name, requested.INDEX_NAME AS index_name, requested.LOCK_MODE AS lock_mode FROM performance_schema.data_lock_waits AS waits JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID WHERE requested.OBJECT_SCHEMA = 'tbloom_testing'");
    expect($found)->toBe($count, 'Refund worker barrier timed out: '.json_encode($locks));
}

/** @param list<array{process: Process, stream: InputStream}> $workers */
function refundRegressionDrain(array $workers): void
{
    $deadline = microtime(true) + 50;
    do {
        $running = false;
        foreach ($workers as $worker) {
            $running = $worker['process']->isRunning() || $running;
        }
        if (! $running) {
            return;
        }
        usleep(2000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Refund workers did not finish after release.');
}

/** @return array<string, mixed> */
function refundRegressionResult(Process $process): array
{
    expect($process->wait())->toBe(0, $process->getErrorOutput());
    $lines = explode("\n", trim($process->getOutput()));
    $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    expect($result['result'])->not->toBe('error', ($result['type'] ?? '').':'.($result['driver_code'] ?? ''));

    return $result;
}

test('full domain cancellation refund chain preserves gross80 and canonical return across settlement duplicates and scanner recovery', function () {
    Storage::fake('private_uploads');
    $responses = [];
    refundRegressionGateway($responses);
    $context = refundRegressionAttempt($this, $responses);
    $payment = $context['payment'];
    $refund = $context['refund'];
    $transfer = $context['transfer'];
    $released = $context['order']->item->getRawOriginal();
    $duplicate = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);
    expect(app(ProcessPaymentEvent::class)->process($duplicate->id))->toBeTrue();
    expect($refund->fresh()->amount)->toBe(80000);
    expect($transfer->fresh()->status->value)->toBe('unknown');
    expect(app(RefundTransfers::class)->complete($refund->id, $transfer->id, $context['admin'], $context['completion'])?->status->value)->toBe('succeeded');
    $later = PaymentEvent::factory()->for($payment)->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'settlement']]);
    expect(app(ProcessPaymentEvent::class)->process($later->id))->toBeTrue();
    expect(app(CancelPaidOrder::class)->approve($context['request']->id, $context['admin'], 'Pembatalan disetujui')?->id)->toBe($refund->id);
    expect($refund->fresh()->amount)->toBe(80000);
    expect($refund->fresh()->status->value)->toBe('completed');
    expect($payment->fresh()->status->value)->toBe('partially_refunded');
    expect(PaymentMovement::query()->where('kind', 'receipt')->count())->toBe(1);
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(1);
    expect($context['order']->item->fresh()->getRawOriginal())->toBe($released);
    Queue::fake([ProcessPaymentEventJob::class]);
    expect(app(ReconcilePayments::class)->dispatch())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertSentCount(3);
});

test('actual overlapping refund workers serialize preparation exact replay and global bank reference identity', function (string $scenario) {
    Storage::fake('private_uploads');
    $responses = [];
    refundRegressionGateway($responses);
    $prepare = $scenario === 'prepare';
    $sameAttempt = $scenario !== 'identity';
    $first = $prepare ? refundRegressionObligation($this, $responses) : refundRegressionAttempt($this, $responses);
    $second = $sameAttempt ? $first : refundRegressionAttempt($this, $responses);
    $workers = [];
    try {
        foreach ([$first, $second] as $number => $context) {
            $workers[] = refundRegressionWorker(['action' => $prepare ? 'prepare' : 'complete', 'actor_id' => $context['admin']->id, 'refund_id' => $context['refund']->id,
                'transfer_id' => $context['transfer']->id ?? null, 'input' => $prepare
                    ? ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'prepare-worker-'.$number] : $context['completion'],
                'write_barrier' => true, 'write_status' => $prepare ? 'prepared' : 'succeeded']);
        }
        refundRegressionBarrier($workers, '"ready":', 2);
        $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $ids = array_map(fn (array $worker): int => json_decode(explode("\n", $worker['process']->getOutput())[0], true, flags: JSON_THROW_ON_ERROR)['ready'], $workers);
        expect(count(array_unique([$parentId, ...$ids])))->toBe(3);
        foreach ($workers as $worker) {
            $worker['stream']->write("go\n");
        }
        refundRegressionBarrier($workers, '"writing":', $sameAttempt ? 1 : 2);
        if ($sameAttempt) {
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits JOIN performance_schema.threads AS threads ON threads.THREAD_ID = waits.BLOCKING_THREAD_ID WHERE threads.PROCESSLIST_ID IN (?, ?)', $ids)->total;
                if ($waiting > 0) {
                    break;
                }
                usleep(2000);
            } while (microtime(true) < $deadline);
            expect($waiting)->toBeGreaterThan(0);
        }
        foreach ($workers as $worker) {
            $worker['stream']->write("commit\n");
            $worker['stream']->close();
        }
        refundRegressionDrain($workers);
        $results = array_map(fn (array $worker): array => refundRegressionResult($worker['process']), $workers);
        expect(array_column($results, 'transaction_level'))->toBe([0, 0]);
        expect(count(array_filter($results, fn (array $result): bool => $result['result'] === 'ok')))->toBe($sameAttempt && ! $prepare ? 2 : 1);
        if ($prepare) {
            expect(RefundTransfer::query()->count())->toBe(1);
            expect(RefundTransfer::query()->sole()->status->value)->toBe('prepared');
            expect(AuditLog::query()->where('action', 'refund.transfer_prepared')->count())->toBe(1);
            expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
            $funds = DB::transaction(function () use ($first): array {
                $payment = Payment::query()->whereKey($first['payment']->id)->lockForUpdate()->firstOrFail();
                RefundLog::query()->whereKey($first['refund']->id)->lockForUpdate()->firstOrFail();
                RefundTransfer::query()->where('refund_log_id', $first['refund']->id)->lockForUpdate()->get();

                return app(PaymentFunds::class)->snapshot($payment);
            });
            expect($funds['reserved'])->toBe(80000);
            expect($funds['available'])->toBe(20001);
            expect($funds['remaining_obligation'])->toBe(0);
        } else {
            expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(1);
            expect(RefundTransfer::query()->where('status', 'succeeded')->count())->toBe(1);
            expect(AuditLog::query()->where('action', 'refund.transfer_succeeded')->count())->toBe(1);
            expect(RefundLog::query()->where('status', 'completed')->count())->toBe(1);
        }
        if (! $sameAttempt) {
            expect(RefundTransfer::query()->where('status', 'unknown')->count())->toBe(1);
            expect(Payment::query()->where('review_reason', 'ledger_identity_conflict')->count())->toBe(1);
            expect(AuditLog::query()->where('action', 'refund.transfer_identity_conflict')->count())->toBe(1);
        }
    } finally {
        foreach ($workers as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(0);
            }
        }
    }
})->with(['two completers for one attempt' => 'replay', 'two attempts claiming one bank reference' => 'identity', 'two preparation keys for one obligation' => 'prepare']);

test('a real Owner takeover process during evidence reading fences the old completion and preserves the inherited attempt', function () {
    Storage::fake('private_uploads');
    $responses = [];
    refundRegressionGateway($responses);
    $context = refundRegressionAttempt($this, $responses);
    $next = User::factory()->admin()->create();
    $original = $context['transfer']->getRawOriginal();
    $real = app(PrivateUploadStorage::class);
    $storage = Mockery::mock(PrivateUploadStorage::class);
    $injected = false;
    $storage->shouldReceive('assertReadable')->andReturnUsing(function ($metadata) use ($real, $context, $next, &$injected): void {
        expect(DB::transactionLevel())->toBe(0);
        $real->assertReadable($metadata);
        if ($injected) {
            return;
        }
        $injected = true;
        $worker = refundRegressionWorker(['action' => 'takeover', 'actor_id' => $context['owner']->id, 'destination_id' => $next->id,
            'refund_id' => $context['refund']->id, 'write_barrier' => false]);
        try {
            refundRegressionBarrier([$worker], '"ready":', 1);
            $worker['stream']->write("go\n");
            $worker['stream']->close();
            $result = refundRegressionResult($worker['process']);
            expect($result['result'])->toBe('ok');
            expect((int) $result['connection_id'])->not->toBe((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        } finally {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(0);
            }
        }
    });
    $this->app->instance(PrivateUploadStorage::class, $storage);
    expect(fn () => app(RefundTransfers::class)->complete($context['refund']->id, $context['transfer']->id, $context['admin'], $context['completion']))->toThrow(AuthorizationException::class);
    expect($context['transfer']->fresh()->getRawOriginal())->toBe($original);
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
    $this->actingAs($next);
    expect(app(RefundTransfers::class)->complete($context['refund']->id, $context['transfer']->id, $next, array_replace($context['completion'], ['assignment_version' => 2]))?->status->value)->toBe('succeeded');
    expect($context['transfer']->fresh()->assignment_version)->toBe(1);
    expect($context['transfer']->fresh()->prepared_by)->toBe($context['admin']->id);
});

test('unsupported BCA return observation freezes new work without fabricating money and a proved manual success remains a separate incident', function () {
    Storage::fake('private_uploads');
    $responses = [];
    refundRegressionGateway($responses);
    $context = refundRegressionAttempt($this, $responses);
    $responses[$context['url']]['transaction_status'] = 'refund';
    $event = PaymentEvent::factory()->for($context['payment'])->create(['verified_at' => now(), 'payload_redacted' => ['transaction_status' => 'refund']]);
    app(ProcessPaymentEvent::class)->process($event->id);
    expect($context['payment']->fresh()->review_reason)->not->toBeNull();
    expect(PaymentMovement::query()->where('kind', 'return')->count())->toBe(0);
    expect($context['transfer']->fresh()->status->value)->toBe('unknown');
    expect(app(RefundTransfers::class)->prepare($context['refund']->id, $context['admin'], ['assignment_version' => 1, 'amount' => 1, 'idempotency_key' => 'blocked-new-attempt']))->toBeNull();
    expect(app(RefundTransfers::class)->complete($context['refund']->id, $context['transfer']->id, $context['admin'], $context['completion'])?->status->value)->toBe('succeeded');
    expect(PaymentMovement::query()->where('kind', 'return')->sole()->source->value)->toBe('manual_refund');
    expect($context['refund']->fresh()->status->value)->toBe('review');
    expect(AuditLog::query()->where('action', 'refund.actual_success_incident')->count())->toBe(1);
    Http::assertSentCount(2);
});

test('Owner initial assignment of a pending obligation can start processing through a fully guarded prepare', function (bool $ownerWorks) {
    $responses = [];
    refundRegressionGateway($responses);
    $context = refundRegressionObligation($this, $responses, false);
    $refund = $context['refund'];
    $destination = $ownerWorks ? $context['owner'] : $context['admin'];
    $this->actingAs($context['owner'])->postJson('/staff/refunds/'.$refund->id.'/takeover', [
        'assignment_version' => 0, 'assigned_admin_id' => $destination->id, 'reason' => 'Owner menentukan pelaksana refund awal',
    ])->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.assignment_version', 1);
    $this->actingAs($destination);
    $bank = ['assignment_version' => 1, 'bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => 'Pemilik synthetic'];
    $this->putJson('/refunds/'.$refund->id.'/bank-details', $bank)->assertOk();
    $token = $this->getJson('/refunds/'.$refund->id.'/bank-details')->assertOk()->json('data.bank_token');
    $this->postJson('/staff/refunds/'.$refund->id.'/bank-verification', ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true])->assertOk();
    $this->postJson('/staff/refunds/'.$refund->id.'/transfers', ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'owner-initial-prepare'])
        ->assertCreated()->assertJsonPath('data.status', 'prepared');
    expect($refund->fresh()->status->value)->toBe('processing');
    expect($refund->fresh()->assignment_version)->toBe(1);
    expect($refund->fresh()->assigned_admin_id)->toBe($destination->id);
    expect(AuditLog::query()->where('action', 'refund.transfer_prepared')->sole()->after_redacted)
        ->toMatchArray(['refund_status_before' => 'pending', 'refund_status_after' => 'processing']);
})->with(['Owner assigns Admin' => false, 'Owner assigns itself' => true]);

test('failed bank guard and a rolled back prepare keep an initially assigned pending obligation unchanged', function () {
    $responses = [];
    refundRegressionGateway($responses);
    $context = refundRegressionObligation($this, $responses, false);
    $refund = $context['refund'];
    $owner = $context['owner'];
    $this->actingAs($owner);
    app(AssignRefund::class)->takeOver($refund->id, $owner, $owner->id, 0, 'Owner menangani refund awal');
    $input = ['assignment_version' => 1, 'amount' => 80000, 'idempotency_key' => 'pending-rollback'];
    expect(app(RefundTransfers::class)->prepare($refund->id, $owner, $input))->toBeNull();
    expect($refund->fresh()->status->value)->toBe('pending');
    $private = app(RefundPrivateData::class);
    $private->saveBank($refund->id, $owner, ['bank_name' => 'BCA', 'account_number' => '0012345678', 'account_holder' => 'Pemilik synthetic'], 1);
    $token = $private->bank($refund->id, $owner)['bank_token'];
    $private->verifyBank($refund->id, $owner, ['assignment_version' => 1, 'bank_token' => $token, 'customer_confirmed' => true]);
    AuditLog::creating(function (AuditLog $audit): void {
        if ($audit->action === 'refund.transfer_prepared') {
            throw new RuntimeException('synthetic prepare audit failure');
        }
    });
    try {
        expect(fn () => app(RefundTransfers::class)->prepare($refund->id, $owner, $input))->toThrow(RuntimeException::class);
    } finally {
        AuditLog::flushEventListeners();
    }
    expect($refund->fresh()->status->value)->toBe('pending');
    expect($refund->fresh()->assignment_version)->toBe(1);
    $this->assertDatabaseCount('refund_transfers', 0);
    expect(AuditLog::query()->where('action', 'refund.transfer_prepared')->count())->toBe(0);
});
