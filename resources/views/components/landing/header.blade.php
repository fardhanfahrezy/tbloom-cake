@props(['testimonials' => collect()])
<header class="sticky top-0 z-50 border-b border-[#D6C6B9] bg-[#FAF5EF]/95 backdrop-blur-sm">
    <div class="mx-auto flex min-h-[76px] max-w-[1440px] items-center justify-between gap-6 px-5 sm:px-8 xl:px-16">
        <a href="{{ route('home') }}" aria-label="TBloom Cake, beranda" class="shrink-0 rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">
            <img src="{{ asset('icon.PNG') }}" width="1475" height="568" alt="TBloom Cake" class="h-auto w-32 sm:w-36">
        </a>
        <nav aria-label="Navigasi utama" class="hidden items-center gap-7 text-sm font-medium lg:flex">
            <a href="{{ route('catalog.index') }}" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Katalog</a>
            <a href="#how-to-order" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Cara Pemesanan</a>
            @if ($testimonials && $testimonials->isNotEmpty())
                <a href="#testimonials" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Testimoni</a>
            @endif
            <a href="#store-information" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Tentang Toko</a>
            @auth
                <a href="{{ route('account.show') }}" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Akun Saya</a>
                @if (in_array(auth()->user()->role, [\App\Models\UserRole::Admin, \App\Models\UserRole::Owner], true))
                    <a href="{{ route('staff.dashboard') }}" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Area Staf</a>
                @endif
            @else
                <a href="{{ route('login') }}" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Masuk</a>
                <a href="{{ route('register') }}" class="hover:text-[#7B3547] focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Daftar</a>
            @endauth
        </nav>
        <div class="hidden items-center gap-3 lg:flex">
            @auth
                @if (auth()->user()->role === \App\Models\UserRole::Customer)
                    <a href="{{ route('custom-requests.create') }}" class="rounded-full bg-[#512323] px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Pesan Custom Cake</a>
                @else
                    <a href="{{ route('catalog.index') }}" class="rounded-full bg-[#512323] px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Lihat Katalog</a>
                @endif
            @else
                <a href="{{ route('custom-requests.create') }}" class="rounded-full bg-[#512323] px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323]">Pesan Custom Cake</a>
            @endauth
        </div>
        <details class="relative lg:hidden" data-mobile-navigation>
            <summary class="flex min-h-11 cursor-pointer list-none items-center rounded-full border border-[#C3B59C] px-4 text-sm font-semibold marker:hidden focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#512323] [&::-webkit-details-marker]:hidden">
                Menu <span aria-hidden="true" class="ml-2 text-lg leading-none">+</span>
            </summary>
            <nav aria-label="Navigasi mobile" class="absolute right-0 top-[calc(100%+12px)] flex w-[min(86vw,340px)] flex-col gap-1 rounded-2xl border border-[#D6C6B9] bg-[#FFFCF8] p-3 text-sm shadow-xl">
                <a href="{{ route('catalog.index') }}" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Katalog</a>
                <a href="#how-to-order" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Cara Pemesanan</a>
                @if ($testimonials && $testimonials->isNotEmpty())
                    <a href="#testimonials" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Testimoni</a>
                @endif
                <a href="#store-information" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Tentang Toko</a>
                @auth
                    <a href="{{ route('account.show') }}" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Akun Saya</a>
                    @if (in_array(auth()->user()->role, [\App\Models\UserRole::Admin, \App\Models\UserRole::Owner], true))
                        <a href="{{ route('staff.dashboard') }}" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Area Staf</a>
                    @endif
                    @if (auth()->user()->role === \App\Models\UserRole::Customer)
                        <a href="{{ route('custom-requests.create') }}" class="rounded-lg px-4 py-3 font-semibold text-[#512323] hover:bg-[#F7E3DF]">Pesan Custom Cake</a>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Masuk</a>
                    <a href="{{ route('register') }}" class="rounded-lg px-4 py-3 hover:bg-[#F7E3DF]">Daftar Akun</a>
                    <a href="{{ route('custom-requests.create') }}" class="mt-1 rounded-full bg-[#512323] px-4 py-3 text-center font-semibold text-white">Pesan Custom Cake</a>
                @endauth
            </nav>
        </details>
    </div>
</header>
