<?php

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\OpenSnapSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-open-key']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 03:00:00', 'UTC'));
    Http::preventStrayRequests();
});

function snapOrder(): Order
{
    $order = Order::factory()->create(['product_total' => 400000]);
    Payment::factory()->for($order)->create(['merchant_reference' => $order->order_number]);
    OrderItem::factory()->create(['order_id' => $order->id, 'unit_price' => 400000, 'line_total' => 400000]);

    return $order->load('payment', 'user', 'item');
}

/** @return array{token: string, redirect_url: string} */
function snapReply(): array
{
    return ['token' => '123e4567-e89b-42d3-a456-426614174000',
        'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/123e4567-e89b-42d3-a456-426614174000'];
}

test('commits its reservation before HTTP then stores an encrypted hidden reusable token', function () {
    $order = snapOrder();
    $other = DB::build(config('database.connections.mysql_testing'));
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => function (Request $request) use ($order, $other) {
        expect(DB::transactionLevel())->toBe(0);
        expect($other->table('payments')->where('id', $order->payment->id)->value('snap_requested_at'))->not->toBeNull();
        expect($request['transaction_details'])->toBe(['order_id' => $order->order_number, 'gross_amount' => 400000]);

        return Http::response(snapReply(), 201);
    }]);

    $url = app(OpenSnapSession::class)->open($order->user, $order);
    $again = app(OpenSnapSession::class)->open($order->user->fresh(), $order->fresh());

    expect($url)->toBe(snapReply()['redirect_url'])->and($again)->toBe($url);
    $payment = $order->payment->fresh();
    expect($payment->snap_token)->toBe(snapReply()['token'])->and($payment->getRawOriginal('snap_token'))->not->toBe(snapReply()['token']);
    expect($payment->toArray())->not->toHaveKey('snap_token');
    expect($payment->snap_requested_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($order->fresh()->status->value)->toBe('pending')->and($order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('audit_logs', 2);
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain(snapReply()['token'], 'synthetic-open-key');
    Http::assertSentCount(1);
    $other->disconnect();
});

test('timeout or process interruption never creates a replacement session', function (string $failure) {
    $order = snapOrder();
    $posts = 0;
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use ($failure, &$posts) {
            $posts++;
            if ($failure === 'crash') {
                throw new RuntimeException('simulated process interruption');
            }
            throw new ConnectionException('private response');
        },
        'https://api.sandbox.midtrans.com/v2/'.$order->order_number.'/status' => Http::response(['status_code' => '404'], 404),
    ]);
    if ($failure === 'crash') {
        expect(fn () => app(OpenSnapSession::class)->open($order->user, $order))->toThrow(RuntimeException::class);
    } else {
        expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();
    }

    expect(app(OpenSnapSession::class)->open($order->user, $order->fresh()))->toBeNull();

    expect($order->payment->fresh()->snap_requested_at)->not->toBeNull();
    expect($order->payment->fresh()->merchant_reference)->toBe($order->order_number);
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payments', 1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    expect($posts)->toBe(1);
})->with(['timeout', 'crash']);

test('independent contender during provider HTTP observes the committed claim without a second POST', function () {
    $order = snapOrder();
    $second = DB::build(config('database.connections.mysql_testing'));
    $original = DB::getDefaultConnection();
    $posts = 0;
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use ($order, $second, $original, &$posts) {
            $posts++;
            DB::setDefaultConnection($second->getName());
            try {
                expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();
            } finally {
                DB::setDefaultConnection($original);
            }

            return Http::response(snapReply(), 201);
        },
        'https://api.sandbox.midtrans.com/v2/'.$order->order_number.'/status' => Http::response(['status_code' => '404'], 404),
    ]);

    $url = app(OpenSnapSession::class)->open($order->user, $order);

    expect($url)->toBe(snapReply()['redirect_url'])->and($posts)->toBe(1);
    $this->assertDatabaseCount('payments', 1);
    Http::assertSentCount(2);
    $second->disconnect();
});

test('deadline prevents both new sessions and reuse without releasing the held order', function (bool $existingToken) {
    $order = snapOrder();
    if ($existingToken) {
        $order->payment->forceFill(['snap_requested_at' => now(), 'snap_token' => snapReply()['token']])->save();
    }
    $this->travelTo($order->payment_expires_at);
    Http::fake();

    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();

    expect($order->fresh()->status->value)->toBe('pending')->and($order->item->fresh()->quota_released_at)->toBeNull();
    Http::assertNothingSent();
})->with([false, true]);

test('token received after deadline is retained encrypted but never exposed for payment', function () {
    $order = snapOrder();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use ($order) {
        $this->travelTo($order->payment_expires_at->addSecond());

        return Http::response(snapReply(), 201);
    }]);

    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();

    expect($order->payment->fresh()->snap_token)->toBe(snapReply()['token']);
    expect($order->item->fresh()->quota_released_at)->toBeNull();
    Http::assertSentCount(1);
});

test('account ownership and live customer role are rechecked under lock', function (string $actorState) {
    $order = snapOrder();
    $actor = match ($actorState) {
        'foreign' => User::factory()->create(),
        'admin' => User::factory()->admin()->create(),
        default => $order->user,
    };
    if ($actorState === 'inactive') {
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
    }
    Http::fake();

    expect(fn () => app(OpenSnapSession::class)->open($actor, $order))->toThrow(HttpException::class);

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    Http::assertNothingSent();
})->with(['foreign', 'admin', 'inactive']);

