<x-account-layout title="Akun saya">
    <p class="text-lg">Halo, {{ $user->name }}.</p>
    <p class="mt-3 text-sm text-brand-taupe dark:text-stone-300">Anda sudah masuk ke akun TBloom Cake.</p>
    @if (Route::has('profile.edit'))
        <a href="{{ route('profile.edit') }}" class="mt-6 inline-block font-medium text-brand-burgundy underline underline-offset-4 dark:text-brand-pink">Kelola profil</a>
    @endif

    <section aria-labelledby="account-orders-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="account-orders-heading" class="text-xl font-semibold">Riwayat pesanan Simple Cake</h2>
        @if (($orders ?? collect())->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Belum ada pesanan Simple Cake pada akun ini.</p>
        @else
            <div class="mt-4 overflow-x-auto" role="region" aria-labelledby="account-orders-heading" tabindex="0">
                <table class="w-full border-collapse text-left text-sm">
                    <caption class="sr-only">Daftar pesanan Simple Cake milik {{ $user->name }} beserta status pesanan, pembayaran, dan refund</caption>
                    <thead>
                        <tr class="border-b border-brand-beige">
                            <th scope="col" class="py-3 pr-4 font-semibold">Pesanan</th>
                            <th scope="col" class="py-3 pr-4 font-semibold">Status pesanan</th>
                            <th scope="col" class="py-3 pr-4 font-semibold">Status pembayaran</th>
                            <th scope="col" class="py-3 font-semibold">Status refund</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-beige/60">
                        @foreach ($orders as $order)
                            @php
                                $orderStatusLabel = match ($order->status) {
                                    \App\Models\OrderStatus::Pending => 'Menunggu pembayaran',
                                    \App\Models\OrderStatus::Paid => 'Pembayaran terverifikasi',
                                    \App\Models\OrderStatus::Completed => 'Pesanan telah diserahkan',
                                    \App\Models\OrderStatus::Expired => 'Pesanan kedaluwarsa',
                                    \App\Models\OrderStatus::Cancelled => 'Pesanan dibatalkan',
                                };
                                $paymentStatusLabel = match ($order->payment?->status) {
                                    \App\Models\PaymentStatus::Pending => $order->payment?->review_reason !== null
                                        ? 'Pembayaran sedang diperiksa — Jangan membayar ulang'
                                        : 'Menunggu pembayaran',
                                    \App\Models\PaymentStatus::Paid => 'Pembayaran diterima',
                                    \App\Models\PaymentStatus::Failed => 'Pembayaran gagal',
                                    \App\Models\PaymentStatus::Expired => 'Pembayaran kedaluwarsa',
                                    \App\Models\PaymentStatus::Cancelled => 'Pembayaran dibatalkan',
                                    \App\Models\PaymentStatus::PartiallyRefunded => 'Refund sebagian selesai',
                                    \App\Models\PaymentStatus::Refunded => 'Refund penuh selesai',
                                    default => 'Belum ada catatan pembayaran',
                                };
                                $refundStatusLabel = match ($order->refundLog?->status) {
                                    \App\Models\RefundLogStatus::Pending => 'Refund menunggu tindak lanjut',
                                    \App\Models\RefundLogStatus::Processing => 'Refund sedang diproses',
                                    \App\Models\RefundLogStatus::Review => 'Refund sedang ditinjau',
                                    \App\Models\RefundLogStatus::Completed => 'Refund selesai',
                                    default => 'Tidak ada kewajiban refund',
                                };
                            @endphp
                            <tr>
                                <th scope="row" class="py-4 pr-4 align-top font-normal">
                                    <a href="{{ route('orders.show', $order) }}" class="font-semibold text-brand-burgundy underline underline-offset-4 dark:text-brand-pink">{{ $order->order_number }}</a>
                                    @if ($order->item)
                                        <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">{{ $order->item->product_name_snapshot }} — {{ $order->item->size_snapshot }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">Ambil: {{ $order->pickup_date->format('d-m-Y') }} · Rp{{ number_format($order->product_total, 0, ',', '.') }}</p>
                                </th>
                                <td class="py-4 pr-4 align-top">{{ $orderStatusLabel }}</td>
                                <td class="py-4 pr-4 align-top">{{ $paymentStatusLabel }}</td>
                                <td class="py-4 align-top">
                                    <p>{{ $refundStatusLabel }}</p>
                                    @if ($order->refundLog)
                                        <p class="mt-1 text-xs">{{ $order->refundLog->percentage }}% — Rp{{ number_format($order->refundLog->amount, 0, ',', '.') }}</p>
                                        <a href="{{ route('refunds.bank.show', $order->refundLog) }}" class="mt-1 inline-block text-xs underline underline-offset-4">Kelola rekening refund</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="account-custom-requests-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="account-custom-requests-heading" class="text-xl font-semibold">Riwayat pengajuan Custom Cake</h2>
        @if (($customRequests ?? collect())->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Belum ada pengajuan Custom Cake pada akun ini.</p>
        @else
            <ul class="mt-4 divide-y divide-brand-beige/60 text-sm">
                @foreach ($customRequests as $customRequest)
                    @php
                        $customStatusLabel = match ($customRequest->status) {
                            \App\Models\CustomCakeRequestStatus::Submitted => 'Menunggu tinjauan',
                            \App\Models\CustomCakeRequestStatus::Contacted => 'Sudah dihubungi',
                            \App\Models\CustomCakeRequestStatus::Quoted => 'Penawaran harga dikirim',
                            \App\Models\CustomCakeRequestStatus::Accepted => 'Disetujui',
                            \App\Models\CustomCakeRequestStatus::Rejected => 'Ditolak',
                        };
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <a href="{{ route('custom-requests.show', $customRequest) }}" class="font-semibold text-brand-burgundy underline underline-offset-4 dark:text-brand-pink">{{ $customRequest->request_number }}</a>
                            <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">Rencana ambil: {{ $customRequest->pickup_date->format('d-m-Y') }}</p>
                        </div>
                        <span class="rounded bg-brand-beige/40 px-2.5 py-1 text-xs font-medium">{{ $customStatusLabel }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-account-layout>
