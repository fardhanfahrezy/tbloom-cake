<x-account-layout :title="$product->exists ? 'Edit produk' : 'Tambah produk'">
    <a href="{{ route('staff.products.index') }}" class="mb-6 inline-block underline underline-offset-4">Kembali ke daftar produk</a>
    <form method="POST" action="{{ $product->exists ? route('staff.products.update', $product) : route('staff.products.store') }}" class="grid gap-5">
        @csrf
        @if ($product->exists) @method('PATCH') @endif
        <x-form-field name="name" label="Nama produk" :value="$product->name" required maxlength="150" />
        <x-form-field name="slug" label="Slug (huruf kecil, angka, tanda hubung)" :value="$product->slug" required maxlength="191" />
        <div class="grid gap-2">
            <label for="category" class="font-medium">Kategori</label>
            <select id="category" name="category" required class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">
                @foreach (['simple' => 'Simple Cake', 'cupcake' => 'Cupcake', 'bento' => 'Bento Cake'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('category', $product->category?->value) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('category')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        </div>
        <x-form-field name="sales_unit_label" label="Unit jual (contoh: buah, box isi 6)" :value="$product->sales_unit_label" required maxlength="32" />
        <div class="grid gap-2">
            <label for="description" class="font-medium">Deskripsi</label>
            @php($description = old('description', $product->description))
            <textarea id="description" name="description" maxlength="10000" rows="5" class="rounded-lg border border-brand-beige bg-white p-3 dark:bg-brand-dark">{{ is_string($description) ? $description : '' }}</textarea>
            @error('description')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        </div>
        <input type="hidden" name="is_active" value="0">
        <label class="flex gap-3"><input type="checkbox" name="is_active" value="1" @checked(in_array(old('is_active', $product->is_active), [true, 1, '1'], true))> Tampilkan produk aktif</label>
        <input type="hidden" name="archived" value="0">
        <label class="flex gap-3"><input type="checkbox" name="archived" value="1" @checked(in_array(old('archived', $product->archived_at !== null), [true, 1, '1'], true))> Arsipkan produk (menonaktifkan pemesanan baru)</label>
        @foreach (['name', 'slug', 'is_active', 'archived'] as $field)
            @error($field)<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        @endforeach
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan produk</button>
    </form>
    @if ($product->exists)
        <section class="mt-10 border-t border-brand-beige pt-8" aria-labelledby="sizes-title">
            <h2 id="sizes-title" class="text-xl font-semibold">Ukuran dan harga</h2>
            <a href="{{ route('staff.products.variants.create', $product) }}" class="mt-4 inline-block underline underline-offset-4">Tambah ukuran</a>
            <ul class="mt-5 grid gap-3">
                @foreach ($product->variants as $variant)
                    <li><a class="underline underline-offset-4" href="{{ route('staff.products.variants.edit', [$product, $variant]) }}">{{ $variant->size_label }} - Rp{{ number_format($variant->price, 0, ',', '.') }} - {{ $variant->is_active ? 'Aktif' : 'Nonaktif' }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-account-layout>
