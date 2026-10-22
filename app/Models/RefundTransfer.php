<?php

namespace App\Models;

use Database\Factories\RefundTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['bank_name', 'account_number', 'account_holder'])]
class RefundTransfer extends Model
{
    /** @use HasFactory<RefundTransferFactory> */
    use HasFactory;

    /** @return array{status: class-string<RefundTransferStatus>, amount: string, attempt_number: string, assignment_version: string, bank_name: string, account_number: string, account_holder: string, bank_verified_at: string, prepared_at: string, transferred_at: string, verified_at: string, completed_at: string} */
    protected function casts(): array
    {
        return [
            'status' => RefundTransferStatus::class,
            'amount' => 'integer',
            'attempt_number' => 'integer',
            'assignment_version' => 'integer',
            'bank_name' => 'encrypted',
            'account_number' => 'encrypted',
            'account_holder' => 'encrypted',
            'bank_verified_at' => 'immutable_datetime',
            'prepared_at' => 'immutable_datetime',
            'transferred_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RefundLog, $this> */
    public function refundLog(): BelongsTo
    {
        return $this->belongsTo(RefundLog::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
