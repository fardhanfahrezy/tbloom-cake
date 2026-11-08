<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

class InternalNotificationProbe
{
    public const REFERENCE = 'TBLOOM-NOTIFY-P9-02-01';

    public const PATH = 'notification-probes/p9-02-whatsapp-01.json';

    public const MESSAGE = 'TBloom Cake — Uji internal TBLOOM-NOTIFY-P9-02-01. Ini pesan pengujian, bukan pesanan atau tagihan.';

    public function __construct(private FonnteClient $client, private NotificationRecipientIdentity $identities) {}

    /** @return array{reference: string, message: string, whatsapp_ready: bool, slot_reserved: bool} */
    public function preview(): array
    {
        $this->guard();
        $token = config('services.fonnte.token');
        $target = config('services.fonnte.test_target');
        $target = is_string($target) ? CanonicalContact::whatsapp($target) : '';
        $ready = is_string($token) && preg_match('/\A[\x21-\x2b\x2d-\x7e]{1,1024}\z/D', $token) === 1
            && preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $target) === 1;
        if ($ready) {
            try {
                $this->identities->key(NotificationChannel::WhatsApp, $target);
            } catch (Throwable) {
                $ready = false;
            }
        }

        return ['reference' => self::REFERENCE, 'message' => self::MESSAGE, 'whatsapp_ready' => $ready,
            'slot_reserved' => Storage::disk('private_uploads')->exists(self::PATH)];
    }

    /** @return array{reference: string, result: string} */
    public function send(bool $approved): array
    {
        $preview = $this->preview();
        if (! $approved || ! $preview['whatsapp_ready'] || $preview['slot_reserved']) {
            throw new LogicException('Probe requires explicit approval, valid local configuration and an unused slot.');
        }
        $target = CanonicalContact::whatsapp(config('services.fonnte.test_target'));
        $disk = Storage::disk('private_uploads');
        $disk->makeDirectory(dirname(self::PATH));
        $reservation = @fopen($disk->path(self::PATH), 'x');
        if ($reservation === false) {
            throw new LogicException('Probe slot is reserved or unavailable; never create a replacement.');
        }
        $record = ['reference' => self::REFERENCE, 'result' => 'unknown',
            'recipient_key' => $this->identities->key(NotificationChannel::WhatsApp, $target),
            'message_sha256' => hash('sha256', self::MESSAGE), 'attempted_at_utc' => now('UTC')->toIso8601String(),
            'provider_message_id' => null];
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            if (fwrite($reservation, $json) !== strlen($json) || ! fflush($reservation) || ! fsync($reservation)) {
                throw new RuntimeException('Probe reservation could not be persisted; no send was attempted.');
            }
        } finally {
            fclose($reservation);
        }

        $wasEnabled = config('notifications.enabled');
        try {
            config(['notifications.enabled' => true]);
            $result = $this->client->send($target, self::MESSAGE);
            $record['result'] = $result->status->value;
            $record['provider_message_id'] = $result->providerMessageId;
        } catch (Throwable) {
            $record['result'] = 'unknown';
        } finally {
            config(['notifications.enabled' => $wasEnabled]);
        }
        if (! $disk->put(self::PATH, json_encode($record, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Probe acknowledgement is uncertain; inspect the reserved slot and never resend.');
        }

        return ['reference' => self::REFERENCE, 'result' => $record['result']];
    }

    private function guard(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::transactionLevel() !== 0
            || config('filesystems.disks.private_uploads.driver') !== 'local'
            || config('filesystems.disks.private_uploads.serve', false) !== false) {
            throw new LogicException('Probe requires local/testing, private local storage and no caller transaction.');
        }
    }
}
