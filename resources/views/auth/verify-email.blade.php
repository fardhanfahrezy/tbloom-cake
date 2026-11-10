@php
    $rawEmail = $email ?? session('reset_email', old('email', 'email@gmail.com'));
    $targetEmail = is_scalar($rawEmail) && trim((string) $rawEmail) !== ''
        ? (string) $rawEmail
        : 'email@gmail.com';
@endphp
<x-auth-layout
    title="Periksa email"
    heading="Periksa email"
    mobile-heading="Check Email"
    heading-tone="neutral"
    mobile-heading-size="md"
    :show-status="false"
>
    <div class="flex w-full flex-col items-center text-center">
        @if (session('status'))
            <p role="status" class="sr-only">{{ session('status') }}</p>
        @endif

        <p class="font-rubik text-[16px] font-normal leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:text-[20px] lg:text-[#757575]">
            Tautan reset kata sandi telah dikirim ke
        </p>

        <div class="mt-[8px] flex items-center justify-center gap-[8px]">
            <img
                src="{{ asset('assets/auth/envelope-accent.svg') }}"
                alt=""
                aria-hidden="true"
                width="28"
                height="28"
                class="size-[20px] shrink-0 object-contain lg:size-[28px]"
            >
            <span class="break-all font-rubik text-[16px] font-semibold leading-[1.4] tracking-[0.2px] text-[#38271f] lg:text-[20px]">
                {{ $targetEmail }}
            </span>
        </div>

        <p class="mt-[20px] max-w-[460px] font-rubik text-[16px] font-normal leading-[1.4] tracking-[0.2px] text-[#6f594c] lg:mt-[32px] lg:text-[20px] lg:text-[#757575]">
            Periksa kotak masuk atau folder spam. Buka tautan di email untuk mengatur ulang kata sandi.
        </p>

        <form method="POST" action="{{ route('password.email') }}" data-auth-form class="mt-[28px] w-full lg:mt-[36px]">
            @csrf
            <input type="hidden" name="email" value="{{ $targetEmail }}">

            <button
                type="submit"
                data-auth-submit
                data-loading-text="Mengirim ulang..."
                class="w-full cursor-pointer rounded-full bg-[#726251] px-[10px] py-[12px] text-center font-rubik text-[16px] font-semibold leading-[1.4] tracking-[0.2px] text-[#f7e3df] transition hover:bg-[#5b4639] active:bg-[#38271f] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#726251] disabled:cursor-not-allowed disabled:opacity-60 lg:py-[20px] lg:text-[24px]"
            >
                Kirim ulang tautan
            </button>
        </form>

        <div class="mt-[24px] text-center lg:mt-[32px]">
            <a
                href="{{ route('login') }}"
                class="inline-block font-rubik text-[12px] font-semibold leading-[1.4] tracking-[0.2px] text-[#38271f] transition hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#38271f] lg:text-[20px]"
            >
                Kembali ke halaman login
            </a>
        </div>
    </div>
</x-auth-layout>
