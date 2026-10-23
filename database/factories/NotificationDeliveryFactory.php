<?php

namespace Database\Factories;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\NotificationChannel;
use App\NotificationRecipientIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NotificationDelivery> */
class NotificationDeliveryFactory extends Factory
{
    /** @return array{event_key: string, recipient_ciphertext: array{channel: string, address: string}, recipient_key: string, template_key: string, entity_type: string, entity_id: UserFactory, payload_minimal: array{status: string}} */
    public function definition(): array
    {
        return [
            'event_key' => 'test:transition:'.Str::uuid(),
            'recipient_ciphertext' => ['channel' => 'email', 'address' => 'synthetic-recipient@example.test'],
            'recipient_key' => app(NotificationRecipientIdentity::class)->key(NotificationChannel::Email, 'synthetic-recipient@example.test'),
            'template_key' => 'test.order_paid.v1',
            'entity_type' => 'user',
            'entity_id' => User::factory(),
            'payload_minimal' => ['status' => 'paid'],
        ];
    }

    public function pending(): static
    {
        return $this->state([]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()->startOfSecond(), 'attempts' => 1]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => ['status' => 'sent', 'sent_at' => now()->startOfSecond(), 'provider_message_id' => 'TEST-'.Str::uuid()]);
    }

    public function delivered(): static
    {
        return $this->sent()->state(fn (): array => ['status' => 'delivered', 'delivered_at' => now()->startOfSecond()]);
    }

    public function unknown(): static
    {
        return $this->state(['status' => 'unknown', 'attempts' => 1, 'last_error' => '[TEST] outcome unknown']);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => 'failed', 'attempts' => 1, 'next_attempt_at' => now()->addMinute()->startOfSecond(), 'last_error' => '[TEST] retryable failure']);
    }

    public function suppressed(): static
    {
        return $this->state(['status' => 'suppressed']);
    }
}
