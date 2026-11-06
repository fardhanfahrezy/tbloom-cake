<?php

use App\InternalEmailProbe;
use App\OperationalNotificationMail;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\MailFake;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['mail.default' => 'log', 'mail.from.address' => 'production-synthetic@example.test',
        'mail.mailers.gmail_probe.username' => 'probe.owner@gmail.com',
        'mail.mailers.gmail_probe.password' => 'abcdefghijklmnop',
        'notifications.test_email' => 'probe.owner@gmail.com',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'notifications.previous_recipient_hmac_keys' => []]);
});

test('the dedicated Gmail mailer requires STARTTLS without altering the application mailer', function () {
    $transport = Mail::mailer('gmail_probe')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);
    expect($transport->isTlsRequired())->toBeTrue();
    expect($transport->isAutoTls())->toBeTrue();
    expect($transport->getStream()->getHost())->toBe('smtp.gmail.com');
    expect($transport->getStream()->getPort())->toBe(587);
    expect(config('mail.default'))->toBe('log');
    expect(config('mail.from.address'))->toBe('production-synthetic@example.test');
});

test('email probe dry run does not authenticate send or reserve a slot', function () {
    Storage::fake('private_uploads');
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);

    $this->artisan('notifications:email-probe')->expectsOutput('Mode: dry-run; no email sent.')
        ->expectsOutput('Reference: TBLOOM-NOTIFY-P9-02-EMAIL-01')
        ->expectsOutput('Email configuration ready: yes')
        ->expectsOutput('Approval slot reserved: no')
        ->expectsOutput('Gmail authentication and delivery are not verified by dry-run.')->assertSuccessful();
    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-01.json');
});

test('the email probe subject survives actual mailable rendering', function () {
    $mail = (new OperationalNotificationMail('TBloom Cake — Uji email internal TBLOOM-NOTIFY-P9-02-EMAIL-01. Ini pesan pengujian, bukan pesanan atau tagihan.'))
        ->subject('TBloom Cake — Uji email internal P9-02')->from('probe.owner@gmail.com', 'TBloom Cake (uji internal)');

    $mail->assertSeeInText('TBLOOM-NOTIFY-P9-02-EMAIL-01');
    $mail->assertHasSubject('TBloom Cake — Uji email internal P9-02');
    $mail->assertFrom('probe.owner@gmail.com');
});

test('email probe rejects incomplete credentials another recipient and unsafe TLS configuration', function ($overrides) {
    Storage::fake('private_uploads');
    Mail::fake();
    config($overrides);

    expect(app(InternalEmailProbe::class)->preview()['email_ready'])->toBeFalse();
    expect(fn () => app(InternalEmailProbe::class)->send(true))->toThrow(LogicException::class);
    Mail::assertNothingOutgoing();
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-01.json');
})->with([
    'missing username' => [['mail.mailers.gmail_probe.username' => null]],
    'missing password' => [['mail.mailers.gmail_probe.password' => null]],
    'password whitespace' => [['mail.mailers.gmail_probe.password' => 'abcd efgh ijkl mnop']],
    'missing recipient' => [['notifications.test_email' => null]],
    'different recipient' => [['notifications.test_email' => 'another.owner@gmail.com']],
    'non Gmail account' => [['notifications.test_email' => 'owner@example.test', 'mail.mailers.gmail_probe.username' => 'owner@example.test']],
    'TLS optional' => [['mail.mailers.gmail_probe.require_tls' => false]],
    'disabled verification' => [['mail.mailers.gmail_probe.verify_peer' => false]],
    'redirected server' => [['mail.mailers.gmail_probe.host' => 'mail.example.test']],
    'URL override' => [['mail.mailers.gmail_probe.url' => 'smtp://mail.example.test']],
    'unbounded timeout' => [['mail.mailers.gmail_probe.timeout' => null]],
    'missing identity key' => [['notifications.recipient_hmac_key' => null]],
]);

test('email probe refuses unapproved sends and real transports during automated testing', function ($approved) {
    Storage::fake('private_uploads');

    expect(fn () => app(InternalEmailProbe::class)->send($approved))->toThrow(LogicException::class);
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-01.json');
})->with([false, true]);

