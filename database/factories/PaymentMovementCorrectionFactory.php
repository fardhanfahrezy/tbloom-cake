<?php

namespace Database\Factories;

use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentMovementCorrection> */
class PaymentMovementCorrectionFactory extends Factory
{
    /** @return array{payment_movement_id: PaymentMovementFactory, payment_id: Closure, revision: int, previous_correction_id: null, effective_amount: int, effective_occurred_at: string, verified_at: string, approved_by: UserFactory, source_event_id: Closure, refund_transfer_id: null, reason: string, idempotency_key: string, request_hash: string} */
    public function definition(): array
    {
        return [
            'payment_movement_id' => PaymentMovement::factory(),
            'payment_id' => fn (array $attributes): int => PaymentMovement::query()->findOrFail($attributes['payment_movement_id'])->payment_id,
            'revision' => 1,
            'previous_correction_id' => null,
            'effective_amount' => 90000,
            'effective_occurred_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
            'verified_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
            'approved_by' => User::factory()->owner(),
            'source_event_id' => fn (array $attributes): ?int => PaymentMovement::query()->findOrFail($attributes['payment_movement_id'])->source_event_id,
            'refund_transfer_id' => null,
            'reason' => '[TEST] verified synthetic amount correction; no actual funds',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', '[TEST] correction request '.Str::uuid()),
        ];
    }

    public function after(PaymentMovementCorrection $previous): static
    {
        return $this->state([
            'payment_movement_id' => $previous->payment_movement_id,
            'previous_correction_id' => $previous->id,
            'revision' => $previous->revision + 1,
        ]);
    }

    public function voided(): static
    {
        return $this->state(['effective_amount' => 0]);
    }
}
