<?php

use App\DisabledNotificationTransport;
use App\FonnteClient;
use App\Models\NotificationDeliveryStatus;
use App\NotificationTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['notifications.enabled' => true, 'services.fonnte.token' => 'synthetic-fonnte-token']);
});

test('Fonnte accepts one canonical recipient using the documented form contract without claiming delivery', function () {
    Http::preventStrayRequests();
    $options = [];
    Http::fake(['https://api.fonnte.com/send' => function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['status' => true, 'id' => ['80367170'], 'target' => ['628111111111'], 'process' => 'pending']);
    }]);

    $result = app(FonnteClient::class)->send('+628111111111', 'TBloom Cake — pesan uji sintetis.');

    expect($result->status)->toBe(NotificationDeliveryStatus::Sent);
    expect($result->providerMessageId)->toBe('80367170');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.fonnte.com/send'
        && $request->hasHeader('Authorization', 'synthetic-fonnte-token')
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->data() === ['target' => '628111111111', 'message' => 'TBloom Cake — pesan uji sintetis.', 'countryCode' => '0', 'connectOnly' => 'true', 'preview' => 'false']);
    Http::assertSentCount(1);
    expect($options['connect_timeout'])->toBe(5);
    expect($options['timeout'])->toBe(15);
    expect($options['allow_redirects'])->toBeFalse();
});

test('Fonnte refuses disabled delivery and invalid local configuration before making a request', function ($overrides) {
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true])]);
    config($overrides);

    $result = app(FonnteClient::class)->send('+628111111111', 'Pesan sintetis.');

    expect($result->status)->toBe(NotificationDeliveryStatus::Failed);
    Http::assertNothingSent();
})->with([
    'disabled' => [['notifications.enabled' => false]],
    'missing token' => [['services.fonnte.token' => null]],
    'empty token' => [['services.fonnte.token' => '']],
    'header injection' => [['services.fonnte.token' => "synthetic\r\nInjected: value"]],
    'multiple devices' => [['services.fonnte.token' => 'synthetic-one,synthetic-two']],
]);

test('Fonnte rejects bulk or noncanonical targets and invalid text before sending', function ($target, $message) {
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true])]);

    expect(app(FonnteClient::class)->send($target, $message)->status)->toBe(NotificationDeliveryStatus::Failed);
    Http::assertNothingSent();
})->with([
    'local number' => ['081111111111', 'Pesan sintetis.'],
    'bulk number' => ['+628111111111,+628222222222', 'Pesan sintetis.'],
    'group' => ['123456@g.us', 'Pesan sintetis.'],
    'variables' => ['+628111111111|Someone', 'Pesan sintetis.'],
    'empty text' => ['+628111111111', '   '],
    'oversized text' => ['+628111111111', str_repeat('x', 4097)],
    'invalid UTF8' => ['+628111111111', "bad\xff"],
    'control characters' => ['+628111111111', "bad\0text"],
]);

test('Fonnte treats ambiguous responses as unknown and never retries the POST', function ($body, $httpStatus) {
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response($body, $httpStatus)]);

    $result = app(FonnteClient::class)->send('+628111111111', 'Pesan sintetis.');

    expect($result->status)->toBe(NotificationDeliveryStatus::Unknown);
    expect($result->providerMessageId)->toBeNull();
    Http::assertSentCount(1);
})->with([
    'server error' => [['status' => false, 'reason' => 'server failure'], 503],
    'redirect' => ['', 302],
    'invalid JSON' => ['not-json', 200],
    'string status' => [['status' => 'true', 'id' => ['80367170'], 'target' => ['628111111111']], 200],
    'missing id' => [['status' => true, 'target' => ['628111111111']], 200],
    'multiple ids' => [['status' => true, 'id' => ['1', '2'], 'target' => ['628111111111']], 200],
    'invalid id' => [['status' => true, 'id' => [str_repeat('x', 129)], 'target' => ['628111111111']], 200],
    'wrong target' => [['status' => true, 'id' => ['80367170'], 'target' => ['628222222222']], 200],
    'contradictory status' => [['status' => false, 'Status' => true, 'reason' => 'token invalid'], 200],
    'rejected with accepted id' => [['status' => false, 'reason' => 'token invalid', 'id' => ['80367170']], 200],
    'missing negative reason' => [['status' => false], 200],
]);

test('Fonnte interprets an explicit rejection without retaining its raw reason', function ($response, $status) {
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response($response)]);

    $result = app(FonnteClient::class)->send('+628111111111', 'Pesan sintetis.');

    expect($result->status)->toBe($status);
    expect($result->providerMessageId)->toBeNull();
    expect(json_encode($result))->not->toContain('synthetic-private-reason');
    Http::assertSentCount(1);
})->with([
    'documented capital Status' => [['Status' => false, 'reason' => 'token invalid'], NotificationDeliveryStatus::Failed],
    'invalid target' => [['status' => false, 'reason' => 'target invalid'], NotificationDeliveryStatus::Failed],
    'quota not saved' => [['status' => false, 'reason' => 'insufficient quota'], NotificationDeliveryStatus::Pending],
    'disconnected rejection' => [['status' => false, 'reason' => 'synthetic-private-reason'], NotificationDeliveryStatus::Pending],
]);

test('a Fonnte connection failure is unknown without retry or leaking the exception', function () {
    Http::preventStrayRequests();
    $calls = 0;
    Http::fake(['https://api.fonnte.com/send' => function () use (&$calls) {
        $calls++;
        throw new ConnectionException('synthetic-private-token target and response');
    }]);

    $result = app(FonnteClient::class)->send('+628111111111', 'Pesan sintetis.');

    expect($result->status)->toBe(NotificationDeliveryStatus::Unknown);
    expect($calls)->toBe(1);
    expect(json_encode($result))->not->toContain('synthetic-private');
    Http::assertNothingSent();
});

test('the Fonnte client prevents real HTTP in testing even when the test forgets its fake', function () {
    expect(Http::getFacadeRoot()->preventingStrayRequests())->toBeFalse();

    $result = app(FonnteClient::class)->send('+628111111111', 'Pesan sintetis.');

    expect($result->status)->toBe(NotificationDeliveryStatus::Unknown);
    expect(Http::getFacadeRoot()->preventingStrayRequests())->toBeTrue();
});

test('automated testing boots with external notification delivery disabled and without local Fonnte secrets', function () {
    $this->refreshApplication();
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true])]);

    expect(config('notifications.enabled'))->toBeFalse();
    expect(config('services.fonnte.token'))->toBe('');
    expect(config('mail.default'))->toBe('array');
    expect(app(NotificationTransport::class))->toBeInstanceOf(DisabledNotificationTransport::class);
    expect(app(FonnteClient::class)->send('+628111111111', 'Pesan sintetis.')->status)->toBe(NotificationDeliveryStatus::Failed);
    Http::assertNothingSent();
});
