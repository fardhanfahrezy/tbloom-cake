<?php

namespace App\Models;

use Database\Factories\PaymentMovementCorrectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentMovementCorrection extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<PaymentMovementCorrectionFactory> */
    use HasFactory;

    /** @return array{revision: string, effective_amount: string, effective_occurred_at: string, verified_at: string, created_at: string} */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'effective_amount' => 'integer',
            'effective_occurred_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PaymentMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(PaymentMovement::class, 'payment_movement_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<PaymentMovementCorrection, $this> */
    public function previousCorrection(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_correction_id');
    }

    /** @return BelongsTo<PaymentEvent, $this> */
    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class, 'source_event_id');
    }

    /** @return BelongsTo<RefundTransfer, $this> */
    public function refundTransfer(): BelongsTo
    {
        return $this->belongsTo(RefundTransfer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Payment movement corrections are append-only.');
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw new LogicException('Payment movement corrections are append-only.');
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException('Payment movement corrections are append-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Payment movement corrections are append-only.');
    }
}
