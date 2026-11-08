@php
    $filters = $filters ?? [
        'order_number' => '',
        'status' => '',
        'created_date' => '',
        'pickup_date' => '',
        'pickup_method' => '',
        'customer_contact' => '',
    ];
    $orders = $orders ?? collect();
    $customRequests = $customRequests ?? collect();
    $orderRequests = $orderRequests ?? collect();
    $refundLogs = $refundLogs ?? collect();
    $paymentExceptions = $paymentExceptions ?? collect();
    $paymentEventIssues = $paymentEventIssues ?? collect();
    $notificationIssues = $notificationIssues ?? collect();
    $auditLogs = $auditLogs ?? collect();
@endphp
<x-account-layout title="Area staf" eyebrow="Dashboard operasional">
    <p>Selamat datang, {{ auth()->user()->name }}.</p>
    <div class="mt-4 flex flex-wrap gap-4 text-sm">
        <a href="{{ route('staff.products.index') }}" class="underline underline-offset-4">Kelola produk</a>
        @if (Route::has('staff.custom-requests.index'))
            <a href="{{ route('staff.custom-requests.index') }}" class="underline underline-offset-4">Kelola request Custom Cake</a>
        @endif
        @if (Route::has('staff.testimonials.index'))
            <a href="{{ route('staff.testimonials.index') }}" class="underline underline-offset-4">Kelola testimoni</a>
        @endif
        @can('viewAny', \App\Models\User::class)
            <a href="{{ route('staff.accounts.index') }}" class="text-brand-burgundy underline underline-offset-4 dark:text-brand-pink">Kelola akun staf</a>
        @endcan
    </div>

    <section aria-labelledby="staff-orders-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="staff-orders-heading" class="text-xl font-semibold">1. Pesanan Simple Cake</h2>
        <form method="GET" action="{{ route('staff.dashboard') }}" class="mt-4 grid gap-3 rounded-lg border border-brand-beige p-4 text-sm sm:grid-cols-3">
            <div>
                <label for="filter-order-number" class="block font-medium">Nomor pesanan</label>
                <input id="filter-order-number" name="order_number" type="text" value="{{ $filters['order_number'] }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
            </div>
            <div>
                <label for="filter-status" class="block font-medium">Status pesanan</label>
                <select id="filter-status" name="status" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
                    <option value="">Semua status</option>
                    @foreach (\App\Models\OrderStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected($filters['status'] === $case->value)>{{ $case->value }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-pickup-method" class="block font-medium">Metode ambil</label>
                <select id="filter-pickup-method" name="pickup_method" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
                    <option value="">Semua metode</option>
                    <option value="self_pickup" @selected($filters['pickup_method'] === 'self_pickup')>Ambil sendiri</option>
                    <option value="online_courier" @selected($filters['pickup_method'] === 'online_courier')>Kurir online</option>
                </select>
            </div>
            <div>
                <label for="filter-pickup-date" class="block font-medium">Tanggal ambil</label>
                <input id="filter-pickup-date" name="pickup_date" type="date" value="{{ $filters['pickup_date'] }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
            </div>
            <div>
                <label for="filter-created-date" class="block font-medium">Tanggal buat</label>
                <input id="filter-created-date" name="created_date" type="date" value="{{ $filters['created_date'] }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
            </div>
            <div>
                <label for="filter-customer-contact" class="block font-medium">Nama / email / WhatsApp</label>
                <input id="filter-customer-contact" name="customer_contact" type="text" value="{{ $filters['customer_contact'] }}" class="mt-1 w-full rounded border border-brand-beige px-3 py-1.5">
            </div>
            <div class="flex items-end gap-3 sm:col-span-3">
                <button type="submit" class="rounded-lg bg-brand-burgundy px-4 py-2 font-semibold text-white">Saring pesanan</button>
                <a href="{{ route('staff.dashboard') }}" class="underline underline-offset-4">Reset filter</a>
            </div>
        </form>

        @if ($orders->isEmpty())
            <p class="mt-4 text-sm text-stone-600 dark:text-stone-300">Tidak ada pesanan yang cocok dengan filter saat ini.</p>
        @else
            <div class="mt-4 overflow-x-auto" role="region" aria-labelledby="staff-orders-heading" tabindex="0">
                <table class="w-full border-collapse text-left text-sm">
                    <caption class="sr-only">Daftar operasional pesanan Simple Cake</caption>
                    <thead>
                        <tr class="border-b border-brand-beige">
                            <th scope="col" class="py-2 pr-4 font-semibold">Nomor &amp; produk</th>
                            <th scope="col" class="py-2 pr-4 font-semibold">Customer</th>
                            <th scope="col" class="py-2 pr-4 font-semibold">Jadwal &amp; metode</th>
                            <th scope="col" class="py-2 pr-4 font-semibold">Status</th>
                            <th scope="col" class="py-2 font-semibold">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-beige/60">
                        @foreach ($orders as $order)
                            <tr>
                                <th scope="row" class="py-3 pr-4 align-top font-normal">
                                    <span class="font-semibold">{{ $order->order_number }}</span>
                                    @if ($order->item)
                                        <p class="text-xs text-stone-600 dark:text-stone-300">{{ $order->item->product_name_snapshot }} ({{ $order->item->size_snapshot }})</p>
                                    @endif
                                </th>
                                <td class="py-3 pr-4 align-top text-xs">
                                    <p class="font-medium">{{ $order->customer_name_snapshot }}</p>
                                    <p>{{ $order->email_snapshot }}</p>
                                    <p>{{ $order->whatsapp_snapshot }}</p>
                                </td>
                                <td class="py-3 pr-4 align-top text-xs">
                                    <p>{{ $order->pickup_date->format('d-m-Y') }} {{ substr($order->pickup_time, 0, 5) }} WIB</p>
                                    <p>{{ $order->pickup_method === \App\Models\PickupMethod::OnlineCourier ? 'Kurir online' : 'Ambil sendiri' }}</p>
                                </td>
                                <td class="py-3 pr-4 align-top text-xs">
                                    <p>Order: <strong>{{ $order->status->value }}</strong></p>
                                    <p>Bayar: <strong>{{ $order->payment?->status?->value ?? '-' }}</strong></p>
                                    @if ($order->refundLog)
                                        <p>Refund: <strong>{{ $order->refundLog->status->value }}</strong></p>
                                    @endif
                                </td>
                                <td class="py-3 align-top text-xs">
                                    <a href="{{ route('orders.show', $order) }}" class="block underline underline-offset-4">Detail pesanan</a>
                                    <a href="{{ route('staff.orders.handover.show', $order) }}" class="mt-1 block underline underline-offset-4">Checklist penyerahan</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="staff-custom-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="staff-custom-heading" class="text-xl font-semibold">2. Pengajuan Custom Cake</h2>
        @if ($customRequests->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Belum ada pengajuan Custom Cake.</p>
        @else
            <ul class="mt-3 divide-y divide-brand-beige/60 text-sm">
                @foreach ($customRequests as $customRequest)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <div>
                            <a href="{{ route('staff.custom-requests.edit', $customRequest) }}" class="font-semibold underline underline-offset-4">{{ $customRequest->request_number }}</a>
                            <span class="ml-2 text-xs text-stone-600 dark:text-stone-300">Ambil: {{ $customRequest->pickup_date->format('d-m-Y') }} · Status: {{ $customRequest->status->value }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="staff-order-requests-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="staff-order-requests-heading" class="text-xl font-semibold">3. Pengajuan pembatalan &amp; perubahan customer</h2>
        @if ($orderRequests->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Belum ada pengajuan pembatalan atau perubahan personalisasi.</p>
        @else
            <ul class="mt-3 divide-y divide-brand-beige/60 text-sm">
                @foreach ($orderRequests as $orderRequest)
                    <li class="py-2.5">
                        <p class="font-semibold">{{ $orderRequest->order?->order_number ?? '#'.$orderRequest->order_id }} — {{ $orderRequest->type->value }} ({{ $orderRequest->status->value }})</p>
                        <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">{{ $orderRequest->reason }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="staff-refunds-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="staff-refunds-heading" class="text-xl font-semibold">4. Kewajiban &amp; eksekusi refund</h2>
        @if ($refundLogs->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Tidak ada kewajiban refund saat ini.</p>
        @else
            <ul class="mt-3 divide-y divide-brand-beige/60 text-sm">
                @foreach ($refundLogs as $refundLog)
                    <li class="py-2.5">
                        <p class="font-semibold">{{ $refundLog->order?->order_number ?? '#'.$refundLog->order_id }} — {{ $refundLog->status->value }} ({{ $refundLog->percentage }}% · Rp{{ number_format($refundLog->amount, 0, ',', '.') }})</p>
                        <p class="mt-1 text-xs">Penanggung jawab: {{ $refundLog->assignedAdmin?->name ?? 'Belum diklaim' }}</p>
                        @if ($refundLog->review_reason)
                            <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">{{ $refundLog->review_reason }}</p>
                        @endif
                        @foreach ($refundLog->transfers as $transfer)
                            <p class="mt-1 text-xs">Transfer: {{ $transfer->transfer_reference }} ({{ $transfer->status->value }})</p>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="staff-integration-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="staff-integration-heading" class="text-xl font-semibold">5. Antrean review integrasi pembayaran &amp; notifikasi</h2>
        <div class="mt-3 space-y-4 text-sm">
            <div>
                <h3 class="font-semibold">Pengecualian pembayaran &amp; event webhook</h3>
                @if ($paymentExceptions->isEmpty() && $paymentEventIssues->isEmpty())
                    <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">Tidak ada pengecualian pembayaran atau event dalam status review/failed.</p>
                @else
                    <ul class="mt-2 divide-y divide-brand-beige/60 text-xs">
                        @foreach ($paymentExceptions as $payment)
                            <li class="py-2">Pesanan {{ $payment->order?->order_number ?? '#'.$payment->order_id }} — review_reason: <strong>{{ $payment->review_reason }}</strong></li>
                        @endforeach
                        @foreach ($paymentEventIssues as $event)
                            <li class="py-2">Event {{ $event->provider_reference }} ({{ $event->processing_status->value }}) — {{ $event->getAttribute('error') }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <h3 class="font-semibold">Pengiriman notifikasi gagal / unknown</h3>
                @if ($notificationIssues->isEmpty())
                    <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">Tidak ada pengiriman notifikasi gagal atau unknown.</p>
                @else
                    <ul class="mt-2 divide-y divide-brand-beige/60 text-xs">
                        @foreach ($notificationIssues as $delivery)
                            <li class="py-2">{{ $delivery->template_key }} ({{ $delivery->entity_type }} #{{ $delivery->entity_id }} · {{ $delivery->status->value }} · percobaan {{ $delivery->attempts }}) — {{ $delivery->getAttribute('last_error') }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>

    <section aria-labelledby="staff-audit-heading" class="mt-8 border-t border-brand-beige pt-6">
        <h2 id="staff-audit-heading" class="text-xl font-semibold">6. Jejak audit operasional</h2>
        @if ($auditLogs->isEmpty())
            <p class="mt-3 text-sm text-stone-600 dark:text-stone-300">Belum ada catatan audit.</p>
        @else
            <ul class="mt-3 divide-y divide-brand-beige/60 text-xs">
                @foreach ($auditLogs as $auditLog)
                    <li class="py-2">
                        <p class="font-semibold">{{ $auditLog->action }} ({{ $auditLog->entity_type }} #{{ $auditLog->entity_id }})</p>
                        <p class="mt-0.5 text-stone-600 dark:text-stone-300">Aktor: {{ $auditLog->actor?->name ?? $auditLog->actor_type->value }} · Alasan: {{ $auditLog->reason ?? '-' }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-account-layout>
