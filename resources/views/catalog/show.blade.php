<x-landing-layout :title="$card['product']->name" :description="Str::limit(strip_tags($card['product']->description), 150)">
    <div class="mx-auto max-w-[1440px] px-5 py-8 sm:px-8 lg:py-12 xl:px-16" data-catalog-page>
        {{-- Breadcrumbs & Back Link --}}
        <nav aria-label="Breadcrumb" class="mb-8 flex flex-wrap items-center gap-2 text-xs font-medium text-[#6F594C] sm:text-sm">
            <a href="{{ route('home') }}" class="transition hover:text-[#726251] hover:underline">Beranda</a>
            <span aria-hidden="true" class="text-[#C3B59C]">/</span>
            <a href="{{ route('catalog.index', ['pickup_date' => $date]) }}" class="transition hover:text-[#726251] hover:underline">Katalog</a>
            <span aria-hidden="true" class="text-[#C3B59C]">/</span>
            <span class="font-semibold text-[#251306]" aria-current="page">{{ $card['product']->name }}</span>
        </nav>

        <div class="grid items-start gap-10 lg:grid-cols-12 lg:gap-14">
            {{-- Left Column: Product Imagery --}}
            <div class="space-y-4 lg:col-span-7">
                @php
                    $images = $card['product']->images;
                    $primary = $images->first();
                @endphp

                <div class="relative overflow-hidden rounded-2xl border border-[#D6C6B9] bg-[#FFFCF8] shadow-sm">
                    @if ($primary)
                        <img id="main-product-image" src="{{ Storage::disk('public')->url($primary->path) }}" alt="{{ $primary->alt_text }}" width="900" height="675" class="aspect-[4/3] w-full object-cover">
                    @else
                        <div class="flex aspect-[4/3] w-full items-center justify-center bg-[#F7E3DF] p-8 text-center font-lufga text-3xl font-medium text-[#726251]">
                            {{ $card['product']->name }}
                        </div>
                    @endif
                </div>

                @if ($images->count() > 1)
                    <div class="flex flex-wrap gap-3" aria-label="Galeri foto produk">
                        @foreach ($images as $img)
                            <button type="button" onclick="document.getElementById('main-product-image').src='{{ Storage::disk('public')->url($img->path) }}'; document.getElementById('main-product-image').alt='{{ $img->alt_text }}';" class="group relative aspect-[4/3] w-20 overflow-hidden rounded-lg border border-[#D6C6B9] bg-white transition hover:border-[#726251] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251] sm:w-24">
                                <img src="{{ Storage::disk('public')->url($img->path) }}" alt="{{ $img->alt_text }}" class="h-full w-full object-cover">
                                <span class="sr-only">Lihat foto {{ $loop->iteration }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Right Column: Product Info & Order Configuration --}}
            <div class="flex flex-col space-y-6 lg:col-span-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">
                        {{ $card['product']->category?->name ?? 'Cake' }}
                    </p>
                    <h1 class="mt-2 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">
                        {{ $card['product']->name }}
                    </h1>
                    <p class="mt-2 text-sm text-[#6F594C]">
                        Unit jual: <span class="font-medium text-[#251306]">{{ $card['product']->sales_unit_label }}</span>
                    </p>
                    @if ($card['product']->variants->isNotEmpty())
                        <p class="mt-4 text-2xl font-semibold text-[#726251]">
                            Mulai Rp{{ number_format($card['product']->variants->min('price'), 0, ',', '.') }}
                        </p>
                    @endif
                </div>

                <div class="border-t border-[#D6C6B9] pt-5">
                    <h2 class="font-lufga text-lg font-semibold text-[#251306]">Deskripsi</h2>
                    <p class="mt-3 whitespace-pre-line text-sm leading-7 text-[#6F594C] sm:text-base">{{ $card['product']->description }}</p>
                </div>

                {{-- Date Availability Filter --}}
                <div class="rounded-2xl border border-[#D6C6B9] bg-[#FFFCF8] p-5 shadow-sm">
                    <h2 class="font-lufga text-base font-semibold text-[#251306]">Pilih Tanggal Pengambilan</h2>
                    <div class="mt-3">
                        @include('catalog.filters', ['action' => route('catalog.show', $card['product']->slug), 'showCategory' => false])
                    </div>
                    <div class="mt-4 border-t border-[#E5D8CC] pt-3 text-sm text-[#6F594C]">
                        @include('catalog.availability', ['card' => $card])
                    </div>
                </div>

                {{-- Variants & Pricing Table --}}
                <div class="rounded-2xl border border-[#D6C6B9] bg-[#FFFCF8] p-5 shadow-sm">
                    <h2 class="font-lufga text-base font-semibold text-[#251306]">Pilihan Ukuran &amp; Harga</h2>
                    <div class="mt-3">
                        @include('catalog.variants', ['product' => $card['product']])
                    </div>
                </div>

                {{-- Ordering Action Button --}}
                <div>
                    @if ($card['remaining'] > 0 && $card['product']->variants->isNotEmpty())
                        <a href="{{ route('checkout.create', ['product' => $card['product']->slug, 'pickup_date' => $date]) }}" class="inline-flex min-h-14 w-full items-center justify-center rounded-full bg-[#726251] px-7 py-4 text-base font-semibold text-white transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                            Pesan cake ini <span aria-hidden="true" class="ml-2 text-lg">↗</span>
                        </a>
                    @elseif ($card['remaining'] === 0)
                        <div class="rounded-xl border border-[#C3B59C] bg-[#FFFCF8] p-4 text-center text-sm font-semibold text-[#726251]">
                            Kuota produksi untuk tanggal ini telah penuh. Silakan pilih tanggal lain pada kalender di atas.
                        </div>
                    @endif
                </div>

                {{-- Policy Guidance & Terms Notice --}}
                <div class="rounded-xl border border-[#D6C6B9]/80 bg-[#FAF5EF] p-4 text-xs leading-6 text-[#6F594C]">
                    <p class="font-medium text-[#251306]">Ketentuan Pemesanan &amp; Pengambilan:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        <li>Semua ukuran berbagi kuota produk pada tanggal yang sama. Ketersediaan diperiksa kembali saat pemesanan.</li>
                        <li>Pesanan Simple Cake dibayar penuh dalam 30 menit setelah checkout.</li>
                        <li>Pengambilan dilakukan secara mandiri di toko atau menggunakan kurir online (Grab/Gojek).</li>
                    </ul>
                    <p class="mt-3">
                        <a href="{{ route('pickup.show').'#policies' }}" class="font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                            Baca syarat &amp; kebijakan pemesanan lengkap <span aria-hidden="true">↗</span>
                        </a>
                    </p>
                </div>
            </div>
        </div>

        {{-- Related Products Section --}}
        @if (isset($relatedProducts) && $relatedProducts->isNotEmpty())
            <section aria-labelledby="related-heading" class="mt-20 border-t border-[#D6C6B9] pt-14 sm:mt-24 sm:pt-16">
                <div class="mb-10 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">Pilihan Lainnya</p>
                        <h2 id="related-heading" class="mt-2 font-lufga text-2xl font-semibold tracking-tight text-[#251306] sm:text-3xl">
                            Produk Terkait
                        </h2>
                    </div>
                    <a href="{{ route('catalog.index', ['pickup_date' => $date]) }}" class="text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                        Lihat semua katalog <span aria-hidden="true">↗</span>
                    </a>
                </div>

                <div class="grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($relatedProducts as $related)
                        @php
                            $relatedImage = $related->primaryImage;
                            $relatedVariant = $related->variants->first();
                        @endphp
                        @if ($relatedVariant)
                            <article class="group min-w-0">
                                <a href="{{ route('catalog.show', ['product' => $related->slug, 'pickup_date' => $date]) }}" class="block overflow-hidden rounded-xl bg-[#F7E3DF] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                                    @if ($relatedImage)
                                        <img src="{{ Storage::disk('public')->url($relatedImage->path) }}" alt="{{ $relatedImage->alt_text }}" width="640" height="480" loading="lazy" class="aspect-[4/3] w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
                                    @else
                                        <div class="flex aspect-[4/3] items-center justify-center px-6 text-center font-lufga text-2xl text-[#726251]">
                                            {{ $related->name }}
                                        </div>
                                    @endif
                                </a>
                                <div class="flex items-start justify-between gap-4 pt-4">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#7B3547]">
                                            {{ match ($related->category) {
                                                \App\Models\ProductCategory::Simple => 'Simple Cake',
                                                \App\Models\ProductCategory::Cupcake => 'Cupcake',
                                                \App\Models\ProductCategory::Bento => 'Bento Cake',
                                                default => 'Cake',
                                            } }}
                                        </p>
                                        <h3 class="mt-2 font-lufga text-xl font-semibold text-[#251306]">
                                            <a href="{{ route('catalog.show', ['product' => $related->slug, 'pickup_date' => $date]) }}" class="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">
                                                {{ $related->name }}
                                            </a>
                                        </h3>
                                        <p class="mt-1 text-sm text-[#6F594C]">{{ $related->sales_unit_label }}</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-[10px] uppercase tracking-wide text-[#6F594C]">Mulai dari</p>
                                        <p class="mt-1 text-sm font-semibold text-[#726251]">
                                            Rp{{ number_format($relatedVariant->price, 0, ',', '.') }}
                                        </p>
                                    </div>
                                </div>
                            </article>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-landing-layout>
