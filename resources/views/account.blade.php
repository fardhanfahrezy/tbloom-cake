<x-account-layout title="Akun saya">
    <p class="text-lg">Halo, {{ $user->name }}.</p>
    <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Anda sudah masuk ke akun TBloom Cake.</p>
    @if (Route::has('profile.edit'))
        <a href="{{ route('profile.edit') }}" class="mt-6 inline-block font-medium text-rose-800 underline underline-offset-4 dark:text-rose-200">Kelola profil</a>
    @endif
</x-account-layout>
