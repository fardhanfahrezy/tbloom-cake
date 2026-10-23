<?php

namespace App\Models;

use Database\Factories\CustomCakeRequestAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['private_path', 'original_name_ciphertext'])]
class CustomCakeRequestAttachment extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<CustomCakeRequestAttachmentFactory> */
    use HasFactory;

    /** @return array{original_name_ciphertext: string, size_bytes: string, created_at: string} */
    protected function casts(): array
    {
        return [
            'original_name_ciphertext' => 'encrypted',
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CustomCakeRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CustomCakeRequest::class, 'custom_cake_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
