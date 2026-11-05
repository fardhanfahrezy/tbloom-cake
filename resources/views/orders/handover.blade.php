<x-account-layout eyebrow="Area staf" title="Pemeriksaan penyerahan">
    <a href="{{ route('orders.show', $order) }}" class="underline underline-offset-4">Lihat detail pesanan</a>
    <p class="mt-5 break-words text-lg font-semibold">{{ $order->order_number }}</p>
    <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
        <div><dt class="font-semibold">Customer</dt><dd class="mt-1 break-words">{{ $order->customer_name_snapshot }}<br>{{ $order->whatsapp_snapshot }}</dd></div>
        <div><dt class="font-semibold">Jadwal</dt><dd class="mt-1">{{ $order->pickup_date->format('d-m-Y') }} {{ substr($order->pickup_time, 0, 5) }} WIB</dd></div>
        <div><dt class="font-semibold">Metode</dt><dd class="mt-1">{{ $order->pickup_method === \App\Models\PickupMethod::OnlineCourier ? 'Kurir online yang dipesan customer' : 'Ambil sendiri' }}</dd></div>
        <div><dt class="font-semibold">Catatan penyerahan</dt><dd class="mt-1 break-words">{{ $order->handover_note ?? 'Tidak ada catatan' }}</dd></div>
        @if ($order->item)<div><dt class="font-semibold">Cake yang diserahkan</dt><dd class="mt-1 break-words">{{ $order->item->product_name_snapshot }} · {{ $order->item->size_snapshot }} · {{ $order->item->quantity }} {{ $order->item->sales_unit_snapshot }}<br>{{ $order->item->flavor_label_snapshot }} / {{ $order->item->filling_label_snapshot }}<br>{{ $order->item->cover_color }} · {{ $order->item->inscription === '' ? 'Tanpa tulisan' : $order->item->inscription }}</dd></div>@endif
    </dl>
    @include('pickup-information')
    <h2 class="mt-6 font-semibold">Cocokkan nomor pesanan dan pihak pengambil</h2>
    <ol class="mt-3 list-inside list-decimal space-y-3 text-sm leading-6">
        <li>Periksa nomor pesanan yang ditunjukkan customer atau kurir dengan nomor di halaman ini.</li>
        <li>Cocokkan pengambil dan catatan customer. Untuk kurir, cocokkan nama atau kode booking Gojek/Grab; hubungi customer jika belum sesuai.</li>
        <li>Pastikan pembayaran terverifikasi dan tidak ada pengajuan pembatalan yang belum diputuskan. Serahkan cake sebelum mencatat penyelesaian.</li>
    </ol>
    <p class="mt-5 text-sm" role="status">Status pesanan: {{ $order->status->value }}. Membuka halaman ini tidak menyelesaikan pesanan.</p>
    @php($cancellationPending = $order->requests->contains(fn ($request) => $request->type === \App\Models\OrderRequestType::Cancellation && $request->status === \App\Models\OrderRequestStatus::Pending))
    @if ($cancellationPending)
        <p role="alert" class="mt-5 rounded-lg border border-brand-taupe p-4">Penyerahan ditahan sampai pengajuan pembatalan diputuskan. Jika disetujui, jangan serahkan cake walaupun refund belum selesai.</p>
    @elseif ($order->status === \App\Models\OrderStatus::Completed)
        <p class="mt-5">Sudah diserahkan pada <time datetime="{{ $order->completed_at->toIso8601String() }}">{{ $order->completed_at->setTimezone('Asia/Jakarta')->format('d-m-Y H:i:s') }} WIB</time>.</p>
    @elseif ($order->status === \App\Models\OrderStatus::Paid && $order->payment?->status === \App\Models\PaymentStatus::Paid && $order->payment->review_reason === null)
        @if ($errors->any())<div role="alert" class="mt-5 text-sm"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('staff.orders.handover.store', $order) }}" class="mt-6 grid gap-5">
            @csrf
            <input type="hidden" name="expected_version" value="{{ is_string(old('expected_version')) ? old('expected_version') : $version }}">
            <input type="hidden" name="idempotency_key" value="{{ is_string(old('idempotency_key')) ? old('idempotency_key') : $idempotencyKey }}">
            <x-form-field name="order_number" label="Ketik ulang nomor pesanan yang ditunjukkan pengambil" maxlength="40" autocomplete="off" required />
            <label class="flex items-start gap-3"><input type="checkbox" name="recipient_confirmed" value="1" required class="mt-1"> Pengambil dan catatan penyerahan sudah saya cocokkan dengan customer atau kurir yang sesuai.</label>
            <label class="flex items-start gap-3"><input type="checkbox" name="handover_confirmed" value="1" required class="mt-1"> Cake sudah benar-benar saya serahkan. Saya akan mencatat penyelesaiannya sekarang.</label>
            <p class="text-sm">Status pembayaran, pembatalan, dan detail pesanan diperiksa ulang oleh server. Tindakan ini mencatat penyerahan pada 09.00–21.00 WIB.</p>
            <button class="rounded-lg bg-brand-burgundy px-5 py-3 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-4 dark:bg-brand-beige dark:text-brand-dark">Catat penyerahan selesai</button>
        </form>
    @else
        <p class="mt-5" role="status">Pesanan belum dapat diserahkan. Periksa status pembayaran atau keputusan pembatalannya.</p>
    @endif
</x-account-layout>
