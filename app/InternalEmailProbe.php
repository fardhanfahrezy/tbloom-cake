<?php

namespace App;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

class InternalEmailProbe
{
    public const REFERENCE = 'TBLOOM-NOTIFY-P9-02-EMAIL-01';

    public const PATH = 'notification-probes/p9-02-email-01.json';

    public const SUBJECT = 'TBloom Cake — Uji email internal P9-02';

    public const MESSAGE = 'TBloom Cake — Uji email internal TBLOOM-NOTIFY-P9-02-EMAIL-01. Ini pesan pengujian, bukan pesanan atau tagihan.';

    public function __construct(private NotificationRecipientIdentity $identities) {}

    /** @return array{reference: string, subject: string, message: string, email_ready: bool, slot_reserved: bool} */
    public function preview(): array
    {
        $this->guard();
        $configuration = config('mail.mailers.gmail_probe', []);
        $username = $configuration['username'] ?? null;
        $recipient = config('notifications.test_email');
        $password = $configuration['password'] ?? null;
        $username = is_string($username) ? CanonicalContact::email($username) : '';
        $recipient = is_string($recipient) ? CanonicalContact::email($recipient) : '';
        $ready = ($configuration['transport'] ?? null) === 'smtp' && ($configuration['scheme'] ?? null) === 'smtp'
            && ($configuration['host'] ?? null) === 'smtp.gmail.com' && ($configuration['port'] ?? null) === 587
            && ($configuration['require_tls'] ?? null) === true && ($configuration['auto_tls'] ?? null) === true
            && ($configuration['verify_peer'] ?? null) === true && empty($configuration['url'])
            && is_numeric($configuration['timeout'] ?? null) && $configuration['timeout'] >= 1 && $configuration['timeout'] <= 30
            && filter_var($username, FILTER_VALIDATE_EMAIL) && strlen($username) <= 254
            && (str_ends_with($username, '@gmail.com') || str_ends_with($username, '@googlemail.com'))
            && $username === $recipient
            && is_string($password) && preg_match('/\A[\x21-\x7e]{16}\z/D', $password) === 1;
        if ($ready) {
            try {
                $this->identities->key(NotificationChannel::Email, $recipient);
            } catch (Throwable) {
                $ready = false;
            }
        }

        return ['reference' => self::REFERENCE, 'subject' => self::SUBJECT, 'message' => self::MESSAGE,
            'email_ready' => (bool) $ready, 'slot_reserved' => Storage::disk('private_uploads')->exists(self::PATH)];
    }

    /** @return array{reference: string, result: string} */
    public function send(bool $approved): array
    {
        $preview = $this->preview();
        if (! $approved || ! $preview['email_ready'] || $preview['slot_reserved']
            || (app()->environment('testing') && ! Mail::isFake())) {
            throw new LogicException('Email probe requires explicit approval, secure local configuration, an unused slot and fake mail in tests.');
        }
        $recipient = CanonicalContact::email(config('notifications.test_email'));
        $disk = Storage::disk('private_uploads');
        $disk->makeDirectory(dirname(self::PATH));
        $reservation = @fopen($disk->path(self::PATH), 'x');
        if ($reservation === false) {
            throw new LogicException('Email probe slot is reserved or unavailable; never create a replacement.');
        }
        $record = ['reference' => self::REFERENCE, 'result' => 'unknown',
            'recipient_key' => $this->identities->key(NotificationChannel::Email, $recipient),
            'message_sha256' => hash('sha256', self::MESSAGE), 'attempted_at_utc' => now('UTC')->toIso8601String(),
            'provider_message_id' => null];
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            if (fwrite($reservation, $json) !== strlen($json) || ! fflush($reservation) || ! fsync($reservation)) {
                throw new RuntimeException('Email probe reservation could not be persisted; no send was attempted.');
            }
        } finally {
            fclose($reservation);
        }

        try {
            $message = (new OperationalNotificationMail(self::MESSAGE))->subject(self::SUBJECT)->from($recipient, 'TBloom Cake (uji internal)');
            $sent = Mail::mailer('gmail_probe')->to($recipient)->send($message);
            if ($sent instanceof SentMessage && preg_match('/\A[\x21-\x7e]{1,128}\z/D', $sent->getMessageId())) {
                $record['result'] = 'sent';
                $record['provider_message_id'] = $sent->getMessageId();
            }
        } catch (Throwable) {
            $record['result'] = 'unknown';
        }
        if (! $disk->put(self::PATH, json_encode($record, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Email probe acknowledgement is uncertain; inspect the reserved slot and never resend.');
        }

        return ['reference' => self::REFERENCE, 'result' => $record['result']];
    }

    private function guard(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::transactionLevel() !== 0
            || config('filesystems.disks.private_uploads.driver') !== 'local'
            || config('filesystems.disks.private_uploads.serve', false) !== false) {
            throw new LogicException('Email probe requires local/testing, private local storage and no caller transaction.');
        }
    }
}
