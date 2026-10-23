<x-account-layout title="Kelola produk">
    <a href="{{ route('staff.products.create') }}" class="inline-block rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white dark:bg-brand-beige dark:text-brand-dark">Tambah produk</a>
    <div class="mt-8 grid gap-4">
        @forelse ($products as $product)
            <article class="rounded-lg border border-brand-beige p-5">
                <h2 class="font-semibold"><a class="underline underline-offset-4" href="{{ route('staff.products.edit', $product) }}">{{ $product->name }}</a></h2>
                <p class="mt-2 text-sm">{{ $product->archived_at ? 'Diarsipkan' : ($product->is_active ? 'Aktif' : 'Nonaktif') }} &middot; {{ $product->variants_count }} ukuran &middot; Unit jual: {{ $product->sales_unit_label }}</p>
            </article>
        @empty
            <p>Belum ada produk. Tambahkan produk pertama Anda.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $products->links() }}</div>
</x-account-layout>
