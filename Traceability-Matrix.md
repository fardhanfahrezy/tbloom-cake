# Traceability Matrix — TBloom Cake R1
Finalisasi dokumentasi 19 Oktober 2026; baseline R1 16 Oktober 2026. Sumber [PRD](PRD-TBloom-Cake-v2.1.md); desain [Database Schema](Database-Schema.md). **Semua status verifikasi implementasi/test: belum diverifikasi.** Persetujuan Owner hanya pada kontrak bisnis, bukan migration/fitur lulus.
C-xx menunjuk Schema §6; DBT menunjuk Test-Cases.md. A-01–05/A-09 disetujui, A-07 diganti K-01, K-17–20 mengesahkan batas input, strategi retry, tanpa SLA customer, penerima dan prinsip retensi; angka retry/jeda TL dan durasi final retensi masih belum ditetapkan.

| Requirement ID | Requirement/sumber PRD | Tabel/kolom/constraint; komponen | Acceptance criteria | Test | Status/gap |
|---|---|---|---|---|---|
| F-001 | Katalog/varian/unit jual §4 | products/variants/images; price,unit; C-01 | F001-AC1–3; R1-AC15 | T-03/05; DBT-04 | Belum diverifikasi; batas input K-17 disetujui |
| F-002 | Akun checkout cutoff/snapshot/idempotency §4 | users; orders.user_id/deadline/key/hash; items.price/qty; C-04/07/08 | F002-AC1–6; R1-AC02/13/14 | T-01/02/06–10; DBT-03/04/17 | Belum diverifikasi; existing users backfill |
| F-003 | Request custom privat tanpa uang §4 | custom requests/attachments; print/date/key; FK+mime | F003-AC1–4; R1-AC14/16/19 | T-21/22; DBT-14/17 | Belum diverifikasi; batas input/routing K-17/K-19 disetujui |
| F-004 | Kuota produk/tanggal §4 | daily_availabilities; items.quota_released_at; C-01/08 | F004 AC; R1-AC03/15 | T-03–05/14/24; DBT-04/05 | Belum diverifikasi; provider finality |
| F-005 | Pickup/handover §4 | orders.pickup*/terms/completed/actor; requests pending; C-07 | F005 AC; R1-AC07/08/16 | T-08/26/30/31; DBT-11/17 | Belum diverifikasi |
| F-006 | Midtrans/payment/expiry §4 | payments.expected_amount/time/closure; events/movements; C-05 | F006 AC; R1-AC01–05/09/21 | T-11–16/25/27/32–34; DBT-05–07/16 | Belum diverifikasi; channel contract |
| F-007 | Cancel/change/refund §4 | requests; obligation/transfer/evidence; C-02/03/06/09 | F007 AC; R1-AC06–12/16/21 | T-17–20/28–31; DBT-08–11 | Belum diverifikasi; bank SOP/ledger correction |
| F-008 | Informasi/testimoni §4 | testimonials publication/rating/index; public content | F008 AC | Public content checks belum dijalankan; DBT-03 publication/rating | Belum diverifikasi; should-have tidak block payment |
| F-009 | Admin produk/kuota §4 | catalog active/archive; availability.limit/updater | F009 AC; R1-AC15 | T-04/05/24; DBT-04/15 | Belum diverifikasi |
| F-010 | Admin order/refund/audit §4 | users.role; requests; refund assignment; audit | F010 AC; R1-AC06–08/19 | T-02/28–31; DBT-10/11/14 | Belum diverifikasi |
| BR-01 | UTC event/Jakarta bisnis §5.1 | D UTC vs DATE/TIME local | R1-AC14/16 | DBT-17 | Belum diverifikasi |
| BR-02 | Online24/7 §5.1 | due workers, order/custom endpoints | R1-AC03/18 | T-16; DBT-12/16/17 | Belum diverifikasi; target infrastruktur teknis terpisah dari SLA penanganan customer |
| BR-03 | Produksi/manual09–17 §5.1/K-05 | review_reason, next_reconcile_at; UI/manual workflow | R1-AC04 | DBT-16 plus jam manual | Belum diverifikasi; automation24/7 |
| BR-04 | Pickup09–21 menit §5.1 | orders.pickup_time CHECK | F002-AC4 | T-08; DBT-03/17 | Belum diverifikasi |
| BR-05 | Cutoff≤17:00:00 H1 §5.1 | created_at/date app validation | F002-AC2 | T-06/07; DBT-17/18 | Belum diverifikasi |
| BR-06 | Cutoff≥17:00:01 H2 §5.1 | created_at/date app validation | F002-AC2 | T-06/07; DBT-17/18 | Belum diverifikasi |
| BR-07 | Paid cancel/change H2 §5.1 | requests.submitted_at; parent lock | R1-AC07/12/16 | T-17/30/31; DBT-11/17 | Bisnis disetujui; unpaid exception K-14 |
| BR-08 | Custom H3 §5.1 | custom.pickup_date | F003-AC1 | T-21; DBT-17 | Belum diverifikasi |
| BR-09 | Print H7 §5.1 | custom.edible_print/date + audit changes | F003-AC2; R1-AC16 | T-21; DBT-17 | Belum diverifikasi |
| BR-10 |30menit aksi bayar §5.1 | payment_expires_at CHECK; adapter | R1-AC01/02 | T-13/15/32; DBT-16 | Channel precision/finality gate |
| BR-11 | Kalender bukan weekday §5.1 | local date validation; availability | R1-AC14/16 | DBT-17 | Belum diverifikasi |
| BR-12 | Default5 unit jual §5.2 | products.default_daily_quota; items.quantity | R1-AC15 | T-03; DBT-04 | Belum diverifikasi |
| BR-13 | Sizes shared, completed consumed §5.2 | product/day unique; unreleased items | R1-AC15 | T-05/24; DBT-04 | Belum diverifikasi |
| BR-14 | Hold ambigu/release sah §5.2 | closure evidence + release marker | R1-AC03/12 | T-14/16; DBT-05 | Channel finality gate |
| BR-15 | Expired terminal normal §5.2 | order.status; recovery exception audit | R1-AC04 | T-34; DBT-05/16 | Controlled incident, bukan auto revive |
| BR-16 | Snapshot harga×qty, noMDR/shipping §5.2 | items line CHECK, order total/payment expected via tx | F006-AC2; R1-AC17 | T-09/10/26; DBT-03 | Belum diverifikasi |
| BR-17 | Customer floor80% §5.2 | obligation basis/percentage/amount; C-07/08 | R1-AC05/11 | T-18/27; DBT-06 | Belum diverifikasi |
| BR-18 | Store/actual late100%, noMDR §5.2 | reason_type/basis/returns | R1-AC01/09/21 | T-19; DBT-06/07 | Belum diverifikasi; bukan webhook late |
| BR-19 | Manual obligation/attempt/proof §5.2 | refund tables; composite FK | R1-AC06/10/11 | T-20/28/29; DBT-08–10 | Belum diverifikasi |
| BR-20 | Custom bukan transaksi/kuota §5.2 | custom tables tanpa payment/availability FK | F003-AC4 | T-21/22; DBT-17 | Belum diverifikasi |
| BR-21 | Notifikasi bukan sumber transaksi §5.2 | outbox unique+claim; domain commit | R1-AC18 | T-23; DBT-13 | K-19 penerima disetujui; belum diverifikasi |
| A-01/K-09 | Single product/size/personalisasi §1.3 | items UNIQUE(order_id), qty>0 | R1-AC15 | DBT-02/03/04 | Disetujui; belum diverifikasi |
| A-02/K-10 | Unit katalog+shared sizes §1.3 | sales_unit snapshot/product/day | R1-AC15 | DBT-04 | Disetujui; belum diverifikasi |
| A-03/K-06 |23.59.59 H2 submission §1.3 | requests.submitted_at | R1-AC16 | DBT-17 | Disetujui; belum diverifikasi |
| A-04/K-12 | Custom calendar/print change §1.3 | custom.date/print | R1-AC16 | DBT-17 | Disetujui; belum diverifikasi |
| A-05/K-08/13 | Whole cancel/edit terbatas §1.3 | one item, request allowlist/audit | R1-AC12/16 | DBT-11/17 | Disetujui; belum diverifikasi |
| A-06/K-17/18 | Batas input awal tetap; retry terbatas+backoff→manual; tanpa SLA customer | lengths,file sizes,attempts/lease/review | R1-AC23/24; batas PRD | DBT-03/12–14/19/21 | Kebijakan disetujui; jumlah/jeda TL belum final; belum diverifikasi |
| A-07/K-01 | Timely paid despite delayed verification §1.3 | provider_paid_at≠received_at | R1-AC01/04 | T-13/15; DBT-05/16 | A-07 lama ditolak |
| A-08/K-19 | Customer transaksi sendiri; Admin lima kategori penting §1.3/7.6 | recipient/template/outbox/policy | R1-AC22 | DBT-13/21 | Disetujui; belum diverifikasi |
| A-09/K-11 | Max3 held pending/D90 inclusive §1.3 | user lock+orders.status, date validation | R1-AC13/14 | DBT-04/17 | Disetujui; belum diverifikasi |
| K-02/07 | Assignment/takeover unknown §1.4 | refund version/active attempt/audit | R1-AC06 | DBT-08/10 | Disetujui; SOP eksternal gate |
| K-03 | Cancellation blocks handover §1.4 | parent lock/requests pending | R1-AC07/08 | DBT-11 | Disetujui; belum diverifikasi |
| K-04 | Amount anomaly close/refund actual §1.4 | receipt ledger/review/gross obligation/return sekali; Schema §8 | R1-AC09/21 | DBT-07 | Disetujui; belum diverifikasi |
| K-05 | Hold ambigu/manual09–17/auto24h §1.4 | review/due/allocations | R1-AC03/04 | DBT-05/16 | Disetujui; tidak ada SLA customer, angka retry TL pending |
| K-14/15 | Unpaid cancel automatic/payment wins §1.4 | request decision_mode/payment closure | R1-AC12 | DBT-05 | Disetujui; belum diverifikasi |
| K-16 | Completed tetap consumed §1.4 | unreleased marker | R1-AC15 | T-24; DBT-04 | Disetujui; belum diverifikasi |
| K-20 | Retensi per kelas; log teknis lebih singkat; PII hapus/anonymize bila tidak diperlukan; purge menunggu durasi final | Lifecycle seluruh tabel/files/log/backup | R1-AC23 | DBT-14/21 | Prinsip disetujui; durasi final pending Owner; belum diverifikasi |
| NFR security | §7.5/7.7/8.2 | encrypted fields/private files/roles/audit | R1-AC19 | T-11/22; DBT-14 | Belum diverifikasi; prinsip K-20 disetujui, durasi pending |
| NFR reliability | §7/8.2 | transactions/inbox/outbox/recovery | R1-AC18/20 | DBT-12/13/18/19 | Belum diverifikasi; tanpa SLA customer, parameter teknis pending |
| NFR performance | §8.2 | indexes/pagination/EXPLAIN | Target awal PRD tetap A-06 | DBT-19 + load test kelak | Belum diverifikasi |
| Go-live | §10 | backup/restore/migration/key/channel gates | Semua MustHave dan bukti UAT | T-01–34 + DBT-01–21 | Belum siap; tidak ada business test pass |

## Pemetaan audit database
DB-01–DB-13 beserta solusi, kewenangan, prerequisite dan test ditutup pada level rancangan dalam Database Schema §9. “Dirancang” berbeda dari “sudah terpasang/lulus”. DB-05 channel finality, DB-02 review canonical correction, parameter retry TL, durasi retensi final dan MySQL environment tetap gate terkait. Kebijakan A-06/A-08 dan prinsip retensi sudah disetujui K-17–20, bukan pending Owner. Tidak menandai blocker implementasi selesai hanya karena dokumen sudah tersedia.
