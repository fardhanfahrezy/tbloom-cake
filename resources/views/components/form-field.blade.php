@props(['name', 'label', 'type' => 'text', 'value' => null])
@php($fieldValue = old($name, $value))
<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        @if ($type !== 'password') value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}" @endif
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        {{ $attributes->class(['w-full rounded-lg border border-brand-beige bg-white px-3 py-2.5 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-burgundy dark:border-brand-taupe dark:bg-brand-dark dark:focus-visible:outline-brand-pink']) }}>
    @error($name)<p id="{{ $name }}-error" role="alert" class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
</div>
