# TESTING REPORT — TBloom Cake

Tanggal: 23 Oktober 2026, Asia/Bangkok (UTC+07:00). Audit dimulai sekitar 14:18; hasil pengujian dikumpulkan sampai sekitar 14:46. Mode: **AUDIT FIRST — NO AUTOMATIC FIXES**.

## 1. Executive Summary

**Belum siap rilis.** Suite yang tersedia lulus, tetapi audit menemukan dua defect terkonfirmasi dan satu masalah environment yang telah direproduksi. Checkout serta alur pembayaran/refund dan beberapa fitur MVP belum diimplementasikan.

| Temuan unik | CRITICAL | HIGH | MEDIUM | LOW |
|---|---:|---:|---:|---:|
| CONFIRMED BUG, termasuk secret/configuration defect | 0 | 1 | 1 | 0 |
| ENVIRONMENT ISSUE utama, dihitung terpisah | 0 | 1 | 0 | 0 |

- **AUD-SEC-001 / HIGH:** server key Midtrans berada di file template yang dilacak Git. Nilainya cocok dengan konfigurasi lokal; tidak ada pada versi HEAD file tersebut. Tidak ada bukti dalam audit ini bahwa perubahan itu sudah dipush atau bahwa key masih valid.
- **AUD-BUG-001 / MEDIUM:** helper upload privat menerima PNG terpotong 33 byte yang tidak bisa didekode.
- **AUD-ENV-001 / HIGH:** katalog lokal menghasilkan HTTP 500 karena database kerja belum mempunyai tabel products.

Tidak ada source PHP/JS/CSS, konfigurasi, migration, dependency, atau test yang diperbaiki. Tidak commit/push/deploy, melakukan pembayaran/transfer, atau mengirim notifikasi nyata. Database kerja tidak dimigrasi/reset; browser menimbulkan penggunaan session/log normal. DDL dan data sintetis suite hanya dijalankan pada tbloom_testing.

## 2. Environment Information

Repository: branch `codex/phase1-database-foundation`, HEAD `83b2f4c7815355f576b344bdd442177bba7ce4fe`.

Perubahan sudah ada saat audit dimulai: `.env.example`, `.gitignore`, `AGENTS.md`, `config/checkout.php`, serta untracked `tests/Feature/CheckoutOptionsTest.php`. Semuanya dipertahankan. Snapshot hash awal mencakup 440 file tracked/untracked yang tidak diabaikan Git.

| Area | Bukti aktual |
|---|---|
| PHP / Composer | PHP 8.4.12; Composer 2.10.1 |
| Laravel / Pest / PHPUnit / Pint | 13.35.0 / 5.3.1 / 13.4.1 / 1.32.1; composer show --direct dan Boost application_info |
| Node / npm | 25.9.0 / 11.12.1 |
| Frontend installed | Tailwind 4.3.3; Vite 8.3.4; laravel-vite-plugin 3.2.0; Bootstrap 5.3.8; tidak mengasumsikan Bootstrap 6 |
| Composer validation/platform | composer validate --no-check-publish PASS; composer check-platform-reqs PASS |
| npm dependency tree | npm ls --depth=0 exit 0; @laravel/multiplex merupakan unmet OPTIONAL dependency |
| Runtime lokal | APP_ENV local, debug aktif, timezone UTC, locale en; session/cache/queue database; mail log |
| Database kerja | MySQL 8.4.3, schema tbloom, 9 tabel InnoDB; tiga migration scaffold Ran dan 22 migration pending |
| Database test | mysql_testing menunjuk 127.0.0.1/tbloom_testing; UTC +00:00, READ COMMITTED; 28 tabel InnoDB sesudah suite |
| Config transaksi kerja | Session MySQL SYSTEM / REPEATABLE-READ; konfigurasi UTC/READ COMMITTED eksplisit hanya pada mysql_testing |
| Routing | 36 route aplikasi dari route:list --except-vendor -vv; tidak ada checkout/payment/webhook/custom/refund/handover route |
| Queue/scheduler | Driver database terkonfigurasi; schedule:list: tidak ada scheduled tasks. Operasi worker bisnis belum ada |
| Filesystem | public/storage terhubung ke public disk; private_uploads terpisah, serve=false; tidak memvalidasi durability produksi |
| Secrets, hanya indikator | APP_KEY dan server key Midtrans ada; FONNTE_TOKEN dan NOTIFICATION_RECIPIENT_HMAC_KEY tidak ada. Tidak menyalin nilainya |
| API mode | MIDTRANS_IS_PRODUCTION=false; prefix server key lokal berbeda dari contoh sandbox SB-Mid-server-. Lingkungan/validitas merchant belum dibuktikan |
| Rules | AGENTS.md dibaca; .ai/rules tidak ada; tujuh dokumen spesifikasi root dan IMPLEMENTATION-PROGRESS.md digunakan |

