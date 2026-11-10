# Deployment Guide: Railway

Dokumen ini menyiapkan deployment Tbloom Cake pada Railway. Deployment publik dan aktivasi transaksi produksi tetap memerlukan persetujuan owner setelah seluruh preflight selesai.

## 1. Arsitektur layanan

Gunakan layanan terpisah berikut:

- **Web**: aplikasi Laravel 13, Blade, dan aset Vite. Hanya layanan ini yang diberi public domain.
- **MySQL**: database Railway dengan kompatibilitas MySQL 8.4. Gunakan private networking dari aplikasi.
- **Worker**: menjalankan antrean `payments` dan `notifications`.
- **Cron**: menjalankan Laravel Scheduler setiap menit.

Jangan mengganti database menjadi PostgreSQL tanpa migrasi dan pengujian terpisah. Source saat ini memakai MySQL/InnoDB dan beberapa migrasi memiliki aturan khusus MySQL.

## 2. Build dan web process

Pastikan runtime PHP memenuhi `composer.json` (PHP 8.4+) dan Node.js memenuhi kebutuhan Vite 8.

Build harus menjalankan dependency production PHP dan membangun aset frontend:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan storage:link
```

Gunakan runtime Laravel/PHP yang disediakan Railway untuk web service. Jangan menjalankan `migrate:fresh` pada deployment. Jalankan migrasi secara terkontrol dengan:

```bash
php artisan migrate --force
```

Healthcheck aplikasi tersedia di `GET /up`.

## 3. Environment variables

Atur melalui Variables di Railway, bukan commit ke repository.

| Variable | Nilai / sumber |
|---|---|
| `APP_NAME` | `Tbloom Cake` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Buat sekali dengan `php artisan key:generate --show`, simpan sebagai secret, dan pertahankan nilainya pada deployment berikutnya |
| `APP_URL` | URL HTTPS domain web Railway |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` atau `info` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | Reference variable `MYSQLHOST` dari layanan MySQL |
| `DB_PORT` | Reference variable `MYSQLPORT` |
| `DB_DATABASE` | Reference variable `MYSQLDATABASE` |
| `DB_USERNAME` | Reference variable `MYSQLUSER` |
| `DB_PASSWORD` | Reference variable `MYSQLPASSWORD` |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `FILESYSTEM_DISK` | `local` |
| `MIDTRANS_IS_PRODUCTION` | `false` selama sandbox/preflight |
| `MIDTRANS_SERVER_KEY` | Kosong sampai server key sandbox tersedia; jangan gunakan key produksi saat staging |
| `NOTIFICATION_DELIVERY_ENABLED` | `false` sampai pengiriman email/WhatsApp diuji dan disetujui owner |
| `NOTIFICATION_RECIPIENT_HMAC_KEY` | Secret acak yang kuat, dibuat khusus untuk deployment; jangan gunakan ulang `APP_KEY` |
| `FONNTE_TOKEN` | Kosong sampai integrasi WhatsApp disetujui dan dikonfigurasi |
| `MAIL_MAILER` | Provider SMTP/API yang benar-benar telah dikonfigurasi dan diuji |

`DB_HOST` dan variabel database lain harus memakai private host/port dari layanan MySQL Railway, bukan public proxy, kecuali ada kebutuhan administrasi eksternal yang memang disetujui.

Jangan biarkan `MAIL_MAILER=log` di production. Email reset password dan notifikasi harus menggunakan provider email yang dikonfigurasi dengan aman.

## 4. Persistent file storage

Aplikasi menyimpan dua kelompok file lokal:

- `storage/app/public`: gambar produk yang ditampilkan ke pengunjung.
- `storage/app/private_uploads`: lampiran custom cake dan bukti refund yang harus tetap privat.

Filesystem container Railway bersifat sementara. Untuk deployment satu instance, pasang persistent volume pada direktori `storage/app` milik web service. Pastikan mount tersebut mempertahankan subdirektori `public` dan `private_uploads`, lalu verifikasi symlink `public/storage` setelah deploy.

Jika web service akan diskalakan ke beberapa instance atau file perlu dibaca lintas service, pindahkan kedua disk ke object storage S3-compatible. Jangan menjadikan `private_uploads` sebagai public bucket dan jangan mengubah policy aksesnya menjadi public.

## 5. Worker dan scheduler

Worker service memakai image/build yang sama dengan web service, tetapi tanpa public domain. Start command:

```bash
php artisan queue:work database --queue=payments,notifications --sleep=3 --tries=1 --timeout=60
```

Scheduler dijalankan oleh Railway Cron Job setiap menit:

```bash
php artisan schedule:run --no-interaction
```

Cron expression: `* * * * *`.

Ketiga task terjadwal saat ini adalah `notifications:dispatch`, `payments:dispatch-events`, dan `payments:reconcile`. Job menggunakan queue database, jadi worker wajib aktif agar pekerjaan yang terjadwal diproses.

## 6. Bootstrap akun Owner

Migration tidak lagi membuat akun Owner otomatis. Setelah database baru selesai dimigrasikan, buka shell layanan web dan jalankan:

```bash
php artisan owner:create
```

Command meminta nama, email, nomor WhatsApp, dan password secara interaktif. Password dimasukkan melalui prompt tersembunyi, minimal 12 karakter, lalu disimpan dalam bentuk hash. Command menolak pembuatan jika akun Owner sudah ada.

Jika database lama sudah pernah menjalankan versi migration yang sebelumnya membuat akun Owner dengan kredensial tertanam di source, akun itu tidak otomatis hilang hanya karena file migration diubah. Sebelum domain publik diaktifkan, rotasi password akun tersebut melalui shell server:

```bash
php artisan owner:reset-password
```

Masukkan email akun Owner yang benar dan password baru melalui prompt tersembunyi. Jangan menjalankan command ini melalui endpoint web atau memasukkan password sebagai argumen CLI.

## 7. Urutan rilis aman

1. Buat layanan MySQL dan pastikan versi kompatibel dengan MySQL 8.4.
2. Buat layanan web dan atur seluruh environment variables.
3. Jalankan build, lalu migrasi dengan `php artisan migrate --force`.
4. Buat Owner dengan `php artisan owner:create`, atau rotasi password Owner lama dengan `php artisan owner:reset-password`.
5. Pasang persistent volume/object storage dan verifikasi upload serta akses file privat.
6. Jalankan worker dan Cron Job.
7. Uji healthcheck, login, reset password, katalog, upload gambar, checkout sandbox, webhook sandbox, antrean, dan notifikasi dalam keadaan delivery masih nonaktif sampai disetujui.
8. Aktifkan public domain setelah preflight dan backup database diverifikasi.
9. Aktifkan pembayaran produksi dan pengiriman notifikasi hanya setelah owner menyetujui kredensial produksi dan pengujian end-to-end.

## 8. Batasan status saat dokumen dibuat

Dokumen ini adalah konfigurasi dan runbook, bukan bukti deployment. Belum ada layanan Railway yang dibuat, database produksi yang dimigrasikan, domain publik yang diterbitkan, ataupun transaksi live yang dijalankan.
