<?php

use App\EmailNotificationClient;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use App\NotificationChannel;
use App\NotificationMessage;
use App\NotificationRecipientIdentity;
use App\OperationalNotificationMail;
use App\PreparedNotificationTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['notifications.enabled' => true, 'services.fonnte.token' => 'synthetic-fonnte-token',
        'notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32)),
        'notifications.previous_recipient_hmac_keys' => []]);
});

test('minimum templates ignore private and untrusted payload fields', function ($template) {
    $message = app(NotificationMessage::class)->render($template, ['order_number' => 'TST-123', 'request_number' => 'TST-CUSTOM',
        'bank_account' => 'private-bank', 'snap_token' => 'private-snap', 'notes' => 'private-notes', 'status' => '<script>private-status</script>']);

    expect($message)->toBeString()->toContain('TBloom Cake')->not->toContain('private-', '<script>', 'https://');
    expect(mb_strlen($message))->toBeLessThan(4097);
})->with([
    'customer.order_created.v1', 'customer.payment_paid.v1', 'customer.order_completed.v1', 'customer.payment_exception.v1',
    'customer.refund_pending.v1', 'customer.order_cancelled.v1', 'customer.order_expired.v1', 'customer.refund_completed.v1',
    'customer.refund_transfer_recorded.v1', 'customer.cancellation_submitted.v1', 'customer.cancellation_rejected.v1',
    'customer.order_change_submitted.v1', 'customer.order_change_applied.v1', 'customer.order_change_rejected.v1', 'customer.custom_request.v1',
    'admin.new_order.v1', 'admin.payment_exception.v1', 'admin.refund.v1', 'admin.refund_assignment.v1', 'admin.custom_request.v1', 'admin.cancellation.v1',
]);

test('templates reject unapproved Admin events and unsafe business references', function () {
    $messages = app(NotificationMessage::class);
    expect($messages->render('admin.payment_paid.v1', ['order_number' => 'TST-123']))->toBeNull();
    expect($messages->render('admin.order_expired.v1', ['order_number' => 'TST-123']))->toBeNull();
    expect($messages->render('customer.unknown.v1', ['order_number' => 'TST-123']))->toBeNull();
    expect($messages->render('customer.order_created.v1', ['order_number' => "TST-123\nInjected"]))->toBeNull();
    expect($messages->render('customer.order_created.v1', []))->toBeNull();
});

test('the prepared channel transport sends only the encrypted and HMAC matched recipient', function () {
    $row = NotificationDelivery::factory()->make(['template_key' => 'customer.order_created.v1', 'payload_minimal' => ['order_number' => 'TST-123'],
        'recipient_ciphertext' => ['channel' => 'whatsapp', 'address' => '+628111111111'],
        'recipient_key' => app(NotificationRecipientIdentity::class)->key(NotificationChannel::WhatsApp, '+628111111111')]);
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['80367170'], 'target' => ['628111111111']])]);
    Mail::fake();

    expect(app(PreparedNotificationTransport::class)->send($row)->status)->toBe(NotificationDeliveryStatus::Sent);
    Http::assertSentCount(1);
    Mail::assertNothingSent();
});

test('the prepared transport suppresses tampered identities and unsupported templates without IO', function ($changes) {
    $row = NotificationDelivery::factory()->make(['template_key' => 'customer.order_created.v1', 'payload_minimal' => ['order_number' => 'TST-123'],
        'recipient_ciphertext' => ['channel' => 'whatsapp', 'address' => '+628111111111'],
        'recipient_key' => app(NotificationRecipientIdentity::class)->key(NotificationChannel::WhatsApp, '+628111111111')]);
    $row->forceFill($changes);
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);
    Mail::fake();

    expect(app(PreparedNotificationTransport::class)->send($row)->status)->toBe(NotificationDeliveryStatus::Suppressed);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([
    'changed address' => [['recipient_ciphertext' => ['channel' => 'whatsapp', 'address' => '+628222222222']]],
    'unapproved template' => [['template_key' => 'admin.payment_paid.v1']],
    'bad channel' => [['recipient_ciphertext' => ['channel' => 'sms', 'address' => '+628111111111']]],
]);

test('the email adapter keeps the selected log mailer but does not log a private message or claim real sending', function () {
    config(['mail.default' => 'log']);
    Mail::fake();

    expect(app(EmailNotificationClient::class)->send('synthetic@example.test', 'Pesan sintetis.')->status)->toBe(NotificationDeliveryStatus::Failed);
    expect(config('mail.default'))->toBe('log');
    Mail::assertNothingSent();
});

test('SMTP can be exercised with Mail fake and does not pretend the fake supplied an acceptance receipt', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();

    expect(app(EmailNotificationClient::class)->send('synthetic@example.test', 'Pesan sintetis.')->status)->toBe(NotificationDeliveryStatus::Unknown);
    Mail::assertSent(OperationalNotificationMail::class, fn ($mail) => $mail->hasTo('synthetic@example.test') && $mail->bodyText === 'Pesan sintetis.');
    Mail::assertSentCount(1);
});