Bootstrap suite memverifikasi testing, uncached config, serta target DB sebelum trait destructive berjalan. TestCase menolak DB kerja/remote/URL/read-write/socket override dan memverifikasi SELECT DATABASE() untuk MySQL. Probe live dipaksa nonaktif melalui environment proses.

Tool yang benar-benar dipakai: Laravel Boost (informasi aplikasi, metadata/query read-only, log, URL), Graphify (repository dan query graph), browser CUA, CLI Composer/npm/Pest/Pint. Graphify masih snapshot 95fab79, sehingga source yang lebih baru dibaca dari checkout aktual. Tidak mengklaim workflow Codex Security atau AI Software Architect dijalankan. Tidak memakai subagent.

## 3. Test Coverage Summary

Angka test Pest adalah kasus setelah ekspansi dataset, bukan jumlah file atau persentase cakupan source. Tidak menghasilkan instrumented code coverage.

| Eksekusi | Terdaftar | PASS | FAIL / ERROR | Dilewati | Assertions |
|---|---:|---:|---:|---:|---:|
| MySQL penuh, bukti utama | 1124 | 1122 | 0 / 0 | 2 live | 3531 |
| Default SQLite memory, regresi tambahan | 1124 | 158 | 0 / 0 | 966 | 392 |
| Browser UI-01–UI-09 | 9 | 8 | 1 | 0 | — |
| Probe audit lokal: upload + secret template | 2 | 0 | 2 | 0 | — |

Durasi runner: MySQL 982290 ms; default 76520 ms. Kedua runner selesai exit 0. Dua live skips pada suite MySQL dilaporkan sebagai **BLOCKED** dalam audit karena kontrak/integrasi provider belum siap dan audit menonaktifkan aktivitas eksternal. Default 966 skips adalah pembatasan konfigurasi, bukan 966 defect atau bukti lulus MySQL.

Untuk inventaris utama yang tidak menghitung ulang regresi default: **1135 kasus tercatat; 1133 dijalankan = 1130 PASS + 3 FAIL; 2 BLOCKED; 0 NOT RUN di inventaris kasus tersebut.** Pemeriksaan build/lint/metadata tidak ditambahkan sebagai test cases. NOT RUN untuk spesifikasi yang belum mempunyai implementasi dicatat terpisah di bawah.

Distribusi 1122 PASS MySQL yang saling terpisah:

| Kelompok | PASS | Batas bukti |
|---|---:|---|
| Unit | 6 | Canonical identity dan scaffold unit |
| Authentication/authorization/profile/staff | 122 | Termasuk policy matrix dan last-Owner locking |
| Catalog/management/image/quota | 92 | Termasuk 2 independent-connection quota tests |
| Checkout input/options | 66 | 53 technical cases + 13 pilihan konfigurasi; belum persistence/atomic checkout |
| Private storage/recovery | 36 | Existing suite belum memiliki fixture PNG terpotong yang ditemukan audit |
| Midtrans preflight dengan fake HTTP | 24 | Tidak membuktikan transaksi atau expiry provider |
| Database safety/environment | 13 | Guard dan koneksi terpisah |
| Notification recipient identity | 16 | HMAC identity/keyring; belum delivery/routing runtime |
| Branding/example HTTP | 3 | Bukan acceptance seluruh MVP |
| Model/schema/financial foundation/upgrade/recovery lainnya | 644 | Constraints, encryption, append-only instance guard, fixtures dan rehearsal |
| **Total** | **1122** | |

