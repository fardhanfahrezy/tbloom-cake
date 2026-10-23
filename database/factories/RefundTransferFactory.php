<?php

namespace Database\Factories;

use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RefundTransfer>
 */
class RefundTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{refund_log_id: RefundLogFactory, payment_id: Closure, attempt_number: int, amount: Closure, prepared_by: Closure, assignment_version: Closure, idempotency_key: string, source_account_key: string, bank_name: Closure, account_number: Closure, account_holder: Closure, bank_verified_at: Closure, prepared_at: string}
     */
    public function definition(): array
    {
        return [
            'refund_log_id' => RefundLog::factory()->processing(),
            'payment_id' => fn (array $attributes): int => RefundLog::query()->findOrFail($attributes['refund_log_id'])->payment_id,
            'attempt_number' => 1,
            'amount' => fn (array $attributes): int => RefundLog::query()->findOrFail($attributes['refund_log_id'])->amount,
            'prepared_by' => fn (array $attributes): int => RefundLog::query()->findOrFail($attributes['refund_log_id'])->assigned_admin_id,
            'assignment_version' => fn (array $attributes): int => RefundLog::query()->findOrFail($attributes['refund_log_id'])->assignment_version,
            'idempotency_key' => (string) Str::uuid(),
            'source_account_key' => config('refunds.source_account_key'),
            'bank_name' => fn (array $attributes): string => RefundLog::query()->findOrFail($attributes['refund_log_id'])->bank_name,
            'account_number' => fn (array $attributes): string => RefundLog::query()->findOrFail($attributes['refund_log_id'])->account_number,
            'account_holder' => fn (array $attributes): string => RefundLog::query()->findOrFail($attributes['refund_log_id'])->account_holder,
            'bank_verified_at' => fn (array $attributes): string => RefundLog::query()->findOrFail($attributes['refund_log_id'])->bank_verified_at->format('Y-m-d H:i:s'),
            'prepared_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
        ];
    }

    public function unknown(): static
    {
        return $this->state(['status' => RefundTransferStatus::Unknown]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundTransferStatus::Succeeded,
            'transfer_reference' => 'TEST-BANK-'.Str::uuid(),
            'transferred_at' => now()->startOfSecond(),
            'verified_by' => fn (array $attributes): int => $attributes['prepared_by'],
            'verified_at' => now()->startOfSecond(),
            'completed_at' => now()->startOfSecond(),
        ]);
    }

    public function failedConfirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundTransferStatus::FailedConfirmed,
            'verified_by' => fn (array $attributes): int => $attributes['prepared_by'],
            'verified_at' => now()->startOfSecond(),
            'verification_note' => '[TEST] confirmed bank failure',
        ]);
    }
}
