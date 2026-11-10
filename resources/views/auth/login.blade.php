<x-auth-layout title="Masuk" heading="Selamat Datang!!" mobile-heading="Login">
    <form method="POST" action="{{ route('login.store') }}" data-auth-form class="flex w-full flex-col">
        @csrf

        <div class="flex flex-col gap-token-20 lg:gap-token-24">
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

        <div class="mt-token-12 flex items-center justify-between gap-4 lg:mt-token-16">
            <div class="flex min-h-11 items-center gap-token-8">
                <input
                    id="remember"
                    name="remember"
                    type="checkbox"
                    value="1"
                    @checked(old('remember'))
                    class="size-4 cursor-pointer rounded-token-xs border border-auth-muted accent-auth-panel focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus lg:size-[18px]"
                >
                <label
                    for="remember"
                    class="inline-flex min-h-11 cursor-pointer items-center font-body text-xs font-medium leading-[1.4] tracking-[0.2px] text-auth-ink lg:text-[14px]"
                >
                    Ingat saya
                </label>
            </div>

            @if (Route::has('password.request'))
                <a
                    href="{{ route('password.request') }}"
                    class="inline-flex min-h-11 items-center font-body text-xs font-semibold leading-[1.4] tracking-[0.2px] text-auth-ink transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus lg:text-[14px]"
                >
                    Lupa kata sandi?
                </a>
            @endif
        </div>

        <button
            type="submit"
            data-auth-submit
            data-loading-text="Memproses..."
            class="mt-token-24 inline-flex min-h-[52px] w-full cursor-pointer items-center justify-center rounded-full bg-auth-panel px-6 py-3 text-center font-body text-base font-semibold leading-[1.4] tracking-[0.2px] text-auth-foreground transition-colors hover:bg-auth-panel-hover active:bg-auth-panel-pressed focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-focus disabled:cursor-not-allowed disabled:opacity-60 lg:mt-token-28 lg:min-h-[56px] lg:py-3.5 lg:text-[18px]"
        >
            Login
        </button>

        <div class="mt-token-20 flex flex-col items-center gap-token-16 lg:mt-token-24 lg:gap-token-20">
            <x-auth-social-providers />

            <p class="text-center font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-muted lg:text-[14px]">
                Belum punya akun?
                <a
                    href="{{ route('register') }}"
                    class="font-semibold text-auth-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus"
                >
                    Sign up
                </a>
            </p>
        </div>
    </form>
</x-auth-layout>
