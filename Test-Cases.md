# Test Cases — TBloom Cake R1

Finalisasi dokumentasi 19 Oktober 2026; baseline R1 16 Oktober 2026. Status seluruh kasus: BELUM DIJALANKAN. Dokumen bukan test code atau laporan hasil.
Baseline T-01–T-26 ada di [PRD §9.1](PRD-TBloom-Cake-v2.1.md). R1 mengganti hasil T-13–T-16 dan menambah T-27–T-34; ID lama tidak dihapus.

| ID | Setup dan aksi | Expected result | Kaitan |
| --- | --- | --- | --- |
| T-13 | Pembayaran sebelum deadline; handler/expiry berjalan paralel, termasuk handler memperoleh lock/commit sesudah deadline | Pembayaran tepat waktu dipenuhi satu kali; pemrosesan terlambat bukan alasan refund | R1-AC01 |
| T-14 | Satu unit tersisa direservasi; deadline tercapai; status provider belum pasti; akun lain checkout | Reservasi tidak dijual ulang pada rancangan R1; setelah final unpaid sah, release satu kali | R1-AC03; gate bukti finalitas channel |
| T-15 | Order 10.00, deadline 10.30, sukses provider 10.29.59, webhook 10.30.20 | Paid dan pesanan dipenuhi; tidak ada kewajiban refund akibat keterlambatan | R1-AC01 |
| T-16 | Worker/scheduler mati melintasi deadline, lalu dipulihkan | Bayar baru ditutup; reservasi ambigu dilindungi; rekonsiliasi membedakan timely paid dari final unpaid | R1-AC02/03 |
| T-27 | Settlement pembayaran yang sama dikirim ulang ketika refund 80% pending, processing, dan completed | Satu kewajiban; tetap 80%; tidak menghapus catatan refund selesai | R1-AC05 |
| T-28 | Dua Admin claim bersamaan; non-assignee complete; Owner takeover dengan alasan | Satu assignee; complete tidak sah ditolak; takeover tercatat | R1-AC06 |
| T-29 | Transfer manual berstatus hasil tidak diketahui lalu pekerjaan diambil alih | Tidak mengulang transfer sebelum mutasi dipastikan; takeover bukan bukti gagal transfer | R1-AC06 |
| T-30 | Paid + cancellation eligible belum diputuskan; coba handover, kemudian jalankan variasi reject/approve | Awalnya ditolak; reject mengizinkan jika valid; approve membatalkan dan melarang handover | R1-AC07; eligibility disetujui K-06/A-03 |
| T-31 | Approval cancellation dan handover pada koneksi/proses berbeda | Tidak kedua aksi berhasil; audit/alokasi/refund konsisten | R1-AC08 |
| T-32 | Order dibuat lalu channel dipilih belakangan; refresh dan retry token; coba bayar setelah deadline | Tidak memperpanjang deadline absolut; expiry halaman/metode dibuktikan tiap channel | R1-AC02; sandbox diperlukan |
| T-33 | Payload/status tanpa bukti waktu sukses atau saling bertentangan | Review, tidak menyimpulkan late dari received_at; tidak memaksa paid/refund dari bukti belum valid | R1-AC04 |
| T-34 | Simulasikan insiden expiry final/alokasi terlepas tetapi bukti timely paid kemudian ditemukan | Eskalasi insiden K-01 tanpa refund otomatis/ambil alokasi pihak lain; recovery terkontrol menurut Database Schema §7.4, tanpa overbook otomatis | R1-AC04; gate finalitas channel |

## Metode pembuktian saat implementasi

- Gunakan Pest dan factory sesuai proyek; tidak menambah dependency tanpa persetujuan.
- Kontrol waktu, HTTP, queue, mail, storage dalam test deterministik; jangan menjalankan transfer atau notifikasi nyata dalam automated test.
- Test race menggunakan MySQL/InnoDB dengan koneksi/proses terpisah, bukan pembuktian melalui SQLite.
- Sandbox channel memverifikasi status/timestamp sukses, penutupan, expiry absolut dan hasil ambigu; screenshot QR/kode saja bukan bukti kontrak.
- Assert respons serta state order/payment/refund/alokasi/audit, bukan hanya status HTTP.
- Bedakan tidak dijalankan, blocked, gagal dan lulus; tidak menandai blocked sebagai lulus.
- Suite terfokus dijalankan saat implementasi; suite lengkap/build dan bukti UAT diperlukan sebelum rilis. Tidak ada perintah pengujian dijalankan untuk perubahan dokumentasi R1 ini.

## Matriks integritas MySQL/InnoDB R1