Empat kasus pada tiga file concurrency lulus: CatalogQuotaConcurrencyTest (2), StaffAccountConcurrencyTest (1), PaymentMovementCorrectionConcurrencyTest (1). Ini membuktikan cakupan protokol lock/unique yang ditulis, bukan stress test 20 checkout, payment/expiry race, ataupun double-refund workflow.

### Pemetaan baseline T-01–T-34 dan DBT-01–DBT-21

Status di tabel berikut menilai **oracle lengkap setiap ID**, bukan keberadaan sebagian assertion. Kasus parsial tetap NOT RUN untuk acceptance lengkap. Matriks ini bukan kasus tambahan yang boleh dijumlahkan dengan tabel runner.

| ID | Status | Bukti / pekerjaan tersisa |
|---|---|---|
| T-02 | PASS | RegistrationTest: payload role tidak menaikkan privilege |
| T-22 | FAIL, parsial direproduksi | AUD-BUG-001 melewati pemeriksaan isi gambar pada helper; penolakan akses HTTP lintas pemilik tetap belum tersedia |
| T-01, T-03–T-10 | NOT RUN lengkap | Fixture kuota/calendar/pickup/input ada; checkout/custom HTTP dan atomic purchase belum ada |
| T-11–T-21 | NOT RUN lengkap | Belum ada handler gateway, expiry, cancellation/refund atau custom workflow. T-18 hanya aritmetika DDL/factory belum operasi refund |
| T-23–T-34 | NOT RUN lengkap | Delivery/replay/handover/channel expiry/incident domain belum ada; T-24 hanya konsumsi completed fixture pada query katalog |
| DBT-02 | PASS, cakupan FK | Suite foundation menolak relasi lintas product/order/payment/refund/evidence/request yang salah; authorization tetap kontrol terpisah |
| DBT-14 | FAIL, parsial direproduksi | Secret template AUD-SEC-001 dan validasi isi file AUD-BUG-001; key recovery/policy foundation lulus, endpoint privat belum ada |
| DBT-16 | BLOCKED | Per-channel finality, success time dan absolute expiry belum dibuktikan; tidak menghubungi provider |
| DBT-20 | PASS sebagai review | Sumber/keputusan ditelusuri; delta dan konflik status dicatat, bukan klaim implementasi penuh |
| DBT-01, DBT-03 | NOT RUN lengkap | Banyak DDL/domain checks lulus; belum full dictionary reconciliation dan semua validasi API bisnis. Delta 27→28 dijelaskan D-024 |
| DBT-04–DBT-13 | NOT RUN lengkap | Sebagian unique/lock/factory/outbox fields lulus; aggregate/state machine/inbox-outbox workflow belum diimplementasikan |
| DBT-15, DBT-17–DBT-19, DBT-21 | NOT RUN lengkap | RESTRICT, kalender helper, local recovery lulus sebagian; missing-file workflow, custom/cancel calendar, deadlock retry, realistic EXPLAIN, routing/retry exhaustion belum lengkap |

Rekap 55 ID spesifikasi: **3 PASS, 2 FAIL, 1 BLOCKED, 49 NOT RUN lengkap**. Dua ID FAIL dapat menunjuk defect yang sama; jangan menghitungnya sebagai defect tambahan.

## 4. Passed Tests

Perintah aktual:

```text
php vendor/bin/pest --configuration phpunit.mysql.xml --compact --log-junit <temp>/tbloom-audit-mysql-20261023.xml
php vendor/bin/pest --configuration phpunit.xml --compact --log-junit <temp>/tbloom-audit-default-20261023.xml
npm run build
php vendor/bin/pint --test --format json
composer validate --no-check-publish
composer check-platform-reqs
npm ls --depth=0
npm audit --json
git diff --check
```

