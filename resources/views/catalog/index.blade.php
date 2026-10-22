<x-account-layout eyebrow="Pilihan TBloom" title="Katalog kue">
    <div data-catalog-page>
        <p class="mb-6 text-brand-taupe dark:text-brand-pink">Pilih tanggal pengambilan untuk melihat sisa unit setiap kue.</p>
        @include('catalog.filters', ['action' => route('catalog.index'), 'showCategory' => true])
        <div class="mt-8 grid gap-6 sm:grid-cols-2">
            @forelse ($products as $card)
                <article class="overflow-hidden rounded-xl border border-brand-beige">
                    @if ($image = $card['product']->images->first())
                        <img src="{{ Storage::disk('public')->url($image->path) }}" alt="{{ $image->alt_text }}" loading="lazy" class="aspect-[4/3] w-full object-cover">
                    @else
                        <div class="flex aspect-[4/3] items-center justify-center bg-brand-pink px-5 text-center text-sm text-brand-taupe dark:bg-brand-dark dark:text-brand-beige">Foto produk belum tersedia</div>
                    @endif
                    <div class="grid gap-3 p-5">
                        <h2 class="text-xl font-semibold"><a class="underline underline-offset-4" href="{{ route('catalog.show', ['product' => $card['product']->slug, 'pickup_date' => $date]) }}">{{ $card['product']->name }}</a></h2>
                        @include('catalog.availability', ['card' => $card])
                        @include('catalog.variants', ['product' => $card['product']])
                    </div>
                </article>
            @empty
                <p class="sm:col-span-2">Belum ada produk dalam kategori ini.</p>
            @endforelse
        </div>
        <div class="mt-8">{{ $products->links() }}</div>
    </div>
</x-account-layout>
