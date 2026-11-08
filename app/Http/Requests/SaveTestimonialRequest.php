<?php

namespace App\Http\Requests;

use App\Models\TestimonialPublicationStatus;
use App\Models\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTestimonialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->is_active
            && in_array($user->role, [UserRole::Admin, UserRole::Owner], true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:2000'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'publication_status' => ['required', Rule::enum(TestimonialPublicationStatus::class)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'photo_path' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(?!\/)(?!.*(?:\.\.|\/\/|\\\\|:))[A-Za-z0-9._\/-]+\.(?:png|jpe?g|webp)$/i',
            ],
        ];
    }
}
