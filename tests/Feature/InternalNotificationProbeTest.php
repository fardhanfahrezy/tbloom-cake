<?php

use App\InternalNotificationProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['notifications.enabled' => false, 'services.fonnte.token' => 'synthetic-private-token',
        'services.fonnte.test_target' => '08111111111', 'mail.default' => 'log',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'notifications.previous_recipient_hmac_keys' => []]);
});

test('the internal probe defaults to a redacted dry run without IO or a reserved slot', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);
    Mail::fake();

    $this->artisan('notifications:internal-probe')->expectsOutput('Mode: dry-run; no messages sent.')
        ->expectsOutput('Reference: TBLOOM-NOTIFY-P9-02-01')
        ->expectsOutput('WhatsApp configuration ready: yes')
        ->expectsOutput('Approval slot reserved: no')
        ->expectsOutput('Email live verification: pending')->assertSuccessful();
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-whatsapp-01.json');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('the probe refuses sending without explicit approval', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);

    expect(fn () => app(InternalNotificationProbe::class)->send(false))->toThrow(LogicException::class);
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-whatsapp-01.json');
    Http::assertNothingSent();
});

test('one approved probe persists unknown before fake IO then records acceptance without exposing the target', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    Mail::fake();
    Http::fake(['https://api.fonnte.com/send' => function ($request) {
        $manifest = Storage::disk('private_uploads')->get('notification-probes/p9-02-whatsapp-01.json');
        expect(json_decode($manifest, true)['result'])->toBe('unknown');
        expect($manifest)->not->toContain('08111111111', '+628111111111', 'synthetic-private-token');
        expect($request['target'])->toBe('628111111111');
        expect($request['message'])->toBe('TBloom Cake — Uji internal TBLOOM-NOTIFY-P9-02-01. Ini pesan pengujian, bukan pesanan atau tagihan.');

        return Http::response(['status' => true, 'id' => ['80367170'], 'target' => ['628111111111']]);
    }]);

    $probe = app(InternalNotificationProbe::class);
    expect($probe->send(true))->toBe(['reference' => 'TBLOOM-NOTIFY-P9-02-01', 'result' => 'sent']);
    expect(config('notifications.enabled'))->toBeFalse();
    $manifest = json_decode(Storage::disk('private_uploads')->get('notification-probes/p9-02-whatsapp-01.json'), true);
    expect($manifest['result'])->toBe('sent');
    expect($manifest['provider_message_id'])->toBe('80367170');
    expect(fn () => $probe->send(true))->toThrow(LogicException::class);
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});

test('a timeout consumes the probe slot as unknown and cannot be retried', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    $calls = 0;
    Http::fake(['https://api.fonnte.com/send' => function () use (&$calls) {
        $calls++;
        throw new ConnectionException('synthetic-private-token and target');
    }]);

    $probe = app(InternalNotificationProbe::class);
    expect($probe->send(true)['result'])->toBe('unknown');
    expect(fn () => $probe->send(true))->toThrow(LogicException::class);
    expect($calls)->toBe(1);
    expect(config('notifications.enabled'))->toBeFalse();
    expect(Storage::disk('private_uploads')->get('notification-probes/p9-02-whatsapp-01.json'))->not->toContain('synthetic-private-token', '08111111111');
});

test('missing or invalid local configuration cannot reserve or send a probe', function ($overrides) {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);
    config($overrides);

    expect(app(InternalNotificationProbe::class)->preview()['whatsapp_ready'])->toBeFalse();
    expect(fn () => app(InternalNotificationProbe::class)->send(true))->toThrow(LogicException::class);
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-whatsapp-01.json');
    Http::assertNothingSent();
})->with([
    'missing token' => [['services.fonnte.token' => null]],
    'bulk target' => [['services.fonnte.test_target' => '081111111111,082222222222']],
    'missing identity key' => [['notifications.recipient_hmac_key' => null]],
]);

test('an internal probe is forbidden in production', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);
    app()->instance('env', 'production');

    expect(fn () => app(InternalNotificationProbe::class)->send(true))->toThrow(LogicException::class);
    Http::assertNothingSent();
});

test('the explicitly approved console path stays redacted and never sends an email', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['80367170'], 'target' => ['628111111111']])]);
    Mail::fake();

    $this->artisan('notifications:internal-probe', ['--send-approved' => true])
        ->expectsOutput('Reference: TBLOOM-NOTIFY-P9-02-01')
        ->expectsOutput('Provider result: sent; delivery is not verified.')->assertSuccessful();
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});
