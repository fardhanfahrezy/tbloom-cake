<x-account-layout :title="$image->exists ? 'Edit foto produk' : 'Tambah foto produk'">
    <p class="mb-4 font-medium">{{ $product->name }}</p>
    <a href="{{ route('staff.products.edit', $product) }}" class="mb-6 inline-block underline underline-offset-4">Kembali ke produk</a>
    @if ($image->exists)
        <img src="{{ Storage::disk('public')->url($image->path) }}" alt="{{ $image->alt_text }}" class="mb-6 aspect-[4/3] w-full rounded-lg object-cover">
    @endif
    <form method="POST" enctype="multipart/form-data" action="{{ $image->exists ? route('staff.products.images.update', [$product, $image]) : route('staff.products.images.store', $product) }}" class="grid gap-5">
        @csrf
        @if ($image->exists) @method('PATCH') @endif
        <div class="grid gap-2">
            <label for="image" class="font-medium">{{ $image->exists ? 'Ganti gambar (opsional)' : 'Gambar produk' }}</label>
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" @required(! $image->exists) aria-describedby="image-help" class="min-w-0 rounded-lg border border-brand-beige p-3">
            <p id="image-help" class="text-sm">JPEG, PNG, atau WebP maksimal 5 MB. Maksimal sisi 4096 piksel dan 12 megapiksel.</p>
            @error('image')<p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
        </div>
        <x-form-field name="alt_text" label="Deskripsi foto untuk pembaca layar" :value="$image->alt_text" required maxlength="255" />
        <x-form-field name="sort_order" label="Urutan foto (angka kecil tampil lebih awal)" type="number" :value="$image->sort_order" required min="0" step="1" />
        <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Simpan foto</button>
    </form>
    @if ($image->exists)
        <form method="POST" action="{{ route('staff.products.images.destroy', [$product, $image]) }}" class="mt-8 border-t border-brand-beige pt-6">
            @csrf @method('DELETE')
            <button class="rounded-lg border border-red-600 px-5 py-3 text-red-700 dark:text-red-300">Hapus foto ini</button>
        </form>
    @endif
</x-account-layout>
