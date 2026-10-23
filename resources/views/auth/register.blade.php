<x-account-layout title="Buat akun">
    <p class="mb-6 text-sm leading-6 text-brand-taupe dark:text-stone-300">Simpan identitas Anda untuk memesan cake dan mengirim request custom. Akun langsung aktif setelah pendaftaran.</p>
    <form method="POST" action="{{ route('register.store') }}" class="grid gap-5">
        @csrf
        <x-form-field name="name" label="Nama lengkap" autocomplete="name" required minlength="2" maxlength="100" />
        <x-form-field name="email" label="Email" type="email" autocomplete="email" required maxlength="254" />
        <x-form-field name="whatsapp_number" label="Nomor WhatsApp" type="tel" autocomplete="tel" placeholder="0812 3456 7890 atau +62..." required />
        <x-form-field name="password" label="Password (minimal 12 karakter)" type="password" autocomplete="new-password" required minlength="12" />
        <x-form-field name="password_confirmation" label="Ulangi password" type="password" autocomplete="new-password" required />
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy dark:bg-brand-beige dark:text-brand-dark dark:hover:bg-brand-pink">Buat akun</button>
    </form>
</x-account-layout>
