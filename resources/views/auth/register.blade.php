<x-auth-layout title="Buat akun" heading="Sign up" mobile-heading="Sign-Up">
    <form method="POST" action="{{ route('register.store') }}" data-auth-form class="flex w-full flex-col">
        @csrf

        <div class="flex flex-col gap-[22px] lg:gap-[26px]">
            <x-auth-input
                name="name"
                label="Nama lengkap"
                type="text"
                icon="user"
                placeholder="Nama lengkap"
                autocomplete="name"
                required
                minlength="2"
                maxlength="100"
            />

            <x-auth-input
                name="email"
                label="Email"
                type="email"
                icon="mail"
                placeholder="email@gmail.com"
                autocomplete="email"
                spellcheck="false"
                required
                maxlength="254"
            />

            <x-auth-input
                name="whatsapp_number"
                label="Nomor telepon"
                type="tel"
                icon="phone"
                placeholder="0823********"
                autocomplete="tel"
                inputmode="tel"
                required
            />

            <x-auth-input
                name="password"
                label="Kata sandi"
                type="password"
                icon="lock"
                placeholder="Masukkan kata sandi Anda"
                autocomplete="new-password"
                toggle-password
                required
                minlength="8"
            />

            <x-auth-input
                name="password_confirmation"
                label="Konfirmasi kata sandi"
                type="password"
                icon="lock"
                placeholder="Ulangi kata sandi Anda"
                autocomplete="new-password"
                toggle-password
                required
                minlength="8"
            />
        </div>

        <div class="mt-[16px] flex flex-col lg:mt-[20px]">
            <div class="flex items-start gap-[10px]">
                <input
                    id="terms"
                    name="terms"
                    type="checkbox"
                    value="1"
                    @checked(old('terms', true))
                    required
                    @error('terms')
                        aria-invalid="true"
                        aria-describedby="terms-error"
                    @enderror
                    class="mt-1 size-[14px] shrink-0 cursor-pointer rounded-[2px] border border-[#6f594c] accent-[#726251] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251] lg:size-[20px]"
                >
                <label
                    for="terms"
                    class="cursor-pointer font-rubik text-[12px] font-normal leading-[1.4] tracking-[0.2px] text-[#38271f] lg:text-[18px]"
                >
                    Saya menyetujui
                    <a
                        href="{{ route('pickup.show').'#policies' }}"
                        class="font-semibold text-[#38271f] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#38271f]"
                    >
                        Syarat &amp; Ketentuan
                    </a>
                    TBloom Cake
                </label>
            </div>

            @error('terms')
                <p id="terms-error" role="alert" class="mt-2 pl-6 font-rubik text-sm font-medium text-red-700 lg:text-base">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <button
            type="submit"
            data-auth-submit
            data-loading-text="Mendaftarkan akun..."
            class="mt-[24px] w-full cursor-pointer rounded-full bg-[#726251] px-[10px] py-[12px] text-center font-rubik text-[16px] font-semibold leading-[1.4] tracking-[0.2px] text-[#f7e3df] transition hover:bg-[#5b4639] active:bg-[#38271f] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251] disabled:cursor-not-allowed disabled:opacity-60 lg:mt-[32px] lg:py-[20px] lg:text-[24px]"
        >
            Sign up
        </button>

        <div class="mt-[24px] flex flex-col items-center gap-[20px] lg:mt-[32px] lg:gap-[24px]">
            <p class="text-center font-rubik text-[14px] font-medium leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:text-[20px]">
                - atau -
            </p>

            <div class="flex items-center justify-center gap-[24px]" role="group" aria-label="Metode daftar alternatif">
                <button
                    type="button"
                    disabled
                    aria-label="Sign up dengan Google (segera hadir)"
                    title="Sign up dengan Google (segera hadir)"
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-full p-1 opacity-85 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img src="{{ asset('assets/Google_icon.svg') }}" alt="" aria-hidden="true" width="32" height="32" class="size-[24px] object-contain lg:size-[32px]">
                </button>
                <button
                    type="button"
                    disabled
                    aria-label="Sign up dengan Facebook (segera hadir)"
                    title="Sign up dengan Facebook (segera hadir)"
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-full p-1 opacity-85 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img src="{{ asset('assets/Facebook_icon.svg') }}" alt="" aria-hidden="true" width="32" height="32" class="size-[24px] object-contain lg:size-[32px]">
                </button>
                <button
                    type="button"
                    disabled
                    aria-label="Sign up dengan Apple (segera hadir)"
                    title="Sign up dengan Apple (segera hadir)"
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-full p-1 opacity-85 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img src="{{ asset('assets/Apple_logo_black.svg') }}" alt="" aria-hidden="true" width="27" height="32" class="h-[24px] w-[20px] object-contain lg:h-[32px] lg:w-[27px]">
                </button>
            </div>

            <p class="text-center font-rubik text-[12px] font-normal leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:text-[20px]">
                Sudah punya akun?
                <a
                    href="{{ route('login') }}"
                    class="font-semibold text-[#38271f] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#38271f]"
                >
                    Login
                </a>
            </p>
        </div>
    </form>
</x-auth-layout>
