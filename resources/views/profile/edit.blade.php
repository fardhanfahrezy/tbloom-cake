<x-account-layout title="Profil saya">
    <p class="mb-6 text-sm leading-6 text-brand-taupe dark:text-stone-300">Perubahan email atau nomor WhatsApp memerlukan password saat ini. Kontak pada pesanan dan request lama tetap tersimpan sesuai saat dibuat.</p>
    <form method="POST" action="{{ route('profile.update') }}" class="grid gap-5">
        @csrf
        @method('PATCH')
        <x-form-field name="name" label="Nama lengkap" :value="$user->name" autocomplete="name" required minlength="2" maxlength="100" />
        <x-form-field name="email" label="Email" type="email" :value="$user->email" autocomplete="email" required maxlength="254" />
        <x-form-field name="whatsapp_number" label="Nomor WhatsApp" type="tel" :value="$user->whatsapp_number" autocomplete="tel" required />
        <x-form-field name="current_password" label="Password saat ini (untuk perubahan kontak)" type="password" autocomplete="current-password" />
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">Simpan profil</button>
    </form>
</x-account-layout>
