# Architecture Document — TBloom Cake R1
Finalisasi dokumentasi 19 Oktober 2026; baseline R1 16 Oktober 2026. Rancangan review, belum implementasi/hasil test. Sumber bisnis [PRD](PRD-TBloom-Cake-v2.1.md) §1.3–1.4; kontrak data/transaksi normatif [Database Schema](Database-Schema.md).

## Keputusan dan scope
K-01–K-16 tersinkron; A-01–A-05/A-09 disetujui, A-07 lama ditolak. K-17–20 mengesahkan batas input awal, retry terbatas+backoff lalu review manual, tanpa SLA customer, penerima A-08 dan prinsip retensi per kelas. Angka retry/jeda TL dan durasi retensi final masih belum ditetapkan. Tidak menambah fitur di luar MVP. Struktur tambahan payment_movements/refund_transfers hanya integritas pembayaran/refund manual.

## Komponen dan sumber kebenaran
Target Laravel13/PHP8.4, MySQL/InnoDB, Blade/Tailwind, queue database, Midtrans Snap, Fonnte/email dan storage privat durable. Versi paket diperiksa sebelum implementasi; versi MySQL live belum diketahui.
- Controller/Form Request/Policy: autentikasi, kepemilikan, allowlist dan otorisasi aktor; tidak menulis status uang langsung dari input/redirect.
- Operasi domain: satu transaksi untuk order/items/payment/reference/audit/outbox; adapter HTTP di luar lock.
- payments.expected_amount adalah tagihan; payment_movements fakta receipt/return; refund_logs kewajiban; refund_transfers attempt, termasuk unknown yang memblokir attempt baru.
- daily_availabilities adalah baris koordinasi; SUM unreleased order_items adalah pemakaian, termasuk completed/pending review. Tidak counter kedua.
- payment_events inbox durable; notification_deliveries outbox durable; jobs hanya pengangkut, bukan sumber kebenaran.
- Custom request+private attachments tanpa payment/kuota/DP dalam sistem.

## Kontrak concurrency
Schema §7.2 menetapkan lock users→catalog→availability→orders→payments→requests→refund→attempts→effects. Semua perubahan pending count mengunci customer. Submission dan handover memakai parent order lock, termasuk belum ada request row. Sesi bisnis READ COMMITTED adalah rekomendasi TL yang harus dibuktikan MySQL; current reads setelah lock, bukan cache. Retry deadlock seluruh DB transaction, tidak replay transfer bank/HTTP.
Foreign keys/composite unique/CHECK menjaga integritas row; saldo, quota, minimum evidence, role dan status transition dijaga aplikasi/SOP. Tidak menjanjikan DB mampu menjamin exactly-once bank manual.

## Pembayaran dan kuota
Deadline30 menit absolut sejak order, pilihan channel tidak reset. Notifikasi terlambat dengan timely payment terverifikasi→paid dan dipenuhi. Ambigu→hold kuota, reconciliation24/7, manual09–17 WIB. Unpaid pending cancel tanpa H-2/approval, release hanya closure sah; timely payment sebelum closure menang, paid, permintaan automatic cancel ditolak. Pending yang tetap hold dihitung max3; paid tidak.
Anomali nominal terkonfirmasi→review Owner, tidak fulfill, close dan return hak customer terverifikasi. Actual late berbeda dari webhook late;100% kewajiban sesuai PRD dan saldo rekonsiliasi.
Adapter channel harus membuktikan absolute expiry, waktu sukses/zona/presisi, race closure/settlement dan status recovery. Referensi umum [Midtrans webhook](https://docs.midtrans.com/docs/https-notification-webhooks) dan [Snap expiry](https://docs.midtrans.com/reference/expire-a-snap-session) bukan bukti konfigurasi merchant. Sandbox belum dijalankan.
Expiry final salah+timely receipt: incident, freeze/alert, recover dengan capacity lock jika tersedia; bila kapasitas terpakai pihak lain, Owner menangani pemenuhan tanpa auto overbook/ambil alokasi/refund. Gate finalitas adapter tetap terbuka.

## Refund dan cancellation/handover
H-2 memakai submitted_at sampai23.59.59 WIB pickup−2. Cancellation pending eligible menahan handover. Approve→cancelled+release+obligation+request applied satu commit; reject boleh handover bila paid. Completed tidak release.
Obligation boleh dibuat sebelum bank tersedia. Prepare hanya assigned Admin aktif, version valid, rekening verified, saldo dan obligation cukup, tanpa unresolved attempt. Destination snapshot encrypted. Sebelum bank attempt unknown; timeout bukan failed. Owner takeover tidak membuka attempt baru. Success dibuktikan mutasi+private evidence, return movement+state satu commit.
Kewajiban disimpan bruto; return sah dikurangkan sekali untuk sisa dana/kewajiban. Return/reversal sama didedup canonical movement; identitas konflik dibekukan untuk review sesuai Schema §8. Reversal ambigu freeze. Peristiwa eksternal independen yang sudah terjadi tetap dicatat walau excess; tidak menyembunyikan fakta agar saldo tampak benar. SOP transfer merupakan kontrol tambahan di luar database.

## Inbox/outbox/recovery
Claim token+lease, bounded processing, index due/lease. Domain effect+processed atomik, stale worker ditolak token. Outbox row bersama domain commit, scanner memulihkan enqueue gagal. External HTTP tidak di dalam DB lock. Provider send timeout→unknown; tanpa idempotency/status provider tidak menjamin exactly-once notifikasi.
Scheduler/retry/lease/alert angka awal PRD tetap usulan teknis; TL mengusulkan jumlah retry/jeda. K-19 menetapkan customer menerima notifikasi transaksinya sendiri dan Admin hanya payment_exception/refund/custom_request/cancellation/new_order, dengan template data minimum. Staf menangani review pada jam operasional, tanpa SLA customer. Tidak gagal bayar/order karena WhatsApp gagal.

## Privasi, file dan deployment
Encrypted bank/token, hash password, redacted audit/errors, private attachments/evidence via policy. File staging/durable object sebelum metadata commit; DB gagal menghasilkan private orphan untuk cleanup setelah grace disetujui. Backup DB/files/keys, rotasi key dan restore harus diuji.
Prinsip K-20 disetujui: retensi per kelas, transaksi/refund sesuai kebutuhan usaha/kewajiban, log teknis lebih singkat, PII dihapus/anonymize saat tidak diperlukan. Durasi final belum disetujui; jangan aktifkan purge otomatis. Migration expand/backfill/validate/contract, FK RESTRICT histori, forward-fix setelah data finansial ada; tidak DROP sebagai rollback produksi.
MySQL terakhir connection refused; engine live/schema kosong/constraint/query plan tidak disimpulkan. Rencana proof DBT-01–21 di Test-Cases.md; belum diverifikasi. Gate per komponen di Database Schema §11.
