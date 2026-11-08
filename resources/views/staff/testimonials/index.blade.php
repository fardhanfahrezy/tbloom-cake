<x-account-layout title="Kelola testimoni" eyebrow="Operasional konten">
    <form method="POST" action="{{ route('staff.testimonials.store') }}" class="space-y-4 rounded-lg border border-brand-beige p-5 text-sm">
        @csrf
        <h2 class="text-base font-semibold">Tambah testimoni</h2>
        <div>
            <label for="new-display-name" class="block font-medium">Nama tampil</label>
            <input id="new-display-name" name="display_name" type="text" required maxlength="120" value="{{ old('display_name') }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-2">
            @error('display_name')<p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="new-content" class="block font-medium">Isi testimoni (maks. 2000 karakter)</label>
            <textarea id="new-content" name="content" rows="3" required maxlength="2000" class="mt-1 w-full rounded border border-brand-beige px-3 py-2">{{ old('content') }}</textarea>
            @error('content')<p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="new-rating" class="block font-medium">Rating (1–5 opsional)</label>
                <input id="new-rating" name="rating" type="number" min="1" max="5" value="{{ old('rating') }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-2">
                @error('rating')<p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="new-sort-order" class="block font-medium">Urutan tampil</label>
                <input id="new-sort-order" name="sort_order" type="number" min="0" required value="{{ old('sort_order', 0) }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-2">
                @error('sort_order')<p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="new-publication-status" class="block font-medium">Status publikasi</label>
                <select id="new-publication-status" name="publication_status" class="mt-1 w-full rounded border border-brand-beige px-3 py-2">
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="archived">Archived</option>
                </select>
                @error('publication_status')<p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
        </div>
        <button type="submit" class="rounded-lg bg-brand-burgundy px-4 py-2 font-semibold text-white">Simpan testimoni</button>
    </form>

    <section aria-labelledby="existing-testimonials-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="existing-testimonials-heading" class="text-xl font-semibold">Daftar testimoni</h2>
        @if ($testimonials->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Belum ada testimoni yang tersimpan.</p>
        @else
            <div class="mt-4 space-y-4">
                @foreach ($testimonials as $testimonial)
                    <form method="POST" action="{{ route('staff.testimonials.update', $testimonial) }}" class="space-y-3 rounded-lg border border-brand-beige p-4 text-sm">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="display-name-{{ $testimonial->id }}" class="block font-medium">Nama tampil</label>
                                <input id="display-name-{{ $testimonial->id }}" name="display_name" type="text" required maxlength="120" value="{{ $testimonial->display_name }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
                            </div>
                            <div>
                                <label for="publication-status-{{ $testimonial->id }}" class="block font-medium">Status publikasi</label>
                                <select id="publication-status-{{ $testimonial->id }}" name="publication_status" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
                                    @foreach (\App\Models\TestimonialPublicationStatus::cases() as $statusCase)
                                        <option value="{{ $statusCase->value }}" @selected($testimonial->publication_status === $statusCase)>{{ $statusCase->value }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="content-{{ $testimonial->id }}" class="block font-medium">Isi testimoni</label>
                            <textarea id="content-{{ $testimonial->id }}" name="content" rows="2" required maxlength="2000" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">{{ $testimonial->content }}</textarea>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="rating-{{ $testimonial->id }}" class="block font-medium">Rating (1–5)</label>
                                <input id="rating-{{ $testimonial->id }}" name="rating" type="number" min="1" max="5" value="{{ $testimonial->rating }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
                            </div>
                            <div>
                                <label for="sort-order-{{ $testimonial->id }}" class="block font-medium">Urutan tampil</label>
                                <input id="sort-order-{{ $testimonial->id }}" name="sort_order" type="number" min="0" required value="{{ $testimonial->sort_order }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
                            </div>
                        </div>
                        <button type="submit" class="rounded bg-brand-burgundy px-3 py-1.5 text-xs font-semibold text-white">Perbarui</button>
                    </form>
                @endforeach
            </div>
        @endif
    </section>
</x-account-layout>

