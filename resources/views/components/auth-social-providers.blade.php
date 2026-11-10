<div class="flex flex-col items-center gap-token-16 lg:gap-token-20">
    <p class="text-center font-body text-xs font-medium leading-[1.4] tracking-[0.2px] text-auth-muted lg:text-[14px]">
        - atau -
    </p>

    <div class="flex items-center justify-center gap-token-20 lg:gap-token-24" role="group" aria-label="Metode masuk alternatif">
        <button
            type="button"
            disabled
            aria-label="Login dengan Google (segera hadir)"
            title="Login dengan Google (segera hadir)"
            class="inline-flex size-11 cursor-not-allowed items-center justify-center rounded-full opacity-85 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus"
        >
            <img src="{{ asset('assets/Google_icon.svg') }}" alt="" aria-hidden="true" width="28" height="28" class="size-6 object-contain lg:size-7">
        </button>

        <button
            type="button"
            disabled
            aria-label="Login dengan Facebook (segera hadir)"
            title="Login dengan Facebook (segera hadir)"
            class="inline-flex size-11 cursor-not-allowed items-center justify-center rounded-full opacity-85 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus"
        >
            <img src="{{ asset('assets/Facebook_icon.svg') }}" alt="" aria-hidden="true" width="28" height="28" class="size-6 object-contain lg:size-7">
        </button>

        <button
            type="button"
            disabled
            aria-label="Login dengan Apple (segera hadir)"
            title="Login dengan Apple (segera hadir)"
            class="inline-flex size-11 cursor-not-allowed items-center justify-center rounded-full opacity-85 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus"
        >
            <img src="{{ asset('assets/Apple_logo_black.svg') }}" alt="" aria-hidden="true" width="24" height="28" class="h-6 w-5 object-contain lg:h-7 lg:w-6">
        </button>
    </div>
</div>
