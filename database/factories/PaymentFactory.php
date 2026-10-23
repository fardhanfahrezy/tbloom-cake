<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentClosureResult;
use App\Models\PaymentEvent;
use App\Models\PaymentStatus;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{order_id: OrderFactory, merchant_reference: string, expected_amount: Closure}
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'merchant_reference' => 'TEST-PAY-'.Str::uuid(),
            'expected_amount' => fn (array $attributes): int => Order::query()->findOrFail($attributes['order_id'])->product_total,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'provider_paid_at' => now()->startOfSecond(),
            'verified_at' => now()->startOfSecond(),
            'is_late' => false,
        ]);
    }

    public function closedUnpaid(): static
    {
        return $this->state(['status' => PaymentStatus::Expired])->afterCreating(function (Payment $payment): void {
            $event = PaymentEvent::factory()->for($payment)->processed()->create([
                'payload_redacted' => ['fixture' => 'synthetic', 'transaction_status' => 'expire'],
            ]);
            $payment->forceFill([
                'closure_result' => PaymentClosureResult::UnpaidClosed,
                'closure_verified_at' => now()->startOfSecond(),
                'closure_event_id' => $event->id,
            ])->save();
        });
    }
}
