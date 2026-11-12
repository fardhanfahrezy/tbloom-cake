<x-landing-layout title="Katalog Kue Artisanal" description="Jelajahi koleksi kue artisanal, simple cake, bento cake, dan cupcake TBloom Cake yang dibuat segar per pesanan di Indramayu.">
    <div data-catalog-page class="bg-[#FAF5EF]">

        {{-- B. Editorial Hero Section --}}
        <section aria-labelledby="catalog-hero-heading" class="relative overflow-hidden border-b border-[#E5D8CC] bg-[#FAF5EF]">
            <div class="mx-auto grid max-w-[1440px] items-center gap-10 px-5 py-12 sm:px-8 lg:grid-cols-12 lg:gap-14 lg:py-16 xl:px-16">
                <div class="lg:col-span-7">
                    <span class="inline-flex items-center gap-2 rounded-full bg-[#F7E3DF] px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-[#7B3547]">
                        Handcrafted Cake Studio
                    </span>
                    <h1 id="catalog-hero-heading" class="mt-4 font-lufga text-3xl font-semibold leading-tight tracking-tight text-[#251306] sm:text-4xl lg:text-5xl">
                        Made with Love, Crafted for Your Moments.
                    </h1>
                    <p class="mt-4 max-w-xl font-inter text-base leading-relaxed text-[#6F594C] sm:text-lg">
                        Discover handcrafted cakes made to make every celebration more meaningful.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-4">
                        <a href="#katalog-koleksi" class="inline-flex items-center justify-center rounded-[8px] bg-[#726251] px-6 py-3.5 font-inter text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                            Lihat Koleksi Kue
                        </a>
                        <a href="{{ route('custom-requests.create') }}" class="inline-flex items-center justify-center rounded-[8px] border border-[#D6C6B9] bg-white px-6 py-3.5 font-inter text-sm font-medium text-[#251306] shadow-sm transition-colors hover:bg-[#F7E3DF]/50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                            Pesan Custom Cake
                        </a>
                    </div>
                </div>
                <div class="lg:col-span-5">
                    <div class="relative mx-auto w-full max-w-[480px] overflow-hidden rounded-[8px] bg-[#F7E3DF] shadow-md">
                        <img src="{{ asset('assets/welcome/pexels-gustavo-fring-7447275.jpg') }}" alt="Proses kreasi dan dekorasi kue di studio TBloom Cake" width="480" height="360" class="aspect-[4/3] w-full rounded-[8px] object-cover">
                    </div>
                </div>
            </div>
        </section>

        {{-- C. Collection Introduction --}}
        <section id="katalog-koleksi" aria-labelledby="collection-heading" class="mx-auto max-w-[1440px] px-5 pt-12 pb-6 sm:px-8 xl:px-16">
            <div class="text-center">
                <h2 id="collection-heading" class="font-lufga text-2xl font-semibold tracking-tight text-[#251306] sm:text-3xl lg:text-4xl">
                    Find Your Perfect Cake
                </h2>
                <p class="mx-auto mt-3 max-w-2xl font-inter text-sm leading-relaxed text-[#6F594C] sm:text-base">
                    Explore our handcrafted cakes, thoughtfully prepared for your special moments.
                </p>
            </div>

            {{-- D. Category Navigation & E. Date Filter Bar --}}
            <div class="mt-8 flex flex-col gap-6 rounded-2xl border border-[#D6C6B9] bg-white p-5 shadow-sm sm:p-6 lg:p-8">
                {{-- Category Navigation Pills --}}
                <div class="flex flex-col gap-3 border-b border-[#E5D8CC] pb-6">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[#6F594C]">Kategori Kue</span>
                    <nav aria-label="Kategori Produk" class="flex flex-wrap items-center gap-2 sm:gap-3">
                        @php
                            $categoriesNav = [
                                '' => 'Semua Kue',
                                'simple' => 'Simple Cake',
                                'bento' => 'Bento Cake',
                                'cupcake' => 'Cupcake',
                            ];
                        @endphp
                        @foreach ($categoriesNav as $catKey => $catLabel)
                            @php
                                $isActive = ($category === $catKey) || ($catKey === '' && empty($category));
                                $targetUrl = route('catalog.index', array_filter(['category' => $catKey ?: null, 'pickup_date' => $date]));
                            @endphp
                            <a href="{{ $targetUrl }}"
                               @if ($isActive) aria-current="page" @endif
                               class="inline-flex items-center justify-center rounded-full px-4 py-2 font-inter text-xs font-semibold transition-all sm:text-sm {{ $isActive ? 'bg-[#726251] text-white shadow-sm' : 'border border-[#D6C6B9] bg-white text-[#726251] hover:bg-[#F7E3DF]/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]' }}">
                                {{ $catLabel }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                {{-- Date Filter Form --}}
                <div>
                    @include('catalog.filters', ['action' => route('catalog.index'), 'showCategory' => true])
                </div>
            </div>
        </section>

        {{-- F. Editorial Product Grid --}}
        <section aria-label="Daftar Produk Kue" class="mx-auto max-w-[1440px] px-5 py-8 sm:px-8 xl:px-16">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @forelse ($products as $card)
                    @php
                        $product = $card['product'];
                        $primaryImg = $product->primaryImage ?? $product->images->first();
                        $cheapestVariant = $product->variants->first();
                    @endphp
                    <article class="group flex flex-col justify-between overflow-hidden rounded-[8px] border border-[#D6C6B9] bg-white p-4 shadow-sm transition-all hover:border-[#726251] hover:shadow-md">
                        <div>
                            {{-- Image Container --}}
                            <a href="{{ route('catalog.show', ['product' => $product->slug, 'pickup_date' => $date]) }}" class="relative block overflow-hidden rounded-[8px] bg-[#F7E3DF] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                                @if ($primaryImg)
                                    <img src="{{ Storage::disk('public')->url($primaryImg->path) }}" alt="{{ $primaryImg->alt_text ?? $product->name }}" loading="lazy" class="aspect-[4/3] w-full rounded-[8px] object-cover transition-transform duration-500 group-hover:scale-[1.03]">
                                @else
                                    <div class="flex aspect-[4/3] w-full flex-col items-center justify-center rounded-[8px] bg-[#F7E3DF] p-4 text-center">
                                        <span class="font-lufga text-2xl font-bold text-[#726251]/40" aria-hidden="true">TB.</span>
                                        <p class="mt-1 font-inter text-xs font-medium text-[#726251]">Foto produk belum tersedia</p>
                                    </div>
                                @endif
                            </a>

                            {{-- Product Meta & Title --}}
                            <div class="mt-4 flex flex-col gap-1.5">
                                <div class="flex items-center justify-between gap-2 text-xs">
                                    <span class="font-semibold uppercase tracking-wider text-[#7B3547]">
                                        {{ match ($product->category) {
                                            \App\Models\ProductCategory::Simple => 'Simple Cake',
                                            \App\Models\ProductCategory::Cupcake => 'Cupcake',
                                            \App\Models\ProductCategory::Bento => 'Bento Cake',
                                            default => 'Cake',
                                        } }}
                                    </span>
                                </div>

                                <h3 class="font-lufga text-lg font-semibold text-[#251306]">
                                    <a href="{{ route('catalog.show', ['product' => $product->slug, 'pickup_date' => $date]) }}" class="hover:text-[#726251] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">
                                        {{ $product->name }}
                                    </a>
                                </h3>

                                {{-- Availability Information --}}
                                <div class="mt-1">
                                    @include('catalog.availability', ['card' => $card])
                                </div>

                                {{-- Variants & Pricing Information --}}
                                <div class="mt-2 border-t border-[#E5D8CC] pt-2">
                                    @include('catalog.variants', ['product' => $product])
                                </div>
                            </div>
                        </div>

                        {{-- Action Link --}}
                        <div class="mt-4 pt-3 border-t border-[#E5D8CC]">
                            <a href="{{ route('catalog.show', ['product' => $product->slug, 'pickup_date' => $date]) }}" class="inline-flex w-full items-center justify-center rounded-[8px] bg-[#726251] px-4 py-2.5 font-inter text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">
                                Lihat Detail Kue
                            </a>
                        </div>
                    </article>
                @empty
                    <div class="col-span-full rounded-2xl border border-dashed border-[#D6C6B9] bg-white p-12 text-center">
                        <p class="font-lufga text-lg font-semibold text-[#251306]">Belum ada produk dalam kategori ini.</p>
                        <p class="mt-2 font-inter text-sm text-[#6F594C]">Silakan pilih kategori lain atau periksa tanggal pengambilan yang berbeda.</p>
                        <a href="{{ route('catalog.index', ['pickup_date' => $date]) }}" class="mt-5 inline-flex items-center justify-center rounded-[8px] bg-[#726251] px-5 py-2.5 font-inter text-sm font-medium text-white transition-colors hover:bg-[#251306]">
                            Tampilkan Semua Kue
                        </a>
                    </div>
                @endforelse
            </div>

            {{-- G. Pagination --}}
            <div class="mt-10">
                {{ $products->links() }}
            </div>
        </section>

    </div>
</x-landing-layout>
