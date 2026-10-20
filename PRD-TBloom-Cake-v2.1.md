# Product Requirements Document — Website TBloom Cake

**Versi:** 2.1  
**Tanggal baseline:** 16 Oktober 2026  
**Finalisasi R1:** 19 Oktober 2026  
**Pemilik produk:** TBloom Cake  
**Pembaca:** Owner, Product Manager, Tech Lead, Developer, QA, dan Admin operasional  
**Status:** Finalisasi dokumentasi R1 19 Oktober 2026; mempertahankan keputusan Owner sebelumnya; rancangan teknis belum diimplementasikan atau diuji. Status asumsi dan keputusan terbaru pada bagian 1.3–1.4; Batas input, strategi retry, penerima dan prinsip retensi sudah disetujui K-17–20; angka retry TL dan durasi retensi final masih tertunda.  
**Zona waktu bisnis:** `Asia/Jakarta` (WIB)

Dokumen ini menggantikan ketentuan PRD v2.0 yang bertentangan dengannya. Penghapusan guest checkout, ongkir otomatis, dan integrasi Google Maps bersifat final untuk MVP. Dokumen ini merupakan spesifikasi, bukan pernyataan bahwa fitur telah diimplementasikan atau diuji.

## 1. Ringkasan Eksekutif dan Changelog v2.1

### 1.1 Ringkasan eksekutif

Website TBloom Cake menyediakan katalog publik, pemesanan Simple Cake dengan pembayaran penuh melalui Midtrans, form request Custom Cake, testimoni, dan dashboard operasional. Customer wajib memiliki akun untuk membuat order maupun request custom. Registrasi langsung mengaktifkan akun; verifikasi email/WhatsApp tidak menjadi penghalang pemesanan pada MVP.

Kuota harian dihitung per produk berdasarkan jumlah unit yang dibeli. Customer mempunyai waktu pembayaran 30 menit sejak order dibuat. Pembayaran yang dilakukan tepat waktu dan terverifikasi melalui gateway tetap dipenuhi walaupun webhook atau verifikasi lokal terlambat. Deadline pembayaran bukan bukti bahwa customer belum membayar. Pelepasan kuota otomatis hanya berdasarkan jam lokal dicabut. Rancangan Tech Lead: reservasi tetap ditahan selama status pembayaran belum pasti; expiry final dan pelepasan kuota memerlukan hasil pemeriksaan provider yang dapat dipercaya. Rekonsiliasi otomatis berjalan 24/7 dan pemeriksaan manual 09.00–17.00 WIB; reservasi ambigu tetap ditahan, bukan perpanjangan waktu bayar customer. Tidak ada SLA customer saat ini; staf menangani pada jam operasional. Strategi retry terbatas+backoff lalu review manual disetujui; angka retry/jeda diusulkan Tech Lead.

Pemesanan online tersedia 24/7. Pemesanan Simple Cake sampai tepat pukul 17.00.00 WIB dapat memilih pengambilan paling awal besok; setelah waktu tersebut paling awal lusa. Produksi/pemrosesan toko berjalan 09.00–17.00 WIB, sedangkan penyerahan cake tersedia 09.00–21.00 WIB.

Metode pengambilan adalah **Ambil Sendiri (Self Pickup)** atau **Diambil Kurir Online (Grab/Gojek)**. Customer memesan dan membayar kurir sendiri. Website tidak meminta alamat tujuan, nomor penerima khusus, maupun ongkir dan tidak mengintegrasikan Google Maps.

Refund customer yang memenuhi batas H-2 sebesar 80% dari total harga produk, dibulatkan ke bawah. Baseline refund 100% untuk pembatalan toko dan pembayaran yang benar-benar terlambat tetap terpisah dari keterlambatan webhook. Pembayaran tepat waktu tidak direfund hanya karena terlambat diverifikasi. Admin yang ditugaskan menjalankan transfer manual; Owner mengendalikan pengecualian dan pengambilalihan tugas. Handover ditahan selama pengajuan pembatalan yang memenuhi syarat belum diputuskan.

Custom Cake hanya menjadi request terstruktur. Negosiasi serta DP 50% dilakukan dan dicatat di luar sistem melalui komunikasi WhatsApp. Fonnte pada MVP mengirim notifikasi transaksi kepada customer pemilik; Admin hanya menerima event payment_exception, refund, custom_request, cancellation, new_order dengan template data minimum sesuai §7.6. Status produksi bertahap dan laporan penjualan otomatis ditunda.

### 1.2 Changelog

| Area | v2.0 | v2.1 |
| --- | --- | --- |
| Akun customer | Guest checkout | Akun wajib; langsung aktif setelah registrasi |
| Kuota | Ambigu antara transaksi/unit dan produk/varian | Unit jual per produk per tanggal; ukuran berbagi kuota produk |
| Pengiriman | Delivery dengan ongkir berbasis jarak | Self pickup atau kurir yang dipesan customer |
| Google Maps | Distance Matrix untuk ongkir | Dihapus; tidak diganti layanan jarak lain |
| Checkout | Alamat, penerima, ongkir | Tanpa ketiga data tersebut; satu catatan penyerahan opsional |
| Cutoff Simple Cake | H-1 belum memiliki batas jam tegas | H-1 kalender, cutoff inklusif 17.00.00 WIB |
| Pembayaran | Expiry 30 menit; retry belum tegas | R1: batas waktu bayar tetap 30 menit; verifikasi terlambat tidak membatalkan pembayaran tepat waktu |
| Late payment | Belum memiliki penyelesaian | R1: bedakan pembayaran benar-benar terlambat dari webhook terlambat; lihat F-006 |
| Refund customer | 80% total pembayaran, metode belum pasti | 80% harga produk, floor rupiah, transfer manual dan audit |
| Refund toko | Belum tegas | 100% dana produk yang diterima |
| Custom Cake | Batas form/order belum jelas | Request saja; DP 50% dan pencatatannya di luar sistem |
| WhatsApp | Scope saat ini/future bertentangan | Order baru, pembayaran berhasil/expired, request baru masuk MVP |
| Produksi dan laporan | Scope belum konsisten | Status produksi bertahap dan laporan otomatis future development |

### 1.3 Asumsi implementasi tambahan

A-01–A-05 dan A-09 telah disetujui dengan penajaman K-06–K-13 pada §1.4. A-07 lama ditolak/diganti K-01. A-06 batas input awal dan strategi retry, A-08 penerima, serta prinsip retensi sudah disetujui K-17–20. Tidak ada SLA customer saat ini. Hanya angka percobaan/jeda usulan Tech Lead dan durasi retensi final yang belum ditetapkan dalam keputusan ini. Persetujuan bisnis tidak berarti implementasi atau test selesai.

| ID | Asumsi/default implementasi |
| --- | --- |
| A-01 | Satu checkout MVP berisi satu produk, satu ukuran, satu kombinasi personalisasi, dan quantity ≥ 1. Skema order_items disiapkan untuk pengembangan multi-item. |
| A-02 | Satu unit adalah satu unit jual katalog; cupcake yang dijual per box dihitung per box dan label kemasan wajib terlihat. Semua ukuran berbagi kuota produk. |
| A-03 | H-2 customer berakhir pukul 23.59.59 WIB dua tanggal sebelum pengambilan. Penilaian memakai waktu pengajuan; admin boleh memprosesnya setelah batas, sebelum penyerahan. |
| A-04 | H-3/H-7 Custom Cake memakai selisih tanggal kalender, tanpa cutoff 17.00 tambahan. Tidak ada kalkulasi hari kerja. |
| A-05 | Pembatalan MVP berlaku untuk seluruh order. Perubahan yang tidak memengaruhi harga/kuota dapat diajukan; perubahan produk, ukuran, jumlah, atau tanggal tidak diterapkan sebagai edit langsung pada order lunas. |
| A-06 | DISETUJUI: batas input awal tetap; retry otomatis terbatas+backoff lalu review manual. TL mengusulkan jumlah/jeda. Tidak ada SLA customer; staf menangani pada jam operasional. Target teknis non-input tidak otomatis disahkan sebagai SLA oleh keputusan ini. |
| A-07 | DITOLAK/DIGANTI K-01: pembayaran tepat waktu tetap dipenuhi meskipun webhook/verifikasi lokal terlambat; bukan alasan refund otomatis. |
| A-08 | DISETUJUI: customer menerima notifikasi transaksinya sendiri. Admin menerima payment_exception, refund, custom_request, cancellation, new_order dengan template minimum sesuai kebutuhan; tidak seluruh data customer. |
| A-09 | DISETUJUI: horizon D+90 inklusif untuk katalog/custom; maksimum tiga pending yang masih menahan kuota per akun, termasuk review setelah deadline; paid dikecualikan. Perubahan angka membutuhkan keputusan bisnis baru. Tanggal katalog ditutup melalui kuota nol. |

### 1.4 Keputusan Owner R1 dan batas persetujuan

| ID | Keputusan |
|---|---|
| K-01 | Bayar tepat waktu tetap dipenuhi setelah verifikasi walau webhook terlambat. Deadline aksi bayar tetap30 menit; A-07 lama ditolak. |
| K-02 | Admin ditugaskan eksklusif untuk refund; Owner mengendalikan pengecualian/takeover. |
| K-03 | Cancellation eligible pending menahan handover. |
| K-04 | Anomali nominal terkonfirmasi: review Owner, tidak dipenuhi, ditutup; kembalikan dana terverifikasi yang masih menjadi hak customer setelah rekonsiliasi. |
| K-05 | Gateway ambigu tetap menahan kuota. Rekonsiliasi otomatis24/7, manual09–17 WIB. |
| K-06/A-03 | H-2 sampai23.59.59 WIB pada pickup_date minus dua tanggal; memakai waktu pengajuan. |
| K-07 | Takeover unknown tidak membolehkan transfer ulang; cek mutasi: sukses dibuktikan, gagal terkonfirmasi boleh retry, unknown tetap blokir. |
| K-08/A-05 | Cancellation seluruh order saja. |
| K-09/A-01 | Satu produk/ukuran/personalisasi per checkout, quantity≥1. |
| K-10/A-02 | Kuota per unit jual katalog, seluruh ukuran berbagi produk/tanggal. |
| K-11/A-09 | Maksimum3 pending yang masih memegang kuota per akun termasuk review; paid tidak dihitung. Horizon katalog/custom D+90 inklusif. |
| K-12/A-04 | Custom H-3 atau edible print H-7 kalender tanpa cutoff17.00; menambah print tetap H-7 saat perubahan. |
| K-13/A-05 | Edit simple hanya flavor/filling/color/inscription tanpa perubahan harga/kuota, H-2 dan approval. Pickup time/method tetap; catatan kurir boleh sebelum handover. Produk/ukuran/qty/tanggal harus cancel sah dan order baru. |
| K-14 | Pending unpaid boleh meminta cancel tanpa H-2/approval; release menunggu closure gateway sah tanpa pembayaran sukses. |
| K-15 | Timely payment sebelum gateway ditutup menang atas cancel pending: paid dan dipenuhi; cancellation berikutnya H-2/80%. |
| K-16 | Completed tetap mengonsumsi kuota. Release sah hanya sekali. |
| K-17 / A-06 input | DISETUJUI: pertahankan seluruh batas input awal PRD; tidak mengubah scope. |
| K-18 / A-06 retry dan layanan | DISETUJUI: retry otomatis terbatas dengan backoff, lalu antrean review manual; Tech Lead mengusulkan angka percobaan/jeda. Tidak ada SLA customer saat ini; staf memantau dan menangani pada jam operasional. |
| K-19 / A-08 | DISETUJUI: customer menerima notifikasi transaksinya sendiri; Admin hanya event penting payment_exception, refund, custom_request, cancellation, new_order. Gunakan template sesuai kebutuhan dengan data minimum. |
| K-20 / Retensi | DISETUJUI: retensi per kelas data; transaksi/refund mengikuti kebutuhan usaha dan kewajiban yang berlaku, log teknis lebih singkat; hapus/anonymize PII ketika tidak diperlukan. Purge otomatis dilarang sampai durasi final disetujui. |

