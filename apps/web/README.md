# DigitalToko web

Status source lokal: **22 Oktober 2026**. Laravel 13 + Inertia 3 + React 19/TypeScript/Vite. Panel admin/owner/staff, landing dan storefront dijalankan satu Laravel. Worker WhatsApp berada di `../bot`.

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
- Pembayaran: wallet, Midtrans merchant toko, QRIS custom berlisensi per toko; inquiry pasca, guest checkout, pembatalan invoice belum dibayar, topup/refund dan snapshot fee/kode unik.
- Sandbox toko: development key Digiflazz, saldo uji, sumber Hanshop/stok simulasi, bayar simulasi lokal atau Midtrans Sandbox. Perubahan mode ditolak bila masih ada pesanan/inquiry berjalan.
- Kanal: WA toko untuk OTP/notifikasi termasuk paket Web, email platform atau SMTP toko, branding pesan per toko. Transaksi chat hanya paket Bot/Full.
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

Belum ada otomatisasi DNS/TLS/custom-host provider, purge seluruh data toko setelah 30 hari, penghapusan sesi/file/provider terkoordinasi, refund sewa gagal provisioning otomatis, dan hardening sesi WA multi-host. Pembersihan KTP ditolak, upload artikel, dan endpoint membersihkan profil owner ketika tidak ada toko tersedia, berbeda dari purge toko. Panel topup saldo Hanshop owner belum memiliki alur tersendiri. Label `customers.manage` belum menyediakan CRUD profil customer penuh.

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
