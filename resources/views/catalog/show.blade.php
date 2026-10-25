<x-account-layout eyebrow="Pilihan TBloom" :title="$card['product']->name">
    <div data-catalog-page>
        <a href="{{ route('catalog.index', ['pickup_date' => $date]) }}" class="mb-6 inline-block underline underline-offset-4">Kembali ke katalog</a>
        @include('catalog.filters', ['action' => route('catalog.show', $card['product']->slug), 'showCategory' => false])
        <div class="mt-8 grid gap-6">
            @foreach ($card['product']->images as $image)
                <img src="{{ Storage::disk('public')->url($image->path) }}" alt="{{ $image->alt_text }}" class="aspect-[4/3] w-full rounded-xl object-cover" loading="lazy">
            @endforeach
            <p class="whitespace-pre-line">{{ $card['product']->description }}</p>
            @include('catalog.availability', ['card' => $card])
            @include('catalog.variants', ['product' => $card['product']])
            @if ($card['remaining'] > 0 && $card['product']->variants->isNotEmpty())
                <a href="{{ route('checkout.create', ['product' => $card['product']->slug, 'pickup_date' => $date]) }}" class="rounded-lg bg-brand-burgundy px-5 py-3 text-center font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark">Pesan cake ini</a>
            @endif
            <p class="text-sm text-brand-taupe dark:text-brand-pink">Semua ukuran berbagi kuota produk pada tanggal yang sama. Ketersediaan diperiksa kembali saat pemesanan.</p>
        </div>
    </div>
</x-account-layout>