K-04–16 adalah penomoran dokumentasi atas keputusan yang sudah diberikan. Persentase80% customer dan100% toko/actual late mengikuti PRD. Ini bukan persetujuan implementasi atau hasil test.


Kontrak teknis, data dictionary, lock order, state, recovery dan gate ada pada [Database Schema R1](Database-Schema.md). Dokumen pendamping: Architecture-Document.md, Laravel-Structure-Document.md, Acceptance-Criteria.md, Test-Cases.md, Traceability-Matrix.md. Semuanya spesifikasi; Batas input, strategi retry, penerima dan prinsip retensi sudah disetujui K-17–20; angka retry TL dan durasi retensi final masih tertunda. Tidak ada implementasi bisnis atau migration baru pada finalisasi ini.

## 2. Product Overview dan Goal

### 2.1 Problem statement

1. Detail pesanan custom melalui chat mudah tidak lengkap dan sulit ditelusuri.
2. Customer belum dapat memesan Simple Cake secara mandiri dengan informasi kuota yang andal.
3. Kapasitas dapat terlampaui jika quantity, checkout bersamaan, dan order pending tidak ditangani konsisten.
4. Admin memerlukan catatan order, request, pembayaran, dan refund yang dapat diaudit.

### 2.2 Target user dan tujuan

| Pengguna | Kebutuhan | Indikator keberhasilan |
| --- | --- | --- |
| Customer registered | Memesan dan membayar Simple Cake; mengirim request custom | Alur selesai tanpa chat untuk Simple Cake; request custom tersimpan lengkap |
| Admin | Mengelola produk, kuota, order, request, refund, testimoni | Kasus operasional dapat ditangani melalui dashboard dengan jejak audit |
| Owner | Seluruh fungsi admin dan pengelolaan akun staf | Hak akses staf dapat dibuat, dinonaktifkan, dan ditinjau |

Keberhasilan MVP dibuktikan melalui acceptance criteria: tidak ada overselling, pembayaran tepat waktu tetap dipenuhi meski verifikasi terlambat, expiry final hanya berdasarkan hasil yang sah, refund tidak tercatat ganda, dan tidak ada kebocoran order antar-customer. Target konversi komersial ditentukan setelah tersedia data penggunaan.

### 2.3 In scope

- Katalog Simple Cake, Cupcake, dan Bento Cake, harga per ukuran, foto, serta availability per tanggal.
- Registrasi, login, logout, pemulihan password via email, profil customer, dan riwayat milik sendiri.
- Checkout berakun, personalisasi sederhana, kuota per unit, batas kalender/cutoff, dan pengambilan di toko.
- Pembayaran penuh Midtrans; MDR ditanggung toko; expiry dan rekonsiliasi late payment.
- Pembatalan customer/toko, perubahan terbatas, refund manual dengan bukti dan audit.
- Custom request dengan upload referensi dan tindak lanjut manual.
- Dashboard admin produk, kuota, order, request, refund, dan testimoni.
- Notifikasi Fonnte sesuai bagian 7.6.
- Company profile, informasi toko/pickup, dan identitas visual TBloom.

### 2.4 Out of scope dan future development

| Dihapus/tidak tersedia pada MVP | Pengembangan berikutnya bila disetujui |
| --- | --- |
| Guest checkout | Verifikasi nomor WhatsApp/email wajib |
| Ongkir otomatis, Google Maps, alamat tujuan, nomor penerima khusus | Integrasi pengiriman yang dikelola toko |
| Pemesanan/pembayaran Grab/Gojek oleh website | Tracking kurir otomatis |
| Refund otomatis melalui API gateway | Otomasi refund setelah evaluasi bisnis |
| Pembayaran atau pencatatan DP Custom Cake dalam sistem | Konversi custom request menjadi order berbayar |
| CRUD master rasa/filling | Pengelolaan master pilihan jika kelak diperlukan |
| COD dan cicilan internal | Promo/loyalty sesuai keputusan baru |
| Status produksi bertahap dan notifikasinya | Timeline produksi dan notifikasi lanjutan |
| Laporan penjualan/penjelajahan otomatis, grafik revenue, ekspor analitik | Reporting dan analitik Owner |
| Checkout multi-item, pembatalan parsial, order offline di dashboard | Perluasan proses penjualan |

Daftar order dengan pencarian/filter dan audit transaksi tetap masuk MVP; keduanya bukan laporan analitik. Custom request tidak mengonsumsi kuota Simple Cake. Alokasi produksi custom/offline diatur toko di luar sistem; admin dapat menyesuaikan kuota Simple Cake yang masih tersedia.

## 3. User Roles dan Permissions

Tidak ada role atau akun Guest. Halaman publik tetap dapat dibaca pengunjung tanpa login; seluruh aksi pemesanan membutuhkan akun customer aktif.

| Kemampuan | Customer registered | Admin | Owner |
| --- | --- | --- | --- |
| Melihat katalog/company profile/testimoni terbit | Ya | Ya | Ya |
| Membuat order/request dengan identitas customer | Ya | Bukan alur penjualan offline | Bukan alur penjualan offline |
| Melihat order/request | Milik sendiri | Semua | Semua |
| Mengajukan pembatalan/perubahan | Milik sendiri, sesuai aturan | Memproses pengajuan | Memproses pengajuan |
| Kelola produk, ukuran, foto, kuota | Tidak | Ya | Ya |
| Kelola status custom request | Tidak | Ya | Ya |
| Membatalkan atas nama toko | Tidak | Ya, alasan wajib | Ya, alasan wajib |
| Menandai order selesai diserahkan | Tidak | Ya, jika paid | Ya, jika paid |
| Mencatat refund dan bukti transfer | Tidak | Ya, sesuai penugasan | Ya, termasuk mengendalikan pengecualian/takeover |
| Mengubah payment gateway menjadi sukses secara manual | Tidak | Tidak | Tidak |
| Kelola testimoni | Tidak | Ya | Ya |
| Membuat/menonaktifkan akun Admin | Tidak | Tidak | Ya |
| Melihat laporan penjualan otomatis | Tidak tersedia | Tidak tersedia | Future development |

### 3.1 Akun dan keamanan akses

- Registrasi: nama 2–100 karakter, email valid maksimal 254 karakter, nomor WhatsApp valid yang dinormalisasi, password minimal 12 karakter dan konfirmasi password.
- Email dan nomor WhatsApp normalized unik per akun sebagai default MVP. Registrasi publik selalu menghasilkan role Customer; payload role dari browser diabaikan.
- Akun langsung aktif. `email_verified_at` dapat disediakan, tetapi tidak menjadi syarat checkout. Sistem tidak boleh mengklaim email/WhatsApp telah terverifikasi.
- Password disimpan menggunakan hashing Laravel. Session diregenerasi setelah login; logout mengakhiri session. Reset password memakai token berumur terbatas via email dan pesan respons yang tidak membocorkan keberadaan akun.
- Perubahan email/WhatsApp memerlukan autentikasi ulang; snapshot kontak order lama tetap utuh. OTP wajib belum masuk scope.
- Policy membatasi order, request, pengajuan, dan bukti kepada pemiliknya atau staf berwenang. Nomor order publik bukan kredensial akses.
- Owner terakhir tidak boleh dinonaktifkan. Kredensial staf tidak ditampilkan kembali dalam dashboard.

## 4. Spesifikasi Detail Fitur F-001–F-010

### F-001 — Katalog dan Kuota per Unit

**Prioritas:** Must Have. **Aktor:** pembaca publik dan customer.

**Input:** kategori produk dan tanggal pengambilan. **Output:** produk aktif, ukuran/harga, foto, dan sisa kapasitas tanggal terpilih.

**Proses dan aturan:**

1. Katalog hanya menampilkan produk aktif; ukuran nonaktif tidak dapat dipilih.
2. Default tanggal mengikuti H-1 dan cutoff saat halaman dibuka. Customer dapat memilih tanggal berikutnya yang tersedia dalam horizon pemesanan.
3. Default kapasitas adalah lima unit per produk per tanggal; override admin dapat mengubahnya.
4. Availability = kapasitas harian dikurangi unit paid/completed dan reservasi pending yang belum dilepas secara sah. Rancangan R1 menahan reservasi yang masih menunggu kepastian pembayaran, termasuk sesudah deadline; detail final lihat 7.2–7.4.
5. Label memakai unit, misalnya **“Tersedia 3 dari 5 unit”**. Ukuran berbeda tidak memiliki kuota tambahan sendiri.
6. Data tampilan diperbarui saat tanggal berubah, halaman kembali aktif, dan sebelum checkout; server adalah sumber keputusan final.

**Acceptance criteria:**

- F001-AC1: Pembelian tiga unit dari kapasitas lima menyisakan dua unit, bukan empat.
- F001-AC2: Produk berkuota nol bertanda penuh dan tidak dapat dibuat order, termasuk melalui request langsung.
- F001-AC3: Produk/ukuran nonaktif ditolak saat checkout walaupun halaman lama masih menampilkannya.

### F-002 — Checkout Berakun dan Cutoff

**Prioritas:** Must Have. **Aktor:** customer login.

