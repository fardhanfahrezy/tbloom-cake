<?php

use App\MidtransSandboxProbe;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.midtrans.is_production' => false, 'services.midtrans.server_key' => 'synthetic-probe-key']);
});

test('reserves each approved probe before its only POST and keeps its URL encrypted', function (string $scenario, int $amount, int $minutes) {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 16:00:00', 'Asia/Jakarta'));
    $url = 'https://app.sandbox.midtrans.com/snap/v4/redirection/123e4567-e89b-42d3-a456-426614174000';
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => function (Request $request) use ($scenario, $url) {
        $reserved = json_decode(Storage::disk('local')->get(MidtransSandboxProbe::DIRECTORY.'/'.$scenario.'.json'), true);
        expect($reserved['creation_result'])->toBe('reserved')
            ->and($reserved['reference'])->toBe($request['transaction_details']['order_id']);

        return Http::response(['redirect_url' => $url, 'token' => 'private-token'], 201);
    }]);

    $result = (new MidtransSandboxProbe)->start($scenario);

    expect($result['amount'])->toBe($amount)->and($result['creation_result'])->toBe('created')
        ->and($result['business_deadline'])->toBe('2026-10-24 16:30:00')
        ->and($result['provider_expiry'])->toBe('2026-10-24 16:'.($minutes === 5 ? '05' : '30').':00')
        ->and($result)->not->toHaveKey('encrypted_redirect_url');
    $stored = Storage::disk('local')->get(MidtransSandboxProbe::DIRECTORY.'/'.$scenario.'.json');
    expect($stored)->not->toContain($url, 'private-token', 'synthetic-probe-key');
    expect(Crypt::decryptString(json_decode($stored, true)['encrypted_redirect_url']))->toBe($url);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['transaction_details']['gross_amount'] === $amount
        && $request['enabled_payments'] === ['bca_va']
        && $request['expiry']['duration'] === $minutes
        && ! isset($request['customer_details']));
})->with([['A', 400000, 30], ['B', 500000, 5]]);

test('a reserved approval slot cannot create a replacement transaction', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake();
    Storage::disk('local')->put(MidtransSandboxProbe::DIRECTORY.'/A.json', '{"creation_result":"reserved"}');

    expect(fn () => (new MidtransSandboxProbe)->start('A'))->toThrow(LogicException::class);

    Http::assertNothingSent();
});

test('a timeout keeps the reference unknown and forbids a second POST', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::failedConnection()]);

    $result = (new MidtransSandboxProbe)->start('B');

    expect($result['creation_result'])->toBe('unknown');
    expect(fn () => (new MidtransSandboxProbe)->start('B'))->toThrow(LogicException::class);
    Http::assertSentCount(1);
});

test('unsafe configuration and unapproved scenarios send no requests', function (array $config, string $scenario) {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake();
    config($config);

    expect(fn () => (new MidtransSandboxProbe)->start($scenario))->toThrow(LogicException::class);

    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    [['services.midtrans.is_production' => true], 'A'],
    [['services.midtrans.is_production' => 'false'], 'B'],
    [['services.midtrans.server_key' => ''], 'A'],
    [[], 'C'], [[], '../A'],
]);

test('failed or redirected session responses consume the slot without revealing their body', function (int $http, string $url) {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
        'redirect_url' => $url, 'message' => 'private-provider-message',
    ], $http)]);

    $result = (new MidtransSandboxProbe)->start('A');

    expect($result['creation_result'])->toBe('unverified')->and($result)->not->toHaveKey('encrypted_redirect_url');
    expect(fn () => (new MidtransSandboxProbe)->start('A'))->toThrow(LogicException::class);
    expect(Storage::disk('local')->get(MidtransSandboxProbe::DIRECTORY.'/A.json'))->not->toContain('private-provider-message');
    Http::assertSentCount(1);
})->with([[302, 'https://private.example.test/'], [500, ''], [201, 'http://app.sandbox.midtrans.com/unsafe']]);

test('probe CLI does not expose redirect URLs or create a replacement session', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
        'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/123e4567-e89b-42d3-a456-426614174000',
    ], 201)]);

    $this->artisan('midtrans:sandbox-phase5-probe', ['scenario' => 'A'])
        ->expectsOutputToContain('"amount":400000')->doesntExpectOutputToContain('redirect_url')
        ->doesntExpectOutputToContain('synthetic-probe-key')->assertSuccessful();
    $this->artisan('midtrans:sandbox-phase5-probe', ['scenario' => 'A'])->assertFailed();

    Http::assertSentCount(1);
});
