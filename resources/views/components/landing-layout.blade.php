@props(['title' => 'Beranda', 'description' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'testimonials' => null])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <meta name="theme-color" content="#FAF5EF">
    <link rel="canonical" href="{{ route('home') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }} | TBloom Cake">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ route('home') }}">
    <title>{{ $title }} | TBloom Cake</title>
    <link rel="icon" href="{{ asset('favicon.PNG') }}" type="image/png">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-[#FAF5EF] font-rubik text-[#38271F] antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-[#251306] focus:px-4 focus:py-3 focus:text-sm focus:font-semibold focus:text-white">Lewati ke konten</a>
    @include('components.landing.header', ['testimonials' => $testimonials ?? collect()])
    <main id="main-content" tabindex="-1" class="focus:outline-none">{{ $slot }}</main>
    @include('components.landing.footer')
</body>
</html>