test('the email adapter refuses a real mailer during automated testing even if configured as SMTP', function () {
    config(['mail.default' => 'smtp']);

    expect(app(EmailNotificationClient::class)->send('synthetic@example.test', 'Pesan sintetis.')->status)->toBe(NotificationDeliveryStatus::Failed);
});

test('operational email renders as minimal plain text without queueing another job', function () {
    $mail = new OperationalNotificationMail('Pesan sintetis.');
    $mail->assertSeeInText('Pesan sintetis.');
    $mail->assertHasSubject('TBloom Cake — Notifikasi transaksi');
    expect($mail)->not->toBeInstanceOf(ShouldQueue::class);
});

test('email transport acceptance is sent and a lost SMTP acknowledgement is unknown without raw errors', function ($fail) {
    config(['mail.default' => 'smtp']);
    $fake = new class(app('mail.manager')) extends MailFake
    {
        public bool $fail = false;

        public function to(mixed $users): PendingMail
        {
            return (new PendingMail($this))->to($users);
        }

        public function send(mixed $view, array $data = [], mixed $callback = null): SentMessage
        {
            parent::send($view, $data, $callback);
            if ($this->fail) {
                throw new RuntimeException('synthetic-private-SMTP-password');
            }
            $message = (new Email)->from('sender@example.test')->to('synthetic@example.test')->text('Pesan sintetis.');
            $message->getHeaders()->addIdHeader('Message-ID', 'synthetic-id@example.test');

            return new SentMessage(new Symfony\Component\Mailer\SentMessage($message, Envelope::create($message)));
        }
    };
    $fake->fail = $fail;
    Mail::swap($fake);

    $result = app(EmailNotificationClient::class)->send('synthetic@example.test', 'Pesan sintetis.');

    expect($result->status)->toBe($fail ? NotificationDeliveryStatus::Unknown : NotificationDeliveryStatus::Sent);
    expect($result->providerMessageId)->toBe($fail ? null : 'synthetic-id@example.test');
    expect(json_encode($result))->not->toContain('synthetic-private');
    Mail::assertSentCount(1);
})->with([false, true]);

test('email rejects unsafe recipients messages and unbounded SMTP before invoking the fake', function ($address, $message, $timeout) {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.timeout' => $timeout]);
    Mail::fake();

    expect(app(EmailNotificationClient::class)->send($address, $message)->status)->toBe(NotificationDeliveryStatus::Failed);
    Mail::assertNothingSent();
})->with([
    'multiple recipients' => ['one@example.test,two@example.test', 'Pesan sintetis.', 15],
    'header injection' => ["one@example.test\r\nBcc: two@example.test", 'Pesan sintetis.', 15],
    'invalid message' => ['synthetic@example.test', "bad\0text", 15],
    'unbounded SMTP' => ['synthetic@example.test', 'Pesan sintetis.', null],
]);

test('prepared notification IO is refused inside a caller transaction', function () {
    $row = NotificationDelivery::factory()->make();
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);
    Mail::fake();
    DB::beginTransaction();
    try {
        expect(app(PreparedNotificationTransport::class)->send($row)->status)->toBe(NotificationDeliveryStatus::Failed);
    } finally {
        DB::rollBack();
    }
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('an email delivery uses its own channel without WhatsApp fallback', function () {
    config(['mail.default' => 'smtp']);
    $row = NotificationDelivery::factory()->make(['template_key' => 'customer.order_created.v1', 'payload_minimal' => ['order_number' => 'TST-123'],
        'recipient_ciphertext' => ['channel' => 'email', 'address' => 'synthetic@example.test'],
        'recipient_key' => app(NotificationRecipientIdentity::class)->key(NotificationChannel::Email, 'synthetic@example.test')]);
    Http::preventStrayRequests();
    Http::fake(['https://api.fonnte.com/send' => Http::response([])]);
    Mail::fake();

    expect(app(PreparedNotificationTransport::class)->send($row)->status)->toBe(NotificationDeliveryStatus::Unknown);
    Http::assertNothingSent();
    Mail::assertSent(OperationalNotificationMail::class, fn ($mail) => $mail->hasTo('synthetic@example.test'));
    Mail::assertSentCount(1);
});