test('the email probe commits uncertainty before fake IO and never repeats any outcome', function ($mode) {
    Storage::fake('private_uploads');
    $fake = new class(app('mail.manager')) extends MailFake
    {
        public string $mode = 'accepted';

        public function to(mixed $users): PendingMail
        {
            return (new PendingMail($this))->to($users);
        }

        public function send(mixed $view, array $data = [], mixed $callback = null): ?SentMessage
        {
            $manifest = Storage::disk('private_uploads')->get('notification-probes/p9-02-email-01.json');
            expect(json_decode($manifest, true)['result'])->toBe('unknown');
            expect($manifest)->not->toContain('abcdefghijklmnop', 'probe.owner@gmail.com');
            parent::send($view, $data, $callback);
            if ($this->mode === 'exception') {
                throw new RuntimeException('synthetic-private-password and SMTP response');
            }
            if ($this->mode === 'no_ack') {
                return null;
            }
            $email = (new Email)->from('probe.owner@gmail.com')->to('probe.owner@gmail.com')->text('synthetic');
            $email->getHeaders()->addIdHeader('Message-ID', 'synthetic-email-id@example.test');

            return new SentMessage(new Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
        }
    };
    $fake->mode = $mode;
    Mail::swap($fake);
    $probe = app(InternalEmailProbe::class);

    $result = $probe->send(true);

    expect($result)->toBe(['reference' => 'TBLOOM-NOTIFY-P9-02-EMAIL-01', 'result' => $mode === 'accepted' ? 'sent' : 'unknown']);
    expect(fn () => $probe->send(true))->toThrow(LogicException::class);
    expect(config('mail.default'))->toBe('log');
    expect(config('mail.from.address'))->toBe('production-synthetic@example.test');
    Mail::assertSent(OperationalNotificationMail::class, fn ($mail) => $mail->hasTo('probe.owner@gmail.com') && $mail->hasFrom('probe.owner@gmail.com')
        && $mail->subject === 'TBloom Cake — Uji email internal P9-02'
        && $mail->bodyText === 'TBloom Cake — Uji email internal TBLOOM-NOTIFY-P9-02-EMAIL-01. Ini pesan pengujian, bukan pesanan atau tagihan.'
        && $mail->usesMailer('gmail_probe') && $mail->cc === [] && $mail->bcc === []);
    Mail::assertSentCount(1);
    expect(json_decode(Storage::disk('private_uploads')->get('notification-probes/p9-02-email-01.json'), true)['result'])
        ->toBe($mode === 'accepted' ? 'sent' : 'unknown');
})->with(['accepted', 'exception', 'no_ack']);

test('email probe cannot run in production or inside a caller transaction', function ($production) {
    Storage::fake('private_uploads');
    Mail::fake();
    if ($production) {
        app()->instance('env', 'production');
    } else {
        DB::beginTransaction();
    }
    try {
        expect(fn () => app(InternalEmailProbe::class)->send(true))->toThrow(LogicException::class);
    } finally {
        if (! $production) {
            DB::rollBack();
        }
    }
    Mail::assertNothingOutgoing();
})->with([false, true]);

test('the approved email command uses only the fake and never claims its void return is delivery', function () {
    Storage::fake('private_uploads');
    Mail::fake();

    $this->artisan('notifications:email-probe', ['--send-approved' => true])
        ->expectsOutput('Reference: TBLOOM-NOTIFY-P9-02-EMAIL-01')
        ->expectsOutput('Provider result: unknown; delivery is not verified.')->assertFailed();
    Mail::assertSentCount(1);
});

test('automated testing clears local Gmail credentials and recipient before application boot', function () {
    $this->refreshApplication();
    Storage::fake('private_uploads');
    Mail::fake();

    expect(config('mail.mailers.gmail_probe.username') === '')->toBeTrue();
    expect(config('mail.mailers.gmail_probe.password') === '')->toBeTrue();
    expect(config('notifications.test_email') === '')->toBeTrue();
    expect(config('mail.default'))->toBe('array');
    expect(app(InternalEmailProbe::class)->preview()['email_ready'])->toBeFalse();
    expect(fn () => app(InternalEmailProbe::class)->send(true))->toThrow(LogicException::class);
    Mail::assertNothingOutgoing();
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-01.json');
});

test('a separately approved second email test has its own reference and never changes the first unknown slot', function () {
    Storage::fake('private_uploads');
    Mail::fake();
    $firstRecord = '{"reference":"TBLOOM-NOTIFY-P9-02-EMAIL-01","result":"unknown"}';
    Storage::disk('private_uploads')->put('notification-probes/p9-02-email-01.json', $firstRecord);
    $probe = app(InternalEmailProbe::class);

    expect($probe->preview(2)['reference'])->toBe('TBLOOM-NOTIFY-P9-02-EMAIL-02');
    expect($probe->preview(2)['message'])->toContain('TBLOOM-NOTIFY-P9-02-EMAIL-02');
    expect($probe->send(true, 2))->toBe(['reference' => 'TBLOOM-NOTIFY-P9-02-EMAIL-02', 'result' => 'unknown']);
    expect(fn () => $probe->send(true, 2))->toThrow(LogicException::class);
    expect(Storage::disk('private_uploads')->get('notification-probes/p9-02-email-01.json'))->toBe($firstRecord);
    Storage::disk('private_uploads')->assertExists('notification-probes/p9-02-email-02.json');
    Mail::assertSent(OperationalNotificationMail::class, fn ($mail) => str_contains($mail->bodyText, 'TBLOOM-NOTIFY-P9-02-EMAIL-02'));
    Mail::assertSentCount(1);
});

test('a second email slot requires both explicit approval and the preserved first slot', function () {
    Storage::fake('private_uploads');
    Mail::fake();

    expect(fn () => app(InternalEmailProbe::class)->send(false, 2))->toThrow(LogicException::class);
    expect(fn () => app(InternalEmailProbe::class)->send(true, 2))->toThrow(LogicException::class);
    Mail::assertNothingOutgoing();
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-02.json');
});

test('email test slots are bounded and a third or malformed slot is refused without IO', function () {
    Storage::fake('private_uploads');
    Mail::fake();

    expect(fn () => app(InternalEmailProbe::class)->preview(3))->toThrow(InvalidArgumentException::class);
    $this->artisan('notifications:email-probe', ['--slot' => '3'])->assertFailed();
    $this->artisan('notifications:email-probe', ['--slot' => '2extra'])->assertFailed();
    Mail::assertNothingOutgoing();
});

test('previewing the second email does not consume either slot or send a message', function () {
    Storage::fake('private_uploads');
    Mail::fake();

    $this->artisan('notifications:email-probe', ['--slot' => '2'])
        ->expectsOutput('Mode: dry-run; no email sent.')
        ->expectsOutput('Reference: TBLOOM-NOTIFY-P9-02-EMAIL-02')
        ->expectsOutput('Approval slot reserved: no')->assertSuccessful();
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-01.json');
    Storage::disk('private_uploads')->assertMissing('notification-probes/p9-02-email-02.json');
    Mail::assertNothingOutgoing();
});
