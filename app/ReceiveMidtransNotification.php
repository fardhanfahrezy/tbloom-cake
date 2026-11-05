<?php

namespace App;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReceiveMidtransNotification
{
    public function __construct(private MidtransNotification $notifications) {}

    public function receive(string $body): PaymentEvent
    {
        $payload = $this->notifications->verify($body);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Durable inbox requires MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Durable inbox requires READ COMMITTED and UTC.');
        }
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        try {
            return $connection->transaction(function () use ($payload, $fingerprint): PaymentEvent {
                $existing = PaymentEvent::query()->where('provider', 'midtrans')->where('event_fingerprint', $fingerprint)->first();
                if ($existing !== null) {
                    return $existing;
                }
                $payment = Payment::query()->where('provider', 'midtrans')->whereRaw('BINARY merchant_reference = ?', [$payload['order_id']])->first();
                $event = new PaymentEvent;
                $event->forceFill([
                    'payment_id' => $payment?->id, 'provider' => 'midtrans', 'provider_reference' => $payload['order_id'],
                    'event_fingerprint' => $fingerprint, 'payload_redacted' => $payload,
                    'received_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                    'verified_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                    'processing_status' => $payment === null ? PaymentEventStatus::Review : PaymentEventStatus::Pending,
                    'error' => $payment === null ? 'unknown_reference' : null,
                ])->save();

                return $event;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return PaymentEvent::query()->where('provider', 'midtrans')->where('event_fingerprint', $fingerprint)->first() ?? throw $exception;
        }
    }
}
