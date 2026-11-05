<?php

use App\CompleteHandover;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\RefundLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\CommittedMySqlDatabase;
use Tests\HandoverFixture;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
    config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'app.previous_keys' => [],
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'notifications.previous_recipient_hmac_keys' => []]);
    Model::encryptUsing(new Encrypter(str_repeat('t', 32), config('app.cipher')));
});

afterEach(function () {
    Model::encryptUsing(null);
});

/** @param array<string, mixed> $task @return array{process: Process, stream: InputStream} */
function handoverRaceWorker(array $task): array
{
    $code = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! $app->environment('testing') || $app->configurationIsCached()) { exit(91); }
        $db = Illuminate\Support\Facades\DB::connection();
        Tests\TestCase::assertSafeDatabaseConfiguration($db->getConfig());
        if ($db->selectOne('SELECT DATABASE() AS name')->name !== 'tbloom_testing') { exit(92); }
        $db->statement('SET SESSION innodb_lock_wait_timeout = 20');
        Illuminate\Support\Facades\Date::setTestNow(Carbon\CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
        Illuminate\Support\Facades\Http::preventStrayRequests();
        $task = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        $gated = false;
        Illuminate\Support\Facades\DB::listen(function ($query) use ($task, &$gated): void {
            if (! $gated && str_contains($query->sql, 'from `orders`') && str_contains($query->sql, 'for update')) {
                $gated = true;
                echo json_encode(['locked' => true]).PHP_EOL;
                fflush(STDOUT);
                if ($task['hold'] && trim(fgets(STDIN)) !== 'release') { throw new RuntimeException('Missing lock release.'); }
            }
        });
        echo json_encode(['ready' => $db->selectOne('SELECT CONNECTION_ID() AS id')->id]).PHP_EOL;
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'go') { exit(93); }
        echo json_encode(['started' => true]).PHP_EOL;
        fflush(STDOUT);
        try {
            $actor = App\Models\User::findOrFail($task['actor_id']);
            Illuminate\Support\Facades\Auth::setUser($actor);
            $result = match ($task['action']) {
                'complete' => $app->make(App\CompleteHandover::class)->complete($task['order_id'], $actor, $task['input']),
                'submit' => $app->make(App\CancellationRequests::class)->submit($task['order_id'], $actor, $task['input']),
                'approve' => $app->make(App\CancelPaidOrder::class)->approve($task['request_id'], $actor, 'Synthetic race approval'),
            };
            Illuminate\Support\Facades\Http::assertNothingSent();
            echo json_encode(['result' => $result === null ? 'conflict' : 'ok', 'status' => $result?->status->value,
                'transaction_level' => $db->transactionLevel()]).PHP_EOL;
        } catch (Throwable $exception) {
            $status = $exception instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode()
                : ($exception instanceof Illuminate\Validation\ValidationException ? 422 : 500);
            echo json_encode(['result' => 'error', 'http_status' => $status, 'type' => get_class($exception), 'transaction_level' => $db->transactionLevel()]).PHP_EOL;
        }
        PHP;
    $stream = new InputStream;
    $process = new Process([PHP_BINARY, '-r', $code, base_path(), base64_encode(json_encode($task, JSON_THROW_ON_ERROR))], base_path(), [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'APP_PREVIOUS_KEYS' => '',
        'DB_CONNECTION' => 'mysql_testing', 'DB_DATABASE' => 'tbloom_testing', 'DB_URL' => '', 'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MIDTRANS_SANDBOX_CONTRACT_TESTS' => 'false',
        'NOTIFICATION_RECIPIENT_HMAC_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)), 'NOTIFICATION_PREVIOUS_RECIPIENT_HMAC_KEYS' => '',
    ]);
    $process->setInput($stream)->setTimeout(90)->start();

    return ['process' => $process, 'stream' => $stream];
}

