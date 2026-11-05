<x-account-layout title="Detail request custom">
    <p class="break-words text-lg font-semibold">{{ $custom->request_number }}</p>
    <p class="mt-3" role="status">Status: {{ $custom->status->value }}</p>
    <p class="mt-3 text-sm">Request ini bukan invoice atau bukti pembayaran. Status accepted tidak berarti lunas. Penawaran dan pembayaran dibahas melalui WhatsApp di luar website.</p>
    <dl class="mt-6 grid gap-4 break-words">
        @foreach (['Nama customer' => $custom->customer_name_snapshot, 'WhatsApp' => $custom->whatsapp_snapshot, 'Tanggal pengambilan' => $custom->pickup_date->toDateString(), 'Ukuran' => $custom->size, 'Rasa' => $custom->option_labels_snapshot['flavor'], 'Filling' => $custom->option_labels_snapshot['filling'], 'Warna' => $custom->color, 'Tulisan' => $custom->inscription !== null && $custom->inscription !== '' ? $custom->inscription : 'Tanpa tulisan', 'Edible print' => $custom->edible_print ? 'Ya' : 'Tidak', 'Fondant' => $custom->fondant ? $custom->fondant_details : 'Tidak', 'Catatan' => $custom->notes !== null && $custom->notes !== '' ? $custom->notes : '—'] as $label => $value)
            <div><dt class="text-sm font-medium">{{ $label }}</dt><dd class="mt-1">{{ $value }}</dd></div>
        @endforeach
    </dl>
    @if ($custom->status_reason)<p class="mt-5">Alasan: {{ $custom->status_reason }}</p>@endif
    <h2 class="mt-6 font-semibold">Referensi privat</h2>
    <ul class="mt-3 grid gap-3">
        @foreach ($custom->attachments as $attachment)
            <li><a href="{{ route('custom-attachments.show', $attachment) }}" class="underline underline-offset-4">Unduh referensi {{ $loop->iteration }} ({{ number_format($attachment->size_bytes / 1024, 1, ',', '.') }} KB)</a></li>
        @endforeach
    </ul>
    @can('create', \App\Models\CustomCakeRequest::class)<a href="{{ route('custom-requests.create') }}" class="mt-6 inline-block underline underline-offset-4">Buat request baru</a>@endcan
    @can('manage', $custom)<a href="{{ route('staff.custom-requests.edit', $custom) }}" class="mt-6 inline-block underline underline-offset-4">Kelola request</a>@endcan
</x-account-layout>
