<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PaymentMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PaymentMovement extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<PaymentMovementFactory> */
    use HasFactory;

    /** @return array{kind: class-string<PaymentMovementKind>, source: class-string<PaymentMovementSource>, amount: string, occurred_at: string, verified_at: string, created_at: string} */
    protected function casts(): array
    {
        return [
            'kind' => PaymentMovementKind::class,
            'source' => PaymentMovementSource::class,
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Payment movements are append-only.');
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw new LogicException('Payment movements are append-only.');
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException('Payment movements are append-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Payment movements are append-only.');
    }

    /** @return HasMany<PaymentMovementCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(PaymentMovementCorrection::class);
    }

    /** @return HasOne<PaymentMovementCorrection, $this> */
    public function headCorrection(): HasOne
    {
        return $this->hasOne(PaymentMovementCorrection::class)->ofMany('revision', 'max');
    }

    /**
     * Read one effective interpretation; monetary decisions require the domain parent locks.
     *
     * @return array{amount: int, occurred_at: CarbonImmutable}
     */
    public function effectiveFact(): array
    {
        $head = $this->headCorrection()->first();

        return [
            'amount' => $head?->effective_amount ?? $this->amount,
            'occurred_at' => $head?->effective_occurred_at ?? $this->occurred_at,
        ];
    }
}
