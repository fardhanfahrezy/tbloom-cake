<?php

namespace App;

class NotificationMessage
{
    private const TEXTS = [
        'customer.order_created.v1' => 'Pesanan tercatat. Periksa status dan instruksi pembayaran di akun Anda.',
        'customer.payment_paid.v1' => 'Pembayaran tercatat. Periksa status pemenuhan di akun Anda.',
        'customer.order_completed.v1' => 'Penyerahan pesanan tercatat.',
        'customer.payment_exception.v1' => 'Pembayaran memerlukan pemeriksaan staf. Jangan melakukan pembayaran ulang.',
        'customer.refund_pending.v1' => 'Ada pembaruan pengembalian dana. Periksa rincian di akun Anda.',
        'customer.refund_completed.v1' => 'Penyelesaian pengembalian dana tercatat. Periksa rincian di akun Anda.',
        'customer.refund_transfer_recorded.v1' => 'Transfer pengembalian dana tercatat. Periksa sisa pengembalian di akun Anda.',
        'customer.order_cancelled.v1' => 'Pembatalan pesanan tercatat. Periksa status pembayaran dan pengembalian dana di akun Anda.',
        'customer.order_expired.v1' => 'Masa pembayaran pesanan berakhir. Periksa status terbaru di akun Anda sebelum mengambil tindakan.',
        'customer.cancellation_submitted.v1' => 'Pengajuan pembatalan tercatat. Periksa keputusan terbaru di akun Anda.',
        'customer.cancellation_rejected.v1' => 'Ada keputusan pengajuan pembatalan. Periksa rincian di akun Anda.',
        'customer.order_change_submitted.v1' => 'Pengajuan perubahan pesanan tercatat. Periksa keputusan terbaru di akun Anda.',
        'customer.order_change_applied.v1' => 'Perubahan pesanan tercatat. Periksa rincian di akun Anda.',
        'customer.order_change_rejected.v1' => 'Ada keputusan pengajuan perubahan pesanan. Periksa rincian di akun Anda.',
        'customer.custom_request.v1' => 'Ada pembaruan pengajuan kue custom. Periksa rincian di akun Anda.',
        'admin.new_order.v1' => 'Pesanan baru tercatat. Tinjau status terbaru melalui dashboard.',
        'admin.payment_exception.v1' => 'Pembayaran memerlukan pemeriksaan. Tinjau status terbaru melalui dashboard.',
        'admin.refund.v1' => 'Ada pembaruan pengembalian dana. Tinjau status terbaru melalui dashboard.',
        'admin.refund_assignment.v1' => 'Ada pembaruan penugasan pengembalian dana. Tinjau melalui dashboard.',
        'admin.custom_request.v1' => 'Ada pembaruan pengajuan kue custom. Tinjau melalui dashboard.',
        'admin.cancellation.v1' => 'Ada pembaruan pembatalan. Tinjau status terbaru melalui dashboard.',
    ];

    /** @param array<string, mixed> $payload */
    public function render(string $template, array $payload): ?string
    {
        $text = self::TEXTS[$template] ?? null;
        $custom = in_array($template, ['customer.custom_request.v1', 'admin.custom_request.v1'], true);
        $number = $payload[$custom ? 'request_number' : 'order_number'] ?? null;
        if ($text === null || ! is_string($number) || ! preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $number)) {
            return null;
        }

        return 'TBloom Cake'."\n".($custom ? 'Pengajuan ' : 'Pesanan ').$number."\n".$text;
    }
}
