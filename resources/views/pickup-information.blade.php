<section aria-labelledby="pickup-information-heading" class="my-6 rounded-lg border border-brand-beige p-5 text-sm leading-6">
    <h2 id="pickup-information-heading" class="font-semibold">Informasi pengambilan</h2>
    <address class="mt-3 break-words not-italic">{{ config('store.pickup_address') }}<br>
        Kontak toko: <a href="tel:{{ config('store.contact_international') }}" class="underline underline-offset-4">{{ config('store.contact_display') }}</a>
    </address>
    <p class="mt-3">Produksi 09.00–17.00 WIB. Penyerahan 09.00–21.00 WIB.</p>
    <p class="mt-3">Batas tanggal pengambilan Simple Cake dihitung saat pembayaran berhasil: pukul 00.00.00–17.00.00 WIB paling cepat diambil besok (H+1), sedangkan pukul 17.00.01 WIB–23.59.59 WIB paling cepat diambil lusa (H+2). Custom Cake memerlukan waktu tunggu H-3 untuk desain standar dan H-7 untuk edible print.</p>
    <p class="mt-3">{{ config('store.courier_instructions') }}</p>
    <p class="mt-3">Tunjukkan nomor pesanan saat pengambilan. Staf memeriksa kesesuaian customer atau kurir dan catatan penyerahan sebelum menyerahkan cake.</p>
    <p class="mt-3">Website menerima pemesanan 24 jam. Jika customer atau kurir terlambat, hubungi toko; pesanan tidak otomatis dibatalkan atau ditandai selesai karena tidak datang.</p>
</section>
