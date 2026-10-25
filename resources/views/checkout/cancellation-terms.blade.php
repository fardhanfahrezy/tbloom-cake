@php
    $cancellationDeadline = \Carbon\CarbonImmutable::parse($pickupDate, 'Asia/Jakarta')->subDays(2)->endOfDay()->startOfSecond();
    $deadlinePassed = $instant->startOfSecond()->gt($cancellationDeadline);
    $tomorrow = $pickupDate === $instant->setTimezone('Asia/Jakarta')->addDay()->toDateString();
@endphp
<section aria-label="Ketentuan pembatalan" class="mt-5 rounded-lg border border-brand-beige p-4 text-sm leading-6">
    <h2 class="font-semibold">Ketentuan pembatalan setelah pembayaran</h2>
    <p class="mt-2">Pengajuan pembatalan customer paling lambat H-2: {{ $cancellationDeadline->format('d-m-Y H:i:s') }} WIB, dan memerlukan persetujuan admin sebelum pesanan diserahkan.</p>
    <p class="mt-2">Jika disetujui, refund sebesar 80% dari total harga produk, dibulatkan ke bawah ke rupiah penuh, tanpa potongan MDR. Refund dilakukan melalui transfer manual.</p>
    @if ($deadlinePassed)
        <p class="mt-2 font-semibold">{{ $tomorrow ? 'Pengambilan besok: batas pembatalan customer untuk pesanan yang sudah dibayar telah lewat.' : 'Batas pembatalan customer untuk pesanan yang sudah dibayar telah lewat.' }}</p>
    @endif
    <p class="mt-2">Pesanan yang belum dibayar dapat meminta pembatalan tanpa batas H-2; kuota tetap ditahan sampai pembayaran ditutup secara sah tanpa pembayaran sukses.</p>
</section>
