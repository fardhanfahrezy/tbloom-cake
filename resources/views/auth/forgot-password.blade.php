<x-account-layout title="Lupa password">
    <p class="mb-6 text-sm leading-6 text-brand-taupe dark:text-stone-300">Masukkan email akun Anda untuk meminta tautan pemulihan password.</p>
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-5">
        @csrf
        <x-form-field name="email" label="Email" type="email" autocomplete="email" required maxlength="254" />
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">Minta tautan pemulihan</button>
    </form>
    <a href="{{ route('login') }}" class="mt-6 inline-block text-sm underline underline-offset-4">Kembali ke halaman masuk</a>
</x-account-layout>
