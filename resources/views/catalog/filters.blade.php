<form method="GET" action="{{ $action }}" data-catalog-filters class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="flex flex-1 flex-col gap-1.5">
        <label for="pickup_date" class="font-inter text-sm font-semibold text-[#251306]">Tanggal pengambilan (WIB)</label>
        <p class="text-xs text-[#6F594C]">Pilih tanggal pengambilan untuk memeriksa ketersediaan sisa unit kue.</p>
        <div class="mt-1">
            <input id="pickup_date" name="pickup_date" type="date" value="{{ $date }}" min="{{ $window['earliest']->toDateString() }}" max="{{ $window['latest']->toDateString() }}" required class="w-full rounded-[8px] border border-[#D6C6B9] bg-white px-3.5 py-2.5 font-inter text-sm text-[#251306] shadow-sm transition-colors focus:border-[#726251] focus:ring-1 focus:ring-[#726251] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251]">
        </div>
        @error('pickup_date')
            <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
        @enderror
    </div>
    @if ($showCategory && !empty($category))
        <input type="hidden" name="category" value="{{ $category }}">
    @endif
    <div class="shrink-0">
        <button type="submit" class="inline-flex min-h-[42px] w-full items-center justify-center rounded-[8px] bg-[#726251] px-5 py-2.5 font-inter text-sm font-medium text-white shadow-sm transition-colors hover:bg-[#251306] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#726251] sm:w-auto">Lihat ketersediaan</button>
    </div>
</form>
