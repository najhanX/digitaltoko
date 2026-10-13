# DigitalToko web foundation

Laravel 13.35 + Inertia 3 + React 19/TypeScript/Vite. Panel admin/owner/staff dan storefront berada dalam satu runtime Laravel. Bot terpisah di ../bot.

## Local

1. composer install
2. Salin .env.example ke .env jika belum ada, php artisan key:generate.
3. php artisan migrate, npm ci, npm run build.
4. php artisan serve --host=127.0.0.1 --port=8000.

APP_URL=http://localhost:8000 dan PLATFORM_DOMAIN=localhost. Untuk pratinjau lokal set PLATFORM_PREVIEW_ENABLED=true. /preview/admin, /preview/owner, /preview/staff dan /preview/storefront hanya terbuka pada APP_ENV=local; tetap 404 di production walaupun flag true. Pratinjau adalah data contoh dan bukan login/impersonasi. DemoStoreSeeder opsional, local/testing saja; toko demo draft dan tidak menerima pesanan. Akun demo tidak diberi password login yang dibagikan.

Pada APP_ENV=local, localhost dan 127.0.0.1 sama-sama diterima untuk root/panel agar URL bawaan php artisan serve tidak 404. PLATFORM_DOMAIN kosong mengikuti host APP_URL. Alias loopback otomatis ini tidak berlaku di production. Jika mengubah .env/config, jalankan php artisan optimize:clear; gunakan port yang benar-benar ditampilkan artisan serve.

## Yang tersedia

- Migrasi users platform, stores, satu custom domain per toko, dan store_staff lokal.
- Host resolver berdasarkan slug + configured platform domain atau custom hostname active/verified.
- Role gate admin/owner dan auth guard staff, akses lintas toko ditolak; login staff password dengan rate limit.
- Halaman dasar panel, landing, dan tiga varian storefront; semua memakai Inertia React.
- Token worker per toko, status/QR cache pendek, endpoint pairing state dengan role/permission; bot entitlement read.
- Session host-only dan proxy allowlist. Tidak ada domain produksi tertanam dalam route/client.

## Belum tersedia

OTP owner dan pendaftaran Hanshop, verifikasi email dan KTP, toko draft, serta CRUD staff tersedia. Admin memakai login password lokal di /admin/login; owner memakai OTP di /login. Pengiriman sungguhan membutuhkan bridge Hanshop dan SMTP. OTP pembeli, saldo pemasok, subscription/trial/billing, katalog/stok/checkout, pembayaran Midtrans/QRIS, otomatisasi domain, provisioning worker, purge toko/renewal, dan UI pairing masih tahap berikutnya. DB onboarding bukan schema bisnis final.

SQLite hanya untuk development/tests. Deployment VPS memakai database aplikasi MySQL sendiri; jangan gunakan database Hanshop sebagai DB Laravel. Integrasi Hanshop server-to-server akan dibuat kemudian.

Tests: php artisan test. Frontend: npm run build. Deployment dan migrasi domain: ../../docs/DEPLOYMENT.md.

CSS utama adalah entry Vite tersendiri, dimuat sebagai stylesheet di head sebelum markup Inertia/SSR tampil. Jangan memindahkannya hanya ke import JavaScript karena pada dev SSR dapat muncul flash halaman tanpa style. Navigasi internal memakai Link Inertia; perpindahan ke domain storefront yang berbeda tetap anchor biasa. Setelah mengubah vite.config.js, restart npm run dev bila server tidak reload otomatis.
