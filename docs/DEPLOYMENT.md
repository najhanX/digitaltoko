# VPS dan domain deployment DigitalToko

Fondasi sudah dibuat lokal, belum dideploy. Tidak ada perubahan pada DNS/server hansdigi.biz.id dan domain digitaltoko.com belum dibeli. Ini panduan untuk deployment aplikasi; modul bisnis/live auth belum siap layanan penyewa.

## Runtime

- Aplikasi web apps/web: Laravel 13.35, Inertia 3, React 19/TypeScript, Vite 8. Lock Composer saat ini memerlukan PHP 8.4+ (local diuji PHP 8.5.5); verifikasi composer check-platform-reqs pada VPS.
- Node 22.12+ untuk build React dan worker apps/bot. Git diperlukan untuk patch Baileys exact 7.0.0-rc14.
- PHP-FPM/Nginx dan extension PHP pdo_mysql untuk MySQL aplikasi terpisah, queue worker/scheduler. Redis dibutuhkan saat koordinasi multi-proses diperluas, bukan syarat demo foundation ini.
- Satu worker bot per toko, satu port loopback dan direktori session UUID; tidak menjalankan semua tenant pada satu socket global.

## Domain awal

Gunakan contoh apps/web/.env.vps.example:

```dotenv
APP_URL=https://hansdigi.biz.id
PLATFORM_DOMAIN=hansdigi.biz.id
PLATFORM_DOMAIN_ALIASES=
PLATFORM_PREVIEW_ENABLED=false
```

Landing di root; panel /admin, /owner, dan /staff/<uuid>/login pada domain platform yang sama. Storefront toko memakai <slug>.hansdigi.biz.id atau satu custom hostname verified. Slug adalah identitas domain bawaan yang disimpan DB, bukan full hostname domain platform. Custom domain menyimpan hostname milik tenant tersendiri.

DNS root dan wildcard *.hansdigi.biz.id harus diarahkan ke origin yang sesuai, dengan TLS mencakup masing-masing host. Wildcard certificate dan DNS adalah konfigurasi berbeda. Periksa apakah domain awal sudah melayani aplikasi lain sebelum mengganti routing VPS; contoh tidak menimpa konfigurasi existing.

Untuk custom domain, DNS saja belum cukup: verifikasi kepemilikan, routing, dan sertifikat sebelum menandai row domain active/verified. Workflow Cloudflare for SaaS belum dibuat. Host tak terdaftar ditolak aplikasi; web bot-only tidak membuka storefront. Nginx example menerima host ke satu origin dan Laravel memvalidasi mapping.

## Install pada VPS

Document root wajib apps/web/public. Contoh deploy/nginx.conf.example harus disesuaikan root, socket PHP, dan terminasi TLS. Jika menggunakan proxy/CDN, isi TRUSTED_PROXIES hanya dengan IP/CIDR proxy terpercaya; jangan wildcard semua alamat. Jangan membuka port bot publik. Jalankan proses sebagai user service non-root.

```bash
cd /var/www/digitaltoko/apps/web
composer install --no-dev --optimize-autoloader
# Siapkan .env produksi, APP_KEY hanya saat instalasi pertama.
php artisan key:generate
php artisan migrate --force
npm ci
npm run build
php artisan optimize
composer check-platform-reqs
```

Simpan APP_KEY existing pada redeploy agar session/encryption tidak rusak. Siapkan permission storage dan bootstrap/cache. Jalankan queue:work sebagai service dan schedule:run setiap menit saat job bisnis tersedia. Contoh worker PM2 ada di deploy/ecosystem.config.cjs; gunakan config/env/store UUID/port berbeda per worker dan jangan clone session toko.

## Berpindah ke domain final

```dotenv
APP_URL=https://digitaltoko.com
PLATFORM_DOMAIN=digitaltoko.com
PLATFORM_DOMAIN_ALIASES=hansdigi.biz.id
```

