# VPS dan domain deployment DigitalToko

Panduan disinkronkan dengan source lokal **22 Oktober 2026**. Modul bisnis, checkout, OTP dan notifikasi sudah tersedia; DNS/VPS, kepemilikan domain, secret dan layanan produksi harus diverifikasi pada lingkungan tujuan. Sinkronisasi dokumen tidak mengubah server atau membuktikan deploy telah dilakukan.

## Runtime

- Aplikasi web apps/web: Laravel 13.35, Inertia 3, React 19/TypeScript, Vite 8. Lock Composer saat ini memerlukan PHP 8.4+ (local diuji PHP 8.5.5); verifikasi composer check-platform-reqs pada VPS.
- Node 22.12+ untuk build React dan worker apps/bot. Git diperlukan untuk patch Baileys exact 7.0.0-rc14.
- PHP-FPM/Nginx dan extension PHP pdo_mysql untuk MySQL aplikasi terpisah, queue worker/scheduler. Redis dipakai cache/lock pemasok dan koordinasi runtime; Predis tersedia. Pastikan service/driver dikonfigurasi dan tidak bergantung pada array cache untuk produksi.
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

Untuk custom domain, DNS saja belum cukup: verifikasi kepemilikan, routing, dan sertifikat sebelum menandai row domain active/verified. Alur owner dan adapter izin Caddy tersedia; konfigurasi origin/sertifikat tetap perlu diterapkan di VPS. Lihat CUSTOM_DOMAINS.md dan deploy/Caddyfile.example. Cloudflare for SaaS API belum dibuat. Host tak terdaftar ditolak aplikasi; web bot-only tidak membuka storefront. Nginx example menerima host ke satu origin dan Laravel memvalidasi mapping.

## Install pada VPS

Document root wajib apps/web/public. Contoh deploy/nginx.conf.example harus disesuaikan root, socket PHP, dan terminasi TLS. Jika menggunakan proxy/CDN, isi TRUSTED_PROXIES hanya dengan IP/CIDR proxy terpercaya; jangan wildcard semua alamat. Jangan membuka port bot publik. Jalankan proses sebagai user service non-root.

```bash
cd /var/www/digitaltoko/apps/web
composer install --no-dev --optimize-autoloader
# Siapkan .env produksi, APP_KEY hanya saat instalasi pertama.
# php artisan key:generate  # hanya instalasi pertama yang belum memiliki APP_KEY
php artisan migrate --force
npm ci
npm run build
php artisan optimize
composer check-platform-reqs
```

Simpan APP_KEY existing pada redeploy agar session/encryption tidak rusak. Siapkan permission storage dan bootstrap/cache. Jalankan queue:work sebagai service dan satu scheduler: schedule:work melalui PM2, atau schedule:run setiap menit melalui cron. Scheduler juga memiliki pekerjaan submenit customer-notices; jangan menjalankan keduanya bersamaan. Contoh PM2 menjalankan satu manager yang membuat worker per toko, bukan satu konfigurasi PM2 manual untuk setiap tenant. Jangan clone session toko.

## PM2 satu perintah

Ditambahkan 26 Oktober 2026. Profil laptop diperbarui menjadi tujuh proses sesuai perintah development pengguna. Konfigurasi memakai path relatif terhadap lokasi repository. PM2 sudah terpasang pada komputer pengembangan saat perubahan ini dibuat; pada instalasi baru, pasang PM2 melalui `npm install -g pm2`. Profil produksi tetap contoh terpisah untuk ditinjau kembali ketika deployment VPS.

### Lokal Windows / pengembangan

Siapkan dependensi sekali mengikuti README masing-masing proyek (vendor/node_modules web, node_modules apps/bot, API Hanshop dan bot_wa_v2). Pasang launcher global per user dari root Hansbot:

```powershell
# Sekali, dari root Hansbot
.\deploy\install-pm2-laptop.ps1
# Tutup dan buka terminal baru, boleh dari folder mana pun
pm2 start
pm2 status
```

Launcher terpasang pada `%USERPROFILE%\.digitaltoko\bin`, ditambahkan di awal PATH user, dengan shim CMD dan PowerShell. File PM2 milik npm tidak ditimpa. Bare `pm2 start` diarahkan ke root `ecosystem.config.js` DigitalToko secara absolut; perintah lain dan `pm2 start <target>` tetap diteruskan ke PM2 asli. Untuk proyek lain, sebutkan target ecosystem/script secara eksplisit. Buka terminal baru; bila terminal terintegrasi VS Code/Codex masih membawa PATH lama, tutup/buka aplikasi induknya. Tanpa launcher, perintah yang sama tetap bekerja dari root Hansbot.

Pada Windows, installer juga membangun `hidden-process.exe` dari source `deploy/HiddenProcess.cs`, memakai compiler .NET Framework bawaan Windows. Host GUI ini membuat console PHP tersembunyi sejak awal dengan stdout/stderr/stdin tetap diteruskan ke PM2. Proses turunan PHP CLI (serve, scheduler, tugas terjadwal) mewarisi console tersebut. Vite/manager/API/bot tetap proses Node langsung agar pemantauan memori PM2 tidak berpindah ke proxy; preload `hide-child-windows.cjs` melalui NODE_OPTIONS menyembunyikan child watch/build dan tetap mempertahankan NODE_OPTIONS existing. Tidak mengubah vendor Laravel/Symfony, PHP.ini atau instalasi PM2. Penanganan khusus ini hanya profil development Windows.

