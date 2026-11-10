<x-auth-layout
    title="Password baru"
    heading="Password baru"
    mobile-heading="Reset Password"
    heading-tone="neutral"
    mobile-heading-size="md"
>
    <p class="mb-token-20 text-center font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-muted lg:mb-token-24 lg:text-[14px]">
        Masukkan kata sandi baru untuk akun Anda (minimal 8 karakter).
    </p>

    <form method="POST" action="{{ route('password.update') }}" data-auth-form class="flex w-full flex-col">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="flex flex-col gap-token-20 lg:gap-token-24">
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
                placeholder="Minimal 8 karakter"
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
                placeholder="Ulangi kata sandi baru"
                autocomplete="new-password"
                toggle-password
                required
                minlength="8"
            />
        </div>

        <button
            type="submit"
            data-auth-submit
            data-loading-text="Menyimpan kata sandi..."
            class="mt-token-24 inline-flex min-h-[52px] w-full cursor-pointer items-center justify-center rounded-full bg-auth-panel px-6 py-3 text-center font-body text-base font-semibold leading-[1.4] tracking-[0.2px] text-auth-foreground transition-colors hover:bg-auth-panel-hover active:bg-auth-panel-pressed focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-focus disabled:cursor-not-allowed disabled:opacity-60 lg:mt-token-28 lg:min-h-[56px] lg:py-3.5 lg:text-[18px]"
        >
            Simpan password baru
        </button>

        <div class="mt-token-20 text-center lg:mt-token-24">
            <a
                href="{{ route('login') }}"
                class="inline-block font-body text-xs font-semibold leading-[1.4] tracking-[0.2px] text-auth-ink transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus lg:text-[14px]"
            >
                Kembali ke halaman login
            </a>
        </div>
    </form>
</x-auth-layout>
