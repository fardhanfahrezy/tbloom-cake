<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /** @return array{actor_user_id: null, actor_type: string, entity_type: string, entity_id: UserFactory, action: string, before_redacted: array{status: string}, after_redacted: array{status: string}, reason: string, correlation_id: string} */
    public function definition(): array
    {
        return [
            'actor_user_id' => null,
            'actor_type' => 'system',
            'entity_type' => 'user',
            'entity_id' => User::factory(),
            'action' => 'test.status_changed',
            'before_redacted' => ['status' => 'pending'],
            'after_redacted' => ['status' => 'approved'],
            'reason' => 'Synthetic redacted audit fixture.',
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function byUser(User $actor): static
    {
        return $this->state(['actor_user_id' => $actor->id, 'actor_type' => 'user']);
    }
}
