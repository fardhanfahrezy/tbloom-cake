<?php

namespace Database\Factories;

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestStatus;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomCakeRequest> */
class CustomCakeRequestFactory extends Factory
{
    /** @return array{request_number: string, user_id: UserFactory, customer_name_snapshot: Closure, whatsapp_snapshot: Closure, pickup_date: string, size: string, flavor_code: string, filling_code: string, option_labels_snapshot: array{flavor: string, filling: string}, color: string, idempotency_key: string, request_hash: string} */
    public function definition(): array
    {
        return [
            'request_number' => 'TST-'.Str::uuid(),
            'user_id' => User::factory(),
            'customer_name_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['user_id'])->name,
            'whatsapp_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['user_id'])->whatsapp_number,
            'pickup_date' => now()->addDays(3)->format('Y-m-d'),
            'size' => '[TEST] 10 cm',
            'flavor_code' => 'test-vanilla',
            'filling_code' => 'test-cream',
            'option_labels_snapshot' => ['flavor' => '[TEST] Vanilla', 'filling' => '[TEST] Cream'],
            'color' => '[TEST] White',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function submitted(): static
    {
        return $this->state(['status' => CustomCakeRequestStatus::Submitted]);
    }

    public function contacted(): static
    {
        return $this->state(['status' => CustomCakeRequestStatus::Contacted, 'assigned_admin_id' => User::factory()->admin()]);
    }

    public function quoted(): static
    {
        return $this->contacted()->state(['status' => CustomCakeRequestStatus::Quoted]);
    }

    public function accepted(): static
    {
        return $this->contacted()->state(['status' => CustomCakeRequestStatus::Accepted]);
    }

    public function rejected(): static
    {
        return $this->contacted()->state(['status' => CustomCakeRequestStatus::Rejected, 'status_reason' => '[TEST] Synthetic rejection']);
    }

    public function withEdiblePrint(): static
    {
        return $this->state(fn (): array => ['edible_print' => true, 'pickup_date' => now()->addDays(7)->format('Y-m-d')]);
    }

    public function withFondant(): static
    {
        return $this->state(['fondant' => true, 'fondant_details' => '[TEST] Synthetic fondant decoration']);
    }
}
