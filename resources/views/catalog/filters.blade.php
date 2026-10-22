<form method="GET" action="{{ $action }}" data-catalog-filters class="grid items-end gap-4 sm:grid-cols-2">
    <div class="grid gap-2">
        <label for="pickup_date" class="font-medium">Tanggal pengambilan (WIB)</label>
        <input id="pickup_date" name="pickup_date" type="date" value="{{ $date }}" min="{{ $window['earliest']->toDateString() }}" max="{{ $window['latest']->toDateString() }}" required class="min-w-0 rounded-lg border border-brand-beige bg-white px-3 py-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-burgundy dark:bg-brand-dark">
        @error('pickup_date')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
    </div>
    @if ($showCategory)
        <div class="grid gap-2">
            <label for="category" class="font-medium">Kategori</label>
            <select id="category" name="category" class="rounded-lg border border-brand-beige bg-white px-3 py-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-burgundy dark:bg-brand-dark">
                <option value="">Semua kue</option>
                @foreach (['simple' => 'Simple Cake', 'cupcake' => 'Cupcake', 'bento' => 'Bento Cake'] as $value => $label)
                    <option value="{{ $value }}" @selected($category === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('category')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        </div>
    @endif
    <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-4 dark:bg-brand-beige dark:text-brand-dark">Lihat ketersediaan</button>
</form>
