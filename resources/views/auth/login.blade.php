<x-account-layout title="Masuk">
    <p class="mb-6 text-sm text-brand-taupe dark:text-stone-300">Masuk untuk mengakses akun Anda.</p>
    <form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
        @csrf
        <x-form-field name="email" label="Email" type="email" autocomplete="username" required maxlength="254" />
        <x-form-field name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="flex items-center gap-3 text-sm"><input type="checkbox" name="remember" value="1" class="size-4 accent-brand-burgundy"> Ingat saya di perangkat ini</label>
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">Masuk</button>
    </form>
    @if (Route::has('password.request'))
        <a href="{{ route('password.request') }}" class="mt-6 inline-block text-sm underline underline-offset-4">Lupa password?</a>
    @endif
</x-account-layout>
