<x-account-layout title="Pengambilan cake">
    @include('pickup-information')
    <section id="policies" aria-labelledby="policies-heading" class="my-6 space-y-3 rounded-lg border border-brand-beige p-5 text-sm leading-6">
        <h2 id="policies-heading" class="text-base font-semibold">Kebijakan pembayaran, pembatalan, refund, perubahan, &amp; kurir</h2>
        <p><strong>Pembayaran penuh (30 menit):</strong> Pesanan Simple Cake dibayar penuh sesuai harga produk tanpa ongkir website dan tanpa tambahan biaya admin/MDR ke customer, dengan batas waktu 30 menit sejak pesanan dibuat.</p>
        <p><strong>Pembatalan pesanan berbayar &amp; refund:</strong> Pembatalan oleh customer pada pesanan yang sudah dibayar diterima otomatis paling lambat H-2 pukul 23.59.59 WIB sebelum tanggal pengambilan dengan refund 80% (pembulatan ke bawah Rupiah). Pembatalan oleh toko atau pembayaran yang tercatat terlambat mendapat kewajiban refund 100%.</p>
        <p><strong>Perubahan personalisasi:</strong> Pengajuan perubahan teks cake, dekorasi ringan, atau ucapan diterima otomatis hingga H-2 pukul 23.59.59 WIB dan setelah batas tersebut ditinjau manual oleh Admin/Owner selama tidak mengubah harga, kuota, tanggal/jam ambil, atau metode pengambilan.</p>
        <p>{{ \App\CheckoutDetails::COURIER_POLICY_TEXT }}</p>
    </section>
</x-account-layout>
