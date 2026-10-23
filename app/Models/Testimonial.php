<?php

namespace App\Models;

use Database\Factories\TestimonialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['display_name', 'content', 'photo_path', 'rating', 'publication_status', 'sort_order'])]
class Testimonial extends Model
{
    /** @use HasFactory<TestimonialFactory> */
    use HasFactory;

    /**
     * @return array{rating: string, publication_status: class-string<TestimonialPublicationStatus>, sort_order: string}
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'publication_status' => TestimonialPublicationStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
