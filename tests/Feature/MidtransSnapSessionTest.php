<?php

use App\MidtransSnapSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-snap-key']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 03:20:00', 'UTC'));
});

test('requests BCA with the original order reference amount and absolute expiry', function () {
    Http::preventStrayRequests();
    $options = [];
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['token' => '123e4567-e89b-42d3-a456-426614174000',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/123e4567-e89b-42d3-a456-426614174000'], 201);
    }]);

    $token = app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00'));

    expect($token)->toBe('123e4567-e89b-42d3-a456-426614174000');
    expect($options)->toMatchArray(['connect_timeout' => 5, 'timeout' => 15, 'allow_redirects' => false]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('synthetic-snap-key:'))
        && $request->data() === [
            'transaction_details' => ['order_id' => 'TB-SNAP-1', 'gross_amount' => 400000],
            'enabled_payments' => ['bca_va'],
            'expiry' => ['start_time' => '2026-10-25 10:00:00 +0700', 'unit' => 'minutes', 'duration' => 30],
        ]);
});

test('never creates a session at or after deadline or before the order timestamp', function (string $instant) {
    $this->travelTo(new DateTimeImmutable($instant));
    Http::preventStrayRequests();
    Http::fake();

    expect(app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00')))->toBeNull();
    Http::assertNothingSent();
})->with(['2026-10-25 03:30:00+00:00', '2026-10-25 04:00:00+00:00', '2026-10-25 02:59:59+00:00']);

test('invalid reference money or shifted deadline is rejected before HTTP', function (string $reference, int $amount, string $deadline) {
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(MidtransSnapSession::class)->create($reference, $amount,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable($deadline)))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    ['', 400000, '2026-10-25 03:30:00+00:00'], ['../status', 400000, '2026-10-25 03:30:00+00:00'],
    [str_repeat('A', 51), 400000, '2026-10-25 03:30:00+00:00'], ['A', 0, '2026-10-25 03:30:00+00:00'],
    ['A', 400000, '2026-10-25 03:31:00+00:00'], ['A', 400000, '2026-10-25 03:30:00.000001+00:00'],
]);

test('failed or redirected creation remains unknown with one POST only', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['private' => 'private-body'], $status, ['Location' => 'https://example.test'])]);

    expect(app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00')))->toBeNull();
    Http::assertSentCount(1);
})->with([200, 302, 400, 401, 409, 429, 500]);

test('malformed or unsafe token responses are not stored as usable sessions', function (mixed $body) {
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response($body, 201)]);

    expect(app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00')))->toBeNull();
    Http::assertSentCount(1);
})->with([
    ['<html>private</html>'], [[]], [['token' => 'private-token']],
    [['token' => '123e4567-e89b-42d3-a456-426614174000', 'redirect_url' => 'https://app.midtrans.com/snap/v2/vtweb/123e4567-e89b-42d3-a456-426614174000']],
    [['token' => '123e4567-e89b-42d3-a456-426614174000', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/2967a077-17a7-4d5d-93ec-7cca66edd379']],
    [['token' => '123e4567-e89b-42d3-a456-426614174000', 'redirect_url' => 'https://app.sandbox.midtrans.com.evil.test/snap/v2/vtweb/123e4567-e89b-42d3-a456-426614174000']],
]);

test('timeouts do not expose the exception or repeat the POST', function () {
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('private-key-and-payload');
    }]);

    expect(app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00')))->toBeNull();
    expect($attempts)->toBe(1);
});

test('unsafe configuration makes no request', function (mixed $production, mixed $key, string $environment) {
    config(['services.midtrans.is_production' => $production, 'services.midtrans.server_key' => $key]);
    app()->instance('env', $environment);
    Http::preventStrayRequests();
    Http::fake();

    expect(app(MidtransSnapSession::class)->configured())->toBeFalse();
    expect(app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00')))->toBeNull();
    Http::assertNothingSent();
})->with([[true, 'synthetic-key', 'testing'], ['false', 'synthetic-key', 'testing'], [false, null, 'testing'],
    [false, "key\nprivate", 'testing'], [false, 'synthetic-key', 'production']]);

test('invalid callback URL is rejected without sending a session request', function (string $finishUrl) {
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(MidtransSnapSession::class)->create('TB-SNAP-1', 400000,
        new DateTimeImmutable('2026-10-25 03:00:00+00:00'), new DateTimeImmutable('2026-10-25 03:30:00+00:00'), $finishUrl))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['javascript:alert(1)', 'https://private:secret@example.test/order', 'https://example.test/order#private']);
