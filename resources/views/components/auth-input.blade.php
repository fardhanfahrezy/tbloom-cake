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
            ? 'border-auth-muted lg:border-auth-neutral focus-within:border-auth-ink focus-within:ring-2 focus-within:ring-auth-panel/30'
            : 'border-auth-muted focus-within:border-auth-ink focus-within:ring-2 focus-within:ring-auth-panel/30');

    $labelColorClasses = $hasError
        ? 'text-red-700'
        : ($variant === 'neutral'
            ? 'text-auth-ink lg:text-auth-muted'
            : 'text-auth-ink');

    $placeholderClasses = $variant === 'neutral'
        ? 'placeholder:text-auth-muted lg:placeholder:text-auth-placeholder-neutral'
        : 'placeholder:text-auth-muted';

    $iconMap = [
        'mail' => 'assets/auth/mail-02.svg',
        'lock' => 'assets/auth/lock-password.svg',
        'user' => 'assets/auth/user-round.svg',
        'phone' => 'assets/auth/smart-phone-01.svg',
    ];
    $iconPath = $icon && isset($iconMap[$icon]) ? asset($iconMap[$icon]) : null;
@endphp

<div class="w-full">
    <div class="relative flex min-h-[52px] w-full items-center rounded-full border bg-auth-canvas px-6 transition-shadow lg:min-h-[56px] lg:px-7 {{ $borderClasses }}">
        <label
            for="{{ $inputId }}"
            class="absolute -top-[10px] left-6 bg-auth-canvas px-2.5 font-body text-xs font-medium leading-[1.4] tracking-[0.2px] lg:-top-[11px] lg:left-7 lg:text-[14px] {{ $labelColorClasses }}"
        >
            {{ $label }}
        </label>

        <div class="flex w-full items-center justify-between gap-3 lg:gap-4">
            <div class="flex min-w-0 flex-1 items-center gap-3 lg:gap-4">
                @if ($iconPath)
                    <img
                        src="{{ $iconPath }}"
                        alt=""
                        aria-hidden="true"
                        width="24"
                        height="24"
                        class="size-5 shrink-0 object-contain lg:size-6"
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
                        'class' => "w-full min-w-0 bg-transparent py-2.5 font-body text-base font-normal leading-[1.4] tracking-[0.2px] text-auth-ink focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 {$placeholderClasses}",
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
                    class="inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-auth-icon transition hover:text-auth-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-auth-focus lg:size-10"
                >
                    <img
                        src="{{ asset('assets/auth/view-off-slash.svg') }}"
                        alt=""
                        aria-hidden="true"
                        data-password-icon-off
                        width="24"
                        height="24"
                        class="size-5 object-contain lg:size-6"
                    >
                    <img
                        src="{{ asset('assets/auth/view-on.svg') }}"
                        alt=""
                        aria-hidden="true"
                        data-password-icon-on
                        hidden
                        width="24"
                        height="24"
                        class="size-5 object-contain lg:size-6"
                    >
                </button>
            @endif
        </div>
    </div>

    @error($name)
        <p id="{{ $inputId }}-error" role="alert" class="mt-2 pl-6 font-body text-xs font-medium text-red-700 lg:text-sm">
            {{ $message }}
        </p>
    @enderror
</div>
