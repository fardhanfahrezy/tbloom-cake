<x-account-layout title="Area staf">
    <p>Selamat datang, {{ auth()->user()->name }}.</p>
    <a href="{{ route('staff.products.index') }}" class="mt-6 inline-block underline underline-offset-4">Kelola produk</a>
    @can('viewAny', \App\Models\User::class)
        <a href="{{ route('staff.accounts.index') }}" class="mt-6 inline-block text-brand-burgundy underline underline-offset-4 dark:text-brand-pink">Kelola akun staf</a>
    @endcan
</x-account-layout>
