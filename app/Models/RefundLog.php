<?php

namespace App\Models;

use Database\Factories\RefundLogFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['bank_name', 'account_number', 'account_holder'])]
class RefundLog extends Model
{
    /** @use HasFactory<RefundLogFactory> */
    use HasFactory;

    /** @return array{reason_type: class-string<RefundReasonType>, status: class-string<RefundLogStatus>, basis_amount: string, percentage: string, amount: string, assignment_version: string, bank_name: string, account_number: string, account_holder: string, bank_verified_at: string, completed_at: string} */
    protected function casts(): array
    {
        return [
            'reason_type' => RefundReasonType::class,
            'status' => RefundLogStatus::class,
            'basis_amount' => 'integer',
            'percentage' => 'integer',
            'amount' => 'integer',
            'assignment_version' => 'integer',
            'bank_name' => 'encrypted',
            'account_number' => 'encrypted',
            'account_holder' => 'encrypted',
            'bank_verified_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function bankVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bank_verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return HasMany<RefundTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(RefundTransfer::class);
    }
}