**Input wajib:** produk, ukuran, rasa, filling, warna cover, tulisan, quantity, tanggal dan jam pengambilan, metode pengambilan. Identitas pembeli berasal dari akun dan ditampilkan untuk dikonfirmasi.

**Input opsional:** satu field **“Nama Kurir / Kode Booking / Catatan Penyerahan”**, maksimal 255 karakter. Tidak ada field alamat tujuan, nomor penerima khusus, atau ongkir.

**Default validasi:**

- Quantity integer minimal satu dan tidak melebihi kuota tersisa; harga dan subtotal dihitung server.
- Rasa/filling dari allowlist konfigurasi statis; nilai yang tidak dikenal ditolak.
- Warna cover wajib, maksimal 100 karakter; desain rumit diarahkan ke Custom Cake.
- Tulisan maksimal sepuluh kata dan 150 karakter; whitespace dinormalisasi, kata dipisahkan whitespace Unicode. Customer dapat memilih “Tanpa tulisan”; opsi ini menyimpan teks kosong secara eksplisit.
- Waktu pengambilan menggunakan presisi menit, dari 09.00 sampai 21.00 inklusif; tidak ada fixed slot atau tabel slot waktu.
- Tanggal dievaluasi ulang dengan waktu server ketika order benar-benar dibuat, bukan saat customer membuka form.
- Normalisasi waktu bisnis ke detik konsisten: seluruh kejadian pada detik 17.00.00 memenuhi cutoff; detik 17.00.01 tidak.

**Alur:** login → pilih produk/tanggal → isi personalisasi → pilih metode → baca ringkasan/kebijakan → submit → transaksi database untuk order/kuota → buat transaksi Midtrans → halaman pembayaran/countdown.

Satu order menyimpan satu tanggal/jam/metode pengambilan. MVP mengikuti A-01 yang disetujui: satu produk/ukuran/personalisasi, quantity≥1. Horizon D+90 inklusif dan maksimum tiga pending yang masih menahan kuota per akun termasuk review; paid tidak dihitung. Setiap submit memakai idempotency key yang terkait akun dan hash payload: retry payload yang sama mengembalikan order yang sama; key sama dengan payload berbeda ditolak.

**Output:** order `pending`, snapshot harga dan pembeli, nomor order, deadline server, dan akses pembayaran. Total customer sama dengan jumlah subtotal produk; tidak ada ongkir atau surcharge MDR.

**Acceptance criteria:**

- F002-AC1: Pengunjung tanpa login diarahkan ke login/registrasi sebelum submit; request tanpa sesi ditolak.
- F002-AC2: Order 16 Oktober 17.00.00 WIB boleh memilih 17 Oktober; order 17.00.01 paling awal 18 Oktober.
- F002-AC3: Order pukul 23.00 tetap dapat dibuat untuk tanggal yang memenuhi aturan.
- F002-AC4: Waktu 08.59/21.01 ditolak; 09.00/21.00 diterima bila tanggal dan kuota valid.
- F002-AC5: Double click/retry jaringan tidak membuat order atau reservasi kedua.
- F002-AC6: Customer melihat harga, deadline, dan ketentuan H-2 sebelum membayar; pesanan untuk besok diberi informasi bahwa batas pembatalan customer sudah lewat.

### F-003 — Form Request Custom Cake MVP

**Prioritas:** Must Have. **Aktor:** customer login dan admin.

**Input:** snapshot nama/WhatsApp akun, tanggal pengambilan, ukuran, rasa, filling, warna, tulisan atau pilihan tanpa tulisan, referensi desain, edible print (ya/tidak), fondant (ya/tidak), serta catatan opsional. Detail fondant wajib bila dipilih.

**Default batas:** ukuran/warna maksimal 100 karakter; tulisan maksimal 150 karakter; catatan maksimal 2.000 karakter. Aturan sepuluh kata hanya wajib untuk Simple Cake. Upload 1–3 gambar JPEG/PNG/WebP, maksimal 5 MB/file; konten file harus benar-benar gambar. File SVG dan executable tidak diterima.

**Proses:** validasi login dan input → validasi H-3 atau H-7 edible print → simpan request dan attachment privat → catat event → antrekan notifikasi Fonnte → tampilkan nomor request dan status.

**Status:** `submitted` → `contacted` → `quoted` → `accepted` atau `rejected`. Admin boleh menolak lebih awal dari submitted/contacted dengan alasan. Accepted/rejected terminal pada MVP; permintaan baru dibuat untuk kebutuhan baru.

**Batas scope:**

- Quote dan persetujuan merupakan catatan komunikasi, bukan invoice atau bukti pembayaran.
- Harga akhir, DP 50%, pelunasan, dan bukti pembayaran tidak dicatat sebagai transaksi keuangan dalam sistem MVP.
- Accepted tidak berarti lunas dan tidak berubah menjadi order Simple Cake.
- Request tidak menahan kuota Simple Cake dan tidak memiliki timeout pembayaran 30 menit.
- Perubahan desain sebelum status terminal hanya sampai H-2; admin mencatat spesifikasi dan alasan. Menambah edible print tetap harus memenuhi H-7 pada waktu perubahan, H-2 tidak mengalahkannya. Horizon D+90 inklusif juga berlaku untuk custom. Ketentuan fondant sebelum accepted tanpa harga otomatis.

**Acceptance criteria:**

- F003-AC1: Request H-2 ditolak; request H-3 tanpa print diterima. Edible print H-6 ditolak dan H-7 diterima.
- F003-AC2: Field edible print memengaruhi validasi server; manipulasi browser tidak melewati H-7.
- F003-AC3: Request tersimpan dan dapat dibaca pemilik/admin walaupun Fonnte gagal.
- F003-AC4: Attachment tidak dapat diakses publik atau customer lain; status accepted tidak menciptakan pembayaran/kuota.

### F-004 — Availability dan Reservasi Harian

**Prioritas:** Must Have. **Aktor:** customer, admin, proses sistem.

**Input:** produk, tanggal, kapasitas override, dan quantity order. **Output:** kapasitas tersisa yang konsisten.

- Kapasitas per produk/tanggal memiliki unique constraint. Ukuran berbagi baris kapasitas produk.
- Pending menahan unit sampai hasil pembayaran/expiry final diketahui; paid/completed tetap menggunakan kapasitas tanggal tersebut. Berakhirnya countdown saja tidak melepas reservasi (rancangan Tech Lead R1).
- Expired/cancelled melepas unit. Pengajuan pembatalan yang belum disetujui tidak melepas unit.
- Admin dapat menetapkan kapasitas nol hanya jika tidak melanggar alokasi yang sudah ada; penurunan di bawah alokasi aktif ditolak.
- Perubahan default produk memengaruhi tanggal baru yang belum memiliki override/baris kapasitas; tanggal yang sudah materialized diubah secara eksplisit melalui panel.
- Order completed tidak mengembalikan kuota harian. Kuota adalah batas penjualan tanggal tersebut, bukan stok yang diisi ulang setelah pickup.

**Acceptance criteria:**

- F004-AC1: Checkout simultan yang memperebutkan satu unit menghasilkan maksimum satu reservasi berhasil.
- F004-AC2: Quantity tiga tidak diterima saat tersisa dua, walaupun hanya satu transaksi.
- F004-AC3: Worker expiry/webhook yang dipanggil ulang tidak melepas atau mengurangi kuota dua kali.
- F004-AC4: Deadline menutup aksi bayar baru, tetapi tidak melepas reservasi yang masih ambigu. Worker mati tidak boleh menyebabkan kapasitas pembayaran tepat waktu dijual ulang. Pelepasan sah setelah konfirmasi expiry final harus idempoten; oracle provider difinalisasi Tech Lead.

### F-005 — Metode Pengambilan dan Penyerahan

**Prioritas:** Must Have. **Aktor:** customer dan admin. **Perubahan ID:** menggantikan “Estimasi Biaya Pengiriman” v2.0; tidak menyisakan integrasi ongkir.

**Pilihan UI dan nilai internal:**

| Label | Nilai |
| --- | --- |
| Ambil Sendiri (Self Pickup) | `self_pickup` |
| Diambil Kurir Online (Grab/Gojek) | `online_courier` |

Customer memesan dan membayar kurir secara mandiri. Website menampilkan alamat toko sebagai informasi pengambilan statis, bukan input alamat tujuan. Tidak ada pencarian jarak, API Maps, estimasi ongkir, atau pemesanan kurir.

**Teks kebijakan checkout, sesuai keputusan pemilik produk:**

> Pemesanan kurir dan ongkir menjadi tanggung jawab customer. Kerusakan setelah cake diserahkan ke kurir bukan tanggung jawab TBloom Cake.

Teks ditampilkan saat online courier dipilih, dengan checkbox persetujuan yang tidak tercentang otomatis. Simpan versi teks dan waktu persetujuan. Pencantuman ini merekam kebijakan produk; dokumen ini tidak menilai keberlakuan hukumnya.

Catatan penyerahan dapat diisi saat checkout atau ditambahkan pemilik order sebelum penyerahan, tanpa mengubah harga/jadwal. Perubahan dicatat. Ini pengecualian administratif dari H-2 karena kode booking kurir dapat baru tersedia pada hari pengambilan.

Admin memeriksa nomor order dan kesesuaian pihak pengambil, kemudian mencatat waktu penyerahan dan menandai completed hanya jika paid dan tidak ada pengajuan pembatalan sah yang belum diputuskan. Jika cancellation disetujui, order cancelled dan tidak diserahkan meskipun refund belum selesai; jika ditolak, handover dapat dilanjutkan. Tidak ada status “sedang dikirim” atau pelacakan tujuan. Toko menyediakan petugas/prosedur penyerahan sampai 21.00; produksi tetap berakhir 17.00.

**Acceptance criteria:**

- F005-AC1: Kedua metode memakai total produk yang sama dan tidak menampilkan field biaya/alamat penerima.
- F005-AC2: Online courier tidak dapat disubmit tanpa persetujuan kebijakan.
- F005-AC3: Catatan opsional boleh kosong; order paid dapat diselesaikan setelah penyerahan kepada customer/kurir yang sesuai.

### F-006 — Midtrans, Timeout 30 Menit, dan Late Payment

**Prioritas:** Must Have. **Aktor:** customer, Midtrans, sistem, admin untuk pengecualian.

**Integrasi usulan:** Midtrans Snap; hanya channel yang aktif pada akun merchant dan telah diuji. Tidak menjanjikan bank/channel tertentu sebelum aktivasi. Customer membayar penuh harga produk, MDR ditanggung TBloom Cake.

**Deadline:**

`payment_expires_at = orders.created_at + 30 menit`

