<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\OrderRequestDecisionMode;
use App\Models\OrderRequestStatus;
use App\Models\OrderRequestType;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderRequest>
 */
class OrderRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{order_id: OrderFactory, user_id: Closure, type: string, decision_mode: string, reason: string, submitted_at: string, idempotency_key: string, request_hash: string}
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->paid(),
            'user_id' => fn (array $attributes): int => Order::query()->findOrFail($attributes['order_id'])->user_id,
            'type' => 'cancellation',
            'decision_mode' => 'manual',
            'reason' => '[TEST] synthetic cancellation request',
            'submitted_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function change(): static
    {
        return $this->state([
            'type' => OrderRequestType::Change,
            'requested_changes' => ['inscription' => '[TEST] New inscription'],
            'reason' => '[TEST] synthetic personalization change',
        ]);
    }

    public function automaticCancellation(): static
    {
        return $this->state([
            'type' => OrderRequestType::Cancellation,
            'decision_mode' => OrderRequestDecisionMode::Automatic,
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderRequestStatus::Applied,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now()->startOfSecond(),
            'applied_at' => now()->startOfSecond(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderRequestStatus::Rejected,
            'reviewed_by' => User::factory()->admin(),
            'reviewed_at' => now()->startOfSecond(),
            'decision_reason' => '[TEST] synthetic rejection reason',
        ]);
    }
}
