<?php

namespace App\Models;

use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['recipient_ciphertext', 'recipient_key', 'claim_token', 'last_error'])]
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    /** @return array{recipient_ciphertext: string, payload_minimal: string, status: class-string<NotificationDeliveryStatus>, entity_id: string, attempts: string, next_attempt_at: string, lease_until: string, sent_at: string, delivered_at: string, created_at: string, updated_at: string} */
    protected function casts(): array
    {
        return [
            'recipient_ciphertext' => 'encrypted:array',
            'payload_minimal' => 'array',
            'status' => NotificationDeliveryStatus::class,
            'entity_id' => 'integer',
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
