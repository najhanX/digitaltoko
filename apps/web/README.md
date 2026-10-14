# DigitalToko web foundation

Laravel 13.35 + Inertia 3 + React 19/TypeScript/Vite. Panel admin/owner/staff dan storefront berada dalam satu runtime Laravel. Bot terpisah di ../bot.

## Local

1. composer install
2. Salin .env.example ke .env jika belum ada, php artisan key:generate.
3. php artisan migrate, npm ci, npm run build.
4. php artisan serve --host=127.0.0.1 --port=8000.

APP_URL=http://localhost:8000 dan PLATFORM_DOMAIN=localhost. Untuk pratinjau lokal set PLATFORM_PREVIEW_ENABLED=true. /preview/admin, /preview/owner, /preview/staff dan /preview/storefront hanya terbuka pada APP_ENV=local; tetap 404 di production walaupun flag true. Pratinjau adalah data contoh dan bukan login/impersonasi.

### Data dummy lokal

Jalankan `php artisan db:seed --no-interaction` dari apps/web setelah migrate. DatabaseSeeder memanggil DemoStoreSeeder dan CatalogDemoSeeder; keduanya untuk local/testing dan seeder demo menolak production termasuk dengan --force. Tidak perlu migrate:fresh (perintah tersebut menghapus data).

Seeder membuat admin `demo-admin@example.test`, dua owner lokal, delapan toko, dan tiga staff pada masing-masing dari tujuh toko owner utama. Password awal admin/staff: `DigitalToko-Demo2026!`. Admin masuk di `/admin/login`; staff di `/staff/{UUID-toko}/login` (URL lengkap dicetak seeder). Username `manager` mendapat semua permission staff, `viewer` hanya baca, dan `nonaktif` ditolak login. Akun staff tetap terpisah per toko meskipun username/email sama.

Tiga toko aktif `demo-aurora`, `demo-nebula`, `demo-atlas` memakai tiga template berbeda, ditambah `demo-grace` (expiry -3 hari), `demo-expired` (-10 hari), `demo-trial` (bot 7 hari), `demo-draft`, dan draft owner kedua. Expiry dihitung saat pertama dibuat. Buka subdomain sesuai PLATFORM_DOMAIN, misalnya `http://demo-aurora.localhost:8000`. Jika subdomain lokal tidak ter-resolve, tambahkan hostname tersebut ke hosts dengan IP 127.0.0.1.

Seeder tidak mereset perubahan/password/expiry record existing saat diulang. Owner dummy tidak ditautkan ke Hanshop dan tidak memiliki password login; login owner asli tetap melalui OTP, sedangkan `/preview/owner` tersedia untuk preview lokal. Status verifikasi dummy adalah fixture tanpa dokumen KTP/nomor WA/NIK asli. Tidak ada panggilan Hanshop, OTP, pembayaran atau provisioning bot. CatalogDemoSeeder menambahkan 18 produk simulasi per toko demo utama/trial dan stok premium palsu sesuai paket. Produk PPOB demo tanpa parsing pemasok tampil tidak tersedia. Seeder tidak mengisi API key atau saldo pemasok. Data dummy jangan disalin ke database production.

Pada APP_ENV=local, localhost dan 127.0.0.1 sama-sama diterima untuk root/panel agar URL bawaan php artisan serve tidak 404. PLATFORM_DOMAIN kosong mengikuti host APP_URL. Alias loopback otomatis ini tidak berlaku di production. Jika mengubah .env/config, jalankan php artisan optimize:clear; gunakan port yang benar-benar ditampilkan artisan serve.

## Yang tersedia

- Migrasi users platform, stores, satu custom domain per toko, dan store_staff lokal.
- Host resolver berdasarkan slug + configured platform domain atau custom hostname active/verified.
- Role gate admin/owner dan auth guard staff, akses lintas toko ditolak; login staff password dengan rate limit.
- Halaman panel admin/owner/staff dengan tema terang, gelap, dan sistem yang tersimpan di browser; landing dan tiga varian storefront memakai Inertia React.
- Panel owner/staff memakai navigasi bersama, pilihan toko yang tersimpan dalam sesi, menu ponsel, dan tombol sesuai permission. `/owner/stores` menampilkan seluruh toko; pembuatan toko langsung menuju pemilihan paket, pembayaran, persiapan layanan, lalu konfirmasi aktivasi. Pengaturan toko dan pengelolaan staff memiliki halaman/tab terpisah.
- Staff hanya melihat menu yang diizinkan. Login diarahkan ke halaman pertama yang dapat dibuka; staff tanpa izin halaman mendapat petunjuk untuk menghubungi owner. Logout dan sesi kedaluwarsa pada route staff kembali ke login toko tersebut.
- Daftar toko, penawaran admin, invoice sewa, pengajuan verifikasi, dan riwayat invoice memiliki pencarian/filter serta pagination melalui Inertia. Data diambil per halaman (5/10/20/50, maksimal 50); ringkasan total tetap mencakup seluruh toko milik owner. Invoice berjalan dipisahkan dari riwayat agar tetap terlihat saat riwayat difilter. Dropdown toko memuat maksimal 20 pilihan awal ditambah toko terpilih, dengan akses ke pencarian seluruh toko. Staff (maksimal lima akun) dan katalog ilustrasi memakai pagination/filter lokal.
- Penawaran paket per kategori dan sumber produk dapat diatur admin. Owner terverifikasi dapat membeli, memperpanjang, meningkatkan, atau menurunkan paket per toko; staff berizin dapat memperpanjang paket yang sama.
- Invoice sewa QRIS melalui gateway Hanshop, rekonsiliasi terjadwal, trial bot sekali per owner, aktivasi setelah toko siap, dan masa tenggang tujuh hari untuk paket berbayar.
- Koneksi WhatsApp per toko melalui modal nomor dan metode QR/kode; kontrol hubungkan ulang/lepas, token worker privat, status/cache pendek, dan bot manager dengan sesi terpisah. Staff `bot.manage` dapat pairing; `bot.view` hanya melihat status.
- Session host-only dan proxy allowlist. Tidak ada domain produksi tertanam dalam route/client.

