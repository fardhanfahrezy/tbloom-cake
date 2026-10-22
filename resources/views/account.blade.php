<x-account-layout title="Akun saya">
    <p class="text-lg">Halo, {{ $user->name }}.</p>
    <p class="mt-3 text-sm text-brand-taupe dark:text-stone-300">Anda sudah masuk ke akun TBloom Cake.</p>
    @if (Route::has('profile.edit'))
        <a href="{{ route('profile.edit') }}" class="mt-6 inline-block font-medium text-brand-burgundy underline underline-offset-4 dark:text-brand-pink">Kelola profil</a>
    @endif
</x-account-layout>
