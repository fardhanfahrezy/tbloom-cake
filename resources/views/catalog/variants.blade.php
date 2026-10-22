<ul class="grid gap-2 text-sm" aria-label="Ukuran dan harga">
    @forelse ($product->variants as $variant)
        <li class="flex flex-wrap justify-between gap-2"><span>{{ $variant->size_label }}</span><span class="font-semibold">Rp{{ number_format($variant->price, 0, ',', '.') }}</span></li>
    @empty
        <li>Belum ada ukuran yang tersedia.</li>
    @endforelse
</ul>