## Belum tersedia

OTP owner dan pendaftaran Hanshop, verifikasi email dan KTP, toko draft, CRUD staff, langganan/trial, serta koneksi WhatsApp dari panel tersedia. Admin memakai login password lokal di /admin/login; owner memakai OTP di /login. Pengiriman sungguhan membutuhkan bridge Hanshop dan SMTP. Jalankan bot manager terpisah untuk QR/kode; panduan di `../bot/README.md` dan `../../docs/DEPLOYMENT.md`. OTP pembeli, checkout pembeli, pembayaran Midtrans/QRIS untuk checkout produk, otomatisasi domain, penyelesaian invoice yang perlu pemeriksaan manual, penghapusan toko setelah masa retensi, dan hardening auth worker produksi masih tahap berikutnya. DB onboarding bukan schema bisnis final.

SQLite hanya untuk development/tests. Deployment VPS memakai database aplikasi MySQL sendiri; jangan gunakan database Hanshop sebagai DB Laravel. Bridge Hanshop untuk identitas dan invoice sewa memerlukan token server-to-server. Jalankan migrasi tambahan Hanshop `scripts/migrations/20261013_digitaltoko_identity.sql` dan `scripts/migrations/20261013_digitaltoko_billing.sql` pada lingkungan tujuan setelah backup, lalu jalankan `php artisan migrate --force` di aplikasi web. Aktifkan Laravel scheduler agar invoice, trial, dan masa tenggang diperbarui otomatis. Penawaran dummy dari seeder tidak aktif sampai admin mengisi harga sesungguhnya dan mengaktifkannya.

Tests: php artisan test. Frontend: npm run build. Deployment dan migrasi domain: ../../docs/DEPLOYMENT.md.

CSS utama adalah entry Vite tersendiri, dimuat sebagai stylesheet di head sebelum markup Inertia/SSR tampil. Jangan memindahkannya hanya ke import JavaScript karena pada dev SSR dapat muncul flash halaman tanpa style. Navigasi internal memakai Link Inertia; perpindahan ke domain storefront yang berbeda tetap anchor biasa. Setelah mengubah vite.config.js, restart npm run dev bila server tidak reload otomatis.

## Sumber dan katalog toko (14 Oktober 2026)

Owner memilih toko → **Sumber produk** → hubungkan Digiflazz atau akun Hanshop tertaut → sinkronkan → pilih produk dan impor → **Katalog & harga** untuk nama, parsing, status, serta markup → **Stok premium** untuk stok privat. Satu produk dapat memakai beberapa parsing dengan jenis yang sama. Koneksi credential hanya owner; staff mengikuti permission produk/harga/stok. Saldo Hanshop global hanya terlihat owner, saldo Digiflazz membutuhkan `suppliers.balance`.

`predis/predis` sudah tersedia. Atur `REDIS_CLIENT=predis`, `SUPPLIER_CACHE_STORE=redis`, `SUPPLIER_CACHE_SECONDS=120` (dibatasi 60–180), dan Redis sesuai server. Cache memakai fingerprint akun: dua toko dengan akun pemasok sama berbagi snapshot; akun berbeda terpisah. Jangan mengganti APP_KEY: credential dan payload stok tersimpan terenkripsi. Jika Redis/pemasok gagal, tidak ada bypass request langsung; snapshot lama dipertahankan, tetapi setelah 600 detik sumber remote tidak dianggap tersedia.

Jalankan `php artisan schedule:work` untuk development atau cron `schedule:run` setiap menit di VPS. Katalog disinkronkan setiap dua menit. Sinkron manual: `php artisan digitaltoko:catalog-sync --store=UUID_TOKO` (tanpa opsi untuk semua koneksi yang memenuhi syarat). Mode Digiflazz prabayar/pasca dipisah; sinkronkan mode yang ingin dipakai satu kali melalui panel agar scheduler melanjutkannya. Harga pascabayar adalah **biaya admin**, bukan nominal tagihan.

Hanshop memerlukan build API terbaru yang memuat `/internal/digitaltoko/supplier/balance` dan `/supplier/catalog`; URL/token mengikuti bridge identitas existing. Endpoint ini hanya membaca saldo akun tertaut serta snapshot katalog, tanpa menyentuh saldo/checkout atau memanggil pemasok.

Web publik ketiga template dan `#produk`, `#produk canva`, `#produk 2` memakai katalog toko yang sama. Pemesanan, pembayaran pelanggan, fallback saat dispatch, dan pengiriman stok merupakan tahap berikutnya. Restart worker yang sedang berjalan setelah build bot agar handler baru termuat; sesi WhatsApp tetap dipertahankan.

Dummy katalog lokal dapat diulang dengan `php artisan db:seed --class=CatalogDemoSeeder --no-interaction`; tidak memakai `migrate:fresh`. Verifikasi: `php artisan test --compact`, `npm run test:frontend`, `npm run build`, serta `npm test` di apps/bot.
