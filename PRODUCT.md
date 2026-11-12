# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Pelanggan retail dan perorangan di Indramayu yang mencari cake, dessert, dan hadiah personal untuk berbagai momen spesial (ulang tahun, anniversary, wisuda, perayaan, acara keluarga).

Segmen utama:
- Pemesan custom cake dan fondant cake dengan preferensi desain spesifik.
- Pembeli cupcakes dan dessert untuk konsumsi langsung atau bingkisan.
- Pelanggan dengan kebutuhan pemesanan praktis/mendesak (ready/simple cake).

## Product Purpose
Menghadirkan platform pemesanan dan katalog cake artisanal lokal di Indramayu yang memudahkan pelanggan mengeksplorasi produk, melakukan personalisasi/custom request, dan menyelesaikan pesanan (self-pickup maupun pengiriman lokal) secara transparan dan berkesan.

## Positioning
Artisan cake shop lokal terkemuka di Indramayu yang memadukan kustomisasi personal tingkat tinggi (custom & fondant cake), variasi produk siap pesan untuk kebutuhan mendadak (simple cake & cupcakes), dengan kuota produksi harian yang terukur dan transparansi serah terima (handover).

## Operating Context
- Pemesanan cake berbasis web (mobile & desktop) untuk pelanggan lokal Indramayu.
- Alur pemesanan custom memerlukan konsultasi desain (review & quotation oleh admin/owner).
- Sistem pemesanan terikat pada kuota produksi harian per produk/kategori.
- Pengambilan pesanan mandiri (in-store pickup) dengan protokol verifikasi serah terima (handover) atau opsi pengiriman lokal.
- Integrasi pembayaran digital via Midtrans Snap session dan alur pengajuan pembatalan/refund yang dikelola staf.

## Capabilities and Constraints
- Fokus produk ketat: Hanya mencakup produk cake (simple cake, custom cake, fondant cake, cupcakes, bento cake). Produk non-cake seperti flower bouquet, money bouquet, florist, atau hantaran tidak termasuk dalam ruang lingkup produk website.
- Backend & Business Logic: Menggunakan Laravel framework dengan arsitektur route, controller, middleware peran (customer, staff/admin, owner), dan database yang sudah mapan. Tidak boleh diubah tanpa kebutuhan teknis yang jelas.
- Keterbatasan inventaris & kuota harian: Pemesanan divalidasi berdasarkan ketersediaan tanggal dan kuota harian.
- Terminology: Simple Cake, Custom Request, Handover, Quota Availability, Midtrans Snap Payment.

## Brand Commitments
- Brand Promise: "More than just dessert, it's an experience. Serving sweetness, creating memories."
- Karakter & Kepribadian: Warm, playful, artistic, elegant, dan youthful.
- Prioritas Visual: Menampilkan foto produk cake asli TBloom Cake (menghindari generic stock/AI-generated visuals).
- Referensi Brand: Akun media sosial resmi (Instagram TBloom) sebagai acuan visual produk dan tone of voice lokal yang ramah.

## Evidence on Hand
- Aset video hero dan gambar produk pada `public/assets/` dan `resources/img/`.
- Katalog produk, varian, dan kategori (Simple, Bento, Cupcake, Custom) yang tersimpan pada database seeder/migration.
- Riwayat testimoni pelanggan terkelola di sistem.
- Tidak ada data fiktif untuk review, harga, atau lisensi yang boleh diada-adakan.

## Product Principles
1. Cake First: Seluruh navigasi, etalase, dan interaksi pengguna berfokus penuh pada cake dan pengalaman menikmatinya.
2. Authentic Craft: Mengutamakan visualisasi karya cake nyata, bukan ilustrasi generik atau template e-commerce kaku.
3. Clarity in Customization & Ordering: Alur pemesanan—baik kue siap pesan maupun custom—harus transparan mengenai kuota, biaya, status pengerjaan, dan ketentuan pengambilan.
4. Warm & Approachable Excellence: Pengalaman digital yang ramah dan menyenangkan bagi warga Indramayu tanpa mengorbankan kesan artisanal premium.

## Accessibility & Inclusion
- Desain responsif optimal untuk perangkat mobile (smartphone) dan desktop.
- Kontras warna yang memenuhi standar WCAG untuk keterbacaan teks informasi produk, label harga, dan formulir pemesanan.
- Navigasi keyboard dan touch target yang ramah bagi pengguna ponsel layar sentuh.
