<?php

namespace App\Models;

use Database\Factories\RefundEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['private_path', 'original_name_ciphertext'])]
class RefundEvidence extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<RefundEvidenceFactory> */
    use HasFactory;

    protected $table = 'refund_evidence';

    /** @return array{original_name_ciphertext: string, size_bytes: string, created_at: string} */
    protected function casts(): array
    {
        return [
            'original_name_ciphertext' => 'encrypted',
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RefundTransfer, $this> */
    public function refundTransfer(): BelongsTo
    {
        return $this->belongsTo(RefundTransfer::class);
    }

    /** @return BelongsTo<RefundLog, $this> */
    public function refundLog(): BelongsTo
    {
        return $this->belongsTo(RefundLog::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