1. Siapkan DNS dan TLS root/wildcard domain baru sebelum cutover.
2. Ganti konfigurasi URL/domain; aliases membuat root dan subdomain lama tetap terpetakan sementara. Hapus alias setelah masa transisi dan seluruh callback lama tidak dipakai.
3. php artisan optimize:clear lalu php artisan optimize, restart queue/PHP long-lived workers.
4. Update WEB_BASE_URL tiap bot, MAIL_FROM_ADDRESS/sender verification, serta webhook/payment/provider yang nantinya terpasang. Endpoint eksternal tidak berpindah otomatis hanya karena APP_URL diganti.
5. Uji root, panel, satu subdomain toko, custom domain, callback, dan akses lintas toko. Pergantian domain berarti session host-only lama tidak dibagikan; pengguna perlu login kembali di domain baru.

Tidak perlu mengganti slug/database katalog ataupun membangun frontend ulang hanya untuk mengganti domain. Link internal relative; URL toko dihasilkan dari config backend. Custom domain tetap sama selama pemilik/routingnya valid. Bot mengambil WEB_BASE_URL dari environment, bukan brand domain hardcoded.

## Validasi fondasi

Feature tests mencakup rebasing domain, alias transisi, verified custom host, unknown/nested/reserved host, forwarded-host dari proxy asing, batas role/toko, staff password lokal, cookie host-only, lifecycle boundary paid/trial, dan token/QR per toko. Ini bukti lokal, bukan DNS/TLS atau WhatsApp live.

Rujukan: https://laravel.com/framework/docs/deployment dan https://inertiajs.com/docs/v3/installation/server-side-setup .

## Menjalankan onboarding owner

1. Backup database Hanshop, lalu terapkan migration additive API Hanshop/scripts/migrations/20261013_digitaltoko_identity.sql pada database Hanshop. Migration menambah email unik nullable dan timestamp verifikasi; tidak mengubah saldo/history/API key existing. File ini belum dijalankan pada database Hanshop production.
2. Deploy/build source API Hanshop yang memuat /internal/digitaltoko. Atur DIGITALTOKO_IDENTITY_TOKEN minimal 32 karakter acak. API tidak membuka bridge apabila token belum ada.
3. Di Laravel, atur HANSHOP_API_URL ke origin API Hanshop dan HANSHOP_IDENTITY_TOKEN dengan token yang sama. Gunakan HTTPS atau jaringan privat. Token ini server-only, berbeda dari API key customer dan token bot.
4. Atur SMTP/provider mail Laravel dan sender yang valid. Mail log/array tidak diterima untuk email OTP di luar testing. OTP WhatsApp dikirim melalui bot Hanshop existing; bot penyewa belum diperlukan saat onboarding.
5. Jalankan php artisan migrate --force dan php artisan optimize:clear. Jangan mengganti APP_KEY existing: NIK/alamat dienkripsi dengan key ini.
6. Buat admin lokal dengan php artisan digitaltoko:admin admin@domain-anda (password diminta tanpa ditampilkan). Akun owner existing tidak dapat diubah menjadi admin oleh command ini.
7. Jalankan php artisan schedule:run setiap menit untuk penghapusan foto KTP ditolak setelah 72 jam. KTP disetujui tetap disimpan; owner dapat membersihkan data KTP hanya ketika tidak ada toko sama sekali.
8. Untuk upload 5 MB, PHP membutuhkan upload_max_filesize sedikitnya 5M dan post_max_size lebih besar (contoh 8M/12M); nginx perlu client_max_body_size 12m.

Alur operator: owner login/daftar -> WA dan email terverifikasi -> isi nama KTP/NIK/alamat dan foto -> admin review -> owner membuat toko draft -> tambah/edit/nonaktifkan/hapus maksimal 5 staff per toko. Status draft tidak mengaktifkan langganan, bot, atau transaksi. Permission hanya mengaktifkan endpoint modul yang sudah tersedia; modul bisnis lain menyusul.

Dokumen KTP disimpan pada disk privat, diakses hanya owner terkait/admin melalui /identity/{owner}/document, tanpa URL storage publik. NIK tidak dikirim ke dashboard/staff. Admin tidak memakai OTP Hanshop owner.
