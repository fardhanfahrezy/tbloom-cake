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
        <p class="mb-[28px] text-center font-rubik text-[16px] font-normal leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:mb-[36px] lg:text-[20px] lg:text-[#757575]">
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
                class="mt-[28px] w-full cursor-pointer rounded-full bg-[#726251] px-[10px] py-[12px] text-center font-rubik text-[16px] font-semibold leading-[1.4] tracking-[0.2px] text-[#f7e3df] transition hover:bg-[#5b4639] active:bg-[#38271f] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251] disabled:cursor-not-allowed disabled:opacity-60 lg:mt-[36px] lg:py-[20px] lg:text-[24px]"
            >
                Kirim tautan reset
            </button>
        </form>

        <div class="mt-[24px] text-center lg:mt-[32px]">
            <a
                href="{{ route('login') }}"
                class="inline-block font-rubik text-[12px] font-semibold leading-[1.4] tracking-[0.2px] text-[#6b283b] transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#6b283b] lg:text-[20px]"
            >
                Kembali ke halaman login
            </a>
        </div>
    </x-auth-layout>
@endif
