<x-landing-layout title="Tentang Kami" description="Mengenal TBloom Cake — kreasi kue rumahan handcrafted dengan bahan pilihan dan dedikasi rasa di Indramayu.">
    {{-- Hero Editorial Story Section --}}
    <section aria-labelledby="about-hero-heading" class="overflow-hidden">
        <div class="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-14 sm:px-8 lg:grid-cols-12 lg:gap-16 lg:py-20 xl:px-16">
            <div class="lg:col-span-7">
                <h1 id="about-hero-heading" class="font-lufga text-4xl font-semibold leading-tight tracking-tight text-[#251306] sm:text-5xl lg:text-[52px]">
                    Tentang
                </h1>
                <p class="mt-6 text-lg font-medium text-[#726251]">
                    lorem
                </p>
                <div class="mt-6 space-y-4 text-base leading-8 text-[#6F594C]">
                    <p>
                        TBloom Cake lahir dari kecintaan terhadap seni pembuatan kue yang mengutamakan ketelitian dan keaslian rasa. Kami percaya bahwa setiap perayaan—baik pesta besar, perayaan keluarga, maupun hadiah sederhana untuk orang terkasih—layak mendapatkan kue yang dibuat dengan sepenuh hati.
                    </p>
                    <p>
                        Setiap produk kami diproduksi secara segar sesuai pesanan (<em>freshly made-to-order</em>). Kami menjaga kualitas bahan tanpa kompromi, mengutamakan kelembutan tekstur spons, serta keseimbangan rasa manis yang pas.
                    </p>
                </div>
            </div>

            <div class="lg:col-span-5">
                <div class="relative mx-auto w-full max-w-[508px] overflow-hidden rounded-[8px] bg-[#FAF5EF] shadow-sm">
                    <img src="{{ asset('assets/about/pexels-gustavo-fring-7447275.jpg') }}" alt="Studio dan dapur pembuatan kue TBloom Cake" width="508" height="657" class="h-[460px] w-full rounded-[8px] object-cover sm:h-[540px] lg:h-[620px]">
                </div>
            </div>
        </div>
    </section>

    {{-- Contact & Store Location Section (Inspired by Figma Frame Contact Me) --}}
    <section id="contact" aria-labelledby="contact-heading" class="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:py-24 xl:px-16">
        <div class="grid gap-12 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <h2 id="contact-heading" class="mt-3 font-lufga text-3xl font-semibold tracking-tight text-[#251306] sm:text-4xl">
                    Informasi Toko &amp; Kontak
                </h2>

                <div class="mt-8 space-y-6">
                    <div class="border-t border-[#D6C6B9] pt-4">
                        <h3 class="font-lufga text-base font-semibold text-[#251306]">Lokasi Toko</h3>
                        <address class="mt-2 text-sm not-italic leading-6 text-[#6F594C]">
                            {{ config('store.pickup_address') }}
                        </address>
                    </div>

                    <div class="border-t border-[#D6C6B9] pt-4">
                        <h3 class="font-lufga text-base font-semibold text-[#251306]">Nomor Kontak &amp; WhatsApp</h3>
                        <p class="mt-2 text-sm leading-6 text-[#6F594C]">
                            <a href="tel:{{ config('store.contact_international') }}" class="font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                                {{ config('store.contact_display') }}
                            </a>
                        </p>
                    </div>

                    <div class="border-t border-[#D6C6B9] pt-4">
                        <h3 class="font-lufga text-base font-semibold text-[#251306]">Jam Operasional</h3>
                        <dl class="mt-2 space-y-2 text-sm leading-6 text-[#6F594C]">
                            <div class="flex justify-between gap-4">
                                <dt class="font-medium text-[#251306]">Produksi / Pemrosesan</dt>
                                <dd>09.00–17.00 WIB</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="font-medium text-[#251306]">Penyerahan Cake di Toko</dt>
                                <dd>09.00–21.00 WIB</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="font-medium text-[#251306]">Pemesanan Online</dt>
                                <dd>24 Jam</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7">
                <div class="rounded-2xl border border-[#D6C6B9] bg-[#FFFCF8] p-8 shadow-sm sm:p-10">
                    <h3 class="font-lufga text-2xl font-semibold text-[#251306]">
                        Konsultasi &amp; Tanya Pesanan
                    </h3>
                    <p class="mt-2 text-sm leading-6 text-[#6F594C]">
                        Untuk pertanyaan cepat mengenai pesanan aktif, panduan kurir, atau konsultasi ide custom cake, silakan hubungi kami langsung via WhatsApp atau buat pengajuan custom cake terstruktur.
                    </p>

                    <div class="mt-8 space-y-5">
                        <div class="rounded-xl border border-[#E5D8CC] bg-[#FAF5EF] p-5">
                            <h4 class="font-semibold text-[#251306]">Punya Konsep Desain Custom Sendiri?</h4>
                            <p class="mt-1 text-sm text-[#6F594C]">
                                Anda dapat mengunggah gambar referensi dan menentukan tanggal pengambilan melalui formulir request kami.
                            </p>
                            @if (!auth()->check() || auth()->user()->role === \App\Models\UserRole::Customer)
                                <a href="{{ route('custom-requests.create') }}" class="mt-4 inline-flex items-center text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                                    Isi Formulir Request Custom Cake <span aria-hidden="true" class="ml-1">↗</span>
                                </a>
                            @else
                                <a href="{{ route('catalog.index') }}" class="mt-4 inline-flex items-center text-sm font-semibold text-[#726251] underline decoration-[#C3B59C] underline-offset-4 hover:decoration-[#726251]">
                                    Lihat Katalog Kue <span aria-hidden="true" class="ml-1">↗</span>
                                </a>
                            @endif
                        </div>

                        <div class="rounded-xl border border-[#E5D8CC] bg-[#FAF5EF] p-5">
                            <h4 class="font-semibold text-[#251306]">Chat WhatsApp Resmi</h4>
                            <p class="mt-1 text-sm text-[#6F594C]">
                                Butuh informasi cepat atau ingin mengirim bukti pembayaran DP custom cake?
                            </p>
                            <a href="https://wa.me/{{ ltrim(config('store.contact_international'), '+') }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex min-h-12 items-center justify-center rounded-full bg-[#726251] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251]">
                                Chat via WhatsApp: {{ config('store.contact_display') }} <span aria-hidden="true" class="ml-2">↗</span>
                            </a>
                        </div>

                        <div class="pt-2 text-xs text-[#6F594C]">
                            <p>Customer memesan dan membayar kurir Gojek atau Grab sendiri untuk mengambil cake di toko.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-landing-layout>
