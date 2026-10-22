@props(['title'])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | TBloom Cake</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-pink font-sans text-brand-dark antialiased dark:bg-brand-dark dark:text-stone-100">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:rounded focus:bg-white focus:p-4 focus:text-brand-dark">Lewati ke konten</a>
    <header class="border-b border-brand-beige bg-white dark:border-stone-800 dark:bg-brand-brown">
        <nav aria-label="Navigasi utama" class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-5">
            <a href="{{ route('home') }}" class="text-xl font-semibold tracking-tight text-brand-burgundy focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-brand-pink">TBloom Cake</a>
            <div class="flex flex-wrap items-center gap-5 text-sm">
                @auth
                    @if (Route::has('profile.edit'))
                        <a href="{{ route('profile.edit') }}" class="underline underline-offset-4">Profil saya</a>
                    @endif
                    @if (Route::has('logout'))
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="underline underline-offset-4">Keluar</button></form>
                    @endif
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="underline underline-offset-4">Masuk</a>
                    @endif
                    <a href="{{ route('register') }}" class="underline underline-offset-4">Buat akun</a>
                @endauth
            </div>
        </nav>
    </header>
    <main id="main-content" class="mx-auto max-w-3xl px-5 py-10 sm:px-8 sm:py-16">
        <div class="rounded-2xl border border-brand-beige bg-white p-6 shadow-sm sm:p-10 dark:border-stone-800 dark:bg-brand-brown">
            <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-brand-burgundy dark:text-brand-pink">Akun TBloom</p>
            <h1 class="text-3xl font-semibold tracking-tight">{{ $title }}</h1>
            @if (session('status'))
                <p role="status" class="mt-5 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</p>
            @endif
            <div class="mt-7">{{ $slot }}</div>
        </div>
    </main>
</body>
</html>