- MySQL: Unit 6/6, Feature 1116 PASS + 2 live skipped, tanpa failure/error.
- Default: Unit 6/6, Feature 152 PASS + 966 skipped.
- Syntax PHP: `php -l` pada 225 file di app/bootstrap/config/database/routes/tests, tanpa failure.
- Pint read-only: `{"tool":"pint","result":"passed"}`. Tidak menjalankan formatter yang mengubah source.
- Vite build exit 0, 3 modules, sekitar 16.79 detik; manifest dan CSS/JS/font artifacts dihasilkan. Warning optional fontaine tidak memblokir build; dependency tidak dipasang.
- npm audit online exit 0, total advisory 0. Ini hasil registry pada saat audit, bukan jaminan source bebas vulnerability.
- Route/migration/database/scheduler read-only berhasil di luar sandbox. Output db:show juga merangkum schema lain yang dapat dilihat user DB; angka tabel server dari output itu tidak dipakai sebagai jumlah tabel TBloom.

Bukti mentah lokal tersedia di `C:/Users/LENOVO/AppData/Local/Temp/tbloom-audit-mysql-20261023.{log,xml}`, `tbloom-audit-default-20261023.{log,xml}`, dan `tbloom-audit-npm-live-20261023.json`. Log temp dapat dihapus oleh OS; ringkasan hasil disimpan di laporan ini.

## 5. Failed Tests

Tidak ada existing automated test yang gagal. Tiga pemeriksaan audit manual berikut gagal:

| Test ID / nama | Expected | Actual / error | Lokasi | Root cause / severity |
|---|---|---|---|---|
| UI-02: homepage → katalog | Katalog tampil | HTTP 500; SQLSTATE[42S02], table tbloom.products tidak ada | CatalogController.php:17; config/database.php koneksi mysql | Migration bisnis belum diterapkan pada DB kerja; HIGH, ENVIRONMENT ISSUE |
| AUD-PROBE-01: PNG terpotong | ValidationException sebelum penyimpanan | accepted=true, stored=true, full_decode_succeeds=false, 33 bytes, MIME image/png | app/PrivateUploadStorage.php:35–38 | Header/dimensi bukan full decoding; MEDIUM, CONFIRMED BUG |
| AUD-PROBE-02: secret template | Server key kosong/placeholder dalam template Git | Populated=true, cocok dengan konfigurasi lokal, HEAD kosong | .env.example:67 | Credential tersalin ke template tracked; HIGH, CONFIRMED BUG |

Tidak ada patch/permanent test baru ditulis pada tahap audit. Dua probe lokal tidak memanggil provider; repro upload menggunakan disk sementara khusus dan file sintetis yang dihapus setelah pemeriksaan.

## 6. Blocked Tests

| ID | Pemeriksaan | Penyebab / batas |
|---|---|---|
| BLOCK-01 | Dua MidtransSandboxContractTest datasets; DBT-16 | Opt-in live dipaksa false; adapter runtime belum ada; kecocokan credentials sandbox belum terbukti. Tidak membuat Snap session/charge |
| BLOCK-02 | Browser katalog berisi data, detail produk, login/profile/staff mutation end-to-end pada runtime kerja | AUD-ENV-001; tidak menambahkan user/produk sintetis ke DB kerja atau memigrasinya |
| BLOCK-03 | Advisory Composer terbaru | Sandbox DNS gagal; retry di luar sandbox timeout Packagist. composer audit --locked exit 0 setelah fallback cache dengan advisories/abandoned kosong. **Tidak** dianggap bukti database advisory terkini |
| BLOCK-04 | Provider delivery nyata | Mail log; Fonnte/config recipient/HMAC belum siap. Tidak mengirim pesan/email nyata |

Percobaan awal suite dalam sandbox berhenti sebelum test karena `vendor/autoload.php` dianggap unreadable. CLI MySQL dalam sandbox juga ditolak socket permission. Keduanya berhasil dijalankan dengan escalation yang disetujui; bukan bug aplikasi dan bukan blocker akhir automated suite. Tidak mengubah ACL atau vendor.

NOT RUN tambahan: viewport 360/tablet/desktop terkontrol, load/4G measurement, screen-reader/contrast audit lengkap, provider-specific sandbox UAT, produksi DR/storage durability, full financial state races. Keterbatasan browser yang tersedia hanya menghasilkan viewport 668×562; tidak mengklaim pengujian ukuran lain.

## 7. Bugs Found

### AUD-SEC-001 — Server key dalam template tracked

