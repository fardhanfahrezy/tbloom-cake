@props([
    'name',
    'label',
    'type' => 'text',
    'id' => null,
    'value' => null,
    'icon' => null,
    'placeholder' => '',
    'togglePassword' => false,
    'variant' => 'default',
])
@php
    $inputId = $id ?? $name;
    $fieldValue = old($name, $value);
    $hasError = $errors->has($name);
    $showToggle = $togglePassword || $type === 'password';

    $borderClasses = $hasError
        ? 'border-red-700 focus-within:border-red-700 focus-within:ring-2 focus-within:ring-red-700/25'
        : ($variant === 'neutral'
            ? 'border-[#6f594c] lg:border-[#757575] focus-within:border-[#38271f] focus-within:ring-2 focus-within:ring-[#726251]/30'
            : 'border-[#6f594c] focus-within:border-[#38271f] focus-within:ring-2 focus-within:ring-[#726251]/30');

    $labelColorClasses = $hasError
        ? 'text-red-700'
        : ($variant === 'neutral'
            ? 'text-[#38271f] lg:text-[#757575]'
            : 'text-[#38271f]');

    $placeholderClasses = $variant === 'neutral'
        ? 'placeholder:text-[#6f594c] lg:placeholder:text-[#616161]'
        : 'placeholder:text-[#6f594c]';

    $iconMap = [
        'mail' => 'assets/auth/mail-02.svg',
        'lock' => 'assets/auth/lock-password.svg',
        'user' => 'assets/auth/user-round.svg',
        'phone' => 'assets/auth/smart-phone-01.svg',
    ];
    $iconPath = $icon && isset($iconMap[$icon]) ? asset($iconMap[$icon]) : null;
@endphp

<div class="w-full">
    <div class="relative flex flex-col items-start rounded-[63px] border bg-[#fef6ef] px-[28px] py-[13px] transition-shadow lg:px-[32px] lg:py-[22px] {{ $borderClasses }}">
        <label
            for="{{ $inputId }}"
            class="absolute -top-[12px] left-[37px] bg-[#fef6ef] px-[12px] font-rubik text-[16px] font-medium leading-[1.4] tracking-[0.2px] lg:-top-[18px] lg:left-[55px] lg:px-[17px] lg:text-[24px] {{ $labelColorClasses }}"
        >
            {{ $label }}
        </label>

        <div class="flex w-full items-center justify-between gap-[12px] lg:gap-[16px]">
            <div class="flex min-w-0 flex-1 items-center gap-[12px] lg:gap-[16px]">
                @if ($iconPath)
                    <img
                        src="{{ $iconPath }}"
                        alt=""
                        aria-hidden="true"
                        width="28"
                        height="28"
                        class="size-[20px] shrink-0 object-contain lg:size-[28px]"
                    >
                @endif

                <input
                    id="{{ $inputId }}"
                    name="{{ $name }}"
                    type="{{ $type }}"
                    @if ($type !== 'password') value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}" @endif
                    placeholder="{{ $placeholder }}"
                    @if ($hasError)
                        aria-invalid="true"
                        aria-describedby="{{ $inputId }}-error"
                    @endif
                    {{ $attributes->merge([
                        'class' => "w-full min-w-0 bg-transparent font-rubik text-[16px] font-normal leading-[1.4] tracking-[0.2px] text-[#38271f] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 lg:text-[22px] {$placeholderClasses}",
                    ]) }}
                >
            </div>

            @if ($showToggle)
                <button
                    type="button"
                    data-password-toggle
                    aria-controls="{{ $inputId }}"
                    aria-pressed="false"
                    aria-label="Tampilkan kata sandi"
                    class="inline-flex size-[24px] shrink-0 cursor-pointer items-center justify-center rounded text-[#9e9e9e] transition hover:text-[#38271f] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]"
                >
                    <img
                        src="{{ asset('assets/auth/view-off-slash.svg') }}"
                        alt=""
                        aria-hidden="true"
                        data-password-icon-off
                        width="24"
                        height="24"
                        class="size-[20px] object-contain lg:size-[24px]"
                    >
                    <img
                        src="{{ asset('assets/auth/view-on.svg') }}"
                        alt=""
                        aria-hidden="true"
                        data-password-icon-on
                        hidden
                        width="24"
                        height="24"
                        class="size-[20px] object-contain lg:size-[24px]"
                    >
                </button>
            @endif
        </div>
    </div>

    @error($name)
        <p id="{{ $inputId }}-error" role="alert" class="mt-2 pl-6 font-rubik text-sm font-medium text-red-700 lg:text-base">
            {{ $message }}
        </p>
    @enderror
</div>