- Waktu server menjadi sumber kebenaran. Gunakan presisi detik yang sama untuk created_at dan deadline.
- Aksi pembayaran baru hanya tersedia selama `now < payment_expires_at`. Saat deadline tercapai, aplikasi menutup aksi bayar baru. Order tidak otomatis kehilangan hak pemenuhan: pembayaran tepat waktu yang baru terverifikasi setelah deadline tetap dipenuhi (K-01).
- Deadline tidak berubah saat refresh, login ulang, retry pembuatan sesi, atau memilih channel.
- Expired final hanya digunakan setelah hasil pembayaran/penutupan provider dipastikan sesuai kontrak channel, bukan karena webhook belum datang. Order expired final tidak memperoleh sesi baru. Bukti pembayaran tepat waktu yang bertentangan dengan expiry final adalah insiden konsistensi yang harus diselesaikan untuk memenuhi K-01, bukan otomatis direfund.
- Order 17.00.00 WIB memiliki deadline 17.30.00 WIB; melewati cutoff saat membayar tidak membatalkan tanggal yang sudah sah ketika order dibuat.

**Alur normal:** order/attempt tersimpan → request Midtrans di luar lock DB → customer bayar tepat waktu → webhook atau status provider terverifikasi → lock dan periksa ulang fakta pembayaran/order → catat sukses dan ubah pending menjadi paid satu kali → notifikasi. Waktu handler memperoleh lock/commit bukan pengganti waktu pembayaran customer.

**Rancangan expiry Tech Lead:** deadline tercapai → tutup aksi bayar baru → periksa/akhiri transaksi provider sesuai channel di luar lock DB → hasil sukses tepat waktu dipenuhi; hasil belum pasti tetap direkonsiliasi dengan reservasi ditahan; hanya hasil final yang membuktikan tidak ada pembayaran sah dapat melepas kuota dan menandai expired. Status pending/timeout provider atau tidak adanya webhook bukan bukti final. Gangguan provider memicu retry terbatas+backoff lalu review manual. Staf memantau/menangani09.00–17.00 WIB; tidak ada SLA customer. Angka retry/jeda diusulkan TL.

**Anomali nominal terkonfirmasi (K-04):** setelah fakta penerimaan diverifikasi, review Owner, order tidak dipenuhi dan ditutup; kembalikan dana terverifikasi yang masih menjadi hak customer sesuai rekonsiliasi. Tidak top-up kekurangan atau memenuhi order dengan refund selisih kelebihan. Tagihan, receipt, obligation, return dan reversal dibedakan dalam Database Schema R1.

**Alur pembayaran benar-benar terlambat:** bila bukti provider menunjukkan pembayaran dilakukan setelah batas bayar, simpan fakta dana dan klasifikasikan terpisah dari keterlambatan notifikasi. Baseline refund manual 100% untuk pembayaran setelah expiry tetap ada; kasus perbedaan expiry gateway/lokal masuk review sampai fakta waktunya jelas. Persetujuan K-01 tidak mengizinkan memperpanjang waktu bayar atau menyimpulkan keterlambatan dari received_at webhook.

A-07 lama tidak berlaku. Selama pemeriksaan, teks customer menyampaikan bahwa pembayaran sedang diperiksa tanpa meminta pembayaran ulang atau menjanjikan refund. Setelah pembayaran tepat waktu terverifikasi, gunakan konfirmasi order paid normal. Pemetaan bukti waktu sukses harus diuji per channel; transaction_time tidak boleh diasumsikan sebagai waktu pembayaran sukses.

**Penanganan error:**

- Timeout pembuatan transaksi berarti hasil belum diketahui; simpan reference dan rekonsiliasi sebelum mencoba membuat transaksi lain.
- Satu order MVP memiliki satu payment attempt/reference gateway. Pemilihan ulang channel memakai sesi valid yang sama bila didukung; kegagalan terminal memerlukan order baru setelah order lama dilepas secara aman.
- Respons gagal definitif sebelum dana masuk dapat membatalkan pending tanpa refund. Respons ambigu tidak boleh dianggap gagal definitif.
- Nominal/currency/reference tidak cocok: simpan kasus review, jangan tandai order paid atau ubah kuota secara spekulatif.
- Webhook paid duplikat untuk pembayaran yang sudah menjadi dasar cancellation tidak mengubah kewajiban 80% menjadi 100%. Untuk automatic cancellation order pending unpaid, pembayaran tepat waktu yang sukses sebelum gateway ditutup menang: order paid dan permintaan cancel ditolak payment_won (K-15). Dana baru/anomali setelah cancellation paid yang sah masuk review/rekonsiliasi tanpa auto revive atau mengubah kewajiban80%.
- Browser redirect tidak membuktikan pembayaran berhasil.

**Acceptance criteria:**

- F006-AC1: Deadline selalu tepat created_at + 30 menit; order pending pada batas sama dengan deadline tidak dapat dibayar ulang melalui aplikasi.
- F006-AC2: Pembayaran tepat waktu yang terverifikasi membuat order paid satu kali, termasuk webhook/handler setelah deadline; MDR tidak ditambahkan dan keterlambatan verifikasi saja tidak membuat refund.
- F006-AC3: Webhook duplikat/tidak berurutan tidak menggandakan pembayaran, refund, notifikasi, atau kuota.
- F006-AC4: Reservasi tidak dijual ulang selama hasil pembayaran masih ambigu. Setelah expiry final yang sah, kapasitas dapat dipakai order lain; pemulihan insiden tidak boleh diam-diam mengambil alokasi customer lain.
- F006-AC5: Signature tidak valid ditolak; nominal salah ditandai review; callback browser tidak mengubah status paid.
- F006-AC6: Countdown mencapai nol ketika tab ditinggalkan lalu dibuka kembali; server tetap menegakkan deadline walaupun JS dimatikan.

### F-007 — Pembatalan, Perubahan, dan Refund Manual

**Prioritas:** Must Have. **Aktor:** customer pemilik order, admin, owner.

**Input pembatalan:** order, alasan, jenis permintaan; rekening refund (bank, nama pemilik, nomor rekening) diminta secara privat ketika refund diperlukan. Rekening diverifikasi admin dengan customer sebelum transfer, khususnya jika nama berbeda.

| Penyebab | Syarat | Nominal |
| --- | --- | --- |
| Customer membatalkan order paid | Diajukan paling lambat H-2; disetujui admin sebelum completed | `floor(product_total × 80 / 100)` |
| Toko membatalkan order paid | Alasan toko wajib; sebelum completed | 100% product_total yang telah diterima |
| Pembayaran benar-benar terlambat setelah expiry | Fakta waktu/status dana terverifikasi; bukan hanya webhook terlambat | Baseline 100% dana yang diterima; mismatch expiry masuk review |
| Pending dibatalkan tanpa dana masuk | Tidak ada dana untuk dikembalikan | Tidak membuat refund |

`product_total` adalah snapshot total harga produk order, tanpa ongkir/MDR. Perhitungan dilakukan sekali atas total order, bukan floor setiap item. Contoh: Rp100.001 menghasilkan refund customer Rp80.000. Tidak ada pemotongan MDR dari refund 80% maupun 100%. Nominal tidak boleh melebihi sisa dana terverifikasi yang belum direfund.

**Alur customer paid:** ajukan maksimal akhir tanggal H-2 → catat submitted_at → tahan handover → Admin approve/reject dengan alasan. Approve, cancelled, pelepasan kuota, pengajuan applied dan kewajiban refund satu commit. Transfer manual dan bukti terpisah; setelah terbukti selesai, obligation completed dan payment partially_refunded/refunded sesuai fakta dana.

**Pending unpaid:** dapat meminta cancel kapan pun tanpa H-2 atau approval. Tetap pending dan menahan kuota sampai gateway ditutup sah tanpa sukses pembayaran. Jika pembayaran tepat waktu sukses sebelum closure, paid dan dipenuhi; permintaan automatic cancel ditolak dengan alasan payment_won. Cancellation paid berikutnya mengikuti H-2/80%. Tidak ada partial cancellation.

**Alur toko/expired:** pembatalan toko atau pembayaran benar-benar terlambat yang memenuhi klasifikasi expiry menciptakan kewajiban 100% tanpa syarat H-2 customer. Keterlambatan webhook atas pembayaran tepat waktu tidak menciptakan kewajiban refund (K-01). Refund tidak mengubah cancelled/expired menjadi order aktif.

**Bukti dan audit wajib:** admin pelaksana, alasan/kategori refund, nominal dasar, persentase, hasil floor, rekening tujuan terlindungi, referensi transfer, waktu transfer, file bukti, dan perubahan status. Bukti gambar/PDF maksimal 5 MB disimpan privat. Nominal dan bukti wajib sebelum completed. Catatan completed tidak dapat diedit/dihapus lewat CRUD biasa; koreksi berupa catatan tambahan yang dapat diaudit.

**Pencegahan ganda:** satu kewajiban refund per pembayaran untuk pembatalan penuh MVP; retry memakai catatan yang sama. Admin yang ditugaskan mengambil processing sebelum transfer; Owner mengendalikan pengecualian dan takeover (K-02). Rancangan teknis: claim eksklusif dan perubahan penugasan diaudit, admin lain tidak dapat menyelesaikan pekerjaan tanpa takeover sah. Jika hasil transfer di bank tidak diketahui, wajib cek mutasi terlebih dahulu; takeover tidak memberi izin mengulang transfer ambigu. Aplikasi tidak menjamin exactly-once transfer manual di luar sistem.

**Perubahan pesanan:**

- Permintaan perubahan personalisasi yang tidak memengaruhi harga/kapasitas diajukan maksimal H-2 dan diterapkan setelah persetujuan; snapshot sebelum/sesudah dicatat.
- Perubahan produk/ukuran/jumlah/tanggal memakai pembatalan yang memenuhi aturan dan order baru, bukan memindahkan kuota diam-diam. Pembatalan order lama tidak menjamin tersedianya order baru.
- Pickup time dan pickup method tidak dapat diubah. Perubahan simple terbatas flavor/filling/color/inscription yang tidak mengubah harga/kuota, H-2 dan approval. Catatan kurir boleh diubah sebelum handover sesuai F-005. Status order tidak berubah menjadi “diubah”.

**Acceptance criteria:**

