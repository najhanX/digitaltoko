# DigitalToko web

Status source lokal: **3 November 2026**. Laravel 13 + Inertia 3 + React 19/TypeScript/Vite. Panel admin/owner/staff, landing dan storefront dijalankan satu Laravel. Worker WhatsApp berada di `../bot`.

## Menjalankan lokal

Dari folder `apps/web`:

```powershell
composer install
# Hanya jika belum ada .env: salin .env.example dan buat APP_KEY baru.
php artisan migrate
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Pertahankan APP_KEY yang sudah dipakai agar credential, stok dan data profil terenkripsi tetap dapat dibaca. Backup database sebelum migrasi pada lingkungan berisi data. Jangan memakai `migrate:fresh` untuk memperbarui instalasi existing.

Atur `APP_URL=http://localhost:8000`, `PLATFORM_DOMAIN=localhost`; alias loopback hanya berlaku saat local. `/login` untuk owner OTP, `/admin/login` untuk admin lokal, `/staff/{UUID_TOKO}/login` untuk staff lokal. Perubahan konfigurasi dapat memerlukan `php artisan optimize:clear` dan restart proses long-lived.

Alternatif praktis: profil laptop `pm2 start` mengelola web, Vite, queue, scheduler, bot manager, API Hanshop dan bot Hanshop sekaligus. Setelah launcher laptop dipasang, perintah tersebut dapat dipanggil dari folder mana pun. Tidak perlu menjalankan perintah manual di bawah bersamaan dengan PM2. Lihat [konfigurasi PM2](../../docs/DEPLOYMENT.md#pm2-satu-perintah).

Jika tidak memakai PM2, jalankan pada terminal/service terpisah:

```powershell
php artisan queue:work --timeout=180
php artisan schedule:work
```

Pastikan driver queue/cache/DB pada `.env` sesuai lingkungan. Queue dan scheduler dapat memproses pesanan lunas sungguhan; gunakan database fixture atau toko Pengujian untuk simulasi. Manager bot dijalankan dari `../bot`, bukan menggandakan proses pada port yang sama.

Untuk Redis: `REDIS_CLIENT=predis`, `SUPPLIER_CACHE_STORE=redis`, `SUPPLIER_CACHE_SECONDS=120`, serta host/port/password sesuai layanan. Paket Predis tersedia. Saldo dan katalog Hanshop memakai cache 60–180 detik per akun/operasi. Katalog Digiflazz memakai cache/cooldown 300 detik per akun/mode. URL Webreport OtomaX memakai snapshot terenkripsi 300 detik per URL lengkap/profil, dengan scheduler terpisah. Semuanya dilindungi lock. Jika cache gagal tidak ada bypass request langsung ke pemasok.

Preview `/preview/admin`, `/preview/owner`, `/preview/staff`, `/preview/storefront` hanya APP_ENV=local dengan `PLATFORM_PREVIEW_ENABLED=true`; ini data contoh, bukan autentikasi atau akun nyata.

## Data dummy

```powershell
php artisan db:seed --no-interaction
```

DatabaseSeeder menjalankan DemoStoreSeeder/PackagePlanSeeder dan CatalogDemoSeeder. Seeder demo menolak lingkungan selain local/testing, termasuk `--force`. Admin demo `demo-admin@example.test`, password awal admin/staff `DigitalToko-Demo2026!`; username staff manager/viewer/nonaktif lokal per toko. Owner dummy tidak ditautkan ke Hanshop dan tetap tidak dapat melewati OTP owner asli.

Toko demo: demo-aurora, demo-nebula, demo-atlas, demo-grace, demo-expired, **demo-bot** (bot berlangganan biasa), demo-draft dan demo-owner-two. Tidak membuat trial baru. Record existing tidak direset/dihapus saat seeder diulang, termasuk demo-trial lama bila pernah dibuat. CatalogDemoSeeder menambahkan katalog/stok simulasi tanpa memanggil pemasok. Produk tanpa parsing eligible dapat tampil tidak tersedia.

## Modul yang tersedia

- Owner: identitas/daftar Hanshop, profil dan email, KTP manual, toko draft, staff maksimal lima termasuk nonaktif. Admin password lokal dan guard staff terpisah.
- Admin: ringkasan, verifikasi, kelola/detail toko, koreksi paket/masa aktif, suspend/pulihkan dengan audit, paket/harga, pembayaran, voucher, harga modul custom.
- Langganan: invoice sewa Hanshop, aktivasi pertama dan otomatis 24 jam setelah siap, renewal/upgrade/downgrade, grace tujuh hari. Trial baru dihapus; voucher platform dapat membuat invoice gratis.
- Produk: sumber Digiflazz/Hanshop dan beberapa supplier OtomaX per toko, saldo pemasok, layanan/kategori/produk, impor semua/layanan/kategori/pilihan, parsing, aktif/nonaktif/hapus massal, markup nominal/persen/kombinasi dan stok privat.
- Penjualan: pesanan, detail/hasil, keputusan manual Digiflazz eligible, callback koreksi status/SN, pelanggan/wallet/ledger, pencairan manual saat berhenti, gateway dan voucher.
- Pembayaran: wallet, Midtrans merchant toko, QRIS custom berlisensi per toko; inquiry pasca Digiflazz dan pasangan SKU cek/bayar OtomaX, guest checkout, pembatalan invoice belum dibayar, topup/refund dan snapshot fee/kode unik.
- Sandbox toko: development key Digiflazz, saldo uji, sumber Hanshop/stok simulasi, bayar simulasi lokal atau Midtrans Sandbox. Perubahan mode ditolak bila masih ada pesanan/inquiry berjalan.
- WhatsApp toko untuk OTP, notifikasi dan transaksi chat termasuk satu paket sewa. Email memakai pengirim platform atau SMTP toko.
- Storefront: Aurora/Nebula/Atlas dengan susunan mobile khusus dan menu ringkas, pencarian dropdown infinite scroll, 24 preset tema (delapan terang/gelap/pastel), kategori/checkout, quick order Aurora ringkas dengan dropdown scroll dan loading minimal 500 ms, nickname/tujuan kategori, banner/warna/logo/favicon/section, flash sale, trending, iklan, CS, leaderboard, kalkulator, artikel dan akun pelanggan.
- Artikel: Tiptap lazy-loaded, JSON tervalidasi, draf/terbit, revisi konflik, media privat sesuai akses, tags/share/related. Warna isi publik mengikuti tema; editor memiliki latar putih.
- UI: sidebar compact/collapsed, terang/gelap/sistem, modal aksi, pencarian dan pagination tabel utama atas/bawah. Picker popup memakai dropdown overlay dan pemuatan lanjutan saat scroll. Staff maksimal lima tanpa filter/pagination. Notifikasi lonceng memakai infinite scroll; aksi menghasilkan toast.

Katalog publik dan bot memakai proyeksi yang sama tanpa credential/modal/stok rahasia. Sinkronisasi manual: `php artisan digitaltoko:catalog-sync --store=UUID_TOKO`; tanpa opsi store untuk semua koneksi eligible. Refresh pembaca mempertahankan snapshot saat gagal; sumber Digiflazz/Hanshop default lebih dari 600 detik tidak dapat dipesan. OtomaX memakai batas umur katalog per koneksi (default 24 jam, dibatasi 1–168 jam). Harga pasca pada katalog merupakan biaya admin, bukan total tagihan.

## Integrasi dan callback

- Bridge server-only Hanshop memakai `HANSHOP_API_URL` + `HANSHOP_IDENTITY_TOKEN`, cocok dengan `DIGITALTOKO_IDENTITY_TOKEN` API Hanshop. Tidak memberikan token bridge ke tenant/browser.
- DB Laravel harus terpisah dari DB Hanshop. Migrasi identitas/billing API Hanshop dijalankan manual dari `scripts/migrations/20261013_digitaltoko_identity.sql` dan `20261013_digitaltoko_billing.sql` setelah backup; Laravel memakai `php artisan migrate`.
- `BOT_MANAGER_URL`/`BOT_MANAGER_TOKEN` cocok dengan manager. WA customer dikirim melalui worker tokonya; owner/platform tetap jalur identitas Hanshop. Jalur OTP tidak melakukan HTTP balik worker ke Laravel agar server local satu proses tidak deadlock.
- Email bawaan memakai MAIL_* platform. Menu Notifikasi & email mengatur SMTP toko terenkripsi dan email uji. Status tersimpan bukan bukti pengiriman diterima.
- Payload URL Digiflazz adalah URL platform `/api/commerce/digiflazz`, tidak memakai UUID toko. Secret/signature dan referensi attempt menentukan penerimaan callback. Salin URL yang ditampilkan panel; hostname publik/ngrok harus diterima konfigurasi platform.
- Midtrans `/api/commerce/midtrans/{store}` dan Hans Forward `/api/commerce/forward/{store}` per toko; gunakan URL dan token dari panel. Status browser tidak melunasi invoice.

## Batas yang belum selesai

Pembaruan 28 Oktober: **Admin → Kesehatan sistem** tersedia di `/admin/system-health`. Mengamati layanan inti, antrean/failed jobs, koneksi WhatsApp, sinkronisasi pemasok dan callback. Collector berjalan tiap menit melalui scheduler existing; `php artisan digitaltoko:health-check` menjalankan pemeriksaan manual tanpa memalsukan heartbeat scheduler. Setelah migrasi, muat ulang worker memakai `php artisan queue:restart`. Tidak ada dependency baru, akses publik, retry transaksi atau restart layanan dari panel. Detail instalasi dan batas monitoring ada di `../../docs/DEPLOYMENT.md` bagian Kesehatan sistem.

Purge data/media/sesi toko sudah tersedia dengan hold kewajiban, pratinjau admin dan flag otomatis yang default-nya nonaktif. Refund sewa awal gagal provisioning sudah tersedia setelah persetujuan admin, dengan rekonsiliasi idempoten ke saldo Hanshop. Otomatisasi DNS/TLS provider, pelepasan sumber daya provider eksternal, backup/restore operasional dan hardening sesi WA multi-host tetap memerlukan tindak lanjut. Pembersihan KTP ditolak, upload artikel, dan endpoint membersihkan profil owner ketika tidak ada toko tersedia, berbeda dari purge toko. Topup Hanshop owner tersedia melalui Koneksi supplier → Saldo & deposit pemasok, terpisah dari wallet pelanggan. Label `customers.manage` belum menyediakan CRUD profil customer penuh.

Penutupan manual dan pemeriksaan kewajiban tersedia; pencairan hanya mencatat transfer yang dilakukan toko. Keputusan manual produk dibatasi Digiflazz eligible, bukan pembatalan sembarang request pemasok ambigu. Callback terverifikasi tetap dapat mengoreksi hasil.

## Pengujian dan referensi

```powershell
php artisan test --compact
npm run test:frontend
npm run build
```

Test integrasi MySQL tertentu bersifat opt-in/terisolasi; jangan mengarahkannya ke DB produksi. Bukti historis tidak sama dengan hasil pemeriksaan ulang hari ini. Lihat [arsitektur](../../docs/ARCHITECTURE.md), [aturan bisnis](../../docs/BUSINESS_RULES.md), [deployment](../../docs/DEPLOYMENT.md), [checkpoint](../../docs/NOTIFICATION_PROGRESS.md) dan [riwayat validasi](../../docs/FOUNDATION_VALIDATION.md).

CSS `resources/css/app.css` adalah entry Vite di head Blade, bukan hanya import JS, untuk menghindari flash tanpa style. Navigasi internal memakai Link Inertia; perpindahan hostname memakai anchor. `public/hot` mengarahkan browser ke dev Vite bila ada; pastikan dev server berjalan atau pakai build sesuai lingkungan.


## Sinkronisasi URL OtomaX

Aktifkan pada Supplier OtomaX → Daftar harga URL, isi URL ALL produk HTTPS, uji/pratinjau lalu Simpan & sinkronkan. Interval default 15 menit, pilihan 5/15/30/60. Riwayat ada di tab Sinkronisasi harga. Scheduler `digitaltoko:otomax-catalogs` berjalan tiap menit dan mengantrekan pekerjaan; `digitaltoko:catalog-sync` hanya untuk Digiflazz/Hanshop. `queue:work` harus berjalan dan perlu restart setelah deploy. PHP DOM/libxml/cURL serta disk privat bersama web/worker dibutuhkan; tidak ada dependensi baru pada tahap ini.

SKU hilang pada snapshot lengkap menjadi tidak tersedia, bukan dihapus. SKU yang kembali dapat tersedia lagi; harga nol/tidak valid ditahan. Penurunan besar/daftar valid kosong memerlukan konfirmasi. Harga manual, klasifikasi, gambar dan markup toko dipertahankan; harga callback yang lebih baru tidak ditimpa snapshot lama. Kegagalan jaringan/parser mempertahankan snapshot terakhir. URL akun tidak dikembalikan lengkap ke browser. Pengujian toko tidak mengambil feed OtomaX nyata. UI Excel/CSV tersembunyi, sedangkan fondasi dan data impor lama dipertahankan.

Untuk mengubah warna: Desain web → Logo & warna → Ubah. Filter Semua/Terang/Gelap/Pastel hanya memudahkan pemilihan; warna tetap dapat diedit manual. Terapkan ke desain lalu Simpan desain untuk menerbitkan. Katalog memakai `sale_price` naik sebelum pagination; harga promo akhir tetap dihitung oleh mesin katalog.

## QRIS HansDev — pembaruan 1 November 2026

Pembayaran pelanggan → Atur gateway menerima gambar QRIS langsung (PNG/JPG/WebP≤5MB), dibaca lokal oleh browser; pengguna tidak perlu mendapatkan payload sendiri. Menu Hans Forward menyediakan Download APK (ARM64) dan dropdown OS untuk mengunduh tools Windows/macOS/Linux secara terpisah, disertai panduan aktivasi listener USB. Salin APK serta tiga ZIP tools ke storage/app/releases ketika deploy; gunakan HANSDEV_FORWARDER_APK_PATH dan HANSDEV_FORWARDER_TOOLS_WINDOWS_PATH/MACOS_PATH/LINUX_PATH bila memakai lokasi khusus. HANSDEV_FORWARDER_BUNDLE_PATH tidak lagi dipakai. Biner tidak termasuk Git. Detail versi/hash dan penempatan file ada pada DEPLOYMENT.md.

Aplikasi penerima boleh memakai package Android lain atau nama aplikasi; pilih sumber yang sama pada Android. Format pesan pembayaran tetap diperiksa. Tombol Periksa pembayaran manual mengarah ke transaksi belum dibayar; di rincian transaksi tersedia Konfirmasi pembayaran QRIS bagi owner/staff berizin payments.confirm. Nominal/waktu/referensi/catatan dicatat dengan idempotensi; invoice expired masuk review, bukan otomatis dikirim ke pemasok. Tanpa migrasi schema atau perubahan APK native. Tutorial `/panduan/pembayaran-custom` mencakup seluruh alur.

Panduan `/panduan/aktifkan-listener` menjelaskan mode pengembang, USB debugging, persetujuan RSA di HP, perintah ADB sesuai terminal dan perbedaan akses notifikasi dengan listener terhubung. Tidak menjalankan aktivasi pada perangkat pengguna secara otomatis.


## Koneksi supplier dan sumber produk — 2 November 2026

Grup sidebar Koneksi supplier memuat API Hanshop (`/stores/{store}/supplier-connections/hanshop`), Digiflazz (`/stores/{store}/supplier-connections/digiflazz`), Supplier OtomaX (`/stores/{store}/otomax`), dan Saldo & deposit pemasok (`/stores/{store}/supplier-wallets`). Digiflazz/Hanshop memiliki modal koneksi; webhook Digiflazz juga berada di halaman ini. Staff berizin dapat membaca metadata koneksi, tanpa credential atau hak menggantinya. Izin pengelolaan OtomaX tetap memakai allowlist existing.

`/stores/{store}/sources` hanya memuat daftar/filter/pagination, sync katalog dan impor. Setelah filter sumber diterapkan, tombol impor semua/per layanan/per kategori tersedia untuk sumber aktif; impor checkbox tetap tersedia. Endpoint mutasi existing di bawah `/sources` dipertahankan; memindahkan UI tidak mengubah ID koneksi, mapping, callback URL atau kredensial yang tersimpan.

Penangkap QRIS berada pada Pembayaran pelanggan: profil dengan maksimal 10 format, [nominal] tepat sekali, placeholder opsional tanpa pengulangan, pilihan isi atau judul+isi, pengecualian dan penguji tanpa settlement. Staff perlu `payments.parsers`; melunasi penerimaan QRIS manual tetap memerlukan `payments.confirm`. Panduan `/panduan/pembayaran-custom` menjelaskan alur beserta screenshot. Deploy unduhan: HansDev-Forwarder.apk dan HansDev-Listener-windows.zip / -macos.zip / -linux.zip; alias ZIP gabungan lama mengarah ke panduan.


## Profil owner/pelanggan dan email wajib — 3 November 2026

- Owner: menu **Profil akun** di sidebar Ruang owner dan dropdown akun kanan atas membuka `/owner/account`. Form nama, WhatsApp, dan email memakai modal. Verifikasi identitas/KTP tetap di `/owner/profile`; nama akun tidak mengubah legal_name atau persetujuan KTP.
- Owner tanpa email valid/terverifikasi mendapat popup wajib pada panel. Escape/backdrop tidak menutupnya; tersedia Keluar, kirim OTP, verifikasi, serta ganti email/kirim ulang. Middleware menahan mutasi panel owner/store dan mengarahkan ke profil, kecuali endpoint OTP email. Admin, staff yang sedang bertindak sebagai staff, storefront, callback/API worker dan preview restore read-only tidak dikenai kewajiban ini. Toko aktif tidak dihentikan.
- Email owner tetap memakai OTP 5 menit dan sinkronisasi `/internal/digitaltoko/profile`. Nama/WA memakai bridge baru `/internal/digitaltoko/profile/contact`; server mengambil ID owner dari sesi, bukan ID dari form. WA baru diverifikasi lewat Hanshop pusat untuk identitas owner. Pengiriman email membutuhkan SMTP platform yang sudah dikonfigurasi; mail log/array ditolak di luar testing.
- Pelanggan membuka **Profil** dari dropdown storefront atau tab akun. Nama dapat diubah; WA baru memakai OTP dari bot toko dan email melalui pengirim toko/platform sesuai konfigurasi. Kontak lama tetap dipakai sampai verifikasi berhasil. Phone/email unik per toko, bukan global antartoko. Riwayat dan wallet tidak dibuat ulang. Saat WA toko belum terhubung, perubahan/verifikasi WA ditolak secara jelas; email tetap tersedia.
- Challenge terikat akun, tujuan, purpose dan toko (pelanggan), sekali pakai, kedaluwarsa 5 menit dan batas percobaan existing. Kontak duplikat diperiksa ulang sebelum disimpan; perubahan email/WA yang sudah berganti selama challenge berjalan ditolak. Sinkronisasi owner diserialisasi dengan cache lock; tidak mengubah saldo/API key/ID Hanshop.
- Deploy source web dan API Hanshop bersama, build keduanya dan restart API bila menjalankan hasil build. Tidak ada dependency/schema/migrasi baru. API tetap memerlukan migration existing `scripts/migrations/20261013_digitaltoko_identity.sql` untuk email.
- Validasi: suite web 733 tes (728 lulus, 5 opsional dilewati), suite API 221 tes (216 lulus, 5 opsional dilewati), frontend 40 lulus, TypeScript/Vite build dan Pint lulus. Tes profil mencakup email wajib/placeholder, OTP salah/kedaluwarsa/replay/lintas akun, konflik kontak, kegagalan sinkronisasi, saldo tetap, dan isolasi pelanggan antartoko. Browser memakai database simulasi: profil owner/pelanggan, modal dan menu akun, tema terang/gelap, Escape popup wajib dan batas layout 320/390 px. Pengiriman OTP nyata tidak dijalankan pada akun pengguna.


## Maintenance layanan dan template email

Menu admin `/admin/maintenance` dan owner `/stores/{store}/maintenance` menulis tabel `maintenance_windows`; maintenance lokal tidak memengaruhi toko owner lainnya. Waktu input WIB dikonversi UTC; batas aktif dicek di service setiap request, sehingga pembukaan otomatis tidak mengandalkan queue/scheduler. Halaman `/maintenance` menampilkan pesan, identitas toko, countdown, serta akses cek pesanan dan akun/riwayat. Inertia diarahkan dengan external-location, JSON menerima 503; panel tidak terkena gate storefront. Halaman tidak dicache. Seluruh callback API tetap melewati autentikasi/signature existing tanpa gate maintenance ini.

`StoreMaintenance::assertOpen` dipakai pada order/topup baru, inquiry publik, bot quote/buy/topup dan deposit supplier. Mesin pemenuhan/callback/payment-refresh existing tidak ditutup. Inquiry internal PostpaidFailover memakai konteks CustomerOrder paid tervalidasi agar pemrosesan lanjutan tetap berjalan. Store::acceptsOrders/canConnectBot tidak diberi flag maintenance sehingga sesi/notifikasi tidak terputus. Kewajiban email owner tetap berlaku pada panel.

`BrandedMail` + `MailBranding` menyiapkan layout `resources/views/mail/` untuk OwnerOtpMail, CustomerOtpMail dan StoreMessageMail. StoreMail memasang brand toko sebelum transport platform/custom mengirim; retention fallback juga membawa brand toko. Logo lokal memakai CID inline (preview render Laravel dapat mengubahnya menjadi data URI). Logo antartoko ditolak; tanpa logo memakai nama toko. Warna mengikuti desain toko dengan fallback tervalidasi, dan text/plain mempertahankan karakter kredensial. Uji menggunakan transport array/fake, bukan email/OTP nyata.

Migrasi: `php artisan migrate --force` sesudah backup. Tidak ada migrasi API Hanshop/dependency baru. Jalankan build web/bot dan restart worker setelah deploy. Source baru memerlukan tabel maintenance_windows; saat memulihkan snapshot versi lama, jalankan migrasi yang belum ada sebelum membuka layanan.

Validasi modul ini: 749 tes web (744 lulus, 5 opsional dilewati), 76 tes bot dan 40 tes frontend lulus; build web/bot, Pint dan diff check lulus. Tes mencakup batas antarowner, dampak global, tenggat tanpa scheduler, jendela tumpang tindih, admission bot/web, registrasi OTP yang terbit sebelum maintenance, callback pembayaran dan fulfillment stok lama, inquiry failover pascabayar paid, isolasi logo, escaping HTML dan karakter kredensial pada text/plain. Browser memakai data simulasi: modal admin/owner, halaman countdown, pembukaan otomatis sesudah tenggat, email platform/toko/notifikasi, dan batas lebar 320/390 px. Tidak menyalakan maintenance pada toko nyata, tidak melakukan pembayaran/OTP/email nyata.


### Perbaikan alamat logo maintenance — 4 November 2026

Logo media lokal pada MailBranding memakai path relatif storefront-media yang sama dengan storefront, sehingga halaman maintenance tidak bergantung pada IP/hostname APP_URL yang mungkin sudah berubah di laptop. Email tetap menyematkan file logo lokal sebagai CID; URL eksternal HTTPS tetap mengikuti konfigurasi. Uji regresi memeriksa gambar bisa diakses saat maintenance dengan APP_URL berbeda, serta CID, disposition inline, dan byte gambar pada MIME OTP/notifikasi. Validasi terkait: 25 tes lulus. Tidak ada perubahan data toko, SMTP, schema, atau pengiriman email nyata.
