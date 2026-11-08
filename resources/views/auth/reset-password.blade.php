<x-auth-layout
    title="Password baru"
    heading="Password baru"
    mobile-heading="Reset Password"
    heading-tone="neutral"
    mobile-heading-size="md"
>
    <p class="mb-[28px] text-center font-rubik text-[16px] font-normal leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:mb-[36px] lg:text-[20px] lg:text-[#757575]">
        Masukkan kata sandi baru untuk akun Anda (minimal 12 karakter).
    </p>

    <form method="POST" action="{{ route('password.update') }}" data-auth-form class="flex w-full flex-col">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="flex flex-col gap-[24px] lg:gap-[28px]">
            <x-auth-input
                name="email"
                label="Email"
                type="email"
                icon="mail"
                variant="neutral"
                :value="$email"
                placeholder="email@gmail.com"
                autocomplete="username"
                spellcheck="false"
                required
                maxlength="254"
            />

            <x-auth-input
                name="password"
                label="Kata sandi baru"
                type="password"
                icon="lock"
                placeholder="Minimal 12 karakter"
                autocomplete="new-password"
                toggle-password
                required
                minlength="12"
            />

            <x-auth-input
                name="password_confirmation"
                label="Konfirmasi kata sandi"
                type="password"
                icon="lock"
                placeholder="Ulangi kata sandi baru"
                autocomplete="new-password"
                toggle-password
                required
                minlength="12"
            />
        </div>

        <button
            type="submit"
            data-auth-submit
            data-loading-text="Menyimpan kata sandi..."
            class="mt-[28px] w-full cursor-pointer rounded-full bg-[#726251] px-[10px] py-[12px] text-center font-rubik text-[16px] font-semibold leading-[1.4] tracking-[0.2px] text-[#f7e3df] transition hover:bg-[#5b4639] active:bg-[#38271f] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251] disabled:cursor-not-allowed disabled:opacity-60 lg:mt-[36px] lg:py-[20px] lg:text-[24px]"
        >
            Simpan password baru
        </button>

        <div class="mt-[24px] text-center lg:mt-[32px]">
            <a
                href="{{ route('login') }}"
                class="inline-block font-rubik text-[12px] font-semibold leading-[1.4] tracking-[0.2px] text-[#6b283b] transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#6b283b] lg:text-[20px]"
            >
                Kembali ke halaman login
            </a>
        </div>
    </form>
</x-auth-layout>
