<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

test('serializes actual checkout workers using ready and release barriers', function (string $scenario, int $workerCount, int $expectedOrders) {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Checkout process races require isolated MySQL.');
    }
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    $connection = DB::connection();
    $processes = [];
    $streams = [];
    $workerCode = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! $app->environment('testing') || $app->configurationIsCached()) { exit(91); }
        $db = Illuminate\Support\Facades\DB::connection();
        Tests\TestCase::assertSafeDatabaseConfiguration($db->getConfig());
        if ($db->selectOne('SELECT DATABASE() AS name')->name !== 'tbloom_testing') { exit(92); }
        $db->statement('SET SESSION innodb_lock_wait_timeout = 8');
        Illuminate\Support\Facades\Http::preventStrayRequests();
        $task = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        Illuminate\Support\Facades\Date::setTestNow(Carbon\CarbonImmutable::parse($task['scenario'] === 'cutoff' ? '2026-10-23 10:00:00' : '2026-10-23 09:00:00', 'UTC'));
        if ($task['scenario'] === 'cutoff') {
            Illuminate\Support\Facades\DB::listen(function ($query): void {
                if (str_contains($query->sql, 'from `products`') && str_contains($query->sql, 'for update')) {
                    Illuminate\Support\Facades\Date::setTestNow(Carbon\CarbonImmutable::parse('2026-10-23 10:00:01', 'UTC'));
                }
            });
        }
        $retries = 0;
        Illuminate\Support\Sleep::fake();
        Illuminate\Support\Sleep::whenFakingSleep(function () use (&$retries): void { $retries++; });
        if ($task['scenario'] === 'deadlock') {
            Illuminate\Support\Facades\Event::listen('eloquent.created: '.App\Models\Payment::class, function () use ($task): void {
                static $injected = false;
                if (! $injected) {
                    $injected = true;
                    echo json_encode(['partial_writes' => true]).PHP_EOL;
                    fflush(STDOUT);
                    App\Models\Product::whereKey($task['blocker_id'])->lockForUpdate()->firstOrFail();
                }
            });
        }
        $id = $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
        echo json_encode(['ready' => $id]).PHP_EOL;
        fflush(STDOUT);
        if (trim(fgets(STDIN)) !== 'go') { exit(93); }
        try {
            $order = $app->make(App\CreateOrder::class)->create(App\Models\User::findOrFail($task['user_id']), $task['input']);
            Illuminate\Support\Facades\Http::assertNothingSent();
            echo json_encode(['status' => 'ok', 'order_id' => $order->id, 'connection_id' => $id, 'retries' => $retries, 'transaction_level' => $db->transactionLevel()]).PHP_EOL;
        } catch (Illuminate\Validation\ValidationException $exception) {
            echo json_encode(['status' => 'validation', 'fields' => array_keys($exception->errors()), 'connection_id' => $id]).PHP_EOL;
        } catch (Throwable $exception) {
            echo json_encode(['status' => 'error', 'type' => get_class($exception), 'code' => $exception->getCode()]).PHP_EOL;
        }
        PHP;
    try {
        $product = Product::factory()->create(['default_daily_quota' => $scenario === 'capacity' ? 5 : 100]);
        $variants = [ProductVariant::factory()->for($product)->create(['price' => 100001]), ProductVariant::factory()->for($product)->create(['price' => 200002])];
        $sharedCustomer = User::factory()->create();
        $customers = $scenario === 'capacity' ? User::factory()->count($workerCount)->create()->all() : array_fill(0, $workerCount, $sharedCustomer);
        $blocker = $scenario === 'deadlock' ? Product::factory()->create() : $product;
        $heavyCustomers = $scenario === 'deadlock' ? User::factory()->count(30)->create()->modelKeys() : [];
        $deadlocksBefore = $connection->selectOne("SELECT COUNT AS total FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'")->total;
        $parentId = $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $connection->beginTransaction();
        if ($heavyCustomers !== []) {
            DB::table('users')->whereIn('id', $heavyCustomers)->update(['name' => 'Synthetic deadlock blocker']);
        }
        Product::query()->whereKey($blocker->id)->lockForUpdate()->firstOrFail();
        for ($number = 0; $number < $workerCount; $number++) {
            $customer = $customers[$number];
            $input = ['product_id' => $product->id, 'product_variant_id' => $variants[$scenario === 'replay' ? 0 : $number % 2]->id,
                'quantity' => 1, 'flavor_code' => 'chocolate', 'filling_code' => 'strawberry', 'cover_color' => 'Putih',
                'inscription_choice' => 'none', 'pickup_date' => '2026-10-24', 'pickup_time' => '09:00',
                'pickup_method' => 'self_pickup', 'idempotency_key' => $scenario === 'replay' ? 'race-same-key' : 'race-'.$number];
            $task = base64_encode(json_encode(['scenario' => $scenario, 'user_id' => $customer->id, 'input' => $input, 'blocker_id' => $blocker->id], JSON_THROW_ON_ERROR));
            $stream = new InputStream;
            $process = new Process([PHP_BINARY, '-r', $workerCode, base_path(), $task], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql_testing', 'DB_DATABASE' => 'tbloom_testing', 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'NOTIFICATION_RECIPIENT_HMAC_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
                'NOTIFICATION_PREVIOUS_RECIPIENT_HMAC_KEYS' => '',
            ]);
            $process->setInput($stream)->setTimeout(90)->start();
            $streams[] = $stream;
            $processes[] = $process;
        }
        $deadline = microtime(true) + 60;
        do {
            $ready = array_filter($processes, fn (Process $process): bool => str_contains($process->getOutput(), '"ready":'));
            if (count($ready) === $workerCount) {
                break;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning() && ! str_contains($process->getOutput(), '"ready":')) {
                    $this->fail('Worker did not reach its ready barrier: '.$process->getErrorOutput());
                }
            }
            usleep(2000);
        } while (microtime(true) < $deadline);
        expect(count($ready))->toBe($workerCount);
        $connectionIds = array_map(fn (Process $process): int => json_decode(explode("\n", trim($process->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR)['ready'], $processes);
        expect(count(array_unique([$parentId, ...$connectionIds])))->toBe($workerCount + 1);
        foreach ($streams as $stream) {
            $stream->write("go\n");
            $stream->close();
        }
        $deadline = microtime(true) + 10;
        do {
            foreach ($processes as $process) {
                $process->isRunning();
            }
            $waiting = $connection->selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits JOIN performance_schema.threads AS threads ON threads.THREAD_ID = waits.BLOCKING_THREAD_ID WHERE threads.PROCESSLIST_ID = ?', [$parentId])->total;
            if ($waiting > 0) {
                break;
            }
            usleep(2000);
        } while (microtime(true) < $deadline);
        expect((int) $waiting)->toBeGreaterThan(0);
        if ($scenario === 'deadlock') {
            expect($processes[0]->getOutput())->toContain('"partial_writes":true');
            User::query()->whereKey($sharedCustomer->id)->lockForUpdate()->firstOrFail();
        }
        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }
        expect(array_filter($results, fn (array $result): bool => $result['status'] === 'error'))->toBe([]);
        $successful = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'ok'));
        expect(count($successful))->toBe($scenario === 'replay' ? $workerCount : $expectedOrders);
        $this->assertDatabaseCount('orders', $expectedOrders);
        $this->assertDatabaseCount('order_items', $expectedOrders);
        $this->assertDatabaseCount('payments', $expectedOrders);
        $this->assertDatabaseCount('audit_logs', $expectedOrders);
        $this->assertDatabaseCount('notification_deliveries', $expectedOrders * 2);
        expect((int) DB::table('order_items')->whereNull('quota_released_at')->sum('quantity'))->toBe($expectedOrders);
        $this->assertDatabaseCount('daily_availabilities', $expectedOrders > 0 ? 1 : 0);
        if ($scenario === 'replay') {
            expect(count(array_unique(array_column($successful, 'order_id'))))->toBe(1);
        }
        if ($scenario === 'cutoff') {
            expect($results[0]['fields'])->toContain('pickup_date');
        }
        if ($scenario === 'deadlock') {
            expect($results[0]['retries'])->toBe(1);
            expect($results[0]['transaction_level'])->toBe(0);
            expect((int) $connection->selectOne("SELECT COUNT AS total FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'")->total)->toBeGreaterThan((int) $deadlocksBefore);
        }
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
    }
})->with([
    '20 checkout competing for 5 shared units' => ['capacity', 20, 5],
    '4 checkout competing for 3 pending slots' => ['pending', 4, 3],
    '8 concurrent retries with the same key' => ['replay', 8, 1],
    'lock held across the cutoff boundary' => ['cutoff', 1, 0],
    'deadlock victim retries after partial checkout writes' => ['deadlock', 1, 1],
]);
