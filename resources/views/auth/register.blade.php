<x-auth-layout title="Buat akun" heading="Sign up" mobile-heading="Sign-Up">
    <form method="POST" action="{{ route('register.store') }}" data-auth-form class="flex w-full flex-col">
        @csrf

        <div class="flex flex-col gap-token-20 lg:gap-token-24">
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

        <div class="mt-token-16 flex flex-col">
            <div class="flex items-start gap-2.5">
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
                    class="mt-1 size-4 shrink-0 cursor-pointer rounded-token-xs border border-auth-muted accent-auth-panel focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus lg:size-[18px]"
                >
                <label
                    for="terms"
                    class="cursor-pointer font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-ink lg:text-[14px]"
                >
                    Saya menyetujui
                    <a
                        href="{{ route('pickup.show').'#policies' }}"
                        class="font-semibold text-auth-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus"
                    >
                        Syarat &amp; Ketentuan
                    </a>
                    TBloom Cake
                </label>
            </div>

            @error('terms')
                <p id="terms-error" role="alert" class="mt-2 pl-6 font-body text-xs font-medium text-red-700 lg:text-sm">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <button
            type="submit"
            data-auth-submit
            data-loading-text="Mendaftarkan akun..."
            class="mt-token-24 inline-flex min-h-[52px] w-full cursor-pointer items-center justify-center rounded-full bg-auth-panel px-6 py-3 text-center font-body text-base font-semibold leading-[1.4] tracking-[0.2px] text-auth-foreground transition-colors hover:bg-auth-panel-hover active:bg-auth-panel-pressed focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-focus disabled:cursor-not-allowed disabled:opacity-60 lg:mt-token-28 lg:min-h-[56px] lg:py-3.5 lg:text-[18px]"
        >
            Sign up
        </button>

        <div class="mt-token-20 flex flex-col items-center gap-token-16 lg:mt-token-24 lg:gap-token-20">
            <x-auth-social-providers />

            <p class="text-center font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-muted lg:text-[14px]">
                Sudah punya akun?
                <a
                    href="{{ route('login') }}"
                    class="font-semibold text-auth-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus"
                >
                    Login
                </a>
            </p>
        </div>
    </form>
</x-auth-layout>
