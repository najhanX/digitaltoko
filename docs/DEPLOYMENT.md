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


## Panel theme dan langganan (13 Oktober 2026)

- Panel admin/owner/staff dan login memakai palet indigo/cyan dengan pilihan terang, gelap, atau sistem. Preferensi localStorage `digitaltoko.panel.theme` hanya memengaruhi panel; template storefront tetap milik tenant. Bootstrap tema berjalan sebelum stylesheet/SSR agar tidak flash warna terang.
- Jalankan migrasi Laravel `2026_10_13_161039_create_subscription_billing_tables` setelah backup. Seeder menyediakan sembilan penawaran **nonaktif** seharga dummy Rp50.000/30 hari. Admin harus mengatur harga sebenarnya dan mengaktifkan penawaran pada `/admin/plans`.
- Pada API Hanshop, terapkan `scripts/migrations/20261013_digitaltoko_billing.sql`, build source, lalu restart proses yang Anda kelola. Development `npm run dev` memuat source; jangan menjalankan instance kedua pada port 4000.
- Gateway memakai URL/token bridge existing, QR_DYNAMIC/Cloudinary existing, dan tabel mutasi dari forwarder. `/internal/digitaltoko/billing/invoice` hanya server-to-server. ID invoice menjadi kunci idempotensi; nominal dan akun yang berubah pada ID yang sama ditolak. Pembayaran sewa tidak mengkredit saldo Hanshop.
- Laravel cron `schedule:run` per menit menjalankan `digitaltoko:subscriptions`; saat development jalankan `php artisan schedule:work` di terminal Anda. Ini memeriksa pembayaran, aktivasi 24 jam setelah siap, downgrade terjadwal, dan grace. Tombol cek pembayaran juga memeriksa invoice tanpa menunggu cron.
- Pembayaran pertama menunggu konfirmasi aktivasi. Web siap ketika slug tersedia; Bot/Full perlu heartbeat CONNECTED worker per toko. Owner/staff berizin sekarang dapat menghubungkan worker lewat menu Koneksi WhatsApp menggunakan QR atau kode pairing; panduan manager di bawah.
- Upgrade penuh berlaku saat lunas; kanal bot yang baru ditambahkan tetap membutuhkan worker tersambung untuk beroperasi. Downgrade (mengurangi kanal/sumber) berlaku setelah periode existing selesai. Trial Bot Jupiter 7 hari sekali per owner, tanpa grace; paid conversion tidak menambahkan sisa trial.
- Invoice ambigu masuk REVIEW dan tidak mengaktifkan toko. Pembayaran setelah invoice sudah EXPIRED tidak otomatis diterapkan; perlu pemeriksaan/refund operator. Jangan memalsukan status lunas dari browser. Rekonsiliasi gateway tidak membuat transaksi produk atau mengubah saldo pelanggan.
- Nominal unik sewa +1 sampai +999 dialokasikan bersama gateway existing dengan named lock. Nominal historis tidak didaur ulang untuk mencegah mutasi lama membayar invoice baru; jika seluruh rentang telah digunakan, pembuatan invoice ditolak dan perlu penanganan operator.
- Scheduler billing belum menghapus toko setelah 30 hari. Penghapusan/DELETION_HOLD menunggu modul saldo pelanggan dan kewajiban transaksi; data tetap disimpan agar tidak terhapus tanpa pemeriksaan kewajiban.

## Koneksi WhatsApp dari panel (14 Oktober 2026)