Status **setiap DBT di bawah: belum diverifikasi / belum dijalankan**. Sumber: Database Schema §4–8, AC R1-AC01–24. Test dilakukan kelak pada database MySQL terisolasi dengan data sintetis, bukan shared development/staging/production. Jangan menjalankan migration sekarang.

Untuk race gunakan setidaknya dua koneksi/proses independen dengan barrier yang mengendalikan urutan (bukan sleep sebagai bukti). Jangan membungkus seluruh test concurrency dalam satu transaksi test yang membuat data invisible pada koneksi lain. Catat versi server, engine setiap tabel, isolation, sql_mode/collation, trace urutan lock dan final rows. HTTP/clock/mail/storage/queue diisolasi; sandbox integrasi diuji terpisah. Tidak transfer bank nyata.

| ID | Setup/aksi wajib | Oracle/assertion | Lapisan/gate |
|---|---|---|---|
| DBT-01 | Buat schema R1 di disposable DB setelah migration disetujui; inspect SHOW CREATE/metadata | Seluruh27 tabel, tipe/null/default/FK/unique/CHECK/index sesuai dictionary; engine InnoDB; tak ada silent unsupported CHECK | DDL; server belum tersedia |
| DBT-02 | Insert item varian produkA/availabilityB, refund payment orderlain, transfer paymentlain, evidence obligationlain, request userlain | Composite FK menolak masing-masing; kasus valid diterima; nullable event belum matching tetap boleh review | C-01–03, AC17 |
| DBT-03 | Quantity0/negatif, money overflow, line_total salah, currency bukanIDR, status illegal termasuk publikasi testimoni/rating di luar1..5, time08.59/21.01/detik nonzero, required timestamp NULL | DB CHECK/NOT NULL menolak; API invalid date/cutoff ditolak aplikasi; arithmetic aman pada nilai maksimum | C-07, AC17 |
| DBT-04 | 20 checkout memperebutkan5 unit; sizes berbagi pool; satu akun4 checkout; held review+paid; completed; admin turunkan limit | SUM unreleased≤limit; max3 pending held; paid tidak dihitung; qty/BOX tepat; completed tetap terpakai; limit di bawah alokasi ditolak | AC03/13/15; row locks |
| DBT-05 | Payment success tepat waktu versus expiry/unpaid cancel; deadline lewat+provider timeout; replay final closure | Timely paid menang; automatic cancel rejected payment_won; ambiguous tetap hold; verified unpaid close release sekali dan status tepat; tidak membuat refund tanpa dana | AC01/03/12 |
| DBT-06 | Duplicate/reordered receipts; manual return lalu provider reports return sama; obligation80%; receipts100.001/return20.000 | Satu canonical fact per actual movement; obligation80.000, remaining60.000; duplicate tidak ubah funds; old settlement tidak hapus return | AC05/11/21 |
| DBT-07 | Bill100.000 vs verified receipt90.000 dan110.000; spoofed amount tanpa verifikasi; reversal sebelum obligation | Spoof ditolak; actual anomaly review Owner tanpa fulfill; basis/amount obligation100% memakai gross verified receipts, sisa dikurangi actual returns sekali: receipt90.000/return20.000 sebelum obligation → basis90.000, amount90.000, sisa70.000; bukan mengubah bill menjadi actual secara diam-diam | AC09; Owner review |
| DBT-08 | Dua Admin prepare transfer bersamaan, satu active unknown; concurrent refunds/reversal | Satu active attempt; amount≤available/obligation saat authorization; reserved funds tidak double-spend; unknown tidak expire membuka attempt | AC06/11 |
| DBT-09 | Buat obligation tanpa rekening; prepare tanpa verified bank; complete tanpa ref/time/evidence; success lengkap; replay complete | Obligation boleh; prepare/complete invalid ditolak; valid insert satu return+status; replay no-op; failed hanya setelah verified failure | AC10 |
| DBT-10 | Owner takeover saat transfer unknown; old assignee complete; new assignee coba ulang; mutasi success/failure; external reversal race | Assignment version stale ditolak; takeover tidak unlock attempt; success rekam bukti, failure baru retry, unknown tetap block; double-count return dilarang; excess actual dicatat incident | AC06/21 |
| DBT-11 | Submission belum memiliki row versus handover; cancellation approval versus handover; pending change diganti cancellation | Parent lock serialisasi; cancellation committed dahulu menahan handover; completed dahulu menolak submit; tidak completed+applied cancel; one pending slot konsisten | AC07/08 |
| DBT-12 | Inbox crash sebelum/di tengah/sesudah domain commit; lease expired/reclaim; stale worker selesai belakangan | Faktanya satu kali; processed+effects atomic; token lama tidak commit; unknown reference review tak mengubah uang | AC18 |
| DBT-13 | Domain commit lalu enqueue gagal; send berhasil tapi ACK hilang; worker stale; state jadi paid sebelum reminder | Scanner memulihkan pending; unknown send tidak disebut failed pasti; obsolete suppressed; domain tetap committed; jangan assert external exactly-once tanpa dukungan | AC18/22; K-19 routing disetujui |
| DBT-14 | Customerlain/staf nonassigned akses rekening/file; log payload/failed job; encrypted fields/key rotation; MIME fake/path traversal | Unauthorized403/404, no public URL, plaintext tak muncul DB/log/outbox, key recovery sesuai rancangan; invalid upload ditolak | AC19/23; batas input K-17 disetujui, durasi retensi pending |
| DBT-15 | Delete parent yang punya order/refund; orphan polymorphic/unknown events; upload durable lalu rollback; object committed hilang | FK RESTRICT; tidak cascade histori; unknown event review; orphan staging tidak publik; janitor tidak hapus referenced evidence; missing proof block complete/incident | AC20 |
| DBT-16 | Sandbox tiap channel: pilih metode belakangan, bayar dekat/persis batas, close race, not-found/timeout, success timestamp ambiguous | Absolute expiry tak reset; mapping time/finality berbukti; ambiguous hold; timely delayed fulfill. Screenshot QR bukan bukti finalitas | AC01/02/04; blocker channel |
| DBT-17 | UTC date berbeda Jakarta; tengah malam;17:00:00/01; H-2 23:59:59/00 berikutnya; custom H3/H7 dan add-print H6; D90/D91; edit pickup/qty | Konversi/cutoff/horizon/H2 tepat; add print H6 ditolak meski H2 terpenuhi; pickup time/method immutable; custom tanpa financial rows | AC14/16 |
| DBT-18 | ConnectionA pre-read, B commit perubahan sebelum A lock; deliberate deadlock berlawanan pada fixture; lock timeout | Operasi aplikasi reread current state; retry transaksi utuh bounded; tidak stale oversell/refund; tidak mengulang HTTP/bank; audit tidak ganda | AC20 |
| DBT-19 | Upgrade dari scaffold berisi data sintetis, duplicate/orphan/backfill missing; failure tengah DDL; rehearsal reverse FK/restore backup+files+keys | Preflight menolak data tidak valid; tidak fabricate identities; restore utuh, keys decrypt, evidence cocok; rollback tak menghapus transaksi. EXPLAIN volume realistis untuk queue/quota/history | AC20; batas input K-17 disetujui |
| DBT-20 | Review PRD/Schema/Architecture/Structure/AC/traceability/test IDs | K-01–20/A status konsisten, scope MVP tetap, tiap constraint/test punya source; tidak ada klaim runtime pass tanpa log | Review dokumentasi, bukan business test |
| DBT-21 | Uji pemilik notifikasi, seluruh kategori Admin termasuk negative paid/expired rutin, redaksi template; batas input awal; purge enable sebelum durasi disetujui; retry exhaustion | Customer hanya transaksi sendiri; Admin hanya lima kategori K-19; data minimum; batas input tetap; purge otomatis tetap nonaktif tanpa durasi final. Retry terbatas berakhir manual review, tanpa SLA customer/ulang bank unknown. Angka retry final belum tersedia sehingga kasus batas percobaan belum dapat dieksekusi dengan oracle final | R1-AC22/23/24; K-17–20 |

Batas input awal PRD serta penerima A-08 sudah menjadi oracle test melalui K-17/K-19. Strategi retry terbatas+backoff lalu review manual dan tidak adanya SLA customer juga disetujui K-18. Angka retry/jeda/lease teknis dan durasi retensi final belum ditetapkan; jangan mengaktifkan purge otomatis sebelum durasi disetujui. Test larangan purge dapat ditetapkan sekarang tanpa mengarang durasi. Prosedur koreksi fakta ledger salah harus direview TL dan ditambah test sebelum implementasi; tidak ditutupi oleh duplicate-event test.

## Bukti aktual
Pembacaan statis source/docs dilakukan. Pemeriksaan read-only MySQL pada19 Oktober2026 kembali connection refused; server/schema/constraints/query plans belum diverifikasi. Tidak menjalankan T-01–T-34 atau DBT-01–21; example test scaffold juga tidak dijadikan bukti bisnis. Test failures, blocked, not-run dan passed harus dicatat terpisah saat implementasi.