- F007-AC1: Pengambilan 20 Oktober menerima pengajuan sampai 18 Oktober 23.59.59; 19 Oktober 00.00 ditolak.
- F007-AC2: Pengajuan pembatalan yang memenuhi syarat tidak gugur karena admin terlambat meninjau; selama belum diputuskan handover ditolak. Persetujuan menghasilkan cancelled; penolakan membuka kembali handover bila paid. Completed tidak dapat dibatalkan lewat alur standar. Definisi H-2 sudah disetujui K-06/A-03: submitted_at≤23.59.59 WIB pada tanggal pickup−2.
- F007-AC3: Refund customer memakai floor total produk; toko/late payment memakai 100%; tidak ada panggilan API refund otomatis.
- F007-AC4: Tanpa bukti/nominal/referensi transfer, refund tidak dapat diselesaikan. Customer lain tidak dapat membaca bukti atau rekening.
- F007-AC5: Retry persetujuan/penyelesaian tidak membuat kewajiban kedua atau melepaskan kuota dua kali.
- F007-AC6: Hanya petugas refund yang berwenang dapat menjalankan pekerjaan; dua claim bersamaan tidak menghasilkan dua pelaksana. Takeover pengecualian oleh Owner tercatat dengan alasan; transfer ambigu tidak diulang sebelum pemeriksaan mutasi.

### F-008 — Testimoni dan Informasi Publik

**Prioritas:** Should Have untuk testimoni; informasi jam/kebijakan merupakan Must Have.

**Aktor:** admin/owner mengelola; pengunjung membaca.

**Input testimoni:** nama tampilan, isi maksimal 2.000 karakter, foto opsional, rating opsional integer 1–5, urutan, status draft/published. Admin memastikan izin penggunaan nama/foto sesuai proses toko.

Halaman publik menampilkan profil toko, logo, alamat pengambilan statis, kontak, jam produksi 09.00–17.00, penyerahan 09.00–21.00, aturan cutoff, dan tautan kebijakan.

**Acceptance criteria:**

- F008-AC1: Hanya published terlihat publik; draft tidak bocor melalui URL langsung/API.
- F008-AC2: Rating di luar 1–5 ditolak; foto opsional memiliki alt text yang relevan.
- F008-AC3: Halaman publik tidak menyatakan toko menerima produksi sampai 21.00 atau checkout tutup pukul 17.00.

### F-009 — Admin Produk dan Kuota

**Prioritas:** Must Have. **Aktor:** admin/owner.

**Input:** nama, kategori Simple/Cupcake/Bento, deskripsi, ukuran, harga rupiah, unit jual, foto, status aktif, kuota default dan override tanggal.

- Custom Cake merupakan jalur request tersendiri, bukan SKU berharga otomatis.
- Harga disimpan per ukuran. Rasa/filling berasal dari konfigurasi; panel tidak menyediakan CRUD master tersebut.
- Arsip/nonaktif digunakan untuk produk/ukuran yang pernah dipesan. Histori order tetap menyimpan snapshot.
- Perubahan harga hanya memengaruhi order baru. Order yang telah dibuat tetap memakai snapshot, termasuk saat menunggu konfirmasi pembayaran tepat waktu setelah deadline.
- Perubahan stok/kuota divalidasi terhadap alokasi aktif dan diaudit.
- Tidak ada laporan penjualan pada fitur ini.

**Acceptance criteria:**

- F009-AC1: Harga terbaru terlihat pada checkout baru, sementara order lama tidak berubah.
- F009-AC2: Produk yang diarsipkan tidak dapat dipesan baru; detail order historis tetap lengkap.
- F009-AC3: Menurunkan kapasitas lima menjadi dua ketika tiga unit dialokasikan ditolak dengan pesan jelas.

### F-010 — Admin Order, Request, Refund, dan Audit

**Prioritas:** Must Have. **Aktor:** admin/owner.

Dashboard menyediakan daftar terpisah untuk order Simple Cake, Custom Cake request, pengajuan customer, refund, dan kegagalan integrasi. Filter tersedia berdasarkan nomor, status, tanggal dibuat, tanggal pickup, metode pengambilan, dan kontak customer sesuai akses staf.

- Detail order berisi snapshot item/kontak, quantity, jadwal, metode, catatan penyerahan, deadline, status pembayaran, refund, dan histori.
- Aksi operasional MVP: pembatalan sesuai sebab, keputusan pengajuan, catatan penyerahan, dan penandaan completed setelah handover.
- Tidak ada kanban/timeline `in_production`, `ready`, atau laporan penjualan/penjelajahan otomatis.
- Status payment berasal dari hasil integrasi terverifikasi serta catatan refund manual, bukan dropdown “tandai lunas”.
- Audit mencatat pelaku user/system/provider, aksi, sebelum/sesudah, alasan, dan timestamp. Audit bukan editor data.
- Owner memiliki pengelolaan akun Admin. Dashboard dapat menampilkan jumlah pekerjaan yang perlu ditangani, tanpa grafik pendapatan atau laporan analitik.

**Acceptance criteria:**

- F010-AC1: Dari daftar, pencarian nomor dan pembukaan detail memerlukan maksimal tiga aksi navigasi; pengisian form dan konfirmasi sensitif tidak dihapus demi target klik.
- F010-AC2: Pending/expired/cancelled tidak dapat completed; paid hanya dapat completed setelah penyerahan dan tanpa pengajuan pembatalan sah yang belum diputuskan. Approval cancellation dan handover bersamaan tidak boleh keduanya berhasil.
- F010-AC3: Perubahan status operasional/refund memiliki audit actor dan waktu; customer tidak dapat mengakses dashboard.
- F010-AC4: Kegagalan notifikasi dan late payment terlihat sebagai pekerjaan operasional, bukan disembunyikan dalam log server saja.

## 5. Matriks Aturan Bisnis

### 5.1 Waktu dan kalender

| ID | Aturan | Batas dan contoh |
| --- | --- | --- |
| BR-01 | Zona waktu | Semua keputusan tanggal/jam bisnis dalam Asia/Jakarta; timestamp kejadian disimpan UTC |
| BR-02 | Website | Katalog, registrasi, checkout, pembayaran, dan request custom tersedia 24/7 |
| BR-03 | Operasional/produksi | 09.00–17.00 WIB; toko menyiapkan penyerahan di luar jam produksi |
| BR-04 | Pickup/kurir | 09.00–21.00 WIB inklusif; waktu bebas dalam menit, tanpa slot |
| BR-05 | H-1 sampai cutoff | Local time ≤ 17.00.00: earliest pickup date = local today + 1 hari |
| BR-06 | Setelah cutoff | Local time ≥ 17.00.01: earliest pickup date = local today + 2 hari |
| BR-07 | Pembatalan/perubahan customer paid | Submitted_at ≤23.59.59 WIB tanggal pickup_date−2; pending unpaid cancel tidak tunduk H-2/approval; catatan kurir sebelum handover F-005 |
| BR-08 | Custom standar | Requested pickup date ≥ local today + 3 hari |
| BR-09 | Custom edible print | Requested pickup date ≥ local today + 7 hari; menggantikan H-3 |
| BR-10 | Pembayaran | Batas bayar created_at + 30 menit; pembayaran tepat waktu tetap dipenuhi meski verifikasi terlambat. Expiry final tidak ditentukan hanya dari jam lokal |
| BR-11 | Batas kalender | Tidak memakai jam kerja atau weekday untuk menghitung H; tanggal tutup dinyatakan melalui kapasitas |

Waktu order memakai satu timestamp server yang ditangkap dalam proses pembuatan setelah lock kapasitas diperoleh dan sebelum insert; gunakan nilai yang sama untuk validasi cutoff, created_at, dan deadline. Bila request menunggu lock melewati cutoff, tanggal harus divalidasi ulang. Pergantian tanggal pada tengah malam dihitung ulang berdasarkan tanggal lokal baru, bukan mempertahankan earliest date hari sebelumnya.

### 5.2 Kuota, pembayaran, dan refund

| ID | Aturan |
| --- | --- |
| BR-12 | Default lima unit per produk/tanggal; quantity adalah jumlah unit jual, bukan jumlah transaksi |
| BR-13 | Ukuran berbagi kapasitas produk; paid/completed tetap mengonsumsi kapasitas hari pickup |
| BR-14 | Pending termasuk review menahan reservasi sampai hasil final sah; expired/cancelled sah melepas sekali, completed tetap memakai kuota. Deadline saja tidak cukup |
| BR-15 | Expired terminal; order baru harus melewati validasi cutoff/kuota/harga lagi |
| BR-16 | Total bayar = jumlah harga snapshot × quantity; tidak ada ongkir/MDR tambahan, COD, atau DP Simple Cake |
| BR-17 | Refund customer yang memenuhi aturan = floor(total produk × 80 / 100) |
| BR-18 | Pembatalan toko dan pembayaran benar-benar terlambat setelah expiry mengikuti baseline refund 100%; tidak dipotong MDR. Webhook terlambat atas pembayaran tepat waktu bukan pemicu refund |
| BR-19 | Refund hanya transfer manual; kewajiban, eksekusi, dan bukti dicatat terpisah dari status order |
| BR-20 | Custom request tidak memiliki transaksi/DP/kuota otomatis |
| BR-21 | Notifikasi bukan sumber kebenaran transaksi; kegagalan WhatsApp tidak membatalkan order/request |

### 5.3 Contoh uji batas

| Skenario | Hasil |
| --- | --- |
| Order 16 Oktober 2026 16.59.59 WIB | Pickup paling awal 17 Oktober |
| Order 16 Oktober 2026 17.00.00 WIB | Pickup paling awal 17 Oktober; deadline bayar 17.30.00 |
| Order 16 Oktober 2026 17.00.01 WIB | Pickup paling awal 18 Oktober |
| Order 16 Oktober 2026 23.59.59 WIB | Pickup paling awal 18 Oktober |
| Order 17 Oktober 2026 00.00.00 WIB | Pickup paling awal 18 Oktober |
| Order 10.00.00, belum terkonfirmasi pada 10.30.00 | Aksi bayar ditutup; periksa provider. Jangan menyimpulkan customer belum membayar dari status lokal |
| Kapasitas lima, pembelian tiga unit | Sisa dua unit |
| Total produk Rp100.001, pembatalan customer sah | Refund Rp80.000 |
| Order total Rp100.001, dibayar 10.29.59, webhook 10.30.20 untuk deadline 10.30.00 | Paid setelah verifikasi, pesanan dipenuhi; tidak refund hanya karena webhook terlambat |
| Pickup 20 Oktober, pengajuan 18 Oktober 23.59.59 | Diterima untuk review admin |
| Pickup 20 Oktober, pengajuan 19 Oktober 00.00.00 | Ditolak karena melewati H-2 |

## 6. Rancangan Database MySQL dan State

### 6.1 Sumber schema R1
[Database-Schema.md](Database-Schema.md) adalah data dictionary teknis lengkap pengganti sketch kolom lama, dengan tipe, panjang, signedness, nullable/default, PK/FK/composite FK/UNIQUE/CHECK/index dan lifecycle seluruh27 tabel. Tidak ada migration bisnis dibuat atau dijalankan. Engine target MySQL/InnoDB; versi/schema live belum diverifikasi karena koneksi sebelumnya ditolak.

