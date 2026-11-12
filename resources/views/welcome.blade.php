<x-landing-layout title="Beranda TBloom Cake" :testimonials="$testimonials">
    @php
        $heroProduct = $heroProduct ?? $products->first(fn ($product) => $product->primaryImage !== null);
        $simpleCategory = $categories->firstWhere('value', 'simple');
        $cupcakeCategory = $categories->firstWhere('value', 'cupcake');
        $bentoCategory = $categories->firstWhere('value', 'bento');

        $simpleProduct = $simpleCategory['product'] ?? $products->firstWhere('category', \App\Models\ProductCategory::Simple) ?? $heroProduct;
        $customFeaturedProduct = $bentoCategory['product'] ?? $cupcakeCategory['product'] ?? $products->skip(1)->first() ?? $heroProduct;

        $featuredMain = $products->first();
        $featuredSecondaryOne = $products->skip(1)->first();
        $featuredSecondaryTwo = $products->skip(2)->first();
    @endphp

    {{-- Figma Frame 1:3665 - Header Hero with video background --}}
    <section aria-labelledby="hero-heading" class="relative overflow-hidden bg-[#251306] text-white">
        <video autoplay loop muted playsinline class="absolute inset-0 h-full w-full object-cover">
            <source src="{{ asset('assets/welcome/5703307-uhd_4096_2160_30fps.mp4') }}" type="video/mp4">
            <source src="{{ url('resources/img/welcome/5703307-uhd_4096_2160_30fps.mp4') }}" type="video/mp4">
            @if ($heroProduct && $heroProduct->primaryImage)
                <img src="{{ Storage::disk('public')->url($heroProduct->primaryImage->path) }}" alt="{{ $heroProduct->primaryImage->alt_text ?? 'Koleksi TBloom Cake' }}" width="1440" height="720" fetchpriority="high" class="absolute inset-0 h-full w-full object-cover">
            @endif
        </video>
        <div class="absolute inset-0 bg-black/45 backdrop-blur-[1px]" aria-hidden="true"></div>

        <div class="relative mx-auto flex min-h-[640px] max-w-[1440px] flex-col items-center justify-center px-5 py-24 text-center sm:px-8 lg:min-h-[720px] lg:py-32 xl:px-20">
            <div class="max-w-[844px]">
                <h1 id="hero-heading" class="mt-6 font-inter text-4xl font-bold leading-[1.15] tracking-tight text-white sm:text-5xl lg:text-[64px]">
                    Kreasi Kue Penuh Makna
                </h1>
                <p class="mx-auto mt-6 max-w-2xl font-inter text-lg font-medium leading-relaxed text-white/90 sm:text-xl lg:text-[24px]">
                    Pesan kue artisanal berkualitas tinggi untuk momen istimewa Anda di Indramayu.
                </p>
                <div class="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                    @if (!auth()->check() || auth()->user()->role === \App\Models\UserRole::Customer)
                        <a href="{{ route('custom-requests.create') }}" class="inline-flex min-h-14 items-center justify-center rounded-[8px] border border-white/40 bg-black/30 px-8 py-4 font-inter text-base font-medium text-white backdrop-blur-sm transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white sm:text-lg">
                            Pesan Cake
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- Figma Frame 1:3721 & 1:3737 - Feature Split 1 (Copy Left, Image Right) --}}
    <section aria-labelledby="split-simple-heading" class="border-b border-[#E5D8CC] bg-[#FFFCF8]">
        <div class="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-20 sm:px-8 lg:grid-cols-2 lg:gap-16 lg:py-24 xl:px-20">
            <div class="max-w-[516px]">
                <h2 id="split-simple-heading" class="mt-3 font-inter text-3xl font-semibold leading-[1.2] tracking-tight text-[#251306] sm:text-4xl lg:text-[40px]">
                    Simple Cake
                </h2>
                <p class="mt-5 font-inter text-lg leading-relaxed text-[#6F594C] sm:text-xl lg:text-[22px]">
                    Koleksi kue tart lembut dengan hiasan minimalis elegan. Siap dipesan secara online untuk momen ulang tahun, anniversary, dan kumpul keluarga.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="{{ route('catalog.index', ['category' => 'simple']) }}" class="inline-flex items-center justify-center rounded-[8px] bg-[#726251] px-6 py-3.5 font-inter text-base font-medium text-white shadow-sm transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                        Pesan Simple Cake
                    </a>
                    <a href="{{ route('pickup.show').'#policies' }}" class="inline-flex items-center justify-center rounded-[8px] bg-[#E6E6E6] px-6 py-3.5 font-inter text-base font-medium text-[#251306] shadow-sm transition-colors hover:bg-[#D6C6B9] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                        Ketentuan Pengambilan
                    </a>
                </div>
            </div>

            <div class="relative overflow-hidden rounded-[8px] bg-[#F7E3DF] shadow-sm">
                @if ($simpleProduct && $simpleProduct->primaryImage)
                    <img src="{{ Storage::disk('public')->url($simpleProduct->primaryImage->path) }}" alt="{{ $simpleProduct->primaryImage->alt_text ?? 'Koleksi Simple Cake' }}" width="736" height="400" loading="lazy" class="aspect-[16/10] w-full object-cover">
                @else
                    <div class="flex aspect-[16/10] items-center justify-center p-8 text-center font-inter text-2xl font-medium text-[#726251]">
                        Simple Cake TBloom
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- Figma Frame 1:3720 & 1:3729 - Feature Split 2 (Image Left, Copy Right) --}}
    <section aria-labelledby="split-custom-heading" class="border-b border-[#E5D8CC] bg-[#FAF5EF]">
        <div class="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-20 sm:px-8 lg:grid-cols-2 lg:gap-16 lg:py-24 xl:px-20">
            <div class="order-2 overflow-hidden rounded-[8px] bg-[#F7E3DF] shadow-sm lg:order-1">
                @if ($customFeaturedProduct && $customFeaturedProduct->primaryImage)
                    <img src="{{ Storage::disk('public')->url($customFeaturedProduct->primaryImage->path) }}" alt="{{ $customFeaturedProduct->primaryImage->alt_text ?? 'Koleksi Custom Cake TBloom' }}" width="625" height="400" loading="lazy" class="aspect-[16/10] w-full object-cover">
                @else
                    <div class="flex aspect-[16/10] items-center justify-center p-8 text-center font-inter text-2xl font-medium text-[#726251]">
                        Custom Cake Studio
                    </div>
                @endif
            </div>

            <div class="order-1 max-w-[516px] lg:order-2 lg:ml-auto">
                <h2 id="split-custom-heading" class="mt-3 font-inter text-3xl font-semibold leading-[1.2] tracking-tight text-[#251306] sm:text-4xl lg:text-[40px]">
                    Custom Cake Sesuai Keinginan Anda
                </h2>
                <p class="mt-5 font-inter text-lg leading-relaxed text-[#6F594C] sm:text-xl lg:text-[22px]">
                    Wujudkan dekorasi kue impian mulai dari konsep desain unik hingga edible print. Konsultasikan detail dan dapatkan penawaran terbaik langsung dari kami.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-4">
                    @if (!auth()->check() || auth()->user()->role === \App\Models\UserRole::Customer)
                        <a href="{{ route('custom-requests.create') }}" class="inline-flex items-center justify-center rounded-[8px] bg-[#726251] px-6 py-3.5 font-inter text-base font-medium text-white shadow-sm transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                            Ajukan Custom Cake
                        </a>
                    @else
                        <a href="{{ route('catalog.index') }}" class="inline-flex items-center justify-center rounded-[8px] bg-[#726251] px-6 py-3.5 font-inter text-base font-medium text-white shadow-sm transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                            Lihat Katalog
                        </a>
                    @endif
                    <a href="tel:{{ config('store.contact_international') }}" class="inline-flex items-center justify-center rounded-[8px] bg-[#E6E6E6] px-6 py-3.5 font-inter text-base font-medium text-[#251306] shadow-sm transition-colors hover:bg-[#D6C6B9] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                        Konsultasi Toko
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Figma Frame 1:3664, 1:3658 & 1:3645 - Featured Products (Big Card Left, Card List Right) --}}
    <section aria-labelledby="featured-heading" class="border-b border-[#E5D8CC] bg-[#FFFCF8]">
        <div class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-20">
            <div class="mb-12 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="featured-heading" class="mt-3 font-inter text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl lg:text-[48px]">
                        Pilihan Favorit TBloom
                    </h2>
                </div>
                <a href="{{ route('catalog.index') }}" class="inline-flex items-center font-inter text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                    Katalog <span aria-hidden="true" class="ml-2">↗</span>
                </a>
            </div>

            @if ($featuredMain)
                <div class="grid gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-12">
                    {{-- Card Big (Left) --}}
                    @php
                        $mainVariant = $featuredMain->variants->first();
                        $mainImage = $featuredMain->primaryImage;
                    @endphp
                    <article class="group flex flex-col rounded-[8px]">
                        <a href="{{ route('catalog.show', $featuredMain->slug) }}" class="relative block overflow-hidden rounded-[8px] bg-[#F7E3DF] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                            @if ($mainImage)
                                <img src="{{ Storage::disk('public')->url($mainImage->path) }}" alt="{{ $mainImage->alt_text ?? $featuredMain->name }}" width="735" height="580" loading="lazy" class="aspect-[4/3] w-full object-cover transition-transform duration-500 group-hover:scale-[1.02]">
                            @else
                                <div class="flex aspect-[4/3] items-center justify-center p-8 text-center font-inter text-3xl font-medium text-[#726251]">
                                    {{ $featuredMain->name }}
                                </div>
                            @endif
                        </a>
                        <div class="mt-6 flex flex-col gap-1">
                            <h3 class="font-inter text-2xl font-medium text-[#251306]">
                                <a href="{{ route('catalog.show', $featuredMain->slug) }}" class="hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">
                                    {{ $featuredMain->name }}
                                </a>
                            </h3>
                            <p class="font-inter text-lg text-[#6F594C]">
                                {{ $featuredMain->sales_unit_label }}
                            </p>
                            @if ($mainVariant)
                                <p class="font-inter text-lg font-medium text-[#726251]">
                                    Mulai dari Rp{{ number_format($mainVariant->price, 0, ',', '.') }}
                                </p>
                            @endif
                        </div>
                    </article>

                    {{-- Card List (Right - 2 Stacked Cards) --}}
                    <div class="flex flex-col justify-between gap-10">
                        @foreach ([$featuredSecondaryOne, $featuredSecondaryTwo] as $secondaryProduct)
                            @if ($secondaryProduct)
                                @php
                                    $secVariant = $secondaryProduct->variants->first();
                                    $secImage = $secondaryProduct->primaryImage;
                                @endphp
                                <article class="group flex flex-col rounded-[8px]">
                                    <a href="{{ route('catalog.show', $secondaryProduct->slug) }}" class="relative block overflow-hidden rounded-[8px] bg-[#F7E3DF] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                                        @if ($secImage)
                                            <img src="{{ Storage::disk('public')->url($secImage->path) }}" alt="{{ $secImage->alt_text ?? $secondaryProduct->name }}" width="515" height="284" loading="lazy" class="aspect-[16/9] w-full object-cover transition-transform duration-500 group-hover:scale-[1.02]">
                                        @else
                                            <div class="flex aspect-[16/9] items-center justify-center p-6 text-center font-inter text-2xl font-medium text-[#726251]">
                                                {{ $secondaryProduct->name }}
                                            </div>
                                        @endif
                                    </a>
                                    <div class="mt-4 flex flex-col gap-1">
                                        <h3 class="font-inter text-xl font-medium text-[#251306]">
                                            <a href="{{ route('catalog.show', $secondaryProduct->slug) }}" class="hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">
                                                {{ $secondaryProduct->name }}
                                            </a>
                                        </h3>
                                        <p class="font-inter text-base text-[#6F594C]">
                                            {{ $secondaryProduct->sales_unit_label }}
                                        </p>
                                        @if ($secVariant)
                                            <p class="font-inter text-base font-medium text-[#726251]">
                                                Mulai dari Rp{{ number_format($secVariant->price, 0, ',', '.') }}
                                            </p>
                                        @endif
                                    </div>
                                </article>
                            @endif
                        @endforeach
                    </div>
                </div>
            @else
                <div class="rounded-[8px] border border-[#E5D8CC] p-12 text-center">
                    <p class="font-inter text-xl text-[#251306]">Katalog produk sedang diperbarui.</p>
                </div>
            @endif
        </div>
    </section>

    {{-- Figma Frame 1:3768, 1:3738 & 1:3755 - Value Proposition / Process (4 Items, 2 Columns) --}}
    <section id="how-to-order" aria-labelledby="policy-heading" class="border-b border-[#E5D8CC] bg-[#FAF5EF]">
        <div class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-20">
            <div class="mb-14 max-w-2xl">
                <h2 id="policy-heading" class="mt-3 font-inter text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl lg:text-[48px]">
                    Komitmen & Ketentuan Layanan
                </h2>
            </div>

            <div class="grid gap-12 lg:grid-cols-2 lg:gap-16">
                {{-- Column 1: Items 01 & 02 --}}
                <div class="flex flex-col gap-12">
                    <div class="flex flex-col items-start">
                        <div class="flex size-8 items-center justify-center rounded-full bg-[#726251] text-xs font-bold text-white">
                            01
                        </div>
                        <h3 class="mt-4 font-inter text-2xl font-medium text-[#251306]">
                            Jadwal Produksi &amp; Cutoff Waktu
                        </h3>
                        <p class="mt-3 font-inter text-lg leading-relaxed text-[#6F594C]">
                            Batas waktu pemesanan Simple Cake dihitung dari waktu pembayaran: transaksi pukul 00.00.00–17.00.00 WIB paling cepat diambil besok (H+1), sedangkan transaksi pukul 17.00.01 WIB–23.59.59 WIB paling cepat diambil lusa (H+2).
                        </p>
                    </div>

                    <div class="flex flex-col items-start">
                        <div class="flex size-8 items-center justify-center rounded-full bg-[#726251] text-xs font-bold text-white">
                            02
                        </div>
                        <h3 class="mt-4 font-inter text-2xl font-medium text-[#251306]">
                            Ketentuan Custom Cake
                        </h3>
                        <p class="mt-3 font-inter text-lg leading-relaxed text-[#6F594C]">
                            Pengajuan pesanan Custom Cake memerlukan waktu pengerjaan H-3 untuk desain standar. Khusus untuk dekorasi edible print, pengajuan wajib dilakukan minimal H-7 sebelum jadwal pengambilan.
                        </p>
                    </div>
                </div>

                {{-- Column 2: Items 03 & 04 --}}
                <div class="flex flex-col gap-12">
                    <div class="flex flex-col items-start">
                        <div class="flex size-8 items-center justify-center rounded-full bg-[#726251] text-xs font-bold text-white">
                            03
                        </div>
                        <h3 class="mt-4 font-inter text-2xl font-medium text-[#251306]">
                            Metode Pengambilan Pesanan
                        </h3>
                        <p class="mt-3 font-inter text-lg leading-relaxed text-[#6F594C]">
                            {{ config('store.courier_instructions') }} Pelanggan dapat mengambil langsung di outlet atau memesan jasa kurir online mandiri pada waktu yang disepakati.
                        </p>
                    </div>

                    <div class="flex flex-col items-start">
                        <div class="flex size-8 items-center justify-center rounded-full bg-[#726251] text-xs font-bold text-white">
                            04
                        </div>
                        <h3 class="mt-4 font-inter text-2xl font-medium text-[#251306]">
                            Jam Operasional &amp; Pemesanan Online
                        </h3>
                        <p class="mt-3 font-inter text-lg leading-relaxed text-[#6F594C]">
                            Produksi 09.00–17.00 WIB, Penyerahan 09.00–21.00 WIB, dan pemesanan online aktif 24 jam. Kunjungi toko kami di {{ config('store.pickup_address') }}.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-12 flex flex-wrap items-center gap-6 border-t border-[#E5D8CC] pt-8">
                <a href="{{ route('pickup.show').'#policies' }}" class="inline-flex items-center font-inter text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                    Baca kebijakan pembatalan &amp; pengambilan selengkapnya <span aria-hidden="true" class="ml-2">↗</span>
                </a>
            </div>
        </div>
    </section>

    {{-- Testimonials Section (Published from Database) --}}
    @if ($testimonials->isNotEmpty())
        <section id="testimonials" aria-labelledby="testimonials-heading" class="border-b border-[#E5D8CC] bg-[#FFFCF8]">
            <div class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-20">
                <div class="mb-12 max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">Ulasan Nyata</p>
                    <h2 id="testimonials-heading" class="mt-3 font-inter text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl lg:text-[48px]">
                        Cerita dari Pelanggan Kami
                    </h2>
                </div>
                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($testimonials as $testimonial)
                        <figure class="flex h-full flex-col rounded-[8px] border border-[#E5D8CC] bg-[#FAF5EF] p-8">
                            @if ($testimonial->rating !== null)
                                <p class="text-sm tracking-[0.18em] text-[#7B3547]" aria-label="Rating {{ $testimonial->rating }} dari 5">
                                    {{ str_repeat('★', $testimonial->rating) }}<span class="sr-only"> {{ $testimonial->rating }} dari 5</span>
                                </p>
                            @endif
                            <blockquote class="mt-4 flex-1 font-inter text-base leading-relaxed text-[#38271F] sm:text-lg">
                                “{{ $testimonial->content }}”
                            </blockquote>
                            <figcaption class="mt-6 flex items-center gap-3 border-t border-[#E5D8CC] pt-4">
                                @if ($testimonial->photo_path)
                                    <img src="{{ asset($testimonial->photo_path) }}" alt="Foto {{ $testimonial->display_name }}" width="44" height="44" loading="lazy" class="h-11 w-11 rounded-full object-cover">
                                @else
                                    <span aria-hidden="true" class="flex h-11 w-11 items-center justify-center rounded-full bg-[#F7E3DF] font-inter text-base font-semibold text-[#726251]">
                                        {{ mb_strtoupper(mb_substr($testimonial->display_name, 0, 1)) }}
                                    </span>
                                @endif
                                <span class="font-inter text-sm font-semibold text-[#726251]">{{ $testimonial->display_name }}</span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Store Information & Direct Contacts --}}
    <section id="store-information" aria-labelledby="store-heading" class="bg-[#FAF5EF]">
        <div class="mx-auto grid max-w-[1440px] gap-10 px-5 py-20 sm:px-8 lg:grid-cols-[1fr_1.1fr] lg:gap-16 lg:py-24 xl:px-20">
            <div>
                <h2 id="store-heading" class="mt-3 font-inter text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">
                    Kunjungi TBloom Cake
                </h2>
                <p class="mt-4 max-w-md font-inter text-base leading-relaxed text-[#6F594C]">
                    Kami menyambut Anda untuk mengambil kue pesanan atau berkonsultasi mengenai kebutuhan perayaan Anda.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-6">
                    <a href="{{ route('about') }}" class="inline-flex font-inter text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                        Tentang TBloom Cake <span aria-hidden="true" class="ml-2">↗</span>
                    </a>
                    <a href="{{ route('pickup.show').'#policies' }}" class="inline-flex font-inter text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                        Informasi Pengambilan Lengkap <span aria-hidden="true" class="ml-2">↗</span>
                    </a>
                </div>
            </div>

            <div class="grid gap-8 border-t border-[#C3B59C] pt-6 sm:grid-cols-2">
                <div>
                    <h3 class="font-inter text-lg font-semibold text-[#251306]">Alamat Pengambilan</h3>
                    <address class="mt-3 font-inter text-sm not-italic leading-relaxed text-[#6F594C]">
                        {{ config('store.pickup_address') }}
                    </address>
                    <p class="mt-3 font-inter text-sm">
                        <a href="tel:{{ config('store.contact_international') }}" class="font-semibold text-[#726251] underline underline-offset-4">
                            {{ config('store.contact_display') }}
                        </a>
                    </p>
                </div>
                <div>
                    <h3 class="font-inter text-lg font-semibold text-[#251306]">Jam Operasional Toko</h3>
                    <dl class="mt-3 space-y-2.5 font-inter text-sm leading-relaxed text-[#6F594C]">
                        <div><dt class="font-semibold text-[#38271F]">Produksi 09.00–17.00 WIB</dt><dd class="text-xs text-[#726251]">Waktu operasional dapur &amp; pemrosesan kue</dd></div>
                        <div><dt class="font-semibold text-[#38271F]">Penyerahan 09.00–21.00 WIB</dt><dd class="text-xs text-[#726251]">Layanan serah terima kue di outlet</dd></div>
                        <div><dt class="font-semibold text-[#38271F]">24 jam</dt><dd class="text-xs text-[#726251]">Pemesanan online melalui website</dd></div>
                    </dl>
                </div>
            </div>
        </div>
    </section>
</x-landing-layout>
