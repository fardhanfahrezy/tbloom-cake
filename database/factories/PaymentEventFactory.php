<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{payment_id: PaymentFactory, provider: Closure, provider_reference: Closure, event_fingerprint: string, payload_redacted: array{fixture: string, transaction_status: string}, received_at: string}
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'provider' => fn (array $attributes): string => $attributes['payment_id'] === null ? 'midtrans' : Payment::query()->findOrFail($attributes['payment_id'])->provider,
            'provider_reference' => fn (array $attributes): string => $attributes['payment_id'] === null ? 'UNKNOWN-'.Str::uuid() : Payment::query()->findOrFail($attributes['payment_id'])->merchant_reference,
            'event_fingerprint' => hash('sha256', (string) Str::uuid()),
            'payload_redacted' => ['fixture' => 'synthetic', 'transaction_status' => 'pending'],
            'received_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
        ];
    }

    public function claimed(): static
    {
        return $this->state(fn (): array => [
            'processing_status' => PaymentEventStatus::Processing,
            'claim_token' => (string) Str::uuid(),
            'lease_until' => now()->startOfSecond()->addMinutes(5),
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (): array => [
            'processing_status' => PaymentEventStatus::Processed,
            'verified_at' => now()->startOfSecond(),
            'processed_at' => now()->startOfSecond(),
        ]);
    }

    public function unmatched(): static
    {
        return $this->state(fn (): array => [
            'payment_id' => null,
            'processing_status' => PaymentEventStatus::Review,
        ]);
    }
}
