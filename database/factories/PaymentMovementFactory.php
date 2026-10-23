<?php

namespace Database\Factories;

use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\RefundTransfer;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentMovement> */
class PaymentMovementFactory extends Factory
{
    /** @return array{source_event_id: PaymentEventFactory, payment_id: Closure, kind: string, source: string, canonical_key: string, amount: int, occurred_at: string, verified_at: string} */
    public function definition(): array
    {
        return [
            'source_event_id' => PaymentEvent::factory()->processed(),
            'refund_transfer_id' => null,
            'payment_id' => fn (array $attributes): int => PaymentEvent::query()->findOrFail($attributes['source_event_id'])->payment_id,
            'kind' => 'receipt',
            'source' => 'gateway_receipt',
            'canonical_key' => 'test:v1:'.hash('sha256', (string) Str::uuid()),
            'amount' => 100001,
            'occurred_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
            'verified_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
        ];
    }

    public function receipt(): static
    {
        return $this->state([]);
    }

    public function providerRefund(): static
    {
        return $this->state(['kind' => 'return', 'source' => 'provider_refund', 'amount' => 20000]);
    }

    public function reversal(): static
    {
        return $this->state(['kind' => 'return', 'source' => 'reversal', 'amount' => 20000]);
    }

    public function manualRefund(): static
    {
        return $this->state([
            'source_event_id' => null,
            'refund_transfer_id' => RefundTransfer::factory()->succeeded(),
            'payment_id' => fn (array $attributes): int => RefundTransfer::query()->findOrFail($attributes['refund_transfer_id'])->payment_id,
            'kind' => 'return',
            'source' => 'manual_refund',
            'amount' => fn (array $attributes): int => RefundTransfer::query()->findOrFail($attributes['refund_transfer_id'])->amount,
            'verified_by' => fn (array $attributes): int => RefundTransfer::query()->findOrFail($attributes['refund_transfer_id'])->verified_by,
        ]);
    }
}
