<x-account-layout title="Password baru">
    <form method="POST" action="{{ route('password.update') }}" class="grid gap-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-form-field name="email" label="Email" type="email" :value="$email" autocomplete="username" required maxlength="254" />
        <x-form-field name="password" label="Password baru (minimal 12 karakter)" type="password" autocomplete="new-password" required minlength="12" />
        <x-form-field name="password_confirmation" label="Ulangi password baru" type="password" autocomplete="new-password" required />
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">Simpan password baru</button>
    </form>
</x-account-layout>
