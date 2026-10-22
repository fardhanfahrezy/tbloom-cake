<?php

namespace Database\Factories;

use App\Models\RefundEvidence;
use App\Models\RefundTransfer;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RefundEvidence>
 */
class RefundEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{refund_transfer_id: RefundTransferFactory, refund_log_id: Closure, private_path: string, original_name_ciphertext: string, mime_type: string, size_bytes: int, sha256: string, uploaded_by: Closure}
     */
    public function definition(): array
    {
        return [
            'refund_transfer_id' => RefundTransfer::factory()->unknown(),
            'refund_log_id' => fn (array $attributes): int => RefundTransfer::query()->findOrFail($attributes['refund_transfer_id'])->refund_log_id,
            'private_path' => 'refund-evidence/'.Str::uuid(),
            'original_name_ciphertext' => '[TEST] synthetic-proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'sha256' => hash('sha256', '[TEST] synthetic PDF metadata, no actual file'),
            'uploaded_by' => fn (array $attributes): int => RefundTransfer::query()->findOrFail($attributes['refund_transfer_id'])->prepared_by,
        ];
    }

    public function image(): static
    {
        return $this->state([
            'original_name_ciphertext' => '[TEST] synthetic-proof.png',
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', '[TEST] synthetic PNG metadata, no actual file'),
        ]);
    }
}
