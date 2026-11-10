<x-auth-layout title="Masuk" heading="Selamat Datang!!" mobile-heading="Login">
    <form method="POST" action="{{ route('login.store') }}" data-auth-form class="flex w-full flex-col">
        @csrf

        <div class="flex flex-col gap-[24px] lg:gap-[28px]">
            <x-auth-input
                name="email"
                label="Email"
                type="email"
                icon="mail"
                placeholder="email@gmail.com"
                autocomplete="username"
                spellcheck="false"
                required
                maxlength="254"
            />

            <x-auth-input
                name="password"
                label="Kata sandi"
                type="password"
                icon="lock"
                placeholder="Masukkan kata sandi Anda"
                autocomplete="current-password"
                toggle-password
                required
            />
        </div>

        <div class="mt-[12px] flex items-center justify-between gap-4 lg:mt-[18px]">
            <div class="flex items-center gap-[8px]">
                <input
                    id="remember"
                    name="remember"
                    type="checkbox"
                    value="1"
                    @checked(old('remember'))
                    class="size-[14px] cursor-pointer rounded-[2px] border border-[#6f594c] accent-[#726251] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251] lg:size-[20px]"
                >
                <label
                    for="remember"
                    class="cursor-pointer font-rubik text-[12px] font-medium leading-[1.4] tracking-[0.2px] text-[#38271f] lg:text-[20px]"
                >
                    Ingat saya
                </label>
            </div>

            @if (Route::has('password.request'))
                <a
                    href="{{ route('password.request') }}"
                    class="font-rubik text-[12px] font-semibold leading-[1.4] tracking-[0.2px] text-[#38271f] transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#38271f] lg:text-[20px]"
                >
                    Lupa kata sandi?
                </a>
            @endif
        </div>

        <button
            type="submit"
            data-auth-submit
            data-loading-text="Memproses..."
            class="mt-[28px] w-full cursor-pointer rounded-full bg-[#726251] px-[10px] py-[12px] text-center font-rubik text-[16px] font-semibold leading-[1.4] tracking-[0.2px] text-[#f7e3df] transition hover:bg-[#5b4639] active:bg-[#38271f] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251] disabled:cursor-not-allowed disabled:opacity-60 lg:mt-[36px] lg:py-[20px] lg:text-[24px]"
        >
            Login
        </button>

        <div class="mt-[24px] flex flex-col items-center gap-[20px] lg:mt-[36px] lg:gap-[28px]">
            <p class="text-center font-rubik text-[14px] font-medium leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:text-[20px]">
                - atau -
            </p>

            <div class="flex items-center justify-center gap-[24px]" role="group" aria-label="Metode masuk alternatif">
                <button
                    type="button"
                    disabled
                    aria-label="Login dengan Google (segera hadir)"
                    title="Login dengan Google (segera hadir)"
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-full p-1 opacity-85 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img src="{{ asset('assets/Google_icon.svg') }}" alt="" aria-hidden="true" width="32" height="32" class="size-[24px] object-contain lg:size-[32px]">
                </button>
                <button
                    type="button"
                    disabled
                    aria-label="Login dengan Facebook (segera hadir)"
                    title="Login dengan Facebook (segera hadir)"
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-full p-1 opacity-85 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img src="{{ asset('assets/Facebook_icon.svg') }}" alt="" aria-hidden="true" width="32" height="32" class="size-[24px] object-contain lg:size-[32px]">
                </button>
                <button
                    type="button"
                    disabled
                    aria-label="Login dengan Apple (segera hadir)"
                    title="Login dengan Apple (segera hadir)"
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-full p-1 opacity-85 transition hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img src="{{ asset('assets/Apple_logo_black.svg') }}" alt="" aria-hidden="true" width="27" height="32" class="h-[24px] w-[20px] object-contain lg:h-[32px] lg:w-[27px]">
                </button>
            </div>

            <p class="text-center font-rubik text-[12px] font-normal leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:text-[20px]">
                Belum punya akun?
                <a
                    href="{{ route('register') }}"
                    class="font-semibold text-[#38271f] hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#38271f]"
                >
                    Sign up
                </a>
            </p>
        </div>
    </form>
</x-auth-layout>
