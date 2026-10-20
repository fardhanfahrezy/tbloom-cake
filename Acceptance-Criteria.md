# Acceptance Criteria — TBloom Cake R1
Finalisasi dokumentasi 19 Oktober 2026; baseline R1 16 Oktober 2026. Spesifikasi, **seluruh test runtime belum diverifikasi**. AC baseline F001–F010 tetap di [PRD](PRD-TBloom-Cake-v2.1.md); tabel ini menajamkan kontrak R1. K-01–20 dan batas input awal PRD disetujui; hanya angka retry/jeda TL dan durasi retensi final belum ditetapkan pada keputusan terbaru.

| ID | Given / When | Then terukur | Test |
|---|---|---|---|
| R1-AC01 | Order10.00/deadline10.30, success10.29.59, webhook10.30.20 | Paid satu kali, tetap dipenuhi, tidak refund karena webhook terlambat | T-13/15; DBT-05 |
| R1-AC02 | Refresh/retry/pilih channel setelah order dibuat | Deadline absolut tidak reset; new payment window tidak dibuat setelah deadline | T-16/32; DBT-16 |
| R1-AC03 | Deadline berlalu, gateway ambigu, akun lain checkout last unit | Kuota pertama tetap held; final unpaid sah baru release sekali | T-14/16; DBT-04/05 |
| R1-AC04 | Provider time unknown/contradictory | Review+hold, tidak menganggap received_at sebagai paid_at; incident jika final expiry bertentangan timely success | T-33/34; DBT-05/16 |
| R1-AC05 | Settlement sama diulang saat refund80% pending/processing/completed | Satu receipt/obligation, nominal80% tidak berubah dan return tidak hilang | T-12/25/27; DBT-06 |
| R1-AC06 | Dua Admin claim, nonassignee complete, Owner takeover | Satu assignee, unauthorized/stale version ditolak, audit; unknown tetap memblokir transfer ulang | T-28/29; DBT-08/10 |
| R1-AC07 | Paid dengan eligible cancellation pending | Handover ditolak; reject membuka jika valid; approve cancelled, tidak handover walau refund belum selesai | T-30; DBT-11 |
| R1-AC08 | Submission/approval/handover paralel | Serial outcome sah, tidak completed sekaligus cancellation applied; audit/refund/release konsisten | T-31; DBT-11 |
| R1-AC09 | Bill100.000 actual verified90.000/110.000 | Review Owner, tidak fulfill/top-up/refund selisih sambil fulfill; close, obligation hak customer dari actual reconciled funds | DBT-07 |
| R1-AC10 | Refund obligation belum punya bank; kemudian prepare/complete tanpa data | Obligation tersimpan; prepare ditolak tanpa verified bank; complete ditolak tanpa mutasi/reference/time/evidence durable | T-20; DBT-09 |
| R1-AC11 | Receipt100.001, obligation80.000, return20.000 kemudian event duplicate | Sisa60.000; duplicate tetap60.000; unknown attempt60.000 memblokir attempt tambahan | T-18; DBT-06/08 |
| R1-AC12 | Pending unpaid melewati H-2, customer cancel | Diterima otomatis; hold sampai closure unpaid sah; timely payment sebelum closure menang→paid, cancel request rejected payment_won | DBT-05 |
| R1-AC13 | Akun punya3 pending held termasuk review dan paid lain | Checkout keempat ditolak; paid tidak dihitung; concurrent submit tak melewati3 | DBT-04 |
| R1-AC14 | Catalog/custom memilih D+90 dan D+91 | D+90 diterima jika syarat lain valid; D+91 ditolak; tanggal lokal Jakarta | DBT-17 |
| R1-AC15 | Unit BOX qty3 dari kuota5, sizes berbeda; order completed | Tersisa2, sizes pool sama; completed tetap konsumsi3 | T-03/05/24; DBT-04 |
| R1-AC16 | H-2 akhir hari vs sesudah; edit pickup/qty/print | Batas submitted_at tepat; hanya personalisasi simple tanpa harga/kuota; pickup time/method immutable; add print tetap H-7 | T-17/21; DBT-17 |
| R1-AC17 | Coba FK berbeda order/product dan values illegal via DB | Composite FK/UNIQUE/CHECK menolak; aggregate yang tak dapat CHECK ditolak transaksi | DBT-02/03 |
| R1-AC18 | Worker crash/reclaim/replay sebelum/sesudah commit | Efek uang/kuota satu kali, stale token ditolak; outbox dipulihkan scanner; unknown external send tidak dinyatakan exactly-once | DBT-12/13 |
| R1-AC19 | Customer lain/staf tanpa hak meminta rekening/file/log | Ditolak; file tidak public, rekening encrypted, secrets tak muncul log/outbox | T-22; DBT-14 |
| R1-AC20 | Deadlock/stale snapshot/rollback/recovery | Transaksi utuh rollback/retry terbatas, tidak replay bank, histori tidak hilang dan tidak orphan finansial | DBT-15/18/19 |
| R1-AC21 | Reversal/provider refund ternyata laporan transfer manual sama | Satu return canonical; unresolved/conflicting identity membekukan transfer. Return sebelum obligation dibuat dikurangkan sekali: receipt90.000, bruto obligation90.000, return20.000 → sisa70.000. Actual independent excess return dicatat incident | DBT-06/10 |
| R1-AC22 | Event transaksi/customer A dan event Admin dikirim | Customer B tidak menerima data A. Admin hanya payment_exception/refund/custom_request/cancellation/new_order; paid/expired rutin tidak dikirim ke Admin. Template sesuai kebutuhan tanpa seluruh profil/rekening/bukti privat | DBT-13/21 |
| R1-AC23 | Validasi input dan konfigurasi cleanup ditinjau | Batas awal PRD tetap; purge otomatis tidak diaktifkan sebelum durasi kelas terkait disetujui. Prinsip retensi per kelas diterapkan pada rancangan, bukan angka durasi rekaan | DBT-03/14/21 |
| R1-AC24 | Retry mencapai batas teknis yang kelak dikonfigurasi | Backoff otomatis terbatas berakhir ke antrean review manual; staf menangani pada jam operasional; tidak menjanjikan SLA customer atau mengulang transfer bank unknown | DBT-12/13/21 |

Boundary waktu provider/presisi/channel harus dibuktikan sandbox. Routing, batas input, strategi retry, tanpa SLA customer dan prinsip retensi telah disetujui. Angka retry/jeda teknis serta durasi retensi final masih belum ditetapkan; tidak menandainya lulus. Tidak ada izin refund gateway API.
