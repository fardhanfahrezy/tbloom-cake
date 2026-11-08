<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTestimonialRequest;
use App\Models\AuditActorType;
use App\Models\AuditLog;
use App\Models\Testimonial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TestimonialAdminController extends Controller
{
    public function index(): View
    {
        return view('staff.testimonials.index', [
            'testimonials' => Testimonial::query()
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(SaveTestimonialRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated): void {
            $testimonial = Testimonial::query()->create([
                'display_name' => $validated['display_name'],
                'content' => $validated['content'],
                'rating' => $validated['rating'] ?? null,
                'publication_status' => $validated['publication_status'],
                'sort_order' => (int) $validated['sort_order'],
                'photo_path' => $validated['photo_path'] ?? null,
            ]);

            $audit = new AuditLog;
            $audit->actor_type = AuditActorType::User;
            $audit->actor_user_id = $request->user()->id;
            $audit->action = 'testimonial.created';
            $audit->entity_type = 'testimonial';
            $audit->entity_id = $testimonial->id;
            $audit->correlation_id = (string) Str::uuid();
            $audit->after_redacted = [
                'publication_status' => $testimonial->publication_status->value,
                'rating' => $testimonial->rating,
                'sort_order' => $testimonial->sort_order,
            ];
            $audit->save();
        });

        return to_route('staff.testimonials.index')->with('status', 'Testimoni disimpan.');
    }

    public function update(SaveTestimonialRequest $request, Testimonial $testimonial): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $testimonial, $validated): void {
            $locked = Testimonial::query()->lockForUpdate()->findOrFail($testimonial->id);
            $before = [
                'publication_status' => $locked->publication_status->value,
                'rating' => $locked->rating,
                'sort_order' => $locked->sort_order,
            ];

            $locked->update([
                'display_name' => $validated['display_name'],
                'content' => $validated['content'],
                'rating' => $validated['rating'] ?? null,
                'publication_status' => $validated['publication_status'],
                'sort_order' => (int) $validated['sort_order'],
                'photo_path' => $validated['photo_path'] ?? $locked->photo_path,
            ]);

            $audit = new AuditLog;
            $audit->actor_type = AuditActorType::User;
            $audit->actor_user_id = $request->user()->id;
            $audit->action = 'testimonial.updated';
            $audit->entity_type = 'testimonial';
            $audit->entity_id = $locked->id;
            $audit->correlation_id = (string) Str::uuid();
            $audit->before_redacted = $before;
            $audit->after_redacted = [
                'publication_status' => $locked->publication_status->value,
                'rating' => $locked->rating,
                'sort_order' => $locked->sort_order,
            ];
            $audit->save();
        });

        return to_route('staff.testimonials.index')->with('status', 'Testimoni diperbarui.');
    }
}
