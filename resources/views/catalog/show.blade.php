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
            <p class="text-sm text-brand-taupe dark:text-brand-pink">Semua ukuran berbagi kuota produk pada tanggal yang sama. Ketersediaan diperiksa kembali saat pemesanan.</p>
        </div>
    </div>
</x-account-layout>