### 6.2 Tabel bisnis dan tujuan
| Kelompok | Tabel |
|---|---|
| Akun/katalog | users, products, product_variants, product_images |
| Kuota/order | daily_availabilities, orders, order_items |
| Custom tanpa uang/kuota | custom_cake_requests, custom_cake_request_attachments |
| Pembayaran | payments (tagihan/proyeksi), payment_events (inbox), payment_movements (fakta dana terverifikasi) |
| Pengajuan/refund | order_requests, refund_logs (kewajiban), refund_transfers (attempt manual), refund_evidence |
| Operasional/publik | audit_logs, notification_deliveries (outbox), testimonials |
| Infrastruktur | password_reset_tokens, sessions, jobs, job_batches, failed_jobs, cache, cache_locks, migrations |

Tabel payment_movements/refund_transfers mendukung integritas MVP, bukan fitur akuntansi atau refund otomatis. Tidak ada alamat/pengiriman/DP custom/multi-item checkout baru.

### 6.3 Konvensi dan constraints
Uang integer rupiah, quantity positif, snapshot harga/pembeli historis; timestamp UTC, pickup_date/pickup_time lokal Asia/Jakarta. JSON hanya payload/snapshot pendukung. Composite FK memastikan varian/availability sesuai produk dan refund/payment/order/evidence sesuai objek. UNIQUE menegakkan idempotency dan satu obligation/payment; generated nullable unique slot membatasi satu request pending dan satu transfer unresolved. Aggregate dana/kuota dijaga transaksi aplikasi, bukan klaim CHECK lintas tabel.

### 6.4 ERD dan lifecycle
ERD, deletion/archive/orphan/rollback tersedia Database Schema §4–6. Historis finansial tidak hard-delete lewat CRUD. Tidak cascade menghapus order/refund. Retensi belum diputuskan Owner; jangan menjalankan purge otomatis. Existing database tidak diasumsikan kosong.

### 6.5 State order dan pengajuan
Order pending→paid→completed; pending→expired/cancelled hanya kondisi final sah; paid→cancelled pada cancellation sah. Review payment ambigu tidak melepas reservasi. Completed tetap mengonsumsi kuota. Pengajuan pending→applied/rejected; approval dan application satu commit. Pending cancellation eligible menahan handover.
Expiry final salah kemudian timely payment adalah insiden K-01: tanpa refund otomatis/ambil kuota customer lain; recovery terkontrol menurut Schema §7.4.

### 6.6 State dana dan refund
Payment pending/paid/failed/expired/cancelled/partially_refunded/refunded merepresentasikan fakta dana; review_reason terpisah. Settlement duplikat tidak menghapus return atau mengubah kewajiban80 menjadi100.
Refund_logs pending/processing/review/completed adalah kewajiban. Refund_transfers prepared/unknown/succeeded/failed_confirmed adalah percobaan manual; unknown tidak boleh diulang sebelum mutasi diverifikasi. Kewajiban boleh ada tanpa rekening; prepare wajib rekening verified; complete wajib fakta dan bukti. Sisa dana/kewajiban mempertimbangkan return/reversal yang telah direkonsiliasi dan attempt unresolved, tanpa double count. Kewajiban disimpan bruto sebelum pengurangan return; return yang terjadi sebelum obligation dibuat tetap dikurangkan hanya sekali pada perhitungan sisa. Identitas movement dan contoh kasus ada di Database Schema §8. Model status dan kontrak atomik lengkap ada di Schema §7–8.

## 7. Arsitektur Teknis Laravel 13

### 7.1 Stack dan batas tanggung jawab

- Laravel 13, PHP 8.4, Blade, Tailwind CSS 4, Vite, MySQL/InnoDB.
- Autentikasi session-based untuk customer/admin/owner; Form Request untuk validasi dan Policy untuk otorisasi.
- Midtrans Snap untuk pembayaran; Fonnte untuk WhatsApp; email transaksional untuk pemulihan password.
- Queue database sebagai baseline MVP; cache/database locks sesuai driver. Redis merupakan pilihan peningkatan operasional, bukan dependency wajib baru.
- Penyimpanan publik untuk foto produk terolah; penyimpanan privat durable untuk referensi custom dan bukti refund.
- Target deployment Laravel Cloud sesuai PRD awal; konfigurasi deployment rinci dilakukan pada fase implementasi, termasuk worker, scheduler, storage dan secrets.

Gunakan struktur Laravel yang tersedia: models, controllers, requests, policies, jobs, notifications/services sesuai konvensi proyek. Operasi seperti CreateOrder, ExpireOrder, HandlePaymentNotification, dan RecordManualRefund mengoordinasikan aturan; controller tetap tipis. Jangan menambahkan struktur direktori/dependency tanpa mengikuti aturan proyek.

### 7.2 Concurrency dan sumber kebenaran kuota

Baseline sederhana menggunakan daily_availabilities sebagai baris lock dan order_items sebagai ledger alokasi. Tidak menyimpan counter terpakai kedua yang dapat menyimpang.

**Rancangan unit terpakai R1 (Tech Lead):** jumlah quantity item yang belum dilepas dan order-nya paid/completed/pending, termasuk pending setelah deadline selama hasil pembayaran belum pasti. Tidak menggunakan filter payment_expires_at > now sebagai satu-satunya penentu reservasi. Status penutupan/verifikasi perlu dapat diaudit; detail kontrak provider dan batas eskalasi belum final.

**Pembuatan order:**

1. Validasi sesi, input, idempotency, dan batas akun.
2. Pastikan baris availability tersedia melalui insert yang aman terhadap unique constraint; jangan mengandalkan lock pada baris yang belum ada.
3. Mulai transaksi; kunci akun customer untuk menegakkan batas pending aktif, lalu kunci availability dengan `lockForUpdate()`.
4. Tangkap waktu server; validasi ulang cutoff, tanggal, status produk/ukuran, harga, dan unit terpakai memakai pembacaan terkini yang konsisten setelah lock.
5. Jika quantity melebihi kapasitas tersisa, rollback dan kembalikan pesan kuota tidak mencukupi.
6. Simpan order, item, payment reference, audit, dan outbox notifikasi dalam transaksi yang sama.
7. Commit, kemudian lakukan request Midtrans di luar transaksi DB. Kegagalan jaringan dicatat tanpa menahan lock.

**Urutan lock:** customer users → products/variants urut id → availability urut id → orders urut id → payments → order_requests → refund_logs → refund_transfers → child effects/audit/outbox. Semua operasi yang mengubah hitungan pending mengambil customer lock. Semua perubahan kuota/release mengambil availability; submission cancellation/handover mengambil parent order walau request belum ada. Pre-read hanya menemukan ID, reread sesudah lock. Usulan TL sesi bisnis READ COMMITTED dan retry transaksi utuh; detail Database Schema §7.2. Tidak HTTP di dalam lock.

Expiry final/cancel sah menulis quota_released_at sekali untuk audit. Timestamp tersebut tidak boleh menghasilkan pengurangan counter ulang. Pending yang melewati deadline tidak dilepas hanya karena belum ada webhook. Paid menjadi completed tetap terhitung. Seluruh jalur, termasuk admin, memakai operasi yang sama. Provider call tetap di luar lock DB; hasilnya divalidasi ulang di bawah lock, dan status pending hasil GET saja tidak membuktikan transaksi sudah tidak dapat dibayar.