**Kategori:** CONFIRMED BUG (secret management). **Severity:** HIGH. **Lokasi:** `.env.example:67`. Sudah ada sebelum audit.

Reproduksi aman:

1. Verifikasi `git ls-files .env.example` mengembalikan template tersebut.
2. Baca assignment MIDTRANS_SERVER_KEY secara lokal tanpa mencetak nilainya.
3. Bandingkan in-memory dengan assignment pada .env, dan cek versi HEAD template.
4. Hasil: populated working template=true, equals local key=true, placeholder=false, populated at HEAD=false.

Expected: template tidak menyimpan server credential. Actual: nilai yang dipakai konfigurasi lokal ada di file tracked yang dapat ikut commit/push. Confidence tinggi untuk keberadaan nilai; validitas dan aktivitas provider tidak diuji. Tidak ada bukti audit tentang penyebaran key di remote/history. Client key tidak disamakan dengan server secret.

Prefix lokal `Mid-server-` tanpa `SB-` juga perlu direkonsiliasi dengan mode sandbox, tetapi prefix saja tidak membuktikan credential produksi masih aktif. Midtrans menyatakan server key bersifat rahasia dan membedakan access keys sandbox/production: [API Authorization & Headers](https://docs.midtrans.com/docs/api-authorization-headers).

Tindakan yang disarankan setelah persetujuan: bersihkan template, cek staged/history/remote secara aman tanpa mencetak credential, dan tangani rotasi melalui pemilik merchant apabila key pernah dibagikan atau diragukan kerahasiaannya. Tidak merotasi atau menghapus key pada audit ini.

### AUD-BUG-001 — Gambar privat invalid diterima

**Kategori:** CONFIRMED BUG. **Severity:** MEDIUM. **Lokasi:** `app/PrivateUploadStorage.php:35–38`. Berkaitan G-FILES, P1-10 dan acceptance validasi file T-22/DBT-14.

Fixture non-rahasia untuk reproduksi:

```text
89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de
```

1. Decode hex menjadi 33 byte bernama truncated.png.
2. Buat UploadedFile test dari byte tersebut.
3. Panggil PrivateUploadStorage::store(upload, PrivateUploadPurpose::CustomReference) dengan disk privat temp terisolasi.
4. Bandingkan hasil dengan full decode imagecreatefromstring.

Expected: upload invalid ditolak sebelum disimpan. Actual:

```json
{"case":"AUD-BUG-001","bytes":33,"expected":"reject invalid image","actual":"accepted","mime":"image/png","full_decode_succeeds":false,"stored":true}
```

Root cause: finfo membaca MIME dan getimagesizefromstring menerima header/dimensi, sementara helper tidak membuktikan image data dapat didekode. assertReadable hanya memastikan hash/ukuran byte sesuai. Existing 31 storage tests menolak text/SVG/executable tetapi tidak memuat header-only/truncated-image fixture.

Dampak yang dibuktikan: file rusak dapat menjadi objek privat dengan metadata dianggap verified. Tidak mengklaim RCE, akses publik, atau exploit HTTP; custom/refund upload endpoints belum ada. Perbaikan harus memasukkan validasi decode dengan batas resource dan regresi corrupt PNG/JPEG/WebP sebelum dipakai workflow domain.

## 8. Root Cause Analysis

| Temuan / risiko | Penyebab | Mengapa suite tetap hijau |
|---|---|---|
| AUD-SEC-001 | Config server credential ada dalam perubahan template lokal | Suite tidak memindai tracked template untuk nilai credential |
| AUD-BUG-001 | Pemeriksaan header/dimensi dianggap cukup sebagai validasi gambar | Invalid fixtures belum mencakup PNG dengan header valid tetapi data pixel hilang |
| AUD-ENV-001 | Source dan schema runtime lokal berbeda tingkat deployment | Feature tests memigrasi tbloom_testing, sehingga tidak bergantung pada kesiapan tbloom |
| Domain belum tersedia | Implementasi baru mencakup fondasi dan beberapa fitur | Tests schema/model/fixtures tidak menjalankan handler atau endpoint yang belum ditulis |

### AUD-ENV-001 — Katalog runtime gagal

**Kategori:** ENVIRONMENT ISSUE; **severity:** HIGH. Buka homepage dan klik Katalog pada [runtime lokal](http://localhost:8000/catalog), URL diselesaikan lewat Boost. Error direproduksi dua kali dan log Laravel 23 Oktober sekitar 07:28 UTC mencatat missing table yang sama.

Expected: halaman katalog dapat dibuka setelah aplikasi disiapkan. Actual: HTTP 500 pada query products di CatalogController:17. Read-only metadata menunjukkan tbloom hanya 9 tabel scaffold, sementara migrate:status menunjukkan 22 pending migration. Tidak menyimpulkan source query salah. Tindakan berikutnya adalah rencana upgrade/preflight data dan persetujuan penggunaan DB kerja; jangan migrate:fresh/db:wipe atau menjalankan seeder test pada tbloom.

## 9. Security Findings

- HIGH AUD-SEC-001 dan MEDIUM AUD-BUG-001 tetap terbuka.
- Pada source yang diperiksa, registration allowlist/fillable membatasi role; route staf memakai auth/active/role; mutasi catalog memakai Gate/FormRequest serta actor recheck di bawah lock.
- Existing tests membuktikan generic login/reset response, hashed credentials, reset token expiry/single use, session refresh/revocation, role spoof denial, cross-customer policy denial, encrypted hidden bank/Snap/recipient fields, dan escaping beberapa input.
- Query katalog/filter memakai binding dan enum; tidak menemukan interpolasi input ke raw SQL pada jalur yang diperiksa. Ini bukan pembuktian formal seluruh codebase bebas SQLi/XSS.
- Form state-changing yang diperiksa memakai CSRF. Suite banyak memeriksa markup/state; belum ada bukti browser request token invalid untuk seluruh mutasi.
- Resource policies saat ini hanya view/ownership foundation. Assigned-admin/Owner authorization untuk bank, evidence, takeover dan transfer mutation harus dibuktikan pada endpoint domain nanti; jangan menganggap base view policy cukup untuk membuka seluruh rekening.
- Debug exception lokal menampilkan query/path/headers. Ini konfigurasi local, bukan bukti production information disclosure; produksi belum diaudit.
- Tidak ada purge otomatis di schedule. Durasi retensi masih gate; tidak mengaktifkan cleanup.
- npm advisory query berhasil; Composer terkini belum terverifikasi. Tidak memakai hasil cache sebagai sertifikasi keamanan.

## 10. Database Findings

Metadata stabil sesudah suite: **28 tabel InnoDB; 28 PRIMARY KEY, 37 FOREIGN KEY, 41 UNIQUE, 70 CHECK (seluruhnya ENFORCED=YES)**. Semua 37 FK bisnis beraturan UPDATE/DELETE RESTRICT.

Contoh detail order_items terverifikasi: quantity INT UNSIGNED; money BIGINT UNSIGNED; one item/order unique; composite product/variant dan product/availability FK; CHECK quantity>0, money limit, line_total = DECIMAL multiplication, release marker/reason berpasangan; index availability/released/order.

Foundation tests juga memverifikasi cross-object refund/evidence ownership FK, exact enum values, generated unique pending/active/correction slots, encryption, dan guard immutable instance writes. Upgrade/reverse migration/partial-DDL resume serta snapshot DB+private objects+synthetic key recovery dijalankan pada DB test saja.

Batas:

- Snapshot schema yang sempat terbaca ketika suite sedang migrate:fresh tidak dipakai sebagai temuan constraint hilang; metadata akhir di atas dibaca setelah runner MySQL selesai.
- 28 tabel adalah 27 baseline + payment_movement_corrections yang disetujui pada tracker D-024; bukan tabel tambahan tanpa dasar.
- Belum audit exhaustif setiap column/default/index terhadap seluruh dictionary. DBT-01 penuh tidak dinaikkan menjadi PASS hanya dari jumlah tabel.
- Aggregate pending≤3, total/order, capacity/returns/reserve, state transition, replay/fencing/audit/outbox atomicity memerlukan domain operations; constraint/factory tidak cukup.
- MySQL proof memakai independent connection IDs pada 4 concurrency cases. Tidak menjalankan 20-checkout stress atau bank/gateway race.
- Production READ COMMITTED/UTC, realistic EXPLAIN/load, backup provider, restore produksi dan DB permissions belum terverifikasi.
- Database kerja tetap hanya scaffold; AUD-ENV-001 belum ditangani.

## 11. Frontend Findings

| ID | Skenario browser | Status / bukti |
|---|---|---|
| UI-01 | Homepage dan logo/navigation | PASS, halaman TBloom tampil |
| UI-02 | Klik Katalog | FAIL, AUD-ENV-001 HTTP 500 |
| UI-03 | GET login | PASS, form tampil |
| UI-04 | Submit login kosong | PASS, browser mencegah submit dan memfokuskan Email wajib; tidak menguji server credential path |
| UI-05 | GET register | PASS, nama/email/WA/password/confirmation memiliki nama aksesibel |
| UI-06 | GET forgot-password | PASS, form pemulihan tampil; tidak mengirim email |
| UI-07 | Guest membuka /staff | PASS, diarahkan ke login |
| UI-08 | Login DOM dan tampilan pada 668×562 | PASS terbatas: scrollWidth653≤innerWidth668, tidak ada broken logo; text/password punya label, remember punya accessible name |
| UI-09 | Console pada halaman login yang diperiksa | PASS terbatas: dev.logs error/warn kosong; Boost browser logs menunjukkan Vite terhubung |

Bukti visual lokal: [login](C:/Users/LENOVO/.codex/visualizations/2026/10/23/01a167a0-5478-74c0-b2bd-6111bed63f69/tbloom-login-audit.png) dan [error katalog](C:/Users/LENOVO/.codex/visualizations/2026/10/23/01a167a0-5478-74c0-b2bd-6111bed63f69/tbloom-catalog-error-audit.png). Screenshot error hanya viewport bagian atas, tanpa request cookies/header.

Network failure yang terbukti adalah document katalog HTTP500. Tidak memperoleh seluruh network waterfall atau melakukan konfirmasi semua asset/request pada semua halaman. Aplikasi lokal memakai Vite dev server; keberhasilan produksi build diverifikasi terpisah. Satu navigasi ulang mengalami timeout browser, kemudian halaman error yang sama berhasil dibaca dan diambil screenshot.

Temuan minor yang sudah dicatat pada final review Phase3: image catalog index/detail belum mempunyai atribut width/height eksplisit (PRD8.1), meskipun CSS aspect-ratio tersedia. Dicatat sebagai acceptance gap yang tetap terbuka, tidak dihitung ulang sebagai defect baru atau klaim CLS terbukti.

Keyboard/screen reader/kontras, seluruh viewport target, loading/error state transaksi, layout staf berisi data, checkout/custom multi-step, dan product image URL di runtime kerja belum diverifikasi lengkap.

## 12. Missing Implementations

Kategori **MISSING IMPLEMENTATION**, bukan bug runtime baru. Route inventory dan source domain membuktikan yang tersedia baru input/helper/models/policies/foundations untuk banyak fitur.

| Kelompok | Belum tersedia / belum teruji |
|---|---|
| Checkout/order (P4-02–05) | Atomic order/items/payment/audit/outbox, price lookup, capacity/max3, idempotency hash, after-lock clock, order detail/UI/countdown |
| Payment (Phase5) | Snap adapter/session recovery, webhook signature/inbox handler, canonical apply, expiry/finality, reconciliation/recovery |
| Cancellation/refund (Phase6) | H-2 submit/decide, obligation application, assignment/takeover, bank verify/evidence endpoints, prepare/unknown/complete |
| Custom (Phase7) | Form/request API, H3/H7/print changes, 1–3 durable attachments minimum, lifecycle workflow |
| Handover (Phase8) | Paid-only atomic completion, cancellation hold, actor/time audit and races |
| Notifications (Phase9) | Fonnte/templates/routing, outbox scan/claim/fencing, bounded retry/manual escalation/obsolete suppression |
| History/dashboard (Phase10) | Own order/payment/refund history, operational queues/filter/detail |
| Public content/testimonials (Phase10) | Profil/alamat/jam/kebijakan lengkap, published-only listing dan management |

AUD-DOC-001 — **DOCUMENTATION CONFLICT / status perlu ditinjau:** tracker current-task/G-CONTENT masih mengatakan allowlist rasa/filling kosong dan data belum diberikan. Working tree sudah berisi 3 rasa dan 3 filling, serta 13 CheckoutOptionsTest lulus. Audit tidak membuat konfigurasi tersebut dan tidak menyimpulkan otoritas bisnis hanya dari nama test “official”. Gate/status P4-01 perlu direkonsiliasi dengan sumber persetujuan; tidak otomatis mengubahnya menjadi completed.

G-RETRY, merchant channel contract, Fonnte/recipient/HMAC, storage produksi, durasi retensi dan deployment readiness tetap belum selesai. PHP minimum composer.json ^8.3 lebih longgar dari target8.4, tetapi installed Laravel13/platform requirements saat ini lulus; tidak diklaim sebagai runtime failure.

## 13. Recommended Fix Priority

1. **HIGH, AUD-SEC-001:** amankan template sebelum commit/push berikutnya; pemilik merchant menilai kebutuhan rotasi dan kecocokan environment tanpa mengekspos key.
2. **HIGH, AUD-ENV-001:** siapkan rencana upgrade DB kerja yang menjaga data, jalankan preflight/backup dan migration terkontrol setelah izin. Retest katalog; jangan reset database.
3. **MEDIUM, AUD-BUG-001:** validasi full decode dengan resource bounds dan regression fixtures; retest private storage/custom/refund integration saat tersedia.
4. Rekonsiliasi AUD-DOC-001/P4-01 dan lanjutkan missing implementation sesuai dependency roadmap setelah audit/fix scope disetujui.
5. Lengkapi financial races, endpoint authorization, provider contract, runtime retry/recovery, performance/a11y/viewport dan DR sebelum acceptance/release.

Tidak ada perbaikan otomatis dilakukan atas daftar ini.

## 14. Retest Plan

- AUD-SEC-001: pemeriksaan template/staged/history tanpa output secret; pastikan credential hanya berada di secret store/.env ignored dan mode/key cocok. Tidak menguji payment nyata.
- AUD-ENV-001: setelah upgrade sah, GET catalog/category/detail, actual asset URLs, login/profile/staff dengan synthetic data pada environment QA yang terpisah.
- AUD-BUG-001: tambah failing Pest case di seam PrivateUploadStorage, corrupt/truncated PNG/JPEG/WebP, valid image dan limit resource/5MiB; perbaiki lalu jalankan private storage/recovery dan relevant foundation tests.
- Verifikasi G-FILES/P1-10 yang sebelumnya completed: schema tetap teruji, tetapi acceptance isi file perlu REVIEW REQUIRED; simpan histori hasil lama.
- Jalankan MySQL suite serial sesudah perubahan schema/domain, default suite, lint/Pint sesuai aturan, build; hindari dua runner yang memodifikasi tbloom_testing bersamaan.
- Jalankan T/DBT yang belum penuh ketika endpoint tersedia, terutama 20 checkout/5unit, max3, cutoff melewati lock, payment vs expiry, unknown transfer/takeover dan cancellation vs handover.
- Buktikan sandbox per channel, routing customer/Admin, retry exhaustion, viewport360/tablet/desktop, accessibility dan restore sebelum go-live.

## 15. Final Assessment

**Existing automated suite PASS; audit keseluruhan belum memenuhi acceptance MVP.** Dua defect terkonfirmasi tetap terbuka, katalog lokal gagal akibat kesiapan schema, dan 49 dari 55 baseline IDs belum dijalankan lengkap. Tidak mengklaim proyek bebas bug atau production-ready.

Hanya TESTING-REPORT.md dan pembaruan dokumentasi IMPLEMENTATION-PROGRESS.md ditulis oleh audit ini. Generated build/runtime artifacts dan bukti screenshot bukan perubahan source aplikasi. Perbaikan source, pengelolaan credential provider, dan perubahan database kerja menunggu persetujuan pengguna sebagaimana diminta dalam mode audit.
