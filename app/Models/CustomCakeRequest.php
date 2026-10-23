<?php

namespace App\Models;

use Database\Factories\CustomCakeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomCakeRequest extends Model
{
    /** @use HasFactory<CustomCakeRequestFactory> */
    use HasFactory;

    /** @return array{status: class-string<CustomCakeRequestStatus>, pickup_date: string, option_labels_snapshot: string, edible_print: string, fondant: string} */
    protected function casts(): array
    {
        return [
            'status' => CustomCakeRequestStatus::class,
            'pickup_date' => 'immutable_date',
            'option_labels_snapshot' => 'array',
            'edible_print' => 'boolean',
            'fondant' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    /** @return HasMany<CustomCakeRequestAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(CustomCakeRequestAttachment::class);
    }
}
