<x-account-layout title="Kelola akun staf">
    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-lg border border-red-500 p-4 text-sm text-red-700 dark:text-red-300">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <section aria-labelledby="new-admin-title">
        <h2 id="new-admin-title" class="mb-5 text-xl font-semibold">Buat akun Admin</h2>
        <form method="POST" action="{{ route('staff.accounts.store') }}" class="grid gap-5">
            @csrf
            <x-form-field name="name" label="Nama Admin" autocomplete="off" required minlength="2" maxlength="100" />
            <x-form-field name="email" label="Email Admin" type="email" autocomplete="off" required maxlength="254" />
            <x-form-field name="whatsapp_number" label="WhatsApp Admin" type="tel" autocomplete="off" required />
            <x-form-field name="password" label="Password awal (minimal 12 karakter)" type="password" autocomplete="new-password" required minlength="12" />
            <x-form-field name="password_confirmation" label="Ulangi password awal" type="password" autocomplete="new-password" required />
            <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">Buat akun Admin</button>
        </form>
    </section>
    <section aria-labelledby="staff-title" class="mt-10 border-t border-brand-beige pt-8">
        <h2 id="staff-title" class="mb-5 text-xl font-semibold">Akun staf</h2>
        <div class="grid gap-5">
            @foreach ($staffAccounts as $staff)
                <article class="rounded-lg border border-brand-beige p-4">
                    <h3 class="font-semibold">{{ $staff->name }}</h3>
                    <p class="mt-1 break-all text-sm">{{ $staff->email }}</p>
                    <p class="mt-2 text-sm">{{ $staff->role->name }} &middot; {{ $staff->is_active ? 'Aktif' : 'Nonaktif' }}</p>
                    @if ($staff->role === \App\Models\UserRole::Owner && $staff->is_active && $activeOwnerCount <= 1)
                        <p class="mt-3 text-sm text-brand-taupe dark:text-brand-pink">Owner aktif terakhir tidak dapat dinonaktifkan.</p>
                    @else
                        <form method="POST" action="{{ route('staff.accounts.update', $staff) }}" class="mt-4 grid gap-3">
                            @csrf @method('PATCH')
                            <input type="hidden" name="is_active" value="{{ $staff->is_active ? '0' : '1' }}">
                            <label for="reason-{{ $staff->id }}" class="text-sm font-medium">Alasan perubahan status {{ $staff->name }}</label>
                            <input id="reason-{{ $staff->id }}" name="reason" required minlength="2" maxlength="1000" class="w-full rounded-lg border border-brand-beige bg-white px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-burgundy dark:bg-brand-dark">
                            <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">{{ $staff->is_active ? 'Nonaktifkan akun' : 'Aktifkan akun' }}</button>
                        </form>
                    @endif
                </article>
            @endforeach
        </div>
        <div class="mt-6">{{ $staffAccounts->links() }}</div>
    </section>
</x-account-layout>
