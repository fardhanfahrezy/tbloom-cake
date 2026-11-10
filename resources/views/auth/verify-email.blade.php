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

        <p class="font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-muted lg:text-[14px]">
            Tautan reset kata sandi telah dikirim ke
        </p>

        <div class="mt-token-8 flex items-center justify-center gap-token-8">
            <img
                src="{{ asset('assets/auth/envelope-accent.svg') }}"
                alt=""
                aria-hidden="true"
                width="24"
                height="24"
                class="size-5 shrink-0 object-contain lg:size-6"
            >
            <span class="break-all font-body text-sm font-semibold leading-[1.4] tracking-[0.2px] text-auth-ink lg:text-base">
                {{ $targetEmail }}
            </span>
        </div>

        <p class="mt-token-16 max-w-[460px] font-body text-xs font-normal leading-[1.4] tracking-[0.2px] text-auth-muted lg:mt-token-20 lg:text-[14px]">
            Periksa kotak masuk atau folder spam. Buka tautan di email untuk mengatur ulang kata sandi.
        </p>

        <form method="POST" action="{{ route('password.email') }}" data-auth-form class="mt-token-24 w-full lg:mt-token-28">
            @csrf
            <input type="hidden" name="email" value="{{ $targetEmail }}">

            <button
                type="submit"
                data-auth-submit
                data-loading-text="Mengirim ulang..."
                class="inline-flex min-h-[52px] w-full cursor-pointer items-center justify-center rounded-full bg-auth-panel px-6 py-3 text-center font-body text-base font-semibold leading-[1.4] tracking-[0.2px] text-auth-foreground transition-colors hover:bg-auth-panel-hover active:bg-auth-panel-pressed focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-auth-focus disabled:cursor-not-allowed disabled:opacity-60 lg:min-h-[56px] lg:py-3.5 lg:text-[18px]"
            >
                Kirim ulang tautan
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
    </div>
</x-auth-layout>
