<?php

namespace Database\Factories;

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomCakeRequestAttachment> */
class CustomCakeRequestAttachmentFactory extends Factory
{
    /** @return array{custom_cake_request_id: CustomCakeRequestFactory, private_path: string, original_name_ciphertext: string, mime_type: string, size_bytes: int, sha256: string, uploaded_by: Closure} */
    public function definition(): array
    {
        return [
            'custom_cake_request_id' => CustomCakeRequest::factory(),
            'private_path' => 'custom-requests/'.Str::uuid(),
            'original_name_ciphertext' => '[TEST] synthetic-reference.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1000,
            'sha256' => hash('sha256', '[TEST] synthetic image metadata, no actual file'),
            'uploaded_by' => fn (array $attributes): int => CustomCakeRequest::query()->findOrFail($attributes['custom_cake_request_id'])->user_id,
        ];
    }
}