1. Backup database DigitalToko, kemudian `php artisan migrate --force --no-interaction` untuk kolom nomor/metode/ID koneksi pada toko.
2. Buat token acak khusus manager, minimal 32 karakter. Atur nilai yang sama pada `BOT_MANAGER_TOKEN` di `apps/web/.env` dan `apps/bot/.env`. Laravel menggunakan `BOT_MANAGER_URL=http://127.0.0.1:3099`, manager memakai `MANAGER_PORT=3099`. Jangan tampilkan token pada frontend atau membuka port manager ke publik.
3. Atur `WEB_BASE_URL` pada bot ke origin Laravel yang dapat diakses worker dan diterima `platform.host`. Semua toko menggunakan backend ini; domain tidak di-hardcode. Setelah pindah domain, ubah URL environment dan restart manager/PHP sesuai konfigurasi.
4. Jalankan `npm ci`, `npm run build`, lalu `npm run manager` dalam `apps/bot`; contoh PM2 sekarang menjalankan `dist/manager.js`. Jangan menjalankan PM2 mode cluster atau dua manager pada registry/sesi yang sama. Atur izin folder sesi privat untuk user layanan bot; mode file Unix 0600/0700 tidak menggantikan ACL Windows.
5. Owner memilih toko → Langganan & aktivasi → Koneksi WhatsApp → pilih metode dan nomor → tautkan di HP. Status CONNECTED memungkinkan konfirmasi aktivasi. Staff dengan `bot.manage` dapat menghubungkan/lepas koneksi, sedangkan `bot.view` hanya melihat status. Staff yang hanya mempunyai izin bot diarahkan langsung ke menu koneksi setelah login.
6. Toko draft perlu bayar atau trial terlebih dahulu. Bot berhenti saat akses berakhir; masa tenggang berbayar tetap tersedia tujuh hari. Token dan ID percobaan mencegah worker/sesi lama memperbarui percobaan baru. QR/kode dihapus dari respons ketika kedaluwarsa atau sudah terhubung.

Registry manager dan auth Baileys saat ini berada pada file privat per toko, dengan atomic writer untuk creds. Operasi multi-host, penyimpanan auth/key di database, pemulihan penuh setelah disk rusak, dan integrasi purge toko masih memerlukan tahap hardening. Jangan menyalin sesi bot Hanshop ke worker tenant. Panduan rinci dan batas modul bisnis: `apps/bot/README.md`.

## Sumber produk dan katalog (14 Oktober 2026)

1. Backup database DigitalToko, jalankan `composer install`, `php artisan migrate --force --no-interaction`, dan `npm run build` di apps/web. Migration katalog bersifat additive dan tidak mengubah database/saldo Hanshop.
2. Gunakan `REDIS_CLIENT=predis` (dependensi Composer sudah disetujui/ditambahkan), `SUPPLIER_CACHE_STORE=redis`, `SUPPLIER_CACHE_SECONDS=120`, dan `SUPPLIER_MAX_SNAPSHOT_AGE=600`. Pertahankan APP_KEY untuk dekripsi credential serta stok privat. Redis harus terjangkau PHP/CLI.
3. Build/restart API Hanshop yang memuat bridge supplier read-only, dengan `HANSHOP_API_URL` dan `HANSHOP_IDENTITY_TOKEN` existing. Tidak ada migration Hanshop baru untuk pembacaan katalog ini. Token tetap server-only dan komunikasi HTTPS/jaringan privat.
4. Cron `schedule:run` setiap menit menjalankan sinkron katalog setiap dua menit, tanpa overlap. Saat lokal: `php artisan schedule:work`. Sinkron manual per toko: `php artisan digitaltoko:catalog-sync --store=UUID_TOKO`. Tidak ada transaksi atau perubahan saldo saat sinkron.
5. Owner hubungkan sumber dan sinkronkan mode prabayar/pasca yang dibutuhkan; scheduler mengulang mode yang sudah dipilih. Sembilan paket publik membatasi sumber milik toko sesuai rinciannya; Hanshop tersedia pada semua paket aktif. Stok lokal tetap privat per toko.
6. Build bot dan restart manager yang sudah ada melalui supervisor/PM2 agar handler #produk baru dimuat, tanpa menghapus registry/sesi atau membuat instance kedua.
7. Periksa impor produk, aturan harga, stok, katalog publik dan #produk. Jika cache/provider gagal, snapshot tidak dihapus dan sumber remote yang berumur lebih dari 600 detik dinyatakan tidak tersedia. Stok lokal yang masih ready tetap dapat ditampilkan.

