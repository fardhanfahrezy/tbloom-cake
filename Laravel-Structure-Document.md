# Laravel Structure Document — TBloom Cake R1
Finalisasi dokumentasi 19 Oktober 2026; baseline R1 16 Oktober 2026. Pemetaan tanggung jawab rancangan, bukan class/migration yang sudah dibuat. Kontrak normatif: [Database Schema](Database-Schema.md), bisnis: [PRD](PRD-TBloom-Cake-v2.1.md).

## Repository aktual
Ditemukan User model/factory, migration scaffold users/cache/jobs, controller dasar, AppServiceProvider dan example tests. Belum ada models/migrations/factories/tests order, payment, refund atau quota. phpunit memakai SQLite :memory:; bukan bukti locking MySQL. Tidak ada perubahan kode pada finalisasi ini.

## Konvensi
Tetap direktori Laravel existing; lokasi class operasi domain ditentukan TL mengikuti konvensi saat implementasi, bukan izin membuat base folder/dependency baru. Konfirmasi installed package versions dan gunakan Boost docs sebelum API version-specific. .ai/rules tidak ditemukan saat pemeriksaan. Terapkan PHP enum case TitleCase dengan string sesuai CHECK Schema; unknown berbeda dari failed.

| Area | Rancangan tanggung jawab | Batas |
|---|---|---|
| Models | Dictionary19 tabel bisnis termasuk PaymentMovement/RefundTransfer | Tidak generic CRUD status finansial; encrypted casts TEXT dan protected attributes |
| Form Requests | Input allowlist, canonical contacts, date/amount parsing, idempotency key/hash | Tidak percaya role/harga/status dari browser |
| Policies | Own resources, active role, assigned refund+version, Owner takeover | Bukan sekadar tombol tersembunyi; semua akses file dicek |
| Domain operations | Checkout, payment apply/close, cancel submit/decide, quota edit, handover, refund prepare/reconcile/complete, custom | Lock order Schema §7.2; audit/outbox satu commit |
| Provider adapters | Signature/status/time/absolute expiry/finality dan redacted response | HTTP di luar lock; no automatic refund API |
| Jobs/scheduler | Inbox process, payment reconcile, outbox delivery, claim recovery, orphan review | Retry tidak membuka ulang unknown bank; angka retry/jeda usulan TL belum final |
| Storage controllers | Private evidence/custom download setelah policy | Tidak public disk/symlink, validate MIME/size |
| Factories | Akun role/active, product/date capacity, pending/paid/completed, unknown transfer, canonical facts | Data sintetis; tidak seeder produksi atau secret |
| Pest features | State/permission/constraints/AC DBT-01–21 | MySQL dedicated untuk races, SQLite hanya test umum |
| Views | Countdown absolut, review, dana/refund berbeda status order, cancellation hold | Tidak meminta bayar ulang ketika provider ambigu |

## Kontrak operasi
CreateOrder menyimpan order/item/reference/audit/outbox atomik, kemudian Midtrans HTTP dengan reference sama. ApplyPayment idempotent atas canonical facts dengan pencocokan identitas/provenance dan deteksi konflik sesuai Schema §8, timely paid tetap dipenuhi. ExpireOrder hanya final setelah closure evidence; ambiguous tetap hold. Pending cancel otomatis menunggu closure, timely payment menang. Paid cancel H-2, applied atomik dengan obligation/release.
PrepareRefund memeriksa assignment/version, bank verified, available funds/remaining obligation dan active attempt. RecordRefundSuccess memerlukan mutasi/evidence durable; ledger/state/audit satu commit. Takeover tidak meniadakan unknown. CompleteHandover mengunci order dan memeriksa cancellation pending, tidak release quota.
Custom perubahan print memenuhi H-7, kalender D+90, tidak membentuk payment. Audit/logging/outbox redacted; financial facts immutable melalui aplikasi.

## Implementasi kelak dan gerbang
Schema R1 bukan migration executable. Periksa DB version/engine/data/collation dahulu; review generated unique slots/composite FK/CHECK, ledger canonical identity/correction, source transfer references, channel finality.
Urutan dependency dan rollback Schema §4.23. Existing users harus backfill WA/role secara sah sebelum NOT NULL/unique. Tidak asumsi tabel kosong.
Factories/tests dibuat setelah kontrak terkait final; narrow tests lalu suite relevan, hasil dicatat nyata. Tidak ada hasil test lulus diklaim. K-17–20: batas input awal tetap; retry terbatas+backoff lalu review manual; tanpa SLA customer; customer hanya transaksi sendiri dan Admin hanya payment_exception/refund/custom_request/cancellation/new_order dengan data minimum. Prinsip retensi per kelas disetujui. Pending hanya angka retry/jeda TL dan durasi retensi final; purge otomatis belum boleh aktif. Keputusan A-01–A-05/A-09 tidak ditanyakan ulang.
