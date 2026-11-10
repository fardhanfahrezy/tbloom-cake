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
        ? 'text-auth-heading'
        : 'text-auth-ink [text-shadow:2px_4px_8px_rgba(252,226,206,0.2)]';
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
<body class="min-h-screen bg-auth-canvas font-body text-auth-ink antialiased flex flex-col justify-center overflow-x-hidden">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-auth-ink focus:px-5 focus:py-2.5 focus:text-sm focus:font-medium focus:text-auth-canvas">Lewati ke konten</a>
    <a href="{{ route('catalog.index') }}" class="sr-only focus:not-sr-only focus:fixed focus:left-48 focus:top-4 focus:z-50 focus:rounded-full focus:bg-auth-ink focus:px-5 focus:py-2.5 focus:text-sm focus:font-medium focus:text-auth-canvas">Katalog</a>

    <header class="lg:hidden">
        <div class="relative flex h-[315px] w-full flex-col items-center justify-start overflow-hidden rounded-b-[200px] bg-auth-panel px-6 pt-[68px] text-center">
            <p aria-hidden="true" class="font-display font-bold text-auth-canvas {{ $mobileTitleClasses }}">
                {{ $mobileHeading ?? $heading }}
            </p>
            <a href="{{ route('home') }}" aria-label="Beranda TBloom Cake" class="mt-auto mb-[58px] inline-flex items-center justify-center rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-canvas">
                <img src="{{ asset('icon.PNG') }}" width="1475" height="568" alt="TBloom Cake" class="h-[37px] w-[95px] object-contain">
            </a>
        </div>
    </header>

    <div class="mx-auto flex w-full max-w-[1440px] flex-1 flex-col items-center justify-center px-6 sm:px-10 lg:flex-row lg:items-center lg:justify-between lg:gap-16 xl:gap-24 lg:px-16 xl:px-20">
        <main id="main-content" tabindex="-1" class="mx-auto flex w-full max-w-[360px] flex-1 flex-col justify-center py-8 focus:outline-none sm:max-w-[440px] md:max-w-[480px] lg:mx-0 lg:max-w-[520px] lg:w-[520px] lg:py-12">

            <h1 class="sr-only lg:not-sr-only lg:mb-8 text-center font-heading text-[32px] lg:text-[40px] font-bold leading-[1.15] {{ $headingClasses }}">
                {{ $heading }}
            </h1>

            @if ($showStatus && session('status'))
                <p role="status" class="mb-6 rounded-2xl border border-auth-panel/30 bg-auth-blush px-5 py-3.5 text-center font-body text-sm font-medium text-auth-ink lg:text-base">
                    {{ session('status') }}
                </p>
            @endif

            {{ $slot }}
        </main>

        <aside aria-label="Identitas brand TBloom Cake" class="hidden lg:flex lg:w-[480px] xl:w-[520px] shrink-0 flex-col justify-end self-end pt-8">
            <div class="flex h-[760px] xl:h-[820px] w-full flex-col items-center justify-start overflow-hidden rounded-t-[300px] bg-auth-panel px-6 pt-[160px] xl:pt-[190px]">
                <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-canvas">
                    <img src="{{ asset('icon.PNG') }}" width="1475" height="568" alt="TBloom Cake" class="h-auto w-[360px] max-w-full object-contain xl:w-[420px]">
                </a>
            </div>
        </aside>
    </div>
</body>
</html>
