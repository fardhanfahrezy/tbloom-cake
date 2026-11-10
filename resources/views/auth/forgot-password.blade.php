@if (session('status'))
    @include('auth.verify-email', ['email' => session('reset_email', old('email', 'email@gmail.com'))])
@else
    <x-auth-layout
        title="Lupa password"
        heading="Lupa kata sandi"
        mobile-heading="Forgot Password"
        heading-tone="neutral"
        mobile-heading-size="md"
    >
        <p class="mb-token-20 text-center font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-muted lg:mb-token-24 lg:text-[14px]">
            Masukkan email yang terdaftar. Kami akan mengirim tautan untuk mengatur ulang kata sandi.
        </p>

        <form method="POST" action="{{ route('password.email') }}" data-auth-form class="flex w-full flex-col">
            @csrf

            <x-auth-input
                name="email"
                label="Email"
                type="email"
                icon="mail"
                variant="neutral"
                placeholder="email@gmail.com"
                autocomplete="email"
                spellcheck="false"
                required
                maxlength="254"
            />

            <button
                type="submit"
                data-auth-submit
                data-loading-text="Mengirim tautan..."
                class="mt-token-24 inline-flex min-h-[52px] w-full cursor-pointer items-center justify-center rounded-full bg-auth-panel px-6 py-3 text-center font-body text-base font-semibold leading-[1.4] tracking-[0.2px] text-auth-foreground transition-colors hover:bg-auth-panel-hover active:bg-auth-panel-pressed focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-focus disabled:cursor-not-allowed disabled:opacity-60 lg:mt-token-28 lg:min-h-[56px] lg:py-3.5 lg:text-[18px]"
            >
                Kirim tautan reset
            </button>
        </form>

        <div class="mt-token-20 text-center lg:mt-token-24">
            <a
                href="{{ route('login') }}"
                class="inline-block font-body text-xs font-semibold leading-[1.4] tracking-[0.2px] text-auth-ink transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus lg:text-[14px]"
            >
                Kembali ke halaman login
            </a>
        </div>
    </x-auth-layout>
@endif
