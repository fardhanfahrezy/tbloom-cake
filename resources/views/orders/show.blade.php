@php
    $pending = $order->status === \App\Models\OrderStatus::Pending;
    $remaining = max(0, $order->payment_expires_at->getTimestamp() - $instant->getTimestamp());
    $paymentReview = $pending && $order->payment?->review_reason !== null;
    $status = match ($order->status) {
        \App\Models\OrderStatus::Pending => $paymentReview ? 'Pembayaran sedang diperiksa' : ($remaining > 0 ? 'Menunggu pembayaran' : 'Menunggu kepastian pembayaran'),
        \App\Models\OrderStatus::Paid => 'Pembayaran terverifikasi',
        \App\Models\OrderStatus::Completed => 'Pesanan telah diserahkan',
        \App\Models\OrderStatus::Expired => 'Pesanan kedaluwarsa',
        \App\Models\OrderStatus::Cancelled => 'Pesanan dibatalkan',
    };
@endphp
<x-account-layout eyebrow="Detail pesanan" :title="$order->order_number">
    <p class="rounded-lg border border-brand-beige p-4 font-semibold" role="status">{{ $status }}</p>
    <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
        <div><dt class="font-semibold">Pembeli</dt><dd class="mt-1 break-words">{{ $order->customer_name_snapshot }}<br>{{ $order->email_snapshot }}<br>{{ $order->whatsapp_snapshot }}</dd></div>
        <div><dt class="font-semibold">Pengambilan di toko</dt><dd class="mt-1">{{ $order->pickup_date->format('d-m-Y') }}, {{ substr($order->pickup_time, 0, 5) }} WIB<br>{{ $order->pickup_method === \App\Models\PickupMethod::OnlineCourier ? 'Kurir online' : 'Ambil sendiri' }}</dd></div>
        <div><dt class="font-semibold">Produk dan ukuran</dt><dd class="mt-1">{{ $order->item->product_name_snapshot }} — {{ $order->item->size_snapshot }}<br>{{ $order->item->quantity }} {{ $order->item->sales_unit_snapshot }} × Rp{{ number_format($order->item->unit_price, 0, ',', '.') }}</dd></div>
        <div><dt class="font-semibold">Rasa dan filling</dt><dd class="mt-1">{{ $order->item->flavor_label_snapshot }}<br>{{ $order->item->filling_label_snapshot }}</dd></div>
        <div><dt class="font-semibold">Personalisasi</dt><dd class="mt-1 break-words">Cover: {{ $order->item->cover_color }}<br>Tulisan: {{ $order->item->inscription === '' ? 'Tanpa tulisan' : $order->item->inscription }}</dd></div>
        <div><dt class="font-semibold">Catatan penyerahan</dt><dd class="mt-1 break-words">{{ $order->handover_note ?? 'Tidak ada catatan' }}</dd></div>
    </dl>
    <p class="mt-6 border-t border-brand-beige pt-5 text-2xl font-semibold">Total produk Rp{{ number_format($order->product_total, 0, ',', '.') }}</p>
    <p class="mt-2 text-sm">Tidak termasuk ongkir kurir; tidak ada tambahan MDR.</p>
    @include('checkout.cancellation-terms', ['pickupDate' => $order->pickup_date->toDateString(), 'instant' => $instant])
    @if ($pending)
        <section class="mt-6 rounded-lg border border-brand-beige p-5" data-order-countdown data-remaining-seconds="{{ $remaining }}">
            <h2 class="font-semibold">Batas aksi pembayaran</h2>
            <p class="mt-2">{{ $order->payment_expires_at->setTimezone('Asia/Jakarta')->format('d-m-Y H:i:s') }} WIB</p>
            <p class="mt-2 font-mono text-xl" data-countdown-text>{{ gmdate('i:s', $remaining) }}</p>
            <p class="mt-3 text-sm" data-countdown-notice>{{ $remaining <= 0 ? 'Batas aksi bayar telah lewat. Status menunggu kepastian; kuota tetap ditahan.' : ($paymentReview ? 'Jangan membayar ulang. Pembayaran sedang diperiksa dan kuota tetap ditahan.' : ($paymentAvailable ? 'Gunakan BCA VA sebelum batas waktu pembayaran.' : 'Pembayaran online belum tersedia untuk pesanan ini.')) }}</p>
            @can('pay', $order)
                @if ($remaining > 0 && $paymentAvailable && $order->payment?->status === \App\Models\PaymentStatus::Pending && in_array($order->payment->review_reason, [null, 'snap_creation_unknown'], true))
                    <form method="POST" action="{{ route('orders.payment-session.store', $order) }}" class="mt-4">
                        @csrf
                        <button data-payment-submit class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-burgundy disabled:opacity-50 dark:bg-brand-pink dark:text-brand-dark">{{ $paymentReview ? 'Periksa pembayaran' : 'Bayar dengan BCA VA' }}</button>
                    </form>
                @endif
            @endcan
            <a href="{{ route('orders.show', $order) }}" class="mt-4 inline-block text-sm underline underline-offset-4">Muat ulang status</a>
        </section>
    @endif
    @if ($order->policy_text_snapshot)
        <section class="mt-6 border-t border-brand-beige pt-5 text-sm leading-6"><h2 class="font-semibold">Kebijakan kurir yang disetujui</h2><p class="mt-2">{{ $order->policy_text_snapshot }}</p></section>
    @endif
    <a href="{{ route('catalog.index') }}" class="mt-6 inline-block underline underline-offset-4">Kembali ke katalog</a>
</x-account-layout>