test('nonpending orders conflicting context and reviews cannot start payment', function (string $case) {
    $order = snapOrder();
    if ($case === 'paid') {
        $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    } else {
        $order->payment->forceFill(match ($case) {
            'amount' => ['expected_amount' => 500000],
            'provider' => ['provider' => 'other'],
            default => ['review_reason' => 'provider_context_conflict'],
        })->save();
    }
    Http::fake();

    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    Http::assertNothingSent();
})->with(['paid', 'amount', 'provider', 'review']);

test('a caller transaction is rejected before any HTTP or reservation', function () {
    $order = snapOrder();
    Http::fake();
    DB::beginTransaction();
    try {
        expect(fn () => app(OpenSnapSession::class)->open($order->user, $order))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    Http::assertNothingSent();
});

test('a failed reservation audit rolls back the marker before any provider request', function () {
    $order = snapOrder();
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'payment.snap_requested') {
            throw new RuntimeException('simulated reservation failure');
        }
    });
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(snapReply(), 201)]);

    expect(fn () => app(OpenSnapSession::class)->open($order->user, $order))->toThrow(RuntimeException::class);

    expect($order->payment->fresh()->snap_requested_at)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 0);
    Http::assertNothingSent();
    $fail = false;
    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBe(snapReply()['redirect_url']);
    Http::assertSentCount(1);
});

test('failure saving a successful provider response keeps the durable marker and prevents replay POST', function () {
    $order = snapOrder();
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'payment.snap_response') {
            throw new RuntimeException('simulated response commit failure');
        }
    });
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(snapReply(), 201),
        'https://api.sandbox.midtrans.com/v2/'.$order->order_number.'/status' => Http::response(['status_code' => '404'], 404),
    ]);

    expect(fn () => app(OpenSnapSession::class)->open($order->user, $order))->toThrow(RuntimeException::class);

    expect($order->payment->fresh()->snap_requested_at)->not->toBeNull();
    expect($order->payment->fresh()->snap_token)->toBeNull();
    $this->assertDatabaseCount('audit_logs', 1);
    $fail = false;
    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'GET'))->toHaveCount(1);
});

test('recovery observes the same reference without applying receipts or releasing quota', function (string $status, string $code, string $amount, string $reason) {
    $order = snapOrder();
    $order->payment->forceFill(['snap_requested_at' => now(), 'review_reason' => null])->save();
    $this->travel(20)->minutes();
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$order->order_number.'/status' => Http::response([
        'order_id' => $order->order_number, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
        'gross_amount' => $amount, 'currency' => 'IDR', 'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']],
        'transaction_status' => $status, 'status_code' => $code, 'transaction_time' => '2026-10-25 10:00:00',
        'settlement_time' => $status === 'settlement' ? '2026-10-25 10:05:00' : null,
    ])]);

    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();

    expect($order->payment->fresh()->review_reason)->toBe($reason);
    expect($order->fresh()->status->value)->toBe('pending')->and($order->item->fresh()->quota_released_at)->toBeNull();
    $this->assertDatabaseCount('payment_movements', 0);
    $this->assertDatabaseCount('refund_logs', 0);
    $this->assertDatabaseCount('payments', 1);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
})->with([
    ['pending', '201', '400000.00', 'snap_creation_unknown'],
    ['settlement', '200', '400000.00', 'payment_time_unverified'],
    ['expire', '407', '400000.00', 'closure_verification_required'],
    ['settlement', '200', '500000.00', 'provider_context_conflict'],
]);

test('corrupt encrypted tokens cannot trigger replacement creation', function () {
    $order = snapOrder();
    DB::table('payments')->where('id', $order->payment->id)->update(['snap_token' => 'invalid-ciphertext', 'snap_requested_at' => now()]);
    Http::fake();

    expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();

    Http::assertNothingSent();
    expect($order->item->fresh()->quota_released_at)->toBeNull();
});

test('rollback refuses to drop durable session history and leaves its schema intact', function () {
    $order = snapOrder();
    $order->payment->forceFill(['snap_requested_at' => now()])->save();
    $migration = require database_path('migrations/2026_10_24_182508_add_snap_requested_at_to_payments_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot discard durable Snap attempt history');

    expect($order->payment->fresh()->snap_requested_at)->not->toBeNull();
});

test('delayed recovery cannot overwrite a session token committed while its GET was in flight', function (string $providerStatus) {
    $order = snapOrder();
    $order->payment->forceFill(['snap_requested_at' => now(), 'review_reason' => 'snap_creation_unknown'])->save();
    $second = DB::build(config('database.connections.mysql_testing'));
    expect(DB::selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe($second->selectOne('SELECT CONNECTION_ID() AS id')->id);
    Http::fake(['https://api.sandbox.midtrans.com/v2/'.$order->order_number.'/status' => function () use ($order, $second, $providerStatus) {
        expect(DB::transactionLevel())->toBe(0);
        $second->table('payments')->where('id', $order->payment->id)->update([
            'snap_token' => Crypt::encryptString(snapReply()['token']), 'review_reason' => null,
        ]);

        return $providerStatus === '404' ? Http::response(['status_code' => '404'], 404) : Http::response([
            'order_id' => $order->order_number, 'transaction_id' => '123e4567-e89b-42d3-a456-426614174000',
            'gross_amount' => '400000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca']],
            'transaction_status' => 'pending', 'status_code' => '201', 'transaction_time' => '2026-10-25 10:00:00',
        ]);
    }]);

    try {
        expect(app(OpenSnapSession::class)->open($order->user, $order))->toBeNull();

        expect($order->payment->fresh()->review_reason)->toBeNull();
        expect(app(OpenSnapSession::class)->open($order->user, $order->fresh()))->toBe(snapReply()['redirect_url']);
        expect($order->fresh()->status->value)->toBe('pending')->and($order->item->fresh()->quota_released_at)->toBeNull();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    } finally {
        $second->disconnect();
    }
})->with(['404', 'pending']);
