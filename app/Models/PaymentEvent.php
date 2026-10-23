<?php

namespace App\Models;

use Database\Factories\PaymentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['payload_redacted', 'claim_token', 'error'])]
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<PaymentEventFactory> */
    use HasFactory;

    /** @return array{processing_status: class-string<PaymentEventStatus>, payload_redacted: string, attempts: string, received_at: string, verified_at: string, next_attempt_at: string, lease_until: string, processed_at: string} */
    protected function casts(): array
    {
        return [
            'processing_status' => PaymentEventStatus::class,
            'payload_redacted' => 'array',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return HasMany<PaymentMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(PaymentMovement::class, 'source_event_id');
    }
}