/** @param array{process: Process, stream: InputStream} $worker @param callable(array<string, mixed>): bool $condition @return array<string, mixed> */
function handoverRaceAwait(array $worker, callable $condition): array
{
    $deadline = microtime(true) + 25;
    do {
        $state = [];
        foreach (explode("\n", $worker['process']->getOutput()) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $state = array_replace($state, $decoded);
            }
        }
        if ($condition($state)) {
            return $state;
        }
        if (! $worker['process']->isRunning()) {
            throw new RuntimeException('Handover worker exited before barrier: '.$worker['process']->getOutput().$worker['process']->getErrorOutput());
        }
        usleep(2000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Handover worker barrier timed out.');
}

function handoverRaceWaiter(int $connectionId): void
{
    $deadline = microtime(true) + 15;
    do {
        $waiting = DB::select(<<<'SQL'
            SELECT requested.OBJECT_NAME AS table_name
            FROM performance_schema.data_lock_waits AS waits
            JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
            JOIN performance_schema.threads AS requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
            WHERE requested.OBJECT_SCHEMA = 'tbloom_testing' AND requester.PROCESSLIST_ID = ?
            SQL, [$connectionId]);
        if ($waiting !== []) {
            expect($waiting[0]->table_name)->toBeIn(['users', 'orders', 'daily_availabilities']);

            return;
        }
        usleep(2000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Expected an actual InnoDB lock wait before releasing the holder.');
}

test('independent handover and cancellation workers commit one valid serial outcome', function (string $first, string $second, bool $pending) {
    $order = HandoverFixture::paid();
    $staff = User::factory()->admin()->create();
    $request = $pending ? OrderRequest::factory()->for($order)->create(['submitted_at' => now()]) : null;
    $complete = ['order_number' => $order->order_number, 'recipient_confirmed' => true, 'handover_confirmed' => true,
        'expected_version' => app(CompleteHandover::class)->version($order->fresh(['item'])), 'idempotency_key' => 'race-handover'];
    $workers = [];
    foreach ([$first, $second] as $index => $action) {
        $workers[] = handoverRaceWorker(['action' => $action, 'actor_id' => $action === 'submit' ? $order->user_id : $staff->id,
            'order_id' => $order->id, 'request_id' => $request?->id, 'hold' => $index === 0,
            'input' => $action === 'complete' ? $complete : ['reason' => 'Synthetic race cancellation', 'idempotency_key' => 'race-cancel']]);
    }
    try {
        $readyA = handoverRaceAwait($workers[0], fn (array $state): bool => isset($state['ready']));
        $readyB = handoverRaceAwait($workers[1], fn (array $state): bool => isset($state['ready']));
        expect($readyA['ready'])->not->toBe($readyB['ready']);
        $workers[0]['stream']->write("go\n");
        handoverRaceAwait($workers[0], fn (array $state): bool => isset($state['locked']));
        $workers[1]['stream']->write("go\n");
        handoverRaceAwait($workers[1], fn (array $state): bool => isset($state['started']));
        handoverRaceWaiter((int) $readyB['ready']);
        $workers[0]['stream']->write("release\n");
        $resultA = handoverRaceAwait($workers[0], fn (array $state): bool => isset($state['result']));
        $resultB = handoverRaceAwait($workers[1], fn (array $state): bool => isset($state['result']));
        expect($resultA['transaction_level'])->toBe(0);
        expect($resultB['transaction_level'])->toBe(0);
        if ($pending) {
            expect($order->fresh()->status->value)->toBe('cancelled');
            expect($request->fresh()->status->value)->toBe('applied');
            expect($order->item->fresh()->quota_released_at)->not->toBeNull();
            expect(RefundLog::query()->sole()->amount)->toBe(80000);
            expect(AuditLog::query()->where('action', 'order.paid_cancelled')->count())->toBe(1);
            expect($order->item->fresh()->quota_released_at->equalTo($order->fresh()->cancelled_at))->toBeTrue();
            expect($request->fresh()->applied_at->equalTo($order->fresh()->cancelled_at))->toBeTrue();
            expect(AuditLog::query()->where('action', 'order.completed')->count())->toBe(0);
            $loser = $first === 'complete' ? $resultA : $resultB;
            expect($loser['http_status'])->toBe(409);
        } elseif ($first === 'submit') {
            expect($resultA['result'])->toBe('ok');
            expect($resultB['http_status'])->toBe(409);
            expect($order->fresh()->status->value)->toBe('paid');
            expect(OrderRequest::query()->sole()->status->value)->toBe('pending');
            $this->assertDatabaseCount('refund_logs', 0);
        } else {
            expect($order->fresh()->status->value)->toBe('completed');
            expect($order->item->fresh()->quota_released_at)->toBeNull();
            expect(AuditLog::query()->where('action', 'order.completed')->count())->toBe(1);
            expect(NotificationDelivery::query()->where('template_key', 'customer.order_completed.v1')->count())->toBe(2);
            $this->assertDatabaseCount('refund_logs', 0);
            $this->assertDatabaseCount('order_requests', 0);
            if ($second === 'submit') {
                expect($resultB['http_status'])->toBe(422);
            } else {
                expect([$resultA['result'], $resultB['result']])->toBe(['ok', 'ok']);
            }
        }
        expect($order->fresh()->completed_at !== null && OrderRequest::query()->where('status', 'applied')->exists())->toBeFalse();
        $completed = $order->fresh()->status->value === 'completed';
        expect(AuditLog::query()->where('action', 'order.completed')->count())->toBe($completed ? 1 : 0);
        expect(NotificationDelivery::query()->where('template_key', 'customer.order_completed.v1')->count())->toBe($completed ? 2 : 0);
        expect((int) OrderItem::query()->where('daily_availability_id', $order->item->daily_availability_id)->whereNull('quota_released_at')->sum('quantity'))->toBe($pending ? 0 : 1);
        $this->assertDatabaseCount('payment_movements', 1);
        $this->assertDatabaseCount('refund_transfers', 0);
    } finally {
        foreach ($workers as $worker) {
            $worker['stream']->write("release\n");
            $worker['stream']->close();
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(1);
            }
        }
    }
})->with([
    ['submit', 'complete', false], ['complete', 'submit', false],
    ['approve', 'complete', true], ['complete', 'approve', true], ['complete', 'complete', false],
]);
