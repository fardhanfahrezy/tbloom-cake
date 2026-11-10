<x-landing-layout title="Beranda TBloom Cake">
    @php
        $heroProduct = $products->first(fn ($product) => $product->images->isNotEmpty());
    @endphp

    <section aria-labelledby="hero-heading" class="overflow-hidden">
        <div class="mx-auto grid min-h-[680px] max-w-[1440px] items-center gap-12 px-5 py-14 sm:px-8 lg:grid-cols-[1.04fr_.96fr] lg:gap-16 lg:py-20 xl:px-16">
            <div class="max-w-2xl">
                <p class="mb-6 inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-[#7B3547]">
                    <span class="h-px w-9 bg-[#7B3547]" aria-hidden="true"></span>
                    TBloom Cake
                </p>
                <h1 id="hero-heading" class="max-w-[12ch] font-lufga text-[clamp(3rem,5.2vw,5rem)] font-semibold leading-[1.04] tracking-[-0.045em] text-[#251306]">
                    Lorem ipsum dolor sit amet.
                </h1>
                <p class="mt-7 max-w-xl text-base leading-8 text-[#6F594C] sm:text-lg">
                    Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer posuere erat a ante venenatis dapibus posuere velit aliquet.
                </p>
                <div class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <a href="{{ route('catalog.index') }}" class="inline-flex min-h-14 items-center justify-center rounded-full bg-[#512323] px-7 py-4 text-sm font-semibold text-white transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Lihat Katalog <span aria-hidden="true" class="ml-3 text-lg">↗</span></a>
                    <a href="{{ route('custom-requests.create') }}" class="inline-flex min-h-14 items-center justify-center rounded-full border border-[#C3B59C] px-7 py-4 text-sm font-semibold text-[#512323] transition-colors hover:bg-[#F7E3DF] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Pesan Custom Cake</a>
                </div>
                <p class="mt-8 text-xs font-medium tracking-wide text-[#6F594C]">SIMPLE CAKE <span class="mx-2 text-[#C3B59C]">/</span> CUPCAKE <span class="mx-2 text-[#C3B59C]">/</span> BENTO CAKE</p>
            </div>

            <div class="relative mx-auto w-full max-w-[590px] lg:ml-auto">
                <div class="absolute -right-4 top-10 h-[78%] w-[78%] rounded-t-[15rem] border border-[#C3B59C] sm:-right-7 sm:top-12" aria-hidden="true"></div>
                <div class="relative min-h-[390px] overflow-hidden rounded-t-[15rem] bg-[#726251] sm:min-h-[520px]">
                    @if ($heroProduct && $heroProduct->images->first())
                        <img src="{{ Storage::disk('public')->url($heroProduct->images->first()->path) }}" alt="{{ $heroProduct->images->first()->alt_text }}" width="900" height="1100" fetchpriority="high" class="absolute inset-0 h-full w-full object-cover">
                        <div class="absolute inset-0 bg-[#251306]/10" aria-hidden="true"></div>
                    @else
                        <div class="absolute inset-0 flex flex-col items-center justify-center px-8 text-center text-[#FAF5EF]">
                            <span class="font-lufga text-[clamp(5rem,12vw,9rem)] font-semibold leading-none tracking-[-0.08em]" aria-hidden="true">TB.</span>
                            <p class="mt-5 max-w-xs text-sm leading-7 text-[#F7E3DF]">Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
                        </div>
                    @endif
                    <div class="absolute bottom-5 left-5 right-5 flex items-end justify-between gap-4 border-t border-white/50 pt-4 text-white sm:bottom-7 sm:left-7 sm:right-7">
                        <span class="font-lufga text-lg">TBloom Cake</span>
                        <span class="text-[10px] font-semibold uppercase tracking-[0.18em]">Made for moments</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="categories" aria-labelledby="categories-heading" class="border-y border-[#E5D8CC] bg-[#FFFCF8]">
        <div class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-16">
            <div class="mb-10 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">Explore our collection</p>
                    <h2 id="categories-heading" class="mt-3 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">Temukan Pilihan Kue</h2>
                </div>
                <a href="{{ route('catalog.index') }}" class="text-sm font-semibold text-[#512323] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#512323]">Semua produk <span aria-hidden="true">↗</span></a>
            </div>

            <div class="grid gap-5 md:grid-cols-3">
                @foreach ($categories as $category)
                    <a href="{{ route('catalog.index', ['category' => $category['value']]) }}" class="group block focus-visible:rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">
                        <div class="relative aspect-[5/4] overflow-hidden rounded-t-[9rem] bg-[#F7E3DF]">
                            @if ($category['product'] && $category['product']->images->first())
                                <img src="{{ Storage::disk('public')->url($category['product']->images->first()->path) }}" alt="{{ $category['product']->images->first()->alt_text }}" loading="lazy" width="640" height="512" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
                            @else
                                <div class="flex h-full items-center justify-center px-6 text-center font-lufga text-3xl font-medium text-[#726251]">{{ $category['name'] }}</div>
                            @endif
                        </div>
                        <div class="flex items-start justify-between gap-4 border-b border-[#D6C6B9] py-5">
                            <div>
                                <h3 class="font-lufga text-2xl font-semibold text-[#251306]">{{ $category['name'] }}</h3>
                                <p class="mt-2 max-w-xs text-sm leading-6 text-[#6F594C]">{{ $category['description'] }}</p>
                            </div>
                            <span aria-hidden="true" class="mt-1 text-xl text-[#7B3547] transition-transform group-hover:translate-x-1">↗</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <section aria-labelledby="products-heading" class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-16">
        <div class="mb-10 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">A little something special</p>
                <h2 id="products-heading" class="mt-3 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">Pilihan dari TBloom</h2>
                <p class="mt-3 max-w-xl text-sm leading-7 text-[#6F594C]">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Donec sed odio dui.</p>
            </div>
            <a href="{{ route('catalog.index') }}" class="text-sm font-semibold text-[#512323] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#512323]">Jelajahi katalog <span aria-hidden="true">↗</span></a>
        </div>

        @if ($products->isNotEmpty())
            <div class="grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($products->take(6) as $product)
                    @php
                        $productImage = $product->images->first();
                        $startingVariant = $product->variants->first();
                    @endphp
                    <article class="group min-w-0">
                        <a href="{{ route('catalog.show', $product->slug) }}" class="block overflow-hidden rounded-xl bg-[#F7E3DF] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">
                            @if ($productImage)
                                <img src="{{ Storage::disk('public')->url($productImage->path) }}" alt="{{ $productImage->alt_text }}" width="640" height="480" loading="lazy" class="aspect-[4/3] w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
                            @else
                                <div class="flex aspect-[4/3] items-center justify-center px-6 text-center font-lufga text-2xl text-[#726251]">{{ $product->name }}</div>
                            @endif
                        </a>
                        <div class="flex items-start justify-between gap-4 pt-4">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#7B3547]">{{ match ($product->category) { \App\Models\ProductCategory::Simple => 'Simple Cake', \App\Models\ProductCategory::Cupcake => 'Cupcake', \App\Models\ProductCategory::Bento => 'Bento Cake' } }}</p>
                                <h3 class="mt-2 font-lufga text-xl font-semibold text-[#251306]"><a href="{{ route('catalog.show', $product->slug) }}" class="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#512323]">{{ $product->name }}</a></h3>
                                <p class="mt-2 text-sm text-[#6F594C]">{{ $product->sales_unit_label }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[10px] uppercase tracking-wide text-[#6F594C]">Mulai dari</p>
                                <p class="mt-1 text-sm font-semibold text-[#512323]">Rp{{ number_format($startingVariant->price, 0, ',', '.') }}</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="border-y border-[#D6C6B9] py-12 text-center">
                <p class="font-lufga text-2xl font-medium text-[#251306]">Koleksi sedang disiapkan.</p>
                <p class="mt-2 text-sm text-[#6F594C]">Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
            </div>
        @endif
    </section>

    <section aria-labelledby="custom-heading" class="bg-[#726251] text-[#FAF5EF]">
        <div class="mx-auto grid max-w-[1440px] items-center gap-10 px-5 py-16 sm:px-8 lg:grid-cols-[1fr_.8fr] lg:gap-20 lg:py-24 xl:px-16">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#F7E3DF]">Made just for you</p>
                <h2 id="custom-heading" class="mt-4 max-w-[13ch] font-lufga text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Custom Cake untuk momen istimewa.</h2>
                <p class="mt-6 max-w-xl text-base leading-8 text-[#F7E3DF]">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aenean lacinia bibendum nulla sed consectetur.</p>
                <p class="mt-5 text-sm leading-7 text-[#FAF5EF]">Request desain standar memerlukan H-3. Untuk edible print, pengajuan dilakukan minimal H-7 sebelum tanggal pengambilan.</p>
                <a href="{{ route('custom-requests.create') }}" class="mt-8 inline-flex min-h-14 items-center justify-center rounded-full bg-[#F7E3DF] px-7 py-4 text-sm font-semibold text-[#512323] transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">Ajukan Custom Cake <span aria-hidden="true" class="ml-3 text-lg">↗</span></a>
            </div>
            <div class="relative flex min-h-[320px] items-center justify-center overflow-hidden rounded-t-[13rem] border border-[#C3B59C]/70 bg-[#512323] px-8 py-12 text-center sm:min-h-[420px]">
                <div class="absolute inset-x-8 top-8 h-px bg-[#C3B59C]/50" aria-hidden="true"></div>
                <div>
                    <span class="font-lufga text-7xl font-semibold tracking-[-0.08em] text-[#F7E3DF] sm:text-8xl" aria-hidden="true">TB.</span>
                    <p class="mx-auto mt-5 max-w-xs text-sm leading-7 text-[#F7E3DF]">Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
                </div>
                <div class="absolute inset-x-8 bottom-8 h-px bg-[#C3B59C]/50" aria-hidden="true"></div>
            </div>
        </div>
    </section>

    <section id="how-to-order" aria-labelledby="how-to-order-heading" class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-16">
        <div class="max-w-2xl">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">Simple steps</p>
            <h2 id="how-to-order-heading" class="mt-3 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">Cara Pemesanan</h2>
            <p class="mt-4 text-sm leading-7 text-[#6F594C]">Pilih alur yang sesuai dengan kue yang ingin Anda pesan.</p>
        </div>
        <div class="mt-12 grid gap-12 lg:grid-cols-2 lg:gap-16">
            <div>
                <div class="flex items-center justify-between border-b border-[#C3B59C] pb-4">
                    <h3 class="font-lufga text-2xl font-semibold text-[#251306]">Simple Cake</h3>
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-[#7B3547]">01 — 04</span>
                </div>
                <ol class="mt-6 space-y-6">
                    @foreach (['Pilih produk, varian, dan tanggal pengambilan di katalog.', 'Masuk atau buat akun untuk melanjutkan pemesanan.', 'Periksa detail pesanan dan lakukan pembayaran penuh melalui gateway.', 'Ambil pesanan di toko atau minta kurir online mengambilnya.'] as $step => $label)
                        <li class="flex gap-5">
                            <span class="font-lufga text-2xl text-[#A28D79]">0{{ $step + 1 }}</span>
                            <p class="pt-1 text-sm leading-7 text-[#6F594C]">{{ $label }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
            <div>
                <div class="flex items-center justify-between border-b border-[#C3B59C] pb-4">
                    <h3 class="font-lufga text-2xl font-semibold text-[#251306]">Custom Cake</h3>
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-[#7B3547]">01 — 04</span>
                </div>
                <ol class="mt-6 space-y-6">
                    @foreach (['Masuk atau buat akun customer.', 'Isi detail desain, tanggal pengambilan, dan unggah gambar referensi.', 'Kirim request agar dapat ditinjau oleh Admin.', 'Lanjutkan pembahasan desain, harga, dan DP melalui WhatsApp.'] as $step => $label)
                        <li class="flex gap-5">
                            <span class="font-lufga text-2xl text-[#A28D79]">0{{ $step + 1 }}</span>
                            <p class="pt-1 text-sm leading-7 text-[#6F594C]">{{ $label }}</p>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-6 border-l-2 border-[#7B3547] pl-4 text-xs leading-6 text-[#6F594C]">Custom Cake merupakan request. Negosiasi dan DP 50% dilakukan di luar sistem melalui WhatsApp.</p>
            </div>
        </div>
        <a href="{{ route('pickup.show') }}" class="mt-10 inline-flex text-sm font-semibold text-[#512323] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#512323]">Baca kebijakan pemesanan dan pengambilan <span aria-hidden="true" class="ml-2">↗</span></a>
    </section>

    @if ($testimonials->isNotEmpty())
        <section id="testimonials" aria-labelledby="testimonials-heading" class="border-y border-[#E5D8CC] bg-[#FFFCF8]">
            <div class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-16">
                <div class="mb-10 max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">Kind words</p>
                    <h2 id="testimonials-heading" class="mt-3 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">Cerita dari Pelanggan</h2>
                </div>
                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($testimonials as $testimonial)
                        <figure class="flex h-full flex-col border-t border-[#C3B59C] pt-6">
                            @if ($testimonial->rating !== null)
                                <p class="text-sm tracking-[0.18em] text-[#7B3547]" aria-label="Rating {{ $testimonial->rating }} dari 5">{{ str_repeat('★', $testimonial->rating) }}<span class="sr-only"> {{ $testimonial->rating }} dari 5</span></p>
                            @endif
                            <blockquote class="mt-5 flex-1 font-lufga text-lg leading-8 text-[#38271F]">“{{ $testimonial->content }}”</blockquote>
                            <figcaption class="mt-6 flex items-center gap-3 border-t border-[#E5D8CC] pt-4">
                                @if ($testimonial->photo_path)
                                    <img src="{{ asset($testimonial->photo_path) }}" alt="Foto {{ $testimonial->display_name }}" width="48" height="48" loading="lazy" class="h-12 w-12 rounded-full object-cover">
                                @else
                                    <span aria-hidden="true" class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7E3DF] font-lufga text-lg text-[#512323]">{{ mb_strtoupper(mb_substr($testimonial->display_name, 0, 1)) }}</span>
                                @endif
                                <span class="text-sm font-semibold text-[#512323]">{{ $testimonial->display_name }}</span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section id="store-information" aria-labelledby="store-heading" class="mx-auto grid max-w-[1440px] gap-10 px-5 py-20 sm:px-8 lg:grid-cols-[.9fr_1.1fr] lg:gap-20 lg:py-24 xl:px-16">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#7B3547]">Find us</p>
            <h2 id="store-heading" class="mt-3 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">Kunjungi TBloom</h2>
            <p class="mt-5 max-w-md text-sm leading-7 text-[#6F594C]">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed posuere consectetur est at lobortis.</p>
            <a href="{{ route('pickup.show') }}" class="mt-7 inline-flex text-sm font-semibold text-[#512323] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#512323]">Informasi pengambilan <span aria-hidden="true" class="ml-2">↗</span></a>
        </div>
        <div class="grid gap-8 border-t border-[#C3B59C] pt-6 sm:grid-cols-2">
            <div>
                <h3 class="font-lufga text-xl font-semibold text-[#251306]">Alamat</h3>
                <address class="mt-3 text-sm not-italic leading-7 text-[#6F594C]">{{ config('store.pickup_address') }}</address>
                <p class="mt-3 text-sm"><a href="tel:{{ config('store.contact_international') }}" class="font-semibold text-[#512323] underline underline-offset-4">{{ config('store.contact_display') }}</a></p>
            </div>
            <div>
                <h3 class="font-lufga text-xl font-semibold text-[#251306]">Jam Operasional</h3>
                <dl class="mt-3 space-y-3 text-sm leading-6 text-[#6F594C]">
                    <div><dt class="font-semibold text-[#38271F]">Produksi / pemrosesan</dt><dd>09.00–17.00 WIB</dd></div>
                    <div><dt class="font-semibold text-[#38271F]">Penyerahan cake</dt><dd>09.00–21.00 WIB</dd></div>
                    <div><dt class="font-semibold text-[#38271F]">Pemesanan online</dt><dd>24 jam</dd></div>
                </dl>
            </div>
        </div>
    </section>
</x-landing-layout>
