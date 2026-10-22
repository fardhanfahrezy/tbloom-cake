<x-account-layout title="Masuk">
    <p class="mb-6 text-sm text-stone-600 dark:text-stone-300">Masuk untuk mengakses akun Anda.</p>
    <form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
        @csrf
        <x-form-field name="email" label="Email" type="email" autocomplete="username" required maxlength="254" />
        <x-form-field name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="flex items-center gap-3 text-sm"><input type="checkbox" name="remember" value="1" class="size-4 accent-rose-800"> Ingat saya di perangkat ini</label>
        <button class="rounded-lg bg-rose-800 px-5 py-3 font-semibold text-white hover:bg-rose-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-rose-800 dark:bg-rose-300 dark:text-stone-950 dark:hover:bg-rose-200">Masuk</button>
    </form>
    @if (Route::has('password.request'))
        <a href="{{ route('password.request') }}" class="mt-6 inline-block text-sm underline underline-offset-4">Lupa password?</a>
    @endif
</x-account-layout>
