<x-account-layout title="Selamat datang di TBloom Cake" eyebrow="TBloom Cake">
    <p class="leading-relaxed">TBloom Cake menyediakan Simple Cake, Cupcake, Bento Cake siap pesan melalui katalog online, serta pengajuan Custom Cake untuk perayaan spesial dengan pengambilan langsung di toko atau kurir online mandiri.</p>
    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('catalog.index') }}" class="inline-block rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark">Lihat katalog</a>
        <a href="{{ route('pickup.show').'#policies' }}" class="inline-block rounded-lg border border-brand-burgundy px-5 py-3 font-semibold text-brand-burgundy focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:border-brand-beige dark:text-brand-beige">Informasi pengambilan &amp; kebijakan</a>
    </div>
    @include('pickup-information')
    @if (($testimonials ?? collect())->isNotEmpty())
        <section aria-labelledby="testimonials-heading" class="my-6 rounded-lg border border-brand-beige p-5 text-sm leading-6">
            <h2 id="testimonials-heading" class="text-base font-semibold">Testimoni pelanggan</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($testimonials as $testimonial)
                    <article class="rounded-lg border border-brand-beige/70 p-4">
                        @if ($testimonial->photo_path)
                            <img src="{{ asset($testimonial->photo_path) }}" alt="Foto testimoni {{ $testimonial->display_name }}" width="320" height="240" loading="lazy" class="mb-3 h-32 w-full rounded object-cover">
                        @endif
                        <blockquote class="break-words">{{ $testimonial->content }}</blockquote>
                        <footer class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-brand-burgundy dark:text-brand-pink">
                            <cite class="not-italic">{{ $testimonial->display_name }}</cite>
                            @if ($testimonial->rating !== null)
                                <span>Rating {{ $testimonial->rating }}/5</span>
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</x-account-layout>
