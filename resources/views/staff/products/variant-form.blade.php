<x-account-layout :title="$variant->exists ? 'Edit ukuran dan harga' : 'Tambah ukuran'">
    <p class="mb-4 font-medium">{{ $product->name }}</p>
    <a href="{{ route('staff.products.edit', $product) }}" class="mb-6 inline-block underline underline-offset-4">Kembali ke produk</a>
    <form method="POST" action="{{ $variant->exists ? route('staff.products.variants.update', [$product, $variant]) : route('staff.products.variants.store', $product) }}" class="grid gap-5">
        @csrf
        @if ($variant->exists) @method('PATCH') @endif
        <x-form-field name="size_label" label="Label ukuran" :value="$variant->size_label" required maxlength="100" />
        <x-form-field name="price" label="Harga per unit (rupiah)" type="number" :value="$variant->price" required min="1" step="1" />
        <input type="hidden" name="is_active" value="0">
        <label class="flex gap-3"><input type="checkbox" name="is_active" value="1" @checked(in_array(old('is_active', $variant->is_active), [true, 1, '1'], true))> Ukuran aktif</label>
        @error('is_active')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan ukuran</button>
    </form>
</x-account-layout>
