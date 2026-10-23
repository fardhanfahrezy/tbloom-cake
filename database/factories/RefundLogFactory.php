<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundReasonType;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundLog>
 */
class RefundLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{payment_id: PaymentFactory, order_id: Closure, reason_type: string, basis_amount: Closure, percentage: int, amount: Closure}
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->for(Order::factory()->paid())->paid(),
            'order_id' => fn (array $attributes): int => Payment::query()->findOrFail($attributes['payment_id'])->order_id,
            'reason_type' => 'customer_cancel',
            'basis_amount' => fn (array $attributes): int => Payment::query()->findOrFail($attributes['payment_id'])->expected_amount,
            'percentage' => 80,
            'amount' => fn (array $attributes): int => intdiv($attributes['basis_amount'], 100) * $attributes['percentage']
                + intdiv(($attributes['basis_amount'] % 100) * $attributes['percentage'], 100),
        ];
    }

    public function storeCancellation(): static
    {
        return $this->state(['reason_type' => RefundReasonType::StoreCancel, 'percentage' => 100]);
    }

    public function latePayment(): static
    {
        return $this->state(['reason_type' => RefundReasonType::LatePayment, 'percentage' => 100]);
    }

    public function amountAnomaly(): static
    {
        return $this->state(['reason_type' => RefundReasonType::AmountAnomaly, 'percentage' => 100]);
    }

    public function assigned(): static
    {
        return $this->state(['assigned_admin_id' => User::factory()->admin(), 'assignment_version' => 1]);
    }

    public function verifiedBank(): static
    {
        return $this->state(fn (): array => [
            'bank_name' => '[TEST] Bank',
            'account_number' => '[TEST] private account',
            'account_holder' => '[TEST] Synthetic holder',
            'bank_verified_at' => now()->startOfSecond(),
            'bank_verified_by' => User::factory()->admin(),
        ]);
    }

    public function processing(): static
    {
        return $this->assigned()->verifiedBank()->state(['status' => RefundLogStatus::Processing]);
    }

    public function review(): static
    {
        return $this->state(['status' => RefundLogStatus::Review, 'review_reason' => '[TEST] synthetic review reason']);
    }

    public function completed(): static
    {
        return $this->assigned()->state(fn (): array => [
            'status' => RefundLogStatus::Completed,
            'completed_by' => fn (array $attributes): int => $attributes['assigned_admin_id'],
            'completed_at' => now()->startOfSecond(),
        ]);
    }
}
