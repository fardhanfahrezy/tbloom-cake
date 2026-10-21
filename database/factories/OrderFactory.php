<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PickupMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{user_id: UserFactory, order_number: string, customer_name_snapshot: Closure, email_snapshot: Closure, whatsapp_snapshot: Closure, pickup_date: string, pickup_time: string, pickup_method: string, product_total: int, created_at: string, updated_at: string, payment_expires_at: Closure, idempotency_key: string, request_hash: string}
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_number' => 'TST-'.Str::uuid(),
            'customer_name_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['user_id'])->name,
            'email_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['user_id'])->email,
            'whatsapp_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['user_id'])->whatsapp_number,
            'pickup_date' => now('Asia/Jakarta')->addDays(3)->toDateString(),
            'pickup_time' => '09:00:00',
            'pickup_method' => 'self_pickup',
            'product_total' => 100000,
            'created_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
            'updated_at' => now()->startOfSecond()->format('Y-m-d H:i:s'),
            'payment_expires_at' => fn (array $attributes): string => CarbonImmutable::parse($attributes['created_at'])->addMinutes(30)->format('Y-m-d H:i:s'),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => CarbonImmutable::parse($attributes['created_at'])->addMinutes(10),
        ]);
    }

    public function completed(): static
    {
        return $this->paid()->state(fn (array $attributes): array => [
            'status' => OrderStatus::Completed,
            'completed_at' => CarbonImmutable::parse($attributes['created_at'])->addDays(3),
            'handed_over_by' => User::factory()->admin(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Expired,
            'expired_at' => fn (array $resolved): CarbonImmutable => CarbonImmutable::parse($resolved['payment_expires_at'])->addMinute(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::parse($attributes['created_at'])->addMinutes(5),
            'cancellation_reason' => '[TEST] synthetic cancellation',
        ]);
    }

    public function onlineCourier(): static
    {
        return $this->state(fn (array $attributes): array => [
            'pickup_method' => PickupMethod::OnlineCourier,
            'policy_version' => 'test-v1',
            'policy_text_snapshot' => '[TEST] synthetic courier terms',
            'courier_terms_accepted_at' => CarbonImmutable::parse($attributes['created_at']),
        ]);
    }
}
