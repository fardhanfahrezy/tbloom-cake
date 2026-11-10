<footer class="bg-[#251306] text-[#FAF5EF]">
    <div class="mx-auto grid max-w-[1440px] gap-10 px-5 py-14 sm:px-8 md:grid-cols-[1.2fr_.8fr_.8fr] xl:px-16">
        <div>
            <a href="{{ route('home') }}" class="inline-flex rounded bg-white p-2 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#F7E3DF]">
                <img src="{{ asset('icon.PNG') }}" width="1475" height="568" alt="TBloom Cake" class="h-auto w-36">
            </a>
            <p class="mt-5 max-w-sm text-sm leading-7 text-[#E7D9CD]">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore.</p>
        </div>
        <div>
            <h2 class="font-lufga text-lg font-semibold">Jelajahi</h2>
            <ul class="mt-4 space-y-3 text-sm text-[#E7D9CD]">
                <li><a href="{{ route('catalog.index') }}" class="hover:text-white hover:underline">Katalog</a></li>
                @if (!auth()->check() || auth()->user()->role === \App\Models\UserRole::Customer)
                    <li><a href="{{ route('custom-requests.create') }}" class="hover:text-white hover:underline">Custom Cake</a></li>
                @endif
                <li><a href="{{ route('pickup.show').'#policies' }}" class="hover:text-white hover:underline">Informasi Pengambilan</a></li>
                <li><a href="#how-to-order" class="hover:text-white hover:underline">Cara Pemesanan</a></li>
            </ul>
        </div>
        <div>
            <h2 class="font-lufga text-lg font-semibold">Kunjungi Kami</h2>
            <address class="mt-4 space-y-3 text-sm not-italic leading-6 text-[#E7D9CD]">
                <p>{{ config('store.pickup_address') }}</p>
                <p><a href="tel:{{ config('store.contact_international') }}" class="hover:text-white hover:underline">{{ config('store.contact_display') }}</a></p>
                <p>Produksi 09.00–17.00 WIB<br>Penyerahan 09.00–21.00 WIB</p>
            </address>
        </div>
    </div>
    <div class="border-t border-white/15">
        <div class="mx-auto flex max-w-[1440px] flex-col gap-2 px-5 py-5 text-xs text-[#C3B59C] sm:flex-row sm:items-center sm:justify-between sm:px-8 xl:px-16">
            <p>© {{ now()->year }} TBloom Cake. All rights reserved.</p>
            <p>Pemesanan online tersedia 24 jam.</p>
        </div>
    </div>
</footer>
