@props(['title'])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | TBloom Cake</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased dark:bg-stone-950 dark:text-stone-100">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:rounded focus:bg-white focus:p-4 focus:text-rose-900">Lewati ke konten</a>
    <header class="border-b border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
        <nav aria-label="Navigasi utama" class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-5">
            <a href="{{ route('home') }}" class="text-xl font-semibold tracking-tight text-rose-800 focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-rose-200">TBloom Cake</a>
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
        <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:p-10 dark:border-stone-800 dark:bg-stone-900">
            <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-rose-800 dark:text-rose-200">Akun TBloom</p>
            <h1 class="text-3xl font-semibold tracking-tight">{{ $title }}</h1>
            @if (session('status'))
                <p role="status" class="mt-5 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</p>
            @endif
            <div class="mt-7">{{ $slot }}</div>
        </div>
    </main>
</body>
</html>