Katalog publik tidak memanggil provider langsung. Pasca hanya menampilkan biaya admin; inquiry/checkout/fallback dispatch serta pengiriman stok belum termasuk tahap ini.

## Pembaruan nama paket planet (15 Oktober 2026)

Jalankan `php artisan db:seed --class=PackagePlanSeeder --no-interaction` setelah backup plan_options. Seeder mengganti nama penawaran legacy Orbit/Space/Galaxy sesuai kategori/sumber, sambil mempertahankan ID, harga, durasi, status aktif dan snapshot invoice/langganan. Penawaran khusus yang tidak memakai nama legacy tetap dipertahankan. Jika suatu kombinasi belum ada, dibuat penawaran awal Rp50.000/30 hari **nonaktif** untuk diatur admin. Dapat dijalankan ulang; tidak perlu migrate:fresh.

Frontend dan PHP membaca satu katalog JSON bersama. Deploy juga file resources/js/packageCatalog.json, jalankan npm run build dan muat ulang konfigurasi Laravel jika memakai config cache. Trial bot penuh kini ditampilkan sebagai Jupiter. Nama di invoice tersimpan tetap merupakan bukti historis; panel menampilkan nama paket baru berdampingan dengan nama penawaran historis bila diperlukan.


## Transaksi pelanggan web dan bot (15 Oktober 2026)