Jika memperbarui host Windows ketika proses masih berjalan, hentikan dahulu `pm2 stop digitaltoko` sebelum menjalankan installer lagi. Jika jalur script/interpreter ecosystem berubah dari definisi lama, lakukan `pm2 delete digitaltoko` lalu `pm2 start` untuk mendaftarkan ulang proses; restart biasa dapat mempertahankan pm_exec_path lama. Log tetap dibaca lewat `pm2 logs`. Source host tidak ikut deployment Linux. Rujukan mekanisme: [Windows console creation](https://learn.microsoft.com/en-us/windows/console/creation-of-a-console) dan [PHP proc_open](https://www.php.net/proc-open).

Tujuh proses laptop:

| Nama PM2 | Tugas |
| --- | --- |
| digitaltoko-web | `php artisan serve`, loopback port 8000 |
| digitaltoko-vite | Vite dev server, loopback port 5173; perubahan frontend langsung dimuat |
| digitaltoko-queue | Queue worker, timeout 180 detik, retry 3, batas memori 256 MB dan siklus satu jam |
| digitaltoko-scheduler | Satu `php artisan schedule:work` |
| digitaltoko-bot-manager | Build TypeScript lalu manager, setara `npm run manager:dev`; worker toko dikelola manager |
| api-hanshop | `tsx watch src/server.ts`, setara npm run dev pada API Hanshop |
| hanshop-bot | `tsx watch src/index.ts`, setara npm run dev pada bot_wa_v2 |

Sebelum mulai, hentikan dengan Ctrl+C ketujuh terminal manual yang menjalankan serve, Vite/npm run dev web, queue, scheduler, manager, API Hanshop dan bot Hanshop. Jika layanan sebelumnya sudah memakai PM2, kelola nama proses existing agar tidak menggandakan sesi/registry. Server web tidak berpindah diam-diam ke port lain ketika port 8000 terpakai; Vite juga memakai strictPort. Periksa log jika status errored.

Queue/scheduler ini menggunakan `.env` aplikasi yang sebenarnya dan dapat memproses transaksi lunas. MySQL/Redis tetap dijalankan sebagai service tersendiri; tidak dipasang atau dinyalakan oleh konfigurasi ini. Tidak ada migrasi, seeder atau perubahan `.env` ketika `pm2 start` dipanggil. Khusus manager development, TypeScript bot dibangun sebelum manager dimulai; build gagal menghentikan startup manager. API/bot Hanshop memakai watch sumber tanpa memerlukan build dist. Perubahan source apps/bot memerlukan restart digitaltoko-bot-manager (build otomatis pada startup ulang), sesuai perilaku manager:dev.

### Lokasi API Hanshop dan bot Hanshop

Keduanya sudah termasuk profil laptop utama. Default folder saudara `../API Hanshop` dan `../bot_wa_v2`. Nama folder pada laptop ini tetap bot_wa_v2; label proses PM2-nya hanshop-bot. Jika lokasi dipindah, atur DIGITALTOKO_HANSHOP_API_DIR/DIGITALTOKO_HANSHOP_BOT_DIR sebelum memulai. Tiap proses membaca `.env` proyeknya sendiri.

```powershell
# Alias konfigurasi tujuh proses laptop, tetap didukung dari root Hansbot
pm2 start deploy/ecosystem.full.config.cjs
```

Bot Hanshop untuk OTP owner berbeda dari bot manager tenant. Port API, token bridge dan alamat internal tetap diatur pada `.env` masing-masing, tidak ditulis pada ecosystem. Koneksi database/Redis harus sudah tersedia. Tidak perlu menjalankan kedua profil; ketujuh nama prosesnya sama. Jika ada proses yang memakai nama lain untuk aplikasi yang sama, hentikan proses lama terlebih dahulu.

### Perintah harian

```powershell
pm2 status
pm2 logs digitaltoko-web --lines 50
pm2 logs digitaltoko-queue --lines 50
pm2 logs digitaltoko-bot-manager --lines 50
pm2 restart digitaltoko --update-env
pm2 stop digitaltoko
```

`digitaltoko` adalah namespace ketujuh proses, sehingga stop/restart dapat dipanggil dari folder mana pun. Untuk satu layanan gunakan nama prosesnya. Ctrl+C pada tampilan `pm2 logs` hanya menutup pemantauan log; aplikasi tetap berjalan. Source apps/bot dimuat ulang melalui `pm2 restart digitaltoko-bot-manager` (build otomatis). Untuk PHP setelah perubahan source/config, restart queue/scheduler yang sudah berjalan; web lokal juga perlu restart setelah perubahan `.env` karena serve memakai no-reload. Jangan memakai reload cluster untuk sesi WhatsApp.

Override opsional dibaca dari environment terminal/service yang menjalankan PM2, bukan `.env` Laravel:

```powershell
$env:DIGITALTOKO_PHP_BINARY='C:\Program Files\php-8.5.5\php.exe'
$env:DIGITALTOKO_WEB_PORT='8000'
pm2 start
```

Override lain: DIGITALTOKO_WEB_HOST, DIGITALTOKO_VITE_HOST, DIGITALTOKO_VITE_PORT, DIGITALTOKO_HANSHOP_API_DIR dan DIGITALTOKO_HANSHOP_BOT_DIR. Jika mengubah host/port, sesuaikan APP_URL/platform alias, WEB_BASE_URL bot dan alamat bridge yang relevan. Host default tetap loopback. Untuk akses LAN, konfigurasi host/HMR Vite dan host platform tetap perlu disesuaikan seperti deployment LAN; mengganti listen host saja tidak mendaftarkan hostname tenant.

### VPS

```bash
# Dari root deployment, sesudah build web/bot dan konfigurasi layanan selesai
pm2 start deploy/ecosystem.config.cjs
pm2 save
pm2 startup
```

Profil VPS menjalankan queue, scheduler dan satu bot manager. Web memakai Nginx/Caddy + PHP-FPM, aset frontend memakai `npm run build`; tidak menjalankan artisan serve/Vite di produksi. Hapus berkas `apps/web/public/hot` development yang tertinggal sebelum deploy hasil build. Jangan tambahkan cron schedule:run bila scheduler PM2 ini aktif. Sesuaikan retry_after queue agar lebih besar daripada 180 detik (bawaan database/Redis 240); PHP CLI Linux perlu PCNTL untuk enforcement timeout queue. PM2 memberi queue waktu 200 detik sebelum penghentian paksa. Worker dibiarkan selesai/berhenti lalu dimulai ulang, tidak dimuat dalam cluster.

Jalankan instruksi service yang dicetak `pm2 startup` untuk user layanan yang benar pada Linux. `pm2 save` menyimpan daftar proses, tetapi bukan konfigurasi autostart Windows. Pada Windows, proses PM2 bertahan ketika terminal ditutup; startup setelah reboot memerlukan pengaturan service/Task Scheduler tersendiri dan belum dibuat oleh perubahan ini. Jangan menganggap database, Redis, web server, atau APK Android ikut dimulai PM2.

Rujukan: [PM2 ecosystem](https://pm2.io/docs/runtime/reference/ecosystem-file/) dan [Laravel scheduler](https://laravel.com/framework/docs/13.x/scheduling).

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

1. Backup database Hanshop, lalu terapkan migration additive API Hanshop/scripts/migrations/20261013_digitaltoko_identity.sql pada database Hanshop. Migration menambah email unik nullable dan timestamp verifikasi; tidak mengubah saldo/history/API key existing. Penerapannya pada database Hanshop tujuan harus diperiksa; hasil lokal tidak membuktikan migration production sudah terpasang.
2. Deploy/build source API Hanshop yang memuat /internal/digitaltoko. Atur DIGITALTOKO_IDENTITY_TOKEN minimal 32 karakter acak. API tidak membuka bridge apabila token belum ada.
3. Di Laravel, atur HANSHOP_API_URL ke origin API Hanshop dan HANSHOP_IDENTITY_TOKEN dengan token yang sama. Gunakan HTTPS atau jaringan privat. Token ini server-only, berbeda dari API key customer dan token bot.
4. Atur SMTP/provider mail Laravel dan sender yang valid. Mail log/array tidak diterima untuk email OTP di luar testing. OTP WhatsApp dikirim melalui bot Hanshop existing; bot penyewa belum diperlukan saat onboarding.
5. Jalankan php artisan migrate --force dan php artisan optimize:clear. Jangan mengganti APP_KEY existing: NIK/alamat dienkripsi dengan key ini.
6. Buat admin lokal dengan php artisan digitaltoko:admin admin@domain-anda (password diminta tanpa ditampilkan). Akun owner existing tidak dapat diubah menjadi admin oleh command ini.
7. Jalankan php artisan schedule:run setiap menit untuk penghapusan foto KTP ditolak setelah 72 jam. KTP disetujui tetap disimpan; owner dapat membersihkan data KTP hanya ketika tidak ada toko sama sekali.
8. Untuk upload 5 MB, PHP membutuhkan upload_max_filesize sedikitnya 5M dan post_max_size lebih besar (contoh 8M/12M); nginx perlu client_max_body_size 12m.

Alur operator: owner login/daftar -> WA dan email terverifikasi -> isi nama KTP/NIK/alamat dan foto -> admin review -> owner membuat toko draft -> tambah/edit/nonaktifkan/hapus maksimal 5 staff per toko. Status draft tidak mengaktifkan langganan, bot, atau transaksi. Permission hanya mengaktifkan endpoint modul yang sudah tersedia; modul bisnis lain menyusul.

Dokumen KTP disimpan pada disk privat, diakses hanya owner terkait/admin melalui /identity/{owner}/document, tanpa URL storage publik. NIK tidak dikirim ke dashboard/staff. Admin tidak memakai OTP Hanshop owner.


## Panel theme dan langganan (13 Oktober 2026)

- Panel admin/owner/staff dan login memakai palet indigo/cyan dengan pilihan terang, gelap, atau sistem. Preferensi localStorage `digitaltoko.panel.theme` hanya memengaruhi panel; template storefront tetap milik tenant. Bootstrap tema berjalan sebelum stylesheet/SSR agar tidak flash warna terang.
- Jalankan migrasi Laravel `2026_10_13_161039_create_subscription_billing_tables` setelah backup. Pada instalasi baru seeder menyediakan tiga penawaran platform **nonaktif** seharga dummy Rp50.000/30 hari; pada instalasi existing harga/durasi penawaran terlengkap dipertahankan. Admin harus mengatur harga sebenarnya dan mengaktifkan penawaran pada `/admin/plans`.
- Pada API Hanshop, terapkan `scripts/migrations/20261013_digitaltoko_billing.sql`, build source, lalu restart proses yang Anda kelola. Development `npm run dev` memuat source; jangan menjalankan instance kedua pada port 4000.
- Gateway memakai URL/token bridge existing, QR_DYNAMIC/Cloudinary existing, dan tabel mutasi dari forwarder. `/internal/digitaltoko/billing/invoice` hanya server-to-server. ID invoice menjadi kunci idempotensi; nominal dan akun yang berubah pada ID yang sama ditolak. Pembayaran sewa tidak mengkredit saldo Hanshop.
- Laravel cron `schedule:run` per menit menjalankan `digitaltoko:subscriptions`; saat development jalankan `php artisan schedule:work` di terminal Anda. Ini memeriksa pembayaran, aktivasi 24 jam setelah siap, downgrade terjadwal, dan grace. Tombol cek pembayaran juga memeriksa invoice tanpa menunggu cron.
- Pembayaran pertama menunggu konfirmasi aktivasi. Web siap ketika slug tersedia; Bot/Full perlu heartbeat CONNECTED worker per toko. Owner/staff berizin sekarang dapat menghubungkan worker lewat menu Koneksi WhatsApp menggunakan QR atau kode pairing; panduan manager di bawah.
- Upgrade penuh berlaku saat lunas; kanal bot yang baru ditambahkan tetap membutuhkan worker tersambung untuk beroperasi. Downgrade (mengurangi kanal/sumber) berlaku setelah periode existing selesai. Trial baru dinonaktifkan; promo memakai voucher, termasuk diskon 100% tanpa gateway. Trial lama yang sudah aktif tetap berakhir sesuai jadwal tanpa grace.
- Invoice ambigu masuk REVIEW dan tidak mengaktifkan toko. Pembayaran setelah invoice sudah EXPIRED tidak otomatis diterapkan; perlu pemeriksaan/refund operator. Jangan memalsukan status lunas dari browser. Rekonsiliasi gateway tidak membuat transaksi produk atau mengubah saldo pelanggan.
- Nominal unik sewa +1 sampai +999 dialokasikan bersama gateway existing dengan named lock. Nominal historis tidak didaur ulang untuk mencegah mutasi lama membayar invoice baru; jika seluruh rentang telah digunakan, pembuatan invoice ditolak dan perlu penanganan operator.
- Scheduler retensi mengevaluasi expiry+30 setiap jam. Kewajiban menahan penghapusan. Bawaan STORE_PURGE_ENABLED=false hanya mengevaluasi; periksa preview sebelum mengaktifkan purge. Lihat Operasi retensi toko di bawah.

## Koneksi WhatsApp dari panel (14 Oktober 2026)

1. Backup database DigitalToko, kemudian `php artisan migrate --force --no-interaction` untuk kolom nomor/metode/ID koneksi pada toko.
2. Buat token acak khusus manager, minimal 32 karakter. Atur nilai yang sama pada `BOT_MANAGER_TOKEN` di `apps/web/.env` dan `apps/bot/.env`. Laravel menggunakan `BOT_MANAGER_URL=http://127.0.0.1:3099`, manager memakai `MANAGER_PORT=3099`. Jangan tampilkan token pada frontend atau membuka port manager ke publik.
3. Atur `WEB_BASE_URL` pada bot ke origin Laravel yang dapat diakses worker dan diterima `platform.host`. Semua toko menggunakan backend ini; domain tidak di-hardcode. Setelah pindah domain, ubah URL environment dan restart manager/PHP sesuai konfigurasi.
4. Jalankan `npm ci`, `npm run build`, lalu `npm run manager` dalam `apps/bot`; contoh PM2 sekarang menjalankan `dist/manager.js`. Jangan menjalankan PM2 mode cluster atau dua manager pada registry/sesi yang sama. Atur izin folder sesi privat untuk user layanan bot; mode file Unix 0600/0700 tidak menggantikan ACL Windows.
5. Owner memilih toko → Langganan & aktivasi → Koneksi WhatsApp → pilih metode dan nomor → tautkan di HP. Status CONNECTED memungkinkan konfirmasi aktivasi. Staff dengan `bot.manage` dapat menghubungkan/lepas koneksi, sedangkan `bot.view` hanya melihat status. Staff yang hanya mempunyai izin bot diarahkan langsung ke menu koneksi setelah login.
6. Toko draft perlu membeli paket terlebih dahulu, atau memakai voucher 100% untuk invoice gratis. Bot berhenti saat akses berakhir; masa tenggang berbayar tetap tersedia tujuh hari. Token dan ID percobaan mencegah worker/sesi lama memperbarui percobaan baru. QR/kode dihapus dari respons ketika kedaluwarsa atau sudah terhubung.

Registry manager dan auth Baileys saat ini berada pada file privat per toko, dengan atomic writer untuk creds. Operasi multi-host, penyimpanan auth/key di database, pemulihan penuh setelah disk rusak, dan integrasi purge toko masih memerlukan tahap hardening. Jangan menyalin sesi bot Hanshop ke worker tenant. Panduan rinci dan batas modul bisnis: `apps/bot/README.md`.

## Sumber produk dan katalog (14 Oktober 2026)

1. Backup database DigitalToko, jalankan `composer install`, `php artisan migrate --force --no-interaction`, dan `npm run build` di apps/web. Migration katalog bersifat additive dan tidak mengubah database/saldo Hanshop.
2. Gunakan `REDIS_CLIENT=predis` (dependensi Composer sudah disetujui/ditambahkan), `SUPPLIER_CACHE_STORE=redis`, `SUPPLIER_CACHE_SECONDS=120`, dan `SUPPLIER_MAX_SNAPSHOT_AGE=600`. Pertahankan APP_KEY untuk dekripsi credential serta stok privat. Redis harus terjangkau PHP/CLI.
3. Build/restart API Hanshop yang memuat bridge supplier read-only, dengan `HANSHOP_API_URL` dan `HANSHOP_IDENTITY_TOKEN` existing. Tidak ada migration Hanshop baru untuk pembacaan katalog ini. Token tetap server-only dan komunikasi HTTPS/jaringan privat.
4. Cron `schedule:run` setiap menit menjalankan sinkron katalog setiap dua menit, tanpa overlap. Saat lokal: `php artisan schedule:work`. Sinkron manual per toko: `php artisan digitaltoko:catalog-sync --store=UUID_TOKO`. Tidak ada transaksi atau perubahan saldo saat sinkron.
5. Owner hubungkan sumber dan sinkronkan mode prabayar/pasca yang dibutuhkan; scheduler mengulang mode yang sudah dipilih. Tiga platform publik semuanya mendukung Digiflazz, Hanshop, OtomaX, dan stok premium. Stok lokal tetap privat per toko.
6. Build bot dan restart manager yang sudah ada melalui supervisor/PM2 agar handler #produk baru dimuat, tanpa menghapus registry/sesi atau membuat instance kedua.
7. Periksa impor produk, aturan harga, stok, katalog publik dan #produk. Membaca katalog panel/web/bot juga memperbarui koneksi yang pernah disinkronkan jika snapshot berumur sekitar dua menit; pembacaan tetap memakai cache dan lock per akun pemasok. Ini menjaga katalog lokal saat scheduler belum berjalan. Jika cache/provider gagal, snapshot tidak dihapus dan sumber remote yang berumur lebih dari 600 detik tetap tidak dapat dipesan. Panel menampilkan “Perlu sinkronisasi / Stok belum terverifikasi”, bukan menganggap stok pemasok habis. Stok lokal yang masih ready tetap dapat ditampilkan. Pemeriksaan sebelum membuat pesanan memperbarui pemasok sebelum mengambil lock transaksi database.

Katalog publik membaca snapshot toko; pembaruan saat dibuka melalui layanan server dan cache akun pemasok, tanpa mengekspos credential ke browser/bot. Pasca pada katalog hanya menampilkan biaya admin; alur inquiry/checkout/fallback dispatch serta pengiriman stok dijelaskan pada bagian transaksi pelanggan di bawah.

Editor desain web berada pada Pengaturan toko → Desain web, tersedia bagi owner dan staff berizin `web.manage`. File banner/logo tersimpan di disk `local` pada `storefront/{UUID_TOKO}` (umumnya `storage/app/private/storefront`). Sertakan folder ini pada persistent volume/backup saat redeploy. File disajikan oleh route `/storefront-media/{store}/{asset}` dan tidak memerlukan symlink `storage:link` atau domain tetap. Atur PHP `upload_max_filesize=4M` dan `post_max_size=32M` bila ingin mengunggah lima banner serta logo sekaligus; validasi aplikasi membatasi tiap file 4 MB. Perubahan desain baru berlaku saat Simpan desain; flash sale memilih produk katalog dengan harga yang tetap sama di checkout. Pengaturan jadwal menyembunyikan sorotan promo setelah berakhir, tanpa mengubah harga katalog.

## Pembaruan nama paket planet (15 Oktober 2026)

Jalankan `php artisan db:seed --class=PackagePlanSeeder --no-interaction` setelah backup plan_options. Seeder mengganti nama penawaran legacy Orbit/Space/Galaxy sesuai kategori/sumber, sambil mempertahankan ID, harga, durasi, status aktif dan snapshot invoice/langganan. Penawaran khusus yang tidak memakai nama legacy tetap dipertahankan. Jika suatu kombinasi belum ada, dibuat penawaran awal Rp50.000/30 hari **nonaktif** untuk diatur admin. Dapat dijalankan ulang; tidak perlu migrate:fresh.

Frontend dan PHP membaca satu katalog JSON bersama. Deploy juga file resources/js/packageCatalog.json, jalankan npm run build dan muat ulang konfigurasi Laravel jika memakai config cache. Riwayat trial bot penuh tetap ditampilkan sebagai Jupiter; penawaran trial baru sudah dihapus. Nama di invoice tersimpan tetap merupakan bukti historis; panel menampilkan nama paket baru berdampingan dengan nama penawaran historis bila diperlukan.


## Transaksi pelanggan web dan bot (15 Oktober 2026)

1. Backup database, pertahankan APP_KEY, lalu jalankan migrasi dan build web/bot. Migration `2026_10_15_065647_create_customer_commerce_tables` hanya menambahkan tabel pelanggan, invoice/pesanan, ledger, percobaan pemasok, notifikasi, dan lisensi QRIS custom. Database pelanggan tidak digabung dengan akun owner Hanshop.
2. Build/restart API Hanshop dengan bridge internal `/internal/digitaltoko/supplier/transaction` dan `/internal/digitaltoko/message`. Token layanan existing tetap server-only. Jembatan transaksi memakai preparation/orchestrator/idempotency Hanshop; tidak perlu skema Hanshop tambahan di tahap ini. Jangan menyalin API key owner ke browser atau bot pelanggan.
3. Jalankan SATU bot manager pada port existing (`npm run manager` sesudah build). Endpoint loopback terautentikasi `/qr` memakai qrcode existing untuk QRIS lokal. Muat ulang worker melalui supervisor tanpa menghapus sesi Baileys. Worker lama yang masih hidup belum memuat perintah/outbox baru.
4. `schedule:run` tiap menit / `php artisan schedule:work` saat lokal menjalankan `digitaltoko:commerce`. Command merekonsiliasi pembayaran, fulfillment, pending notice sekali, dan pengiriman notifikasi. Pemeriksaan manual `php artisan digitaltoko:commerce --limit=100`. Jangan menjalankan command pengujian terhadap toko nyata tanpa memahami bahwa pesanan lunas benar-benar akan diproses.
5. Admin → Modul pembayaran mengatur harga lisensi QRIS custom. Nilai awal 0 menutup penjualan; harga aktif minimal Rp1.000. Owner → Pembayaran pelanggan memilih SATU gateway: QRIS Midtrans dengan server key merchant toko dan sandbox/production, atau QRIS custom setelah invoice lisensi sah. Key/token/payload disimpan terenkripsi, tidak dimuat ke props Inertia.
6. Aktifkan QRIS pada merchant Midtrans dan atur Notification URL yang ditampilkan panel. Webhook memverifikasi signature lalu membaca status server Midtrans; JSON status dari browser tidak melunasi pesanan. Biaya sesuai tarif merchant diatur owner/staff berizin. Saldo topup dan refund produk Midtrans selalu mengecualikan fee.
7. QRIS custom: masukkan payload QRIS merchant valid (EMV/CRC/IDR), ambil token privat dari modal Hans Forward. Atur POST JSON, URL panel, Bearer token, sumber aplikasi yang sesuai, tanpa template khusus (field standar event_id/application_package/text/occurred_at; title opsional). Pilih teks gabungan bila judul berisi penanda pembayaran. Nominal dan waktu harus cocok dengan satu invoice. Perubahan QRIS/aplikasi penerima merotasi token; invoice lama memakai snapshot lamanya. Hindari memakai merchant QRIS yang sama pada sistem invoice lain di luar DigitalToko karena nominal unik lintas sistem tidak dapat dijamin.
8. Pelanggan membuka subdomain/custom domain toko → pilih produk → checkout. Guest tergantung kebijakan toko, WA wajib. Pendaftaran web membutuhkan nama, WA, email dan password; akun memakai WA OTP atau email/password/OTP email sesuai verifikasi. SMTP nyata diperlukan untuk OTP/notifikasi email, worker WA toko untuk OTP/notifikasi customer; owner/platform tetap bridge identitas Hanshop. Tidak ada fallback WA pusat tersembunyi. Email memakai platform atau SMTP toko. Akun dan wallet berlaku per toko, saldo owner Hanshop tidak menjadi saldo pelanggan.
9. Bot: `#beli KODE TUJUAN` menampilkan konfirmasi, tombol Saldo/QRIS atau format `#pesan KODE|TUJUAN|saldo|HARGA`. Pascabayar menjalankan inquiry dan membawa ID inquiry pada konfirmasi; gunakan format yang ditampilkan bot. `#topup 50000`, `#saldo`, `#status`, `#status NOMOR_PESANAN`. Command dan tombol membaca mesin yang sama dengan web.
10. Invoice biasa 15 menit; inquiry tagihan standar 5 menit dan harus dibayar pada hari yang sama (Asia/Jakarta). Bayar pasca memakai referensi inquiry; setelah kemungkinan dispatch hanya status-pasca, tidak mengulang pay-pasca. Format khusus multi-parameter seperti PBB/SAMSAT tidak disediakan oleh form standar dan harus diintegrasikan terpisah. Harga naik sebelum dispatch menggagalkan pesanan/refund, hasil ambigu tetap pending tanpa fallback. Pemeriksaan Digiflazz mendekati 90 hari dihentikan ke review agar pemeriksaan prabayar tidak membuat transaksi baru.
11. Owner/staff berizin membuka Transaksi pelanggan, Pelanggan & saldo, dan Pembayaran pelanggan. Kontak, hasil stok, kirim ulang, kebijakan fee, serta pencairan memiliki izin terpisah. Tindakan/modal dan daftar memakai pola UI existing, filter/pagination di atas dan bawah.
12. Penutupan manual menghentikan checkout/topup baru; penutupan final mensyaratkan saldo 0, tidak ada invoice/pesanan belum selesai, dan guest gagal terbayar sudah ditindaklanjuti. Pencairan hanya pencatatan uang yang telah diberikan manual, bukan API transfer. Pembayaran sah yang terlambat masuk review; owner bisa menyelesaikan topup atau menggagalkan/refund pesanan yang BELUM dikirim ke pemasok. Review pembayaran sebelum dispatch berbeda dari keputusan manual produk. Owner/staff orders.manage dapat memutus sukses/gagal transaksi Digiflazz eligible yang sudah dispatch, dengan alasan; webhook Digiflazz sah tetap dapat memperbarui status/SN dan merekonsiliasi refund. Notifikasi berhenti dikirim ke pelanggan bersaldo; perpanjangan membuka akses kembali tanpa mereset wallet/ledger/pesanan.

Validasi protokol mengacu dokumentasi resmi Midtrans QRIS, status transaksi/notification, serta Digiflazz topup, cek tagihan, bayar tagihan, dan cek status. Pengujian otomatis memakai HTTP palsu dan database terisolasi; pembayaran, OTP WhatsApp nyata, serta pembelian pemasok nyata tetap harus diuji dengan akun operator setelah konfigurasi. Penghapusan otomatis toko beserta data saat kewajiban belum selesai tidak diaktifkan oleh modul ini.


### Pengecekan akun game

Endpoint nickname default adalah `https://api.isan.eu.org/nickname` (tanpa API key). `GAME_IDENTITY_URL` dapat mengganti base URL bila memakai layanan lain yang kompatibel. Request dilakukan server-to-server dengan timeout 6 detik, cache bersama 180 detik melalui cache Laravel, penguncian request sejenis, dan pembatasan request. Pastikan cache/lock aplikasi tersedia. Tidak memerlukan migrasi baru.

Owner/staff berizin kelola produk mengatur game dan format tujuan pemasok melalui Layanan & kategori → Kategori utama → Edit → Pengecekan akun game. Deteksi otomatis memakai nama kategori yang dikenali; kategori yang tidak cocok dapat diberi game secara eksplisit atau dinonaktifkan. Mobile Legends/Magic Chess secara standar menggabungkan ID dan zona tanpa pemisah. Sesuaikan format jika pemasok meminta format lain.

Integrasi memanggil endpoint publik, tanpa menyalin implementasi worker pihak ketiga. Repositori referensi `https://github.com/ihsangan/valid` menyatakan lisensi CC BY-NC-SA 4.0; ketentuan pemakaian endpoint untuk bisnis perlu dikonfirmasi kepada penyedia.


## Pembaruan operasional source — 21 Oktober 2026

- Migrasi Laravel tambahan untuk pengirim toko dan voucher: `2026_10_20_071258_add_notification_settings_to_stores_table.php`, `2026_10_20_122931_create_discount_vouchers.php`. Terapkan `php artisan migrate --force` setelah backup. Data/kredensial memakai APP_KEY existing. Tidak ada migration baru yang dibuat oleh pembaruan dokumentasi ini.
- Trial baru tidak tersedia. Voucher platform 100% menghasilkan invoice Rp0 lokal tanpa memanggil billing Hanshop/QRIS; pembelian pertama tetap menunggu aktivasi. Invoice berbayar setelah diskon minimal Rp1.000. Kuota dan idempotency checkout tetap diperiksa; simpan request_id untuk replay invoice gratis.
- Restart bot manager dan worker existing setelah build; jangan menjalankan instance kedua. Restart queue worker agar layanan notifikasi/fulfillment terbaru dimuat. File sesi tetap dipertahankan.
- Mode Pengujian diatur di toko; selesaikan/batalkan order berjalan dan tunggu inquiry kedaluwarsa sebelum beralih. Digiflazz menggunakan development key/testing; sumber Hanshop dan stok premium disimulasikan. Simulasi bayar lokal ada pada halaman status pesanan yang eligible; Midtrans Sandbox bukan pembayaran uang nyata.
- URL payload Digiflazz yang berlaku: `{APP_URL}/api/commerce/digiflazz`, satu URL platform; secret per koneksi dan referensi attempt diverifikasi. Midtrans `/api/commerce/midtrans/{store}` dan Forwarder `/api/commerce/forward/{store}` per toko. Gunakan URL yang ditampilkan panel. Tunnel ngrok harus diterima sebagai host platform (APP_URL/domain/alias yang sesuai), bukan hanya mengganti payload URL di Digiflazz. Restart/clear config ketika konfigurasi URL berubah.
- Semua paket termasuk Web dapat pairing WA untuk notifikasi/OTP. Paket Web tetap tidak menjalankan transaksi chat. Menu Notifikasi & email memilih MAIL_* bawaan atau SMTP tenant; gunakan sender sah dan uji pengiriman secara terkontrol. Jalur OTP lokal tidak memerlukan callback HTTP sinkron worker → Laravel saat request kirim OTP sedang ditahan.
- Status/SN callback Digiflazz dapat mengoreksi hasil terminal/manual DigitalToko. Reclaim refund dapat membuat wallet customer minus; pesanan baru diblokir sampai saldo pulih. Penerapan API Hanshop memiliki aturan/marker tersendiri, lihat dokumentasi source API tersebut. Jangan menyamakan dua database atau menjalankan SQL API pada DB Laravel.
- API Hanshop memiliki migration manual `20261020_manual_result_supplier_callback.sql` dan `20261020_negative_balance_support.sql` untuk perubahan callback/ledger/saldo sumber API sendiri. Periksa prasyarat M9/M12, backup dan nama schema sesuai instruksi file sebelum deploy source terkait; pembaruan dokumentasi ini tidak menjalankannya.
- Retensi toko: source tersedia dan penghapusan bawaan nonaktif. Purge memerlukan ACK bot manager, disk lokal yang dapat dibersihkan, serta kewajiban nol. DNS/TLS eksternal dan backup/log bersama bukan target cleanup otomatis.
- Pembayaran uji/live, penerimaan OTP/email, ketahanan WA dan background Android harus diverifikasi di lingkungan tujuan. Jangan memakai hasil tes fixture sebagai bukti pembayaran/pesan nyata.

## Operasi retensi toko (21 Oktober 2026)

1. Backup database, pertahankan APP_KEY, deploy source/build lalu `php artisan migrate --force`. Restart queue worker dan scheduler long-lived setelah deploy.
2. Biarkan `STORE_PURGE_ENABLED=false`. Jalankan `php artisan digitaltoko:retention --preview` atau tambahkan `--store=UUID` untuk satu toko. Preview tidak mengubah data bisnis/mengirim notice. Hasil memuat tanggal, hold dan inventaris. Tersedia juga pada Admin → Kelola toko → detail toko.
3. `php artisan digitaltoko:retention` mengevaluasi status dan menyiapkan outbox tanpa menghapus. Jalankan queue dan scheduler agar notice diproses. Lokal memakai `php artisan schedule:work`; produksi schedule:run per menit sesuai supervisor/cron.
4. Sebelum mengaktifkan purge, tetapkan retensi backup/log bersama dan prosedur restore offline, periksa seluruh preview/hold, serta pastikan bot manager dan disk privat dapat dihubungi. Antrean database standar menggunakan database aplikasi (`jobs`/`failed_jobs`); Redis/SQS atau koneksi/tabel antrean khusus ditahan untuk operator.
5. Setelah operator memilih mengaktifkan, isi `STORE_PURGE_ENABLED=true`, bangun ulang config cache dan restart scheduler/queue. Jadwal per jam menambahkan --apply. Eksekusi terarah: `php artisan digitaltoko:retention --store=UUID --apply`. Flag berlaku bagi **seluruh toko yang layak**, bukan hanya toko pada command sebelumnya.
6. Tidak ada opsi melewati saldo/pending/hold. Selesaikan lewat settlement/guest dan invoice yang benar; jangan sekadar mengubah saldo menjadi nol lewat SQL. Renewal sebelum claim mempertahankan data/lisensi. Setelah purge_started_at terisi, toko dibekukan untuk cleanup; jangan mengosongkannya untuk mencoba memulihkan berkas yang mungkin sudah terhapus.
7. Jika cleanup gagal, panel menampilkan error. Perbaiki penyebab lalu jalankan ulang --apply. Bot manager harus memberi ACK sebelum data dihapus. Retry melanjutkan cleanup, bukan mengembalikan toko. Berkas/session tidak ikut rollback SQL. Diagnostik ada di log privat; jangan membagikan kredensial.

Purge menghapus data dan direktori **aktif** khusus toko. Snapshot database/media, log terpusat, ekspor dan salinan offsite milik operator tidak dibersihkan per toko oleh modul ini. Tetapkan batas umur, enkripsi dan pemusnahan backup; restore wajib offline dan direkonsiliasi dengan daftar toko yang telah dihapus sebelum trafik publik dibuka. Cache pemasok bersama tetap sampai TTL sesuai jenisnya (60–180 detik saldo/Hanshop, 300 detik katalog Digiflazz/URL OtomaX) tanpa flush global. Login owner dan identitas Hanshop dipertahankan; staff/customer yang telah dihapus tidak bisa diautentikasi. DNS/TLS provider dilepas lewat prosedur domain terpisah.

### Persetujuan penghapusan manual dari panel

Permohonan owner (termasuk toko belum pernah berlangganan) ada pada Admin → Kelola toko → pilih status **Menunggu penghapusan** → Kelola → **Tinjau & hapus toko**. Periksa preview dan kewajiban, ketik subdomain persis, lalu pilih **Hapus permanen**. Jika cleanup gagal, perbaiki bot manager/storage, muat ulang pratinjau dan pilih **Lanjutkan penghapusan**. Tidak perlu mengaktifkan STORE_PURGE_ENABLED atau menjalankan --apply untuk alur ini. Flag otomatis tetap boleh false. Scheduler tidak menyetujui permohonan manual secara otomatis. Tidak ada jalur memaksa melewati saldo/pending. Migration baru mengangkat toko suspended_manual existing menjadi permohonan yang dapat ditinjau admin.


## Supplier OtomaX (21 Oktober 2026)

- Deploy `composer install --no-dev --optimize-autoloader` (PhpSpreadsheet 5.10.0), build web, backup database, lalu `php artisan migrate --force`. Ada dua migrasi: tabel/profil/koneksi multi-supplier dan perluasan encrypted payload/snapshot untuk file besar. Jangan mengganti APP_KEY.
- Menu owner/staff: Produk & harga → Supplier OtomaX. Buat kelompok jawaban, tambahkan koneksi, uji saldo, ambil URL callback, lalu atur Daftar harga URL. Menu XLSX/CSV sementara disembunyikan; fondasinya tetap tersedia. Langkah rinci ada di [OTOMAX_PROGRESS.md](OTOMAX_PROGRESS.md).
- `QUEUE_CONNECTION=database` atau Redis, `DB_QUEUE_RETRY_AFTER=240` / `REDIS_QUEUE_RETRY_AFTER=240` (harus melebihi timeout job impor 180 detik). Jalankan `php artisan queue:work --timeout=180`, serta scheduler. Restart worker setelah deploy. Driver sync didukung untuk tes, tetapi impor besar sebaiknya antrean.
- `APP_URL` harus URL publik platform/URL ngrok yang aktif. Daftarkan URL callback **lengkap** dari modal, termasuk ID koneksi dan token, ke supplier. Route menerima GET/POST `/api/commerce/otomax/{connection}/{token}`; IP callback diisi sesuai IP pengirim nyata. Jangan menyalin URL/token ke log publik. Whitelist IP keluar VPS pada IP Center supplier jika diwajibkan. IP request transaksi dan IP callback bisa berbeda.
- Tidak ada polling pembelian ulang. Biarkan endpoint cek status kosong kecuali supplier menjamin endpoint tersebut read-only dan mendukung `cek=1`. Katalog/harga/ketersediaan diperbarui lewat daftar harga URL Webreport; lihat petunjuk sinkronisasi URL di bawah. Stok jumlah tetap tidak diketahui jika feed tidak melaporkannya.
- Semua paket platform memperoleh OtomaX, Digiflazz, API Hanshop, dan stok premium. Sandbox mensimulasikan transaksi/saldo/tiket tanpa request supplier. Tiket deposit tidak mentransfer uang atau menandai saldo masuk otomatis.
- Scheduler membersihkan file/payload pratinjau setelah dua jam. Riwayat ringkas dan callback mengikuti lifecycle toko; purge juga membersihkan file OtomaX dan job terkait. Tidak ada penghapusan log callback otomatis berdasarkan umur saat ini.


### Impor OtomaX besar dan penyimpanan worker

Hasil normalisasi Excel/CSV disimpan terenkripsi pada `storage/app/private/otomax/{store}/previews/{import}`; database hanya memuat metadata, path dan hash kecil. Mount disk `local` privat yang sama pada proses web dan queue jika keduanya berjalan di container/host berbeda, dan pertahankan APP_KEY. Jangan menaruh preview di disk publik. Setelah pembaruan ini restart worker/scheduler yang berjalan lama lalu unggah ulang file yang sebelumnya gagal. Tidak ada migrasi tambahan atau keharusan menaikkan max_allowed_packet untuk perbaikan ini. Impor selesai dan scheduler membersihkan berkas sesuai lifecycle toko/pratinjau.


## Sinkronisasi URL katalog OtomaX — 22 Oktober 2026

- Backup database/media privat, deploy source, jalankan php artisan migrate --force untuk 2026_10_22_121229_add_otomax_url_catalog_sync, lalu build web. Migrasi menambah konfigurasi URL terenkripsi/jadwal/revisi pada koneksi, penanda sumber riwayat dan metadata ketersediaan/harga. Tidak ada dependency Composer/NPM baru; ekstensi PHP DOM, libxml dan cURL diperlukan.
- Lokal: php artisan schedule:work dan php artisan queue:work --timeout=180 berjalan pada terminal terpisah. Restart proses queue:work lama setelah perubahan source/model; pada VPS gunakan pengelola proses dan php artisan queue:restart. retry_after existing 240 detik harus lebih besar daripada timeout worker 180 detik. Redis dan disk privat harus dapat ditulis/diakses worker dan web.
- Scheduler digitaltoko:otomax-catalogs berjalan tiap menit, hanya mengantrekan koneksi jatuh tempo. Pemeriksaan operator dapat memakai php artisan digitaltoko:otomax-catalogs --store=STORE_UUID. Command ini tidak mengunduh langsung dan bukan pengganti queue worker. Interval per supplier default 15 menit, pilihan 5/15/30/60 menit; shared snapshot URL 300 detik dan cooldown error lima menit.
- Panel Produk & harga → Supplier OtomaX → Daftar harga URL: masukkan URL ALL produk, atur interval/status otomatis, Uji URL, periksa pratinjau, lalu Simpan & sinkronkan. Jika jumlah produk turun drastis atau daftar valid benar-benar kosong, persetujuan eksplisit diperlukan. Produk baru berada di Sumber produk sebelum diimpor ke katalog jual. Riwayat berada pada tab Sinkronisasi harga.
- Staff suppliers.manage dapat menguji/mengganti/menjeda URL; suppliers.import dapat meminta sinkronisasi manual. URL tersimpan tidak dikirim kembali lengkap. Jangan memasukkan URL token asli ke Git atau log. Mode Pengujian tidak mengakses feed supplier nyata. UI impor berkas tetap disembunyikan, backend dan data lama dipertahankan.
- Backup lokal sebelum migrasi disimpan privat sebagai storage/app/private/backups/before-otomax-url-20261022-122029.jsonl.gz (schema dan raw rows 43 tabel, gzip JSONL). Bukan berkas publik dan tidak dimasukkan Git. Pengujian MySQL menggunakan database sementara terpisah, bukan data toko aktif.


## Paket berdasarkan platform — 23 Oktober 2026

Backup database sebelum deploy. Setelah source diperbarui, jalankan dari `apps/web`:

```sh
php artisan migrate --force
php artisan db:seed --class=PackagePlanSeeder --force
php artisan optimize:clear
npm run build
php artisan queue:restart
```

Migrasi `2026_10_23_003547_consolidate_platform_subscription_packages` menonaktifkan penawaran sumber terbatas dan memperbarui entitlement toko menjadi semua sumber tanpa mengubah masa aktif atau snapshot invoice. Seeder mempertahankan ID/harga/durasi/status penawaran terlengkap tiap platform dan menyiapkan penawaran nonaktif bila belum ada. Penawaran baru diatur di Admin → Paket & harga. Kolom internal `plan=galaxy` tetap dipakai; jangan menghapus penawaran lama yang direferensikan invoice/voucher. Rollback kebijakan memerlukan backup; `down()` tidak mengembalikan batasan sumber secara otomatis. Worker/scheduler proses panjang perlu dimuat ulang agar menggunakan kode terbaru.


## IP koneksi pemasok pada panel

Isi `SUPPLIER_OUTBOUND_IP` pada `.env` web dengan IP publik keluar VPS yang digunakan koneksi pemasok. IP ini muncul dengan tombol Salin IP pada popup Digiflazz dan tambah/edit supplier OtomaX. Jika kosong/tidak valid, panel menyatakan IP belum diatur; tidak menampilkan IP tebakan atau melakukan deteksi jaringan saat halaman dibuka.

`DIGIFLAZZ_OUTBOUND_IP` tetap didukung sebagai override khusus Digiflazz. Kosongkan override jika memakai IP bersama. Sesudah mengubah env di deployment yang memakai config cache, jalankan `php artisan config:cache`. Untuk instalasi tanpa config cache, `php artisan config:clear` cukup. Jangan mengisi IP contoh sebagai IP produksi. IP callback OtomaX adalah IP pengirim supplier dan tetap diatur terpisah.

## Deploy saldo & deposit pemasok — 26 Oktober 2026

1. Backup database web dan API Hanshop. Web: php artisan migrate --force (migration 2026_10_26_103804_create_supplier_monitoring_tables).
2. API Hanshop: jalankan manual scripts/migrations/20261026_digitaltoko_supplier_deposits.sql pada database API, setelah migrasi payment/ledger lama, khususnya 20260830_payment_reconciliation_worker.sql. Verifikasi SHOW CREATE TABLE digitaltoko_deposits; tabel payment wajib mempunyai created_at dan expires_at. Script baru bersifat additive, tidak mengganti saldo atau payment lama.
3. Build web (npm run build) dan API Hanshop (npm run build), restart API serta worker web. Scheduler web mengantrekan digitaltoko:supplier-wallets tiap dua menit; queue:work diperlukan. Rekonsiliasi mutasi API Hanshop harus aktif agar deposit terbayar mengkredit saldo lewat settlement asli.
4. Pada laptop PM2: pm2 restart api-hanshop digitaltoko-queue digitaltoko-scheduler setelah source/build/migrasi siap; jangan membuka queue/scheduler kedua. Restart service dilakukan operator pada waktu yang sesuai, bukan otomatis oleh proses validasi.
5. Owner membuka Produk & harga → Saldo & deposit pemasok; tambahkan koneksi melalui Sumber produk bila belum ada. Perbarui memanfaatkan cache akun; Topup membuat tiket/invoice saja dan tidak mentransfer dana. Hanshop khusus owner. Staff perlu suppliers.balance untuk modul dan suppliers.deposit untuk tiket pemasok toko lainnya.
6. OtomaX: periksa zona waktu dan profil tangkapan saldo_akhir/tanggal/jam. Instruksi tiket dapat tidak mempunyai penangkap khusus; teks supplier tetap dapat dibaca. Jangan menganggap instruksi atau saldo bertambah sebagai bukti deposit tertentu lunas. Digiflazz mengikuti pilihan bank/method yang tersedia pada jenis akun.

Migrasi lokal web dan API sudah diterapkan setelah backup privat pada 26 Oktober. Backup web: storage/app/private/backups/before-otomax-20261026-104954.sql (nama helper lama, berisi full database sebelum modul ini). Backup API: storage/app/private/backups/hanshop-before-supplier-deposits-1793011796448.sql. Tidak ada migrasi production, pembayaran, atau deposit nyata pada validasi.

### Tambahan prasyarat MySQL deposit Hanshop

Sebelum script 20261026_digitaltoko_supplier_deposits.sql, jalankan juga 20261026_digitaltoko_payment_runtime_columns.sql dari folder scripts/migrations API Hanshop. Script ini kompatibel MySQL dan aman dijalankan ulang bila expires_at/last_checked_at/index sudah ada. Backup dahulu. Verifikasi kedua kolom pada payment. Schema yang belum lengkap kini menghasilkan alasan khusus di rincian deposit. Permintaan lama yang tidak tercatat upstream tidak dibuat ulang oleh tombol Periksa; buat permintaan baru setelah penyebab diperbaiki.

## Deployment penanganan provisioning/refund

1. Backup web dan API. Web: php artisan migrate --force untuk create_provisioning_cases_table. API: jalankan manual scripts/migrations/20261026_digitaltoko_rental_refunds.sql pada database API, setelah digitaltoko_invoices dan saldo_ledger lengkap (termasuk reference_type/reference_id/entry_status dari migrasi ledger existing). Verifikasi SHOW CREATE TABLE digitaltoko_invoice_refunds.
2. Build web/API dan muat ulang proses API serta queue/scheduler yang berjalan. Jadwal digitaltoko:subscriptions tetap tiap menit, memantau kesiapan dan hanya memeriksa ulang refund yang sudah disetujui admin. Jangan menjalankan beberapa scheduler untuk profil yang sama.
3. Panel owner: Langganan & aktivasi → Penanganan persiapan toko. Panel admin: Kelola toko → filter Penyiapan toko / Detail toko → Penanganan persiapan toko. Admin harus meninjau alasan sebelum approval; retry memeriksa kesiapan tanpa membeli ulang atau mengaktifkan paksa.
4. Refund bukan transfer bank otomatis: total invoice termasuk kode unik masuk saldo Hanshop owner. Jangan menyetujui hanya karena owner belum menghubungkan WA atau berubah pikiran. Jika API timeout, gunakan penanganan invoice yang sama, jangan melakukan kredit saldo manual paralel. Refund invoice voucher Rp0 tidak menghubungi API dan tidak mengembalikan kuota voucher.
5. Status refunded bukan expired/cancelled biasa. Source menyertakannya pada label/filter invoice dan pengecualian kewajiban retensi; kasus review/refund berjalan tetap menahan penghapusan. Store/account lain tidak ikut berubah.

Backup lokal web: storage/app/private/backups/before-otomax-20261026-153139.sql (helper backup lama, dump seluruh DB). Backup API: storage/app/private/backups/hanshop-before-rental-refunds-1793028701103.sql. Eksekusi production tetap manual setelah backup, bukan bagian pengujian lokal.

### Penarikan QRIS WhatsApp setelah lunas

Untuk pembaruan ini, backup database web dan direktori sesi bot privat, jalankan php artisan migrate --force untuk 2026_10_27_082941_create_bot_qris_messages_table sebelum memuat build bot baru. npm run build pada apps/bot, kemudian reload manager/worker existing. Lokal telah dimigrasikan setelah backup penuh before-otomax-20261027-083229.sql. Tidak ada migrasi API Hanshop/dependency baru. Sertakan tabel bot_qris_messages pada backup web; APP_KEY diperlukan untuk membaca key pesan terenkripsi. File qris-message-outbox.json berada di folder sesi per toko dan ikut lifecycle/backup privat sesi.

Fitur hanya dapat menarik gambar QR yang key-nya dicatat sejak versi ini, bukan pesan lama yang ID-nya tidak pernah dicatat. Verifikasi produksi dengan pembayaran uji yang sah: gambar hilang setelah payment_status paid, bukti teks tetap, notifikasi pembayaran tetap sampai. Revoke mengikuti pembatasan WhatsApp; kegagalan akan dicoba kembali dan tidak dianggap sukses. Jangan menghapus semua pesan, mereset sesi, atau mengubah saldo untuk mencoba cleanup.


### Perluasan penghapusan pesan transaksi (2026-10-27)

Backup database web/sesi bot, lalu terapkan `php artisan migrate --force`, termasuk migrasi `2026_10_27_085410_add_order_context_to_bot_qris_messages_table`, sebelum memuat worker baru. Build `apps/bot` dengan `npm run build`, restart layanan bot manager yang sudah ada, dan minta queue memuat source baru melalui `php artisan queue:restart`. Jangan menghapus sesi atau outbox privat. Tabel lama sekarang juga mencatat pesan teks dan tombol per pesanan beserta revisinya; format registrasi QR lama tetap didukung. Tidak ada migrasi API Hanshop. Key pesan lama yang belum pernah dicatat tidak bisa dipulihkan dari fitur ini.


### Perbaikan expires_at MySQL (2026-10-27)

Backup database sebelum menjalankan migrasi 2026_10_27_094531_fix_customer_order_expiry_automatic_timestamp_updates. Pada MySQL yang membuat timestamp wajib pertama dengan ON UPDATE implisit, expires_at bisa berubah setiap update record. Migrasi mengubah kolom deadline customer_orders/customer_inquiries/otomax_imports menjadi DATETIME; hanya invoice biasa dengan pola korupsi yang dapat dibuktikan dipulihkan ke created_at +15 menit. Tidak mengubah status pembayaran/saldo, tidak menebak ulang batas inquiry. Verifikasi metadata kolom tidak memiliki on update dan uji update invoice tanpa menggeser tenggat. Tidak ada perubahan database API Hanshop.


### Keputusan pengelolaan VPS — 28 Oktober 2026

Gunakan PM2 yang sudah dipakai API Hanshop/bot Hanshop untuk queue:work, schedule:work dan bot manager DigitalToko juga. Tidak membutuhkan cron tambahan bila schedule:work sudah aktif. Aktifkan startup/resurrect PM2 pada host produksi dan verifikasi setelah reboot saat deployment; konfigurasi laptop tidak menggantikan langkah ini. Web produksi memakai Nginx/Caddy dan PHP-FPM, tanpa artisan serve/Vite development. Perubahan .env Laravel: config:clear lalu restart layanan yang perlu memuat ulang konfigurasi. Jangan menjalankan dua scheduler atau dua manager pada sesi yang sama.

### Kesehatan sistem admin — 28 Oktober 2026

1. Backup database web, lalu jalankan `php artisan migrate --force` (migrasi `2026_10_28_091338_create_system_health_tables`). Tidak ada perubahan database API Hanshop, dependency, atau konfigurasi rahasia baru.
2. Build web dengan `npm run build`, jalankan `php artisan queue:restart` agar observer heartbeat dimuat oleh worker existing, lalu muat ulang scheduler PM2 existing bila perlu. Jangan membuat worker/scheduler kedua. Collector memakai jadwal `system-health-heartbeat` setiap menit di `routes/console.php`. Lease collector disimpan di database (45 detik), sehingga pemeriksaan tidak bergantung pada mutex cache yang sedang diperiksa.
3. Masuk sebagai admin → **Kesehatan sistem** (`/admin/system-health`). **Periksa sekarang** menjalankan collector; checkbox **Auto refresh** membaca ulang props Inertia setiap 10 detik tanpa reload dokumen. Kedua tindakan tidak mengubah heartbeat scheduler/worker. Polling dijeda saat modal terbuka atau pemeriksaan/filter berjalan. Daftar utama memiliki filter/pagination atas dan bawah; rincian memakai modal.
4. Pemeriksaan CLI opsional: `php artisan digitaltoko:health-check`. Perintah ini mengumpulkan hasil, bukan menyatakan seluruh layanan pasti sehat. Tunggu jadwal berikutnya untuk bukti scheduler nyata; waktu pada panel memakai WIB, penyimpanan DATETIME UTC.
5. Kredensial existing tetap server-only: `BOT_MANAGER_URL` (loopback) + `BOT_MANAGER_TOKEN`, `HANSHOP_API_URL`, default cache dan `SUPPLIER_CACHE_STORE`, serta `QUEUE_CONNECTION`. Manager dipanggil melalui GET `/health`; API Hanshop GET `/health/ready` memeriksa database/Redis-nya. Tidak memanggil endpoint transaksi, deposit, login, OTP atau pengiriman email untuk menguji kesehatan.

Indikator: database menerima write collector; cache aplikasi **dan** cache pemasok menjalankan put/get/delete pada key acak berumur 30 detik. Scheduler sehat hanya jika callback jadwal benar-benar berjalan; bukti layanan inti berumur >3 menit menjadi perlu perhatian. Queue heartbeat hanya dicatat dari Looping worker pada connection/queue yang dikonfigurasi, dibatasi sekali per 30 detik, dan menjadi usang setelah 5 menit. Driver sync/null/deferred/background tidak membuktikan worker persisten. Collector mengirim maksimal satu job uji otomatis yang sedang menunggu; token mencegah penyelesaian ganda atau job lama menimpa uji baru. Pending >3 menit memberi peringatan; job uji gagal melepas status pending agar pemeriksaan berikutnya dapat mencoba uji baru. Tidak ada perubahan finansial pada job ini.

Statistik database queue membedakan siap, reserved dan delayed; siap >5 menit atau reservasi melampaui retry_after diberi petunjuk pemeriksaan. Redis memakai hitungan antrean tanpa membaca payload; daftar job per item dan waktu antrean tertua belum disediakan driver Redis. Failed jobs menampilkan nama proses, nomor, queue/connection, waktu, penyebab aman dan langkah pemeriksaan sesuai peran pada modal Rincian. Metadata payload dibaca terbatas sebagai JSON tanpa unserialize; exception hanya diterjemahkan berdasarkan prefix allowlist, tidak ditampilkan mentah. Kasus yang tidak dikenali diberi arahan mencocokkan nomor/waktu job dengan log server. Tidak ada tombol retry/purge/restart otomatis. Tinjau log dan idempotensi bisnis sebelum operator mencoba ulang job gagal. Jika job pemeriksaan ikut terhapus saat operator membersihkan antrean, gunakan **Uji ulang antrean** setelah pending >3 menit atau status uji gagal. Modal menjelaskan bahwa hanya satu job kesehatan baru dikirim; token lama diabaikan, job bisnis/transaksi tidak diulang. Aksi khusus admin dibatasi 2 kali/menit dan tidak dapat membanjiri antrean melalui polling otomatis.

WhatsApp: hanya toko eligible aktif/grace/provisioning, session connection_id harus cocok, status diperiksa dari cache heartbeat worker (TTL existing 90 detik). Umur bukti >120 detik/tanggal rusak/sesi tidak cocok ditandai belum terverifikasi; jumlah worker manager bukan bukti WA terhubung. Pemasok dipantau dari hasil sinkronisasi tersimpan, tanpa menambah hit daftar harga supplier. Digiflazz/Hanshop snapshot >10 menit memberi perhatian; OtomaX memakai jadwal koneksi + toleransi 5 menit. Pembaruan otomatis nonaktif ditandai belum terverifikasi, bukan dianggap sinkron berkala. Error mentah/URL katalog/credentials tidak disalin ke monitoring.

Callback Digiflazz/Midtrans/OtomaX/Forward dicatat sebagai agregat per menit: provider allowlist, kode HTTP, hasil accepted/ignored/review/error/rejected/redirected, jumlah dan waktu. Middleware global terminable juga mengamati 404 akibat host/rute aplikasi; tidak menyimpan URL/path ber-token, query, IP, header, body atau response message. HTTP 200 bukan bukti transaksi sukses. Panel meringkas 24 jam; collector membersihkan bucket >7 hari. Callback yang gagal sebelum mencapai Laravel (ngrok mati, proxy/firewall/DNS) tidak dapat tercatat di sini. Kegagalan pencatatan monitoring tidak mengubah respons callback.

Perubahan kelompok indikator gangguan/pemulihan mengirim satu notifikasi lonceng kepada akun admin, tanpa WA/email eksternal; indikator yang sama tidak membuat notifikasi baru tiap menit. Perubahan data toko dan transaksi tetap melalui modul existing. Riwayat failed job tersimpan dan callback bermasalah 24 jam tetap diperlihatkan meski layanan saat ini pulih.

Validasi lokal: backup penuh `storage/app/private/backups/before-otomax-20261028-093013.sql` sebelum migrasi; heartbeat scheduler/queue dan penyelesaian job uji teramati dari proses PM2 existing, manager/API Hanshop membalas sehat. Ini bukan bukti deployment VPS. Monitoring di aplikasi tidak dapat memberi alarm saat web/database aplikasi mati total atau host padam; pemantauan eksternal, backup/restore teruji, disk/CPU VPS, SMTP deliverability dan listener HP tetap cakupan operasional berikutnya.


### Pascabayar Digiflazz / pasangan OtomaX — 29 Oktober 2026

- Backup database web sebelum menjalankan `php artisan migrate --force --no-interaction`. Migrasi `2026_10_28_170706_add_postpaid_configuration_to_catalog_and_inquiries` menambahkan metadata billing sumber, pasangan cek/bayar pada parsing, serta status/koneksi inquiry. Tidak ada perubahan schema API Hanshop atau dependency baru.
- Build web/bot, lalu muat ulang queue/scheduler/bot manager existing. Jangan membuat manager kedua. Callback OtomaX memakai endpoint/token/IP allowlist yang sama dengan transaksi normal; inquiry dicocokkan ke toko, koneksi, RefID cek, kode cek dan tujuan. RefID bayar OtomaX terpisah agar tidak dianggap transaksi cek yang sudah pernah dilakukan.
- Sinkronkan ulang URL katalog OtomaX untuk membaca harga nol/minus sebagai metadata pascabayar (komisi negatif), bukan harga jual gratis. Import baru pascabayar OtomaX dinonaktifkan sampai owner memasangkan kode. Pada Katalog & harga → tambah/edit produk → Tagihan pascabayar → Sumber / parsing, pilih SKU bayar (mis. BYRPLN30), pilih SKU cek gratis dari supplier yang sama (CEKPLN30), dan isi admin bawaan. Simpan harga admin + markup pada tab Harga, lalu aktifkan produk. Produk cek saja jangan diaktifkan sebagai produk jual.
- Jawaban inquiry membutuhkan nama pelanggan, tagihan, admin, total tagihan dan periode jika tersedia. Contoh SN `NAMA:.../TAG:87684/ADM::2500/TTAG:90184 RC:00 INQUIRY` dikenali; alias ADMIN/PERIODE/PTAG juga didukung. Penangkap kustom dapat mengisi `nama_pelanggan`, `tagihan`, `admin`, `total_tagihan`, `periode`. Contoh penangkap “Cek tagihan OtomaX (RefID dari query)” disediakan pada editor. RefID query wajib cocok; teks R# kosong tidak boleh ditebak sebagai RefID lain. Bila format belum cocok/tidak lengkap, pembayaran belum dapat dibuat.
- Digiflazz memakai SKU pascabayar yang sama dengan commands `inq-pasca`, `pay-pasca`, `status-pasca`. Modal dari `price`, standar tagihan pelanggan dari `selling_price`; komisi tidak ditambahkan ulang. Markup persen admin toko dihitung dari komisi pemasok, bukan admin layanan atau pokok tagihan. Dokumen resmi: https://developer.digiflazz.com/api/buyer/cek-tagihan/ dan https://developer.digiflazz.com/api/buyer/bayar-tagihan/.
- Inquiry berlaku maksimal lima menit dan tidak melewati pergantian hari WIB. Saat OtomaX masih pending, web memeriksa inquiry tersimpan setiap lima detik, bukan mengirim ulang transaksi cek; bot menyediakan tombol Cek hasil tagihan. Callback cek hanya melengkapi quote, tidak melunasi/mengirim produk. Setelah quote disetujui, sumber pembayaran dibekukan pada sumber yang dicek; tidak berpindah ke pemasok yang tagihannya belum dikonfirmasi. Sandbox tetap simulasi lokal untuk OtomaX.
- Contoh: pokok100000 + admin3000, harga SKU bayar -500, markup1000 → pelanggan103500, modal102500, margin1000 sebelum fee gateway. Jika respons bayar memotong nominal berbeda, biaya nyata dicatat pada percobaan pesanan; total bayar tersebut tidak menggantikan metadata komisi katalog. Pembayaran berhasil bernilai nol ditahan untuk pemeriksaan, bukan dianggap lunas cek.

Migrasi lokal telah diterapkan setelah backup penuh `before-otomax-20261028-173617.sql` (nama berkas mengikuti jam host/UTC). Supplier nyata dan pengiriman WhatsApp nyata belum diuji pada tahap ini; gunakan mode pengujian atau uji terbatas yang disetujui owner setelah pemeriksaan pasangan SKU/penangkap.


Revisi hasil cek pascabayar (29 Oktober 2026): pada Supplier OtomaX → Kelompok jawaban → Tambah penangkap tersedia contoh **Pascabayar gagal (Sal / alasan)** untuk balasan awal/callback/cek status, serta **Cek tagihan berhasil (balasan awal)**. Pilih jenis jawaban sesuai sumber respons; nama pelanggan/tagihan/admin/total/periode dapat ditangkap dari SN atau field terpisah. Field **keterangan** berisi alasan gagal. Untuk format gagal PLN standar Sal/alasan fallback langsung tersedia tanpa mengubah profil tersimpan; pola lain tetap perlu disesuaikan dan diuji di Penguji jawaban. Tab **Respons & callback** menampilkan pesan lengkap cek sebelum order maupun callback transaksi; pelanggan menerima alasan yang aman. Tidak perlu migrate lagi untuk revisi pesan ini. Build web/bot dan muat ulang worker/manager agar source baru terpakai. Inquiry lama yang sudah tercatat tidak diubah/dikirim ulang secara massal.


Field keterangan inquiry kini tampil sesuai tangkapan pada pembeli web/bot, tidak hanya alasan bawaan. Atur keterangan hanya untuk informasi yang memang ditujukan kepada pelanggan; pesan penuh tetap pada Respons & callback. Build terbaru juga mengunci tombol cek dan menampilkan spinner selama HTTP/menunggu callback. Pemantauan pending memakai inquiry/key yang sama dan tidak mengirim perintah cek baru. Tidak perlu migrasi tambahan untuk revisi ini.


Keterangan transaksi produk (29 Oktober 2026): penangkap OtomaX field keterangan berlaku juga untuk prabayar dan aplikasi premium, baik balasan awal maupun callback/cek status. Field tersebut dipisahkan dari SN/data akun dan dari pesan teknis mentah supplier. Detail pelanggan/owner/staff, notifikasi dan #status bot menampilkan keterangan sumber aktif jika tersedia. Adaptor sumber lain menerima description/keterangan eksplisit; tidak mengubah otomatis message mentah mereka menjadi teks pelanggan. Build web/bot dan restart worker/manager terkait; tanpa migrasi tambahan. Keterangan lama tidak diisi ulang secara massal.


Pemisahan biaya pascabayar (29 Oktober 2026): admin layanan diisi sesuai produk PLN/bank/supplier (mis.2000), sementara markup menjadi admin toko terpisah. Basis persen direvisi menjadi komisi pemasok:5% dari komisi500 adalah25; saat komisi400 menjadi20. Admin layanan2000 tetap. Harga tetap pada katalog berarti total admin layanan+toko, bukan pokok tagihan. Contoh **Total biaya admin** menjadi2025, dengan rincian layanan2000/toko25. Penyesuaian pemasok memengaruhi modal; markup toko adalah margin di atas modal (lihat revisi bertanda di bawah). Bukti/hasil cek/bot menampilkan ketiganya terpisah dari gateway/kode unik. Build web/bot dan restart worker/manager; tidak memerlukan migrasi baru. Pesanan lama tidak dihitung ulang, rincian dinormalisasi hanya jika metadata pendukung tersedia.


### 2026-10-29 — Modal pascabayar bertanda + markup (revisi)

Harga pelanggan = pokok tagihan + admin layanan + penyesuaian modal pemasok + markup toko. OtomaX memakai adjustment asli: +500 menambah modal, -500 mengurangi modal. Digiflazz commission positif500 adalah pengurang modal (-500); hasil inquiry memakai price sebagai modal aktual, selling_price sebagai pokok+admin. Jangan menyamakan harga OtomaX positif dengan komisi keuntungan.

Contoh pokok100000/admin2000/markup1000: OtomaX -500 menghasilkan modal101500/pelanggan102500; +500 menghasilkan modal102500/pelanggan103500. Keuntungan sebelum biaya lain pada keduanya1000. Persentase memakai besar penyesuaian (abs), kemudian nominal markup ditambahkan ke modal yang tetap bertanda: -500 dengan5% menghasilkan modal101500/pelanggan101525; +500 menghasilkan modal102500/pelanggan102525. Kombinasi persen+nominal tetap didukung. Nilai nol menghasilkan persen nol, bagian nominal tetap berlaku.

Aktifkan pembaruan otomatis agar perubahan penyesuaian menghitung ulang katalog/inquiry baru. Harga tetap mempertahankan total biaya admin yang ditetapkan owner; markup efektif mengikuti selisih harga tetap dengan modal. Inquiry siap/pesanan terkonfirmasi tidak dihitung ulang. Pending membaca penyesuaian terbaru sebelum siap; pending tetap dideduplikasi tanpa cek ulang. Jika modal terbaru melampaui harga pesanan terkunci, jalur review existing tetap berlaku. Pemilihan sumber awal memakai biaya admin ditambah penyesuaian, bukan admin saja; failover pembayaran pascabayar belum ditambahkan.

Admin layanan tetap utuh; rincian pelanggan admin toko merupakan penyesuaian modal + markup (dapat negatif), sehingga pokok+admin layanan+admin toko sama dengan total produk. Markup keuntungan dan modal aktual tetap tersimpan privat; nilai sumber bertanda dijelaskan pada modal harga owner. Bukti lama dipertahankan, termasuk fallback rincian lama dari total snapshot. Tidak ada migrasi/dependency/database API Hanshop berubah pada revisi ini. Build web dan restart queue worker untuk memuat source baru.


### 2026-10-29 — Dasar harga parsing termahal, pemrosesan termurah

Harga jual otomatis prabayar/premium memakai modal tertinggi dari parsing aktif, tersedia, jumlah stok bukan nol, dan snapshot belum usang. Setelah basis dipilih, aturan markup tetap produk→kategori→layanan. Harga tetap (auto_update nonaktif) tidak diubah. Kandidat pemrosesan tetap diurutkan modal termurah dahulu; kegagalan pasti boleh lanjut sesuai alur existing, pending/hasil belum pasti tidak berpindah. Harga pesanan yang sudah dibuat tetap snapshot, tidak ditagih ulang ketika berpindah sumber. Jika modal naik lagi melampaui harga terkunci, pengaman harga existing tetap berlaku. Diskon/voucher existing tetap berlaku; pengaman modal tidak dihapus.

Untuk stok premium privat, dasar harga memakai stok ready dengan cost tertinggi; kandidat stok memakai cost terendah dan pengiriman tetap mengambil stok termurah secara atomik. Harga sumber premium eksternal dibandingkan dengan modal tertinggi stok ready, bukan modal stok termurah. Tambah/edit/hapus stok tetap menjalankan reprice existing.

Pascabayar membandingkan admin layanan + penyesuaian bertanda untuk memilih basis harga tertinggi. Markup persen memakai besar penyesuaian sumber basis itu; nominal/kombinasi tetap didukung. Katalog menampilkan total biaya admin; hasil inquiry menambahkan pokok tagihan aktual dan mempertahankan basis termahal tersebut, walaupun sumber cek/pembayaran yang dipilih lebih murah. Metadata aktual hasil cek menggantikan estimasi sumber yang dicek saat menentukan basis. OtomaX pending membaca ulang kandidat katalog lokal tanpa hit tambahan pemasok saat jawaban siap. Sumber pembayaran tetap sumber yang telah dicek; belum menambahkan failover pembayaran pascabayar. Komisi/modal privat tidak dikirim ke pembeli.

Tidak ada migrasi/dependency baru. Build web dan restart queue worker setelah deploy. Harga terhitung langsung dipakai katalog web/bot/checkout; nilai sale_price otomatis tersimpan saat reprice/sinkronisasi berjalan. Tidak mengubah invoice/pesanan lama atau harga tetap secara massal.


### 2026-10-29 — Pilihan dasar markup per produk

Owner/staff dengan izin pricing.manage dapat membuka Katalog & harga → Tambah/Edit produk → Harga → Dasar modal untuk markup, lalu memilih parsing termurah atau termahal. Berlaku bagi prabayar, aplikasi premium (sumber pemasok/stok privat), dan pascabayar. Markup nominal/persen/kombinasi tetap mengikuti prioritas produk→kategori→layanan; dasar sumber ditentukan per produk, tidak diwariskan dari aturan kategori/layanan. Pembaruan otomatis memakai pilihan tersebut; harga tetap mempertahankan nominal tersimpan. Inisialisasi harga produk baru tanpa nominal eksplisit juga memakai pilihan basis.

Migrasi 2026_10_29_064107_add_pricing_basis_to_catalog_products_table menambahkan pricing_basis (cheapest/highest, default highest). Produk lama tetap memakai termahal. Jalankan php artisan migrate --force, build web, lalu restart queue worker setelah deploy. Tidak ada perubahan database API Hanshop atau dependency baru.

Urutan candidates/pengiriman tidak berubah: modal termurah dahulu. Memilih basis termurah dapat membuat sumber cadangan lebih mahal daripada harga pesanan; pengaman modal tetap berlaku. Pemilihan basis tidak menambahkan failover pascabayar; pembayaran tetap pada sumber yang dicek. Inquiry pending menyimpan pilihan basis saat permintaan dibuat, sedangkan inquiry siap/pesanan lama tidak dihitung ulang. Tidak mengubah harga tetap secara massal.


### 2026-10-29 — Dasar markup dipindahkan ke Aturan harga

Bagian ini menggantikan pengaturan basis per produk di atas. Buka Katalog & harga → Aturan harga → Tambah/Edit aturan, tentukan kategori/layanan lalu pilih Dasar modal untuk markup: termurah, termahal, atau ikuti layanan/bawaan. Urutan pewarisan basis adalah kategori → layanan → highest. Basis ini independen dari nominal markup: produk yang memiliki markup sendiri tetap mengikuti basis kategori/layanan. Harga tetap (pembaruan otomatis nonaktif) tetap menggunakan nominal tersimpan.

Migrasi 2026_10_29_104937_add_pricing_basis_to_pricing_rules_table menambah pricing_rules.pricing_basis nullable. Nilai null pada kategori mengikuti layanan, nilai null pada layanan memakai default highest. Kolom lama catalog_products.pricing_basis dipertahankan sebagai data legacy tetapi tidak dibaca mesin harga; tidak ada override produk yang menghalangi aturan kategori/layanan. Kedua endpoint ubah produk menolak pengaturan basis per produk. Tidak menghapus nilai lama atau memindahkannya secara spekulatif ke aturan bersama karena beberapa produk dapat memiliki nilai berbeda.

Simpan/hapus aturan menjalankan reprice produk otomatis dalam toko. Web, bot, stok premium dan inquiry pascabayar memakai resolusi basis yang sama; kandidat transaksi tetap termurah dahulu. Inquiry siap/pesanan lama tetap terkunci. Jalankan migrate, build web, restart queue worker pada deploy. Tidak ada perubahan database API Hanshop atau dependency. Failover pembayaran pascabayar tetap belum ditambahkan.


### 2026-10-29 — Failover pembayaran pascabayar

Menggantikan batasan failover pascabayar pada catatan terdahulu. Inquiry baru menyimpan parsing eligible sebagai cadangan; checkout membuat percobaan sumber utama dan cadangan, dengan referensi/kredensial masing-masing. Pembayaran pending/timeout/tidak pasti tidak berpindah. Setelah gagal pasti melalui respons langsung atau callback, pemrosesan melanjutkan cadangan. Tidak membuat invoice atau mendebit pelanggan kembali; harga tetap snapshot yang disetujui.

Sebelum pembayaran cadangan, sistem menjalankan cek tagihan gratis khusus mapping itu. Pokok, admin layanan, nama pelanggan dan periode harus cocok dengan snapshot; nama mengabaikan kapital/spasi, periode mendukung YYYYMM, MM/YYYY dan nama bulan penuh Indonesia/Inggris. Format periode/nama lain yang tidak cocok dilewati secara konservatif. Modal aktual harus tidak melebihi subtotal pesanan; parsing dan koneksi harus masih aktif, produk tersedia, kredensial tidak berubah. Pergantian hari WIB menolak pembayaran cadangan. Digiflazz memeriksa ulang rincian sebelum pay-pasca; OtomaX menggunakan kode cek dan kode bayar masing-masing koneksi.

Cek OtomaX cadangan yang pending/timeout disimpan, tidak dikirim ulang otomatis. Scheduler digitaltoko:commerce melanjutkan setelah hasil cek siap (interval pemeriksaan minimal satu menit), atau melewati sumber bila hasil cek gagal/kedaluwarsa. Callback inquiry menggunakan endpoint OtomaX existing. Hasil cek internal terikat ke pesanan dan tidak dapat dipakai checkout lain. Setelah pembayaran benar-benar dikirim, otomatisasi hanya menunggu hasil/callback; tombol kirim ulang tetap memakai pengamanan existing.

Seluruh sumber gagal/tidak memenuhi syarat: pesanan gagal dan refund saldo pelanggan memakai idempotensi existing; guest mengikuti penyelesaian toko. Sukses terlambat dari sumber sebelumnya setelah berpindah diberi status review untuk pemeriksaan potensi pembayaran ganda; tidak mengirim pembayaran tambahan. Respons/rincian percobaan tampil pada detail transaksi existing. Tidak memigrasikan order/inquiry lama yang hanya memiliki satu sumber menjadi multi-sumber.

Tidak ada migrasi/dependency/API Hanshop berubah. Deploy source lalu jalankan php artisan queue:restart; pastikan queue dan scheduler berjalan. Validasi menggunakan HTTP fake, bukan pembayaran pemasok nyata.

## QRIS gambar dan distribusi HansDev Forwarder — 1 November 2026

Owner mengunggah gambar PNG/JPG/WebP maksimal5MB pada koneksi QRIS. Browser membaca QR lokal memakai jsqr (dependency disetujui owner), lazy-load ketika dipakai; server tetap memvalidasi payload/CRC dan menyimpannya terenkripsi. Gambar asli tidak disimpan atau diteruskan ke pembaca pihak ketiga. QRIS existing tidak wajib diunggah ulang. Build frontend dan restart queue long-lived setelah deploy; tanpa migrasi schema.

Unduhan `/downloads/hansdev-forwarder` kini berupa **ZIP lengkap**, menggantikan unduhan APK terpisah. Tetapkan `HANSDEV_FORWARDER_BUNDLE_PATH` atau salin paket ke default `storage/app/releases/HansDev-Forwarder.zip`. File biner diabaikan Git dan wajib disalin saat deploy; env kosong memakai default. Env APK_PATH dari revisi sebelumnya tidak digunakan untuk unduhan ini. Panel dan panduan hanya menampilkan tombol ketika ZIP tersedia; endpoint404 jika file belum tersedia.

Arsip dasar paket pengguna berasal dari `C:/Users/62878/Music/Hansdev Forwarder.zip` (41.474.142bytes; SHA256 `7d90711be2b677ecb6cbdd4e225d9b47986749b9bb298a33618c130a1bf8f01f`). Isi: HansDev-Forwarder.apk dan ZIP platform-tools Windows/macOS/Linux, masing-masing metadata37.0.1. APK SHA256 `dfaa7de5bd3b4915779fe996647dc03f2779217e0e831ada10e4176f93e62d6a`, sama dengan release1.0.3 ARM64 sebelumnya. Arsip utama lulus pemeriksaan CRC. Rilis terbaru menambahkan script aktivasi portable di setiap ZIP platform-tools: aktifkan-listener.cmd (Windows), aktifkan-listener.command (macOS), aktifkan-listener.sh (Linux). Seluruh berkas SDK dan APK asli tetap identik. Panduan menyediakan launcher dan alternatif perintah manual. Biner ADB tidak dijalankan, tidak ada izin HP yang diubah atau APK yang dipasang pada tahap dokumentasi ini. Jangan ikut menyebarkan keystore/key.properties.

Package notifikasi kini dapat diisi bebas dengan format Android yang valid, atau memakai nama aplikasi persis (pencocokan mengabaikan kapital/spasi tepi). Package lebih tepat karena nama bisa sama. Pengiriman Android mode POST JSON bawaan telah menyertakan application_package dan app_name; gunakan APK existing. Mengganti sumber/QRIS memperbarui token; invoice lama tetap memakai snapshot kredensial lama. Token hanya dibuka owner melalui POST privat dan disamarkan pada modal; tombol Salin token tersedia. Format teks tetap divalidasi sebagai pembayaran masuk dengan satu nominal Rupiah yang jelas, bukan setiap notifikasi aplikasi dianggap uang masuk.

Owner atau staff berizin `payments.confirm` dapat mengonfirmasi invoice custom yang belum dibayar dan tidak dibatalkan. Nominal harus sama total termasuk kode unik; waktu tidak boleh sebelum pesanan atau di masa depan; referensi mutasi dan catatan wajib. Invoice aktif diproses lewat CustomerCommerce::paid dan queue normal, bukan dipaksa sukses produknya. Invoice expired masuk review. Audit tersimpan pada snapshot privat dan tidak masuk DTO publik. Referensi manual diikat identitas merchant dan receipt unik; lock toko/order serta idempotensi paid mencegah debit/topup/pengiriman kedua saat klik berulang atau callback menyusul. Midtrans dan wallet tidak menerima konfirmasi manual ini.

Panduan tenant: `/panduan/pembayaran-custom`; pengaturan aplikasi sumber harus sama di panel dan HansDev Forwarder. Tidak ada perubahan API Hanshop, database utama, APK native atau izin perangkat pengguna pada tahap ini.


### Panduan listener USB — 1 November 2026

`/panduan/aktifkan-listener` berisi12langkah: ekstraksi ZIP bertingkat, APK, mode pengembang, USB debugging, RSA Allow, terminal per OS, adb devices, allow_listener, verifikasi izin/status, konfigurasi aplikasi, baterai dan uji/cabut kabel. Pilihan CMD/PowerShell/macOS/Linux mengubah blok perintah; tombol Salin perintah disediakan. Metode ini didasarkan pada perintah aktivasi source Android existing dan referensi resmi Android/AOSP, bukan klaim uji HP/OEM/macOS/Linux nyata. Masalah driver/udev/kebijakan perangkat tetap ditangani sesuai perangkat.

Unduhan ZIP memakai URL kanonis `/downloads/hansdev-forwarder.zip` (alamat lama tetap kompatibel). Tombol panel/panduan mengarah ke URL baru agar tidak memakai cache respons APK lama. Respons unduhan memakai private/no-store/no-cache/must-revalidate.


### Launcher listener portable (1 November 2026)

Ekstrak paket utama, lalu ZIP platform-tools sesuai OS. Windows: klik dua kali `aktifkan-listener.cmd`; macOS: buka `aktifkan-listener.command` atau jalankan `sh ./aktifkan-listener.command`; Linux: jalankan `sh ./aktifkan-listener.sh`. Lokasi folder bebas, termasuk drive selain C. Script berada di luar folder platform-tools, sejajar dengannya; pertahankan keduanya bersama; bukan perintah global PATH. Persetujuan USB/RSA pada HP tetap wajib, APK harus terpasang. Script menolak perangkat ganda, memverifikasi izin, lalu membuka aplikasi; status listener terhubung dan pengiriman tetap diperiksa di HP. Tidak mengubah token/endpoint atau memaksa pengiriman yang dijeda.

Bangun ulang paket menggunakan Python standar (tanpa dependensi):

```text
python deploy/package-forwarder.py --source "PATH/arsip-asli.zip" --output "apps/web/storage/app/releases/HansDev-Forwarder.zip"
```

Source dan output wajib berbeda. Arsip asli dipertahankan, pembaruan output dilakukan atomik. File APK/SDK/NOTICE asli dipertahankan; hanya satu launcher ditambahkan per ZIP OS. macOS/Linux memiliki mode executable 0755 dan LF, CMD menggunakan CRLF. Rilis lokal SHA256: `5335e75823625053a1745902dcc3abfcda7f50ce96f3da5bd66841907fcdebf2`. Artefak rilis tetap ignored Git; saat deploy, bangun/salin ZIP ke path konfigurasi bundle. URL unduhan tetap `/downloads/hansdev-forwarder.zip`.

Uji launcher: `python -m unittest discover -s deploy -p test_forwarder_launchers.py -v`. Windows dijalankan melalui CMD dengan executable ADB tiruan; macOS/Linux diuji melalui shell POSIX Git Bash di Windows dengan ADB tiruan. Tidak ada perintah yang dikirim ke HP, dan ini bukan bukti uji perangkat/macOS/Linux fisik. Driver, udev, Gatekeeper, dan pembatasan OEM tetap mengikuti sistem pengguna.


### Profil penangkap QRIS HansDev (2 November 2026)

Pembayaran pelanggan → Penangkap QRIS → Atur format. Satu profil per toko terikat sumber aplikasi gateway, maksimal10format dengan [nominal] wajib sekali; [pengirim], [referensi], [waktu], [abaikan] opsional. Isi literal minimal8karakter, nominal bilangan rupiah positif (format ribuan titik, desimal hanya ,00). Judul+isi dapat dipilih pada pengaturan tambahan; satu kata pengecualian per baris. Tidak menerima RegEx bebas. Status gagal/dibatalkan/berhasil dikirim selalu ditolak. Hasil berbeda dari beberapa format ditahan. Simpan memperbarui pembacaan invoice berjalan hanya jika sumber aplikasi snapshot sama; sumber berbeda memakai snapshot sebelumnya. Toko yang belum menyimpan profil mempertahankan pembaca lama.

Uji penangkap merupakan modal terpisah; Uji contoh pada editor memakai draf tanpa menyimpan. Uji tidak memeriksa invoice, sumber aplikasi maupun waktu dan tidak mengubah saldo/pembayaran. Webhook nyata tetap memeriksa token, sumber, merchant, nominal, waktu dan invoice. [waktu] hanya hasil tangkapan; waktu pencocokan tetap occurred_at dari HP. Referensi hasil tangkapan jika tersedia dipakai untuk deduplikasi merchant; jika tidak ada, event_id Android digunakan. Pengaturan disimpan pada credentials terenkripsi existing, tanpa migrasi/dependency baru. Staff perlu payments.parsers; owner tetap mengelola sumber/token gateway.


### Penangkap OtomaX: status dari pesan (2 November 2026)

Sesudah deploy, restart worker queue secara graceful (`php artisan queue:restart`) agar memakai parser terbaru. `outcome` lama diabaikan; pola tanpa status terambil/dikenali tetap pending. RegEx lama perlu named group `(?<status>...)`; untuk format satu pola sukses/gagal gunakan `[status]` pada template atau tangkapan status di mode pembatas. Template/kata kunci yang menulis status literal masih dapat membaca status literal yang benar-benar cocok. Tidak ada fallback parsing tersembunyi. Query status=20/40 hanya validasi silang, bukan pengganti penangkap. Pesanan lama memakai versi profil snapshot; jangan menganggap perubahan kelompok memperbarui pesanan lama otomatis. Jangan replay webhook atau ubah transaksi historis tanpa rekonsiliasi.

Preset Sudah pernah (status dari pesan) tersedia di Tambah penangkap. Sesuaikan event balasan awal/callback/cekstatus; tambah pola pada kelompok dan simpan sebelum membuat pesanan baru. Tidak ada migration database. Profil simulator yang baru dihasilkan sudah menangkap status pada RegEx; profil lab lama yang pernah tersimpan tidak ditimpa otomatis oleh lab:setup dan perlu disesuaikan atau diganti secara eksplisit sebelum run baru.
