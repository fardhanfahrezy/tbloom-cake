@props([
    'title',
    'heading',
    'mobileHeading' => null,
    'headingTone' => 'ink',
    'mobileHeadingSize' => 'lg',
    'showStatus' => true,
])
@php
    $headingClasses = $headingTone === 'neutral'
        ? 'text-[#424242]'
        : 'text-[#38271f] [text-shadow:2px_4px_8px_rgba(252,226,206,0.2)]';
    $mobileTitleClasses = $mobileHeadingSize === 'md'
        ? 'max-w-[273px] text-[38px] sm:text-[48px] leading-[1.15]'
        : 'text-[52px] sm:text-[64px] leading-none';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | TBloom Cake</title>
    <link rel="icon" href="{{ asset('favicon.PNG') }}" type="image/png">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#fef6ef] font-rubik text-[#38271f] antialiased flex flex-col">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-[#38271f] focus:px-5 focus:py-2.5 focus:text-sm focus:font-medium focus:text-[#fef6ef]">Lewati ke konten</a>

    <header class="lg:hidden">
        <div class="relative flex h-[315px] w-full flex-col items-center justify-start overflow-hidden rounded-b-[200px] bg-[#726251] px-6 pt-[68px] text-center">
            <p aria-hidden="true" class="font-lufga font-bold text-[#fef6ef] {{ $mobileTitleClasses }}">
                {{ $mobileHeading ?? $heading }}
            </p>
            <a href="{{ route('home') }}" aria-label="Beranda TBloom Cake" class="mt-auto mb-[58px] inline-flex items-center justify-center rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#fef6ef]">
                <img src="{{ asset('icon.PNG') }}" width="1475" height="568" alt="TBloom Cake" class="h-[37px] w-[95px] object-contain">
            </a>
        </div>
    </header>

    <div class="mx-auto flex w-full max-w-[1728px] flex-1 flex-col items-center justify-between px-6 sm:px-10 lg:flex-row lg:items-stretch lg:gap-12 lg:px-[100px] xl:px-[183px]">
        <main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-[360px] flex-1 flex-col justify-center pt-[44px] pb-[48px] focus:outline-none sm:max-w-[440px] lg:mx-0 lg:max-w-[540px] lg:py-[72px]">
            <nav aria-label="Navigasi toko" class="mb-6 flex items-center justify-between text-xs font-medium text-[#6f594c] lg:mb-8">
                <a href="{{ route('home') }}" class="hover:text-[#38271f] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">Beranda</a>
                <a href="{{ route('catalog.index') }}" class="hover:text-[#38271f] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">Katalog</a>
            </nav>

            <h1 class="sr-only lg:not-sr-only lg:mb-[52px] text-center font-rubik text-[48px] font-bold leading-[1.1] {{ $headingClasses }}">
                {{ $heading }}
            </h1>

            @if ($showStatus && session('status'))
                <p role="status" class="mb-6 rounded-2xl border border-[#726251]/30 bg-[#f7e3df] px-5 py-3.5 text-center font-rubik text-sm font-medium text-[#38271f] lg:text-base">
                    {{ session('status') }}
                </p>
            @endif

            {{ $slot }}
        </main>

        <aside aria-label="Identitas brand TBloom Cake" class="hidden lg:flex lg:w-[460px] xl:w-[550px] shrink-0 flex-col justify-end pt-[64px]">
            <div class="flex min-h-[760px] w-full flex-col items-center justify-start overflow-hidden rounded-t-[394px] bg-[#726251] px-6 pt-[200px] xl:min-h-[939px] xl:pt-[263px]">
                <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#fef6ef]">
                    <img src="{{ asset('icon.PNG') }}" width="1475" height="568" alt="TBloom Cake" class="h-auto w-[460px] max-w-full object-contain xl:w-[538px]">
                </a>
            </div>
        </aside>
    </div>
</body>
</html>