1. Backup database, pertahankan APP_KEY, lalu jalankan migrasi dan build web/bot. Migration `2026_10_15_065647_create_customer_commerce_tables` hanya menambahkan tabel pelanggan, invoice/pesanan, ledger, percobaan pemasok, notifikasi, dan lisensi QRIS custom. Database pelanggan tidak digabung dengan akun owner Hanshop.
2. Build/restart API Hanshop dengan bridge internal `/internal/digitaltoko/supplier/transaction` dan `/internal/digitaltoko/message`. Token layanan existing tetap server-only. Jembatan transaksi memakai preparation/orchestrator/idempotency Hanshop; tidak perlu skema Hanshop tambahan di tahap ini. Jangan menyalin API key owner ke browser atau bot pelanggan.
3. Jalankan SATU bot manager pada port existing (`npm run manager` sesudah build). Endpoint loopback terautentikasi `/qr` memakai qrcode existing untuk QRIS lokal. Muat ulang worker melalui supervisor tanpa menghapus sesi Baileys. Worker lama yang masih hidup belum memuat perintah/outbox baru.
4. `schedule:run` tiap menit / `php artisan schedule:work` saat lokal menjalankan `digitaltoko:commerce`. Command merekonsiliasi pembayaran, fulfillment, pending notice sekali, dan pengiriman notifikasi. Pemeriksaan manual `php artisan digitaltoko:commerce --limit=100`. Jangan menjalankan command pengujian terhadap toko nyata tanpa memahami bahwa pesanan lunas benar-benar akan diproses.
5. Admin → Modul pembayaran mengatur harga lisensi QRIS custom. Nilai awal 0 menutup penjualan; harga aktif minimal Rp1.000. Owner → Pembayaran pelanggan memilih SATU gateway: QRIS Midtrans dengan server key merchant toko dan sandbox/production, atau QRIS custom setelah invoice lisensi sah. Key/token/payload disimpan terenkripsi, tidak dimuat ke props Inertia.
6. Aktifkan QRIS pada merchant Midtrans dan atur Notification URL yang ditampilkan panel. Webhook memverifikasi signature lalu membaca status server Midtrans; JSON status dari browser tidak melunasi pesanan. Biaya sesuai tarif merchant diatur owner/staff berizin. Saldo topup dan refund produk Midtrans selalu mengecualikan fee.
7. QRIS custom: masukkan payload QRIS merchant valid (EMV/CRC/IDR), ambil token privat dari modal Hans Forward. Atur POST JSON, URL panel, Bearer token, sumber aplikasi yang sesuai, tanpa template khusus (field standar event_id/application_package/text/occurred_at; title opsional). Pilih teks gabungan bila judul berisi penanda pembayaran. Nominal dan waktu harus cocok dengan satu invoice. Perubahan QRIS/aplikasi penerima merotasi token; invoice lama memakai snapshot lamanya. Hindari memakai merchant QRIS yang sama pada sistem invoice lain di luar DigitalToko karena nominal unik lintas sistem tidak dapat dijamin.
8. Pelanggan membuka subdomain/custom domain toko → pilih produk → checkout. Guest tergantung kebijakan toko, WA wajib. Akun memakai WA OTP; email/password diaktifkan sesudah verifikasi email. SMTP nyata diperlukan untuk OTP/notifikasi email, bot Hanshop untuk OTP WA dan fallback notifikasi. Akun dan wallet berlaku per toko, saldo owner Hanshop tidak menjadi saldo pelanggan.
9. Bot: `#beli KODE TUJUAN` menampilkan konfirmasi, tombol Saldo/QRIS atau format `#pesan KODE|TUJUAN|saldo|HARGA`. Pascabayar menjalankan inquiry dan membawa ID inquiry pada konfirmasi; gunakan format yang ditampilkan bot. `#topup 50000`, `#saldo`, `#status`, `#status NOMOR_PESANAN`. Command dan tombol membaca mesin yang sama dengan web.
10. Invoice biasa 15 menit; inquiry tagihan standar 5 menit dan harus dibayar pada hari yang sama (Asia/Jakarta). Bayar pasca memakai referensi inquiry; setelah kemungkinan dispatch hanya status-pasca, tidak mengulang pay-pasca. Format khusus multi-parameter seperti PBB/SAMSAT tidak disediakan oleh form standar dan harus diintegrasikan terpisah. Harga naik sebelum dispatch menggagalkan pesanan/refund, hasil ambigu tetap pending tanpa fallback. Pemeriksaan Digiflazz mendekati 90 hari dihentikan ke review agar pemeriksaan prabayar tidak membuat transaksi baru.
11. Owner/staff berizin membuka Transaksi pelanggan, Pelanggan & saldo, dan Pembayaran pelanggan. Kontak, hasil stok, kirim ulang, kebijakan fee, serta pencairan memiliki izin terpisah. Tindakan/modal dan daftar memakai pola UI existing, filter/pagination di atas dan bawah.
12. Penutupan manual menghentikan checkout/topup baru; penutupan final mensyaratkan saldo 0, tidak ada invoice/pesanan belum selesai, dan guest gagal terbayar sudah ditindaklanjuti. Pencairan hanya pencatatan uang yang telah diberikan manual, bukan API transfer. Pembayaran sah yang terlambat masuk review; owner bisa menyelesaikan topup atau menggagalkan/refund pesanan yang BELUM dikirim ke pemasok. Pending yang sudah dispatch tidak dapat dipaksa gagal lewat tindakan tersebut. Notifikasi berhenti dikirim ke pelanggan bersaldo; perpanjangan membuka akses kembali tanpa mereset wallet/ledger/pesanan.

Validasi protokol mengacu dokumentasi resmi Midtrans QRIS, status transaksi/notification, serta Digiflazz topup, cek tagihan, bayar tagihan, dan cek status. Pengujian otomatis memakai HTTP palsu dan database terisolasi; pembayaran, OTP WhatsApp nyata, serta pembelian pemasok nyata tetap harus diuji dengan akun operator setelah konfigurasi. Penghapusan otomatis toko beserta data saat kewajiban belum selesai tidak diaktifkan oleh modul ini.