Dasar API: [Laravel 13 — pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md#pessimistic-locking).

### 7.3 Expiry presisi bisnis dan scheduler

Deadline adalah batas aksi bayar customer, bukan batas kedatangan webhook. Aplikasi menutup pembayaran baru pada deadline walaupun worker mati. Rancangan R1 menahan reservasi yang masih ambigu sampai hasil final diketahui agar pembayaran tepat waktu tetap dapat dipenuhi. Worker/sweeper merekonsiliasi; keterlambatan layanan memicu review dan alert, bukan pembatalan otomatis. Tidak ada SLA customer saat ini; staf memantau dan menangani pada jam operasional. Penahanan kuota ambigu tetap berlaku tanpa timeout lokal yang membatalkan hak pembayaran tepat waktu.

Jadwal numerik berikut tetap usulan parameter teknis, bukan SLA customer atau persetujuan durasi retensi. Strategi retry terbatas+backoff lalu review manual sudah disetujui; TL mengusulkan angka percobaan/jeda. Kebijakan yang sudah final: automation24/7 dan manual review09.00–17.00 WIB.

| Pekerjaan | Jadwal/pemicu | Ketentuan |
| --- | --- | --- |
| ExpireOrder | Delayed job pada payment_expires_at | Mulai verifikasi penutupan; idempoten; tidak langsung expired hanya karena waktu habis |
| SweepExpiredOrders | Setiap menit, batch berdasarkan status/deadline | Memulihkan verifikasi job tertunda; tidak melepas reservasi ambigu atau menghapus order |
| ReconcilePayments | Setiap menit untuk kasus due, dengan backoff | Memeriksa payment pending atau review_reason terisi, serta insiden order final yang bertentangan dengan fakta gateway |
| ProcessPaymentEvent | Setelah event durable tersimpan | Retry aman; respons HTTP webhook tidak menunggu Fonnte |
| DispatchNotifications | Setelah commit serta sweeper setiap menit | Outbox tidak hilang jika proses mati setelah commit |
| AlertOperationalFailures | Setiap lima menit | Late payment/refund tertunda, queue gagal, integrasi bermasalah |
| CleanupTemporaryUploads | Nonaktif sampai durasi retensi kelas terkait disetujui; jadwal harian lama hanya usulan | Hanya file sementara tak terikat dan tidak sedang diproses; tidak menghapus bukti/history |

Proses rekonsiliasi tidak boleh berhenti memeriksa transaksi hanya karena order lokal expired. Transaksi ambigu yang melewati batas retry otomatis masuk antrean review admin dan tetap memiliki jalur pemeriksaan provider. Tidak menghapus riwayat untuk “membersihkan order expired”.

Scheduler dipicu setiap menit oleh platform/cron dan worker queue berjalan terus. Gunakan withoutOverlapping serta onOneServer ketika beberapa instance berbagi scheduler dan cache lock terpusat. Batasi runtime job, gunakan retry/backoff, dan monitor failed_jobs. Overlap lock tidak menggantikan idempotensi transaksi. [Laravel scheduling](https://github.com/laravel/docs/blob/13.x/scheduling.md), [Laravel queues](https://github.com/laravel/docs/blob/13.x/queues.md).

### 7.4 Race pembayaran versus expiry

1. Pembayaran tepat waktu yang terverifikasi membuat pending menjadi paid, termasuk ketika handler memegang lock setelah deadline. Ini keputusan K-01, mengganti A-07 lama.
2. Deadline menutup aksi bayar baru. Rancangan Tech Lead menahan reservasi selama provider belum memastikan hasil final; pending/timeout bukan bukti unpaid final.
3. Bukti waktu/status sukses dipetakan per channel; received_at/processed_at dan transaction_time tidak boleh dianggap waktu sukses customer tanpa kontrak provider.
4. Handler, rekonsiliasi, cancellation, dan expiry final memakai urutan lock yang sama serta validasi state terkini. Event duplikat tidak mengubah nominal refund 80% menjadi 100%.
5. Pembayaran tepat waktu yang bertentangan dengan expiry final/alokasi terlepas adalah insiden, bukan kasus refund otomatis. Recovery mengikuti Database Schema §7.4: freeze/alert, pulihkan dengan lock jika kapasitas tersedia; jika telah dialihkan, eskalasi pemenuhan Owner tanpa auto overbook/ambil kuota/refund. Pembuktian finalitas channel tetap gate teknis.
6. Handover versus cancellation mengunci order dan membaca ulang pengajuan aktif. K-03 melarang handover selama pengajuan cancellation sah belum diputuskan.

Expiry halaman dan metode gateway harus diselaraskan dengan deadline absolut order, termasuk jika channel dipilih belakangan. Retry tidak membuat jendela 30 menit baru. Kesanggupan tiap channel dan fakta waktu sukses harus dibuktikan di sandbox; jangan menyimpulkan kode/QR yang masih terlihat pasti belum melewati deadline. Pembayaran benar-benar terlambat dipisahkan dari notifikasi terlambat; mismatch masuk review, bukan otomatis mengubah keputusan K-01.

Midtrans membedakan masa berlaku halaman Snap dengan masa berlaku metode pembayaran. Konfigurasi keduanya harus diuji per channel; menutup halaman saja tidak cukup. [Dokumentasi expiry Snap](https://docs.midtrans.com/reference/expire-a-snap-session).

### 7.5 Keamanan webhook Midtrans

- Endpoint HTTPS dapat diakses Midtrans tanpa sesi customer. Pengecualian CSRF dibatasi pada endpoint webhook, bukan seluruh aplikasi.
- Untuk notifikasi Snap/Core standar, verifikasi `signature_key` menggunakan SHA512 dari gabungan order_id, status_code, gross_amount, dan ServerKey; bandingkan secara constant-time. Pertahankan representasi gross_amount asli untuk verifikasi signature.
- Cocokkan merchant reference, nominal, currency jika tersedia, dan identitas merchant terhadap konfigurasi/transaksi tersimpan.
- Simpan event durable lalu respons cepat; jangan mengirim WhatsApp dalam request webhook.
- Deduplikasi event identik dan tetap gunakan pemeriksaan state untuk event ekuivalen dengan payload berbeda.
- Gunakan GET Status terautentikasi untuk hasil ambigu/tidak berurutan. Status provider terbaru dan aturan lokal menentukan tindakan; event pending lama tidak menurunkan pembayaran sukses.
- `capture` hanya diterima menurut ketentuan channel/fraud yang telah diuji; status challenge/review bukan sukses otomatis.
- Rahasiakan ServerKey; client key publik sesuai integrasi Snap tidak diperlakukan sebagai server credential. Log menyamarkan token, signature, dan data pribadi.

Rujukan: [Midtrans HTTP notifications](https://docs.midtrans.com/docs/https-notification-webhooks). Provider status disimpan terpisah agar pemetaan internal tidak kehilangan fakta transaksi.

### 7.6 Fonnte dan matriks notifikasi

| Event | Customer | Admin | Isi inti/minimisasi |
| --- | --- | --- | --- |
| new_order — order committed | Pemilik order | Ya | Nomor order, ringkasan operasional/jadwal, tautan dashboard berotorisasi; detail bayar/deadline untuk customer sesuai kebutuhan |
| Pembayaran berhasil untuk order aktif, termasuk webhook terlambat | Pemilik order | Tidak sebagai event rutin | Konfirmasi paid dan jadwal; tidak menduplikasi sebagai payment_exception hanya karena webhook terlambat |
| Order expired final | Pemilik order | Tidak sebagai event rutin | Status expired yang sudah sah, bukan hasil ambigu |
| payment_exception — hasil ambigu/anomali/actual late | Pemilik order: status relevan yang telah diketahui | Ya | Nomor referensi, kategori masalah, tindakan yang perlu diperiksa; tanpa raw payload/seluruh profil |
| refund — perkembangan kewajiban/hasil terverifikasi | Pemilik transaksi | Ya | Referensi dan status/tindakan relevan; tanpa rekening penuh atau bukti privat dalam pesan |
| custom_request — request tersimpan/perkembangan relevan | Pemilik request | Ya | Nomor request, ringkasan relevan dan akses dashboard; tanpa lampiran privat dalam pesan |
| cancellation — pengajuan/keputusan | Pemilik order | Ya | Nomor order, status pengajuan/keputusan; tanpa menjanjikan refund selesai sebelum terbukti |
| Produksi bertahap | Tidak | Tidak | Tetap di luar scope MVP |

Admin hanya menerima lima kategori K-19 di atas. Penerima customer harus pemilik transaksi/request; audience Admin hanya staf berwenang yang dikonfigurasi. Gunakan template terpisah sesuai kebutuhan, bukan mengirim semua data customer atau meneruskan payload mentah.

Late-payment message hanya untuk klasifikasi pembayaran benar-benar terlambat, bukan verifikasi terlambat. Saat job berjalan, validasi ulang state agar pesan segera bayar tidak dikirim setelah deadline atau untuk order paid/expired. Status pemeriksaan provider tidak boleh diberi pesan expired/refund sebelum hasilnya pasti. Penerima mengikuti K-19/A-08 yang sudah disetujui; template membatasi data sesuai kebutuhan dan hak akses.

FonnteService menggunakan HTTP server-side dengan token dari secret configuration, timeout, dan retry terbatas. Angka lama1,5,15 menit tetap usulan teknis, bukan nilai final; TL mengusulkan jumlah percobaan/jeda. Setelah retry otomatis terbatas habis, masukkan antrean review manual untuk staf pada jam operasional. Satu logical event/penerima/template memiliki unique key. Timeout ambigu tidak berarti provider pasti gagal; simpan ID provider bila tersedia dan hindari retry buta yang dapat menghasilkan pesan ganda.

API menerima request tidak sama dengan pesan telah diterima penerima. Bedakan queued/sending/sent/delivered/failed bila status provider tersedia; jangan menjanjikan exactly-once pengiriman eksternal. Dashboard customer tetap sumber status ketika WhatsApp gagal. [Fonnte Send API](https://docs.fonnte.com/api-send-message/).

### 7.7 Keamanan file, audit, dan operasional

- Periksa ukuran, MIME dan isi file; nama storage acak; download file privat melalui otorisasi; tolak executable/SVG untuk upload customer.
- Rate limit login, reset password, registrasi, checkout, dan custom submit; terapkan batas pending aktif A-09. Periksa limit dalam transaksi agar request simultan satu akun tidak melewatinya.
- Gunakan CSRF pada form, output escaping Blade, HTTPS, cookie Secure/HttpOnly/SameSite sesuai deployment, dan session timeout yang memadai.
- Audit tidak menyimpan password, server key, token pembayaran, nomor rekening penuh, atau seluruh payload tanpa redaksi. Rekening/bukti tetap tersedia hanya kepada pihak berwenang.
- Backup DB dan file privat harus dipulihkan bersama; uji restore sebelum go-live. Default target operasional usulan: RPO ≤ 24 jam, RTO ≤ 4 jam; Owner/Tech Lead memvalidasi biaya dan kesiapan layanan.
- K-20: retensi berbeda per kelas data; transaksi/refund sesuai kebutuhan usaha dan kewajiban yang berlaku; log teknis lebih singkat; hapus/anonymize PII ketika tidak diperlukan. Durasi final harus disetujui sebelum purge otomatis diaktifkan, termasuk temporary uploads/log/backup. Angka24 jam lama bukan durasi yang disetujui; jangan menghapus file yang masih diproses atau terkait histori wajib.

## 8. UI/UX dan Non-Functional Requirements

### 8.1 UI/UX

- Mobile-first, Blade + Tailwind CSS 4; warna brand Dark Brown #251306, Burgundy Brown #512323, Brown #442818, Taupe #726251, Beige #C3B59C, Soft Pink #F7E3DF.
- Logo PNG asli; foto produk teroptimasi, alt text, dimensi eksplisit. SVG dekoratif aman dari aset internal diperbolehkan, bukan upload customer.
- Form Custom Cake bertahap dengan state input tetap tersedia saat pindah langkah dan error di field terkait.
- Informasi kuota selalu memakai “unit”; nama unit jual/kemasan jelas.
- Checkout menampilkan subtotal produk, total, deadline, tanggal/jam, metode, dan kebijakan sebelum submit.
- Countdown berdasarkan deadline server; status akhir diperiksa server. Tidak menampilkan countdown baru setiap refresh.
- Halaman order menampilkan status order dan pembayaran/refund secara terpisah, termasuk expired dengan refund pending.
- Animasi hanya mendukung feedback/navigasi; hormati prefers-reduced-motion dan jangan menghalangi submit/pembayaran.

### 8.2 Target yang dapat diuji

| Area | Acceptance target/default usulan |
| --- | --- |
| Performa halaman | Katalog/checkout menampilkan konten utama < 3 detik pada profil perangkat mobile dan 4G yang disepakati QA; pengukuran berulang dan konfigurasi uji dicatat |
| Ketergantungan vendor | Waktu loading Midtrans/Fonnte diukur terpisah; UI memberi status proses/timeout, tidak menggantung tanpa pesan |
| Concurrency | Uji minimal 20 checkout bersamaan atas satu produk dengan kapasitas lima; alokasi tidak pernah melebihi lima unit |
| Expiry | Aksi bayar baru ditutup pada deadline walaupun worker mati; rancangan R1 tidak menjual ulang reservasi ambigu; recovery memenuhi pembayaran tepat waktu tanpa efek ganda |
| Aksesibilitas | Target WCAG 2.2 AA untuk alur inti: keyboard, label, fokus, error, kontras, dan screen reader status |
| Responsif | Alur inti dapat digunakan pada viewport 360 px, tablet, dan desktop tanpa scroll horizontal yang menghalangi input |
| Availability | Pemesanan tersedia di luar jam toko; SLA uptime hosting ditetapkan sebelum go-live, tidak dianggap otomatis 100% |
| Pemulihan integrasi | Fonnte gagal tidak menggagalkan commit; webhook/rekonsiliasi dan outbox dapat diproses ulang tanpa efek bisnis ganda |
| Privasi | Customer A tidak dapat membaca/mengubah data customer B; URL file privat tidak terbuka permanen |

## 9. Rencana Eksekusi dan Gerbang Penerimaan

| Fase | Deliverable | Verifikasi sebelum lanjut |
| --- | --- | --- |
| 1 — Identitas dan fondasi | Akun aktif, sesi, reset password, role/policy, konfigurasi waktu/pilihan, komponen UI dasar | Akses lintas role/customer ditolak; registrasi tidak dapat memilih role staf |
| 2 — Katalog dan kapasitas | Produk/ukuran/foto, kuota harian, katalog per tanggal | F001/F004/F009; harga historis dan satuan unit benar |
| 3 — Checkout pickup | Validasi cutoff, quantity, metode, persetujuan kurir, snapshot, idempotency | F002/F005; boundary 17.00.00/17.00.01 dan concurrency MySQL |
| 4 — Pembayaran/expiry | Snap, webhook inbox, state handler, delayed expiry, sweeper, rekonsiliasi | F006 termasuk race, timeout, event duplikat dan late payment |
| 5 — Operasional/refund | Dashboard, penyerahan completed, pengajuan, refund manual/bukti/audit | F007/F010; floor, privasi bukti dan duplikasi refund |
| 6 — Custom dan notifikasi | Form bertahap, upload privat, state request, Fonnte/outbox | F003; H-3/H-7, kegagalan provider, pesan sesuai state |
| 7 — Konten dan go-live | Testimoni, profil, aksesibilitas, worker/scheduler, backup, UAT | F008; seluruh alur inti dan pemulihan kegagalan lulus |

### 9.1 Matriks pengujian wajib

| ID | Pengujian | Hasil wajib |
| --- | --- | --- |
| T-01 | Checkout/custom tanpa login | Ditolak; halaman publik tetap dapat dibaca |
| T-02 | Payload role admin saat registrasi | Akun tetap Customer |
| T-03 | Beli tiga unit dari kapasitas lima | Sisa dua |
| T-04 | 20 checkout bersamaan | Total unit teralokasi tidak melebihi kapasitas |
| T-05 | Ukuran berbeda satu produk | Memakai pool kuota yang sama |
| T-06 | Cutoff 16.59.59/17.00.00/17.00.01 | H+1/H+1/H+2 |
| T-07 | Lock tertahan melewati cutoff | Tanggal divalidasi ulang; tidak menerima jadwal kedaluwarsa |
| T-08 | Batas pickup 08.59/09.00/21.00/21.01 | Tolak/terima/terima/tolak |
| T-09 | Retry submit identik | Satu order dan satu payment reference |
| T-10 | Ubah nominal di browser | Server memakai harga sah dan snapshot |
| T-11 | Webhook palsu/nominal salah | Tolak atau review; tidak menjadi paid |
| T-12 | Sukses diikuti pending/duplikat | Tidak turun status atau menggandakan efek |
| T-13 | Deadline dan verifikasi sukses bersamaan | Pembayaran tepat waktu tetap dipenuhi; handler setelah deadline tidak memicu refund; efek bisnis satu kali |
| T-14 | Deadline tercapai, pembayaran masih ambigu, customer lain checkout | Reservasi belum dilepas/dijual ulang; expiry final harus dibuktikan sebelum pelepasan; oracle teknis masih perlu validasi |
| T-15 | Bayar sebelum deadline, notifikasi sesudah deadline | K-01: paid setelah verifikasi, pesanan dipenuhi, tidak ada refund hanya karena keterlambatan notifikasi |
| T-16 | Scheduler/worker berhenti | Aksi bayar baru tertutup; reservasi ambigu tetap terlindungi; recovery memeriksa provider, bukan langsung membatalkan |
| T-17 | H-2 tepat akhir hari dan sesudahnya | Terima pengajuan/ditolak sesuai BR-07 |
| T-18 | Total Rp100.001 untuk refund customer | Rp80.000, tanpa potongan MDR |
| T-19 | Pembatalan toko/pembayaran benar-benar terlambat yang memenuhi klasifikasi expiry | Baseline 100%, meskipun H-2 lewat; bukan untuk webhook terlambat atas pembayaran tepat waktu |
| T-20 | Refund tanpa bukti atau selesai dua kali | Penyelesaian tidak sah ditolak; satu kewajiban |
| T-21 | Custom H-3/H-7 | Batas edible print berbeda dan diterapkan server |
| T-22 | File palsu atau akses file customer lain | Ditolak |
| T-23 | Fonnte timeout atau retry job | Data bisnis tetap ada; kegagalan terlihat; deduplikasi lokal berjalan |
| T-24 | Paid menjadi completed | Kuota harian tidak kembali tersedia |
| T-25 | Refund manual selesai lalu webhook settlement lama | Catatan partially_refunded/refunded tidak hilang |
| T-26 | Input alamat/ongkir lewat payload tak dikenal | Tidak menjadi atribut order atau biaya; total hanya produk |
| T-27 | Settlement duplikat saat refund customer 80% pending/processing/completed | Persentase dan satu kewajiban tetap; event ulang bukan dana baru |
| T-28 | Dua Admin claim refund; Admin lain mencoba complete/takeover | Satu petugas berwenang; pengecualian/takeover dikendalikan Owner dan diaudit |
| T-29 | Takeover refund dengan hasil transfer belum diketahui | Tidak transfer ulang sebelum mutasi dipastikan; alasan dan hasil pemeriksaan tercatat |
| T-30 | Pengajuan cancellation sah belum diputuskan, lalu handover | Handover ditolak; reject membuka handover jika paid; approve menghasilkan cancelled tanpa handover |
| T-31 | Approval cancellation dan handover bersamaan | Tidak keduanya berhasil; satu hasil konsisten beserta audit dan kewajiban yang sesuai |
| T-32 | Pilih channel belakangan/refresh/retry sesi | Batas absolut pembayaran tidak bergeser; buktikan expiry halaman dan metode tiap channel |
| T-33 | Bukti waktu/status provider tidak lengkap atau saling bertentangan | Review; tidak menyimpulkan tepat waktu/terlambat dari waktu kedatangan webhook; recovery masih blocker |
| T-34 | Terdeteksi pembayaran tepat waktu setelah expiry final salah | Insiden K-01, tanpa refund otomatis/ambil kuota pihak lain; recovery Schema §7.4; pembuktian finalitas channel dan rehearsal belum dilakukan |

Status seluruh T-01–T-34: spesifikasi, belum dijalankan pada revisi dokumentasi ini. T-14/T-16/T-32–T-34 memerlukan finalisasi kontrak teknis; A-01–A-05/A-09 sudah disetujui. Batas input, strategi retry, tanpa SLA customer, penerima dan prinsip retensi sudah disetujui K-17–20. Angka retry/jeda TL dan durasi retensi final masih belum ditetapkan. DBT-01–21 dan AC tambahan ada pada Test-Cases.md/Acceptance-Criteria.md.

Gunakan Pest dan factory sesuai konvensi proyek. Uji concurrency memakai MySQL nyata dengan koneksi/proses paralel; SQLite tidak cukup untuk membuktikan lock InnoDB. Fake HTTP untuk pengujian deterministik; sandbox Midtrans untuk kontrak channel dan webhook end-to-end. Jangan mengirim notifikasi nyata atau melakukan transfer refund saat automated tests.

Perintah implementasi mengikuti AGENTS.md: pengujian terfokus `php artisan test --compact <path-test>`; build frontend `npm run build`; bila PHP berubah jalankan `vendor/bin/pint --dirty --format agent`. Seluruh suite `php artisan test --compact` dijalankan sebagai gerbang rilis. Dokumen ini sendiri tidak memerlukan migrasi, formatter PHP, atau eksekusi transaksi.

## 10. Checklist Kesiapan Go-Live dan Keputusan Operasional

Detail operasional berikut harus memiliki nilai/pemilik sebelum produksi. Keputusan bisnis dan kontrak teknis yang masih terbuka pada bagian 1.3–1.4 menghalangi implementasi fitur terkait; daftar ini tidak mengizinkan coding dengan asumsi seolah sudah disetujui.

- Alamat toko, nomor WhatsApp admin, logo, foto produk, daftar rasa/filling, kategori/ukuran, harga, dan definisi box/buah.
- K-01–K-16 dan A-01–A-05/A-09 disetujui sesuai1.4. A-07 lama ditolak. K-17–20 mengesahkan batas input, strategi retry, tanpa SLA customer, penerima dan prinsip retensi. Tutup angka retry/jeda teknis dan durasi retensi final tanpa menanyakan ulang kebijakannya; tutup kontrak provider/reservasi dan gate Database Schema §11 sebelum migration terkait/produksi.
- Channel Midtrans aktif, pengujian expiry masing-masing, webhook HTTPS, secrets sandbox/production terpisah, serta prosedur rekonsiliasi.
- Akun/perangkat Fonnte siap, nomor penerima benar, template status pembayaran tidak menjanjikan order aktif untuk late payment.
- Email reset password dapat dikirim; akun yang belum diverifikasi tidak diberi label verified.
- Petugas penyerahan 17.00–21.00; persiapan cake untuk pickup pukul 09.00; prosedur kurir terlambat, customer tidak datang, dan identifikasi pihak pengambil. Sistem tidak otomatis membatalkan/menyelesaikan no-show.
- SOP refund: bank sumber, verifikasi rekening tujuan, penanggung jawab, komunikasi bahwa belum ada SLA customer dan staf menangani pada jam operasional, dan pemeriksaan mutasi sebelum retry transfer ambigu.
- Kebijakan retensi akun/transaksi/foto/bukti, akses staf, dan penghapusan data yang tetap mempertahankan catatan keuangan yang diperlukan.
- Infrastruktur worker/scheduler, storage privat durable, alert, backup, restore, serta kapasitas beban telah diuji.

**Definisi selesai MVP:** Fitur Must Have memenuhi acceptance criteria dengan bukti pengujian nyata, kasus batas transaksi/kuota lulus, asumsi terkait memiliki keputusan Owner yang eksplisit, kontrak teknis R1 selesai dan diuji, serta tidak ada kembali scope guest checkout, ongkir/Google Maps, DP custom dalam sistem, refund otomatis, timeline produksi, atau laporan penjualan otomatis tanpa revisi PRD baru. Revisi dokumentasi R1 belum memenuhi definisi selesai implementasi tersebut.
