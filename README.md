# DigitalToko — workspace Hansbot

Dokumentasi disinkronkan dengan source lokal pada **3 November 2026**. Ini status implementasi, bukan pernyataan bahwa seluruh perubahan telah dideploy atau diuji pada layanan produksi.

DigitalToko adalah platform SaaS multi-store dengan satu paket Web Topup + Bot WhatsApp. Pilihan sewa dibedakan berdasarkan durasi. Satu owner dapat memiliki banyak toko; langganan, katalog, stok, pelanggan, saldo, pesanan, konfigurasi dan sesi WhatsApp dipisahkan per toko.

## Peta proyek

| Folder / proyek | Tanggung jawab |
| --- | --- |
| `apps/web` | Laravel + Inertia React: landing, admin, owner, staff, storefront, backend bisnis dan scheduler |
| `apps/bot` | Node/TypeScript + Baileys: satu manager dan worker/sesi terpisah per toko |
| `apps/storefront` | Catatan lokasi; halaman publik dijalankan oleh `apps/web`, bukan deployment kedua |
| `assets` | Logo/branding dan bukti tampilan lokal |
| `deploy` | Contoh Nginx dan PM2; harus disesuaikan dengan server tujuan |
| `supplier-simulator` | Git terpisah, diabaikan Git utama: tiga supplier OtomaX sintetis, panel 4100, dan dua toko uji terisolasi pada port 8012. Jalankan `npm run lab:setup` lalu `npm run lab` dari foldernya; lihat README simulator. |
| `../API Hanshop` | Backend Hanshop: identitas/saldo owner, sumber produk, bridge dan pembayaran sewa |
| `../bot_wa_v2` | Bot toko Hanshop sendiri, terpisah dari worker tenant |
| `android-listener` | Proyek eksternal HansDev Forwarder di direktori HansDev/projects: notifikasi pembayaran HP ke server |

## Implementasi yang tersedia

- Onboarding owner lewat bridge identitas Hanshop, profil/email, KTP manual, toko draft, serta maksimal lima staff lokal per toko.
- Satu paket Web Topup + Bot WhatsApp, variasi hanya durasi. Semua toko mendapat Digiflazz, API Hanshop, OtomaX dan stok premium. Harga/durasi berasal dari admin. Penawaran bot/web lama dinonaktifkan untuk pembelian baru; invoice historis dan masa aktif dipertahankan. Trial baru tidak tersedia; voucher platform mendukung diskon 100%.
- Admin mengelola toko, paket, verifikasi, pembayaran, voucher dan harga modul. Penangguhan admin dan koreksi paket/masa aktif memiliki audit.
- Sumber Digiflazz/Hanshop serta multi-supplier OtomaX melalui IP Center dan sinkronisasi URL Webreport, cache akun bersama Redis, layanan → kategori → produk, impor semua/per layanan/per kategori/pilihan, parsing, markup dan stok premium privat.
- Checkout web dan bot, inquiry pascabayar Digiflazz dan pasangan cek/bayar OtomaX, failover pascabayar setelah gagal pasti dengan cek ulang tagihan cadangan, akun pelanggan, wallet/topup/refund, guest checkout, Midtrans merchant toko dan QRIS custom berlisensi.
- Webhook Digiflazz dan rekonsiliasi status/SN, keputusan manual yang dibatasi transaksi Digiflazz eligible, koreksi refund, serta blok pembelian bagi pelanggan bersaldo minus.
- WhatsApp toko untuk OTP, notifikasi dan transaksi chat termasuk satu paket sewa. Email memakai pengirim platform atau SMTP toko.
- Aurora, Nebula dan Atlas memiliki susunan mobile khusus, navigasi ringkas, pencarian dropdown dengan infinite scroll, quick order Aurora dengan dropdown scroll/loading 500 ms, dan 24 preset warna (terang/gelap/pastel). Ketiganya memakai katalog/checkout bersama: desain warna/section/banner, flash sale, trending, artikel, leaderboard, cek pesanan, profil dan pengecekan akun game sesuai konfigurasi.
- Mode pengujian per toko: Digiflazz development, pembayaran simulasi atau Midtrans Sandbox, wallet/kuota uji terpisah; sumber Hanshop dan stok premium disimulasikan.
- UI panel responsif, tema terang/gelap, sidebar collapsed, modal aksi, tabel berfilter/pagination, notifikasi lonceng dengan infinite scroll dan toast.
- Kesehatan sistem khusus admin (`/admin/system-health`, 28 Oktober): database/cache aplikasi dan pemasok, heartbeat scheduler/queue, API Hanshop, bot manager, koneksi WhatsApp toko, sinkronisasi pemasok, antrean dan ringkasan callback. Pemeriksaan setiap menit; auto refresh panel dan notifikasi lonceng saat indikator gangguan berubah. [Operasional dan batas pemeriksaan](docs/DEPLOYMENT.md#kesehatan-sistem-admin--28-oktober-2026).

## Menjalankan

Ikuti [web](apps/web/README.md) dan [bot](apps/bot/README.md). Web memerlukan database aplikasi sendiri, cache/lock, queue worker dan scheduler; integrasi membutuhkan konfigurasi layanan terkait. Jangan menjalankan manager kedua pada port/sesi yang sama.

Profil laptop menjalankan tujuh layanan pengembangan: web (8000), Vite (5173), queue, scheduler, bot manager, API Hanshop watch dan bot Hanshop watch. Pasang launcher per user lewat `deploy/install-pm2-laptop.ps1`, lalu **`pm2 start` dapat dipanggil dari folder mana pun di terminal baru**. Manager membangun bot sebelum mulai, seperti manager:dev; dependency tetap perlu disiapkan sekali. Profil VPS merupakan contoh terpisah yang perlu ditinjau kembali saat deployment. Cara menyiapkan, menghentikan proses lama dan melihat log: [PM2 satu perintah](docs/DEPLOYMENT.md#pm2-satu-perintah).

Domain memakai `APP_URL`, `PLATFORM_DOMAIN`, alias, dan mapping custom host verified; pergantian domain tidak memerlukan salinan frontend per toko. Wildcard DNS, TLS dan onboarding custom domain tetap pekerjaan infrastruktur.

## Batas saat ini

Retensi per toko sudah tersedia: jadwal 7/30 hari, hold kewajiban, reminder, pratinjau admin, pembatalan saat renewal, dan purge data/media/sesi WhatsApp. Persetujuan penutupan manual tersedia melalui modal admin, termasuk toko tanpa langganan. Penghapusan otomatis dinonaktifkan secara bawaan (`STORE_PURGE_ENABLED=false`) dan terpisah dari persetujuan manual. Kebijakan backup/log server bersama, otomatisasi DNS/TLS dan hardening worker multi-host tetap memerlukan tindak lanjut operasional. Pemantauan saldo dan permintaan deposit Digiflazz/Hanshop/OtomaX tersedia di Koneksi supplier → Saldo & deposit pemasok; saldo pemasok terpisah dari wallet pelanggan. Deposit nyata dan konfirmasi tiap supplier tetap perlu diuji pada lingkungan operasional.

Source di working tree dapat lebih baru daripada commit/deployment. Pengujian historis tercatat dengan tanggal dan lingkungannya; keberadaan source/test tidak membuktikan layanan produksi sudah diperbarui.

## Pembaruan instalasi existing — 22 Oktober 2026

Backup database dan pertahankan APP_KEY, deploy source/dependensi sesuai lockfile, jalankan `php artisan migrate --force`, lalu `npm run build`. Migrasi URL katalog OtomaX menambah konfigurasi terenkripsi, jadwal/revisi sinkronisasi serta metadata sumber produk. Restart queue worker lama; worker URL memakai timeout 180 detik dengan retry_after di atas batas tersebut. Queue dan scheduler diperlukan untuk sinkronisasi otomatis; langkah lengkap ada di [deployment](docs/DEPLOYMENT.md).

URL daftar harga diatur per koneksi melalui **Supplier OtomaX → Daftar harga URL → Uji URL → Simpan & sinkronkan**. Default 15 menit, pilihan 5/15/30/60 menit. Produk hilang dari snapshot valid menjadi tidak tersedia; error unduh tidak menghapus katalog. Menu upload Excel/CSV sementara tersembunyi, fondasi/backend tetap ada.

## Dokumentasi

- [Arsitektur dan alur source](docs/ARCHITECTURE.md)
- [Aturan bisnis dan batas implementasi](docs/BUSINESS_RULES.md)
- [Keputusan dan pekerjaan terbuka](docs/DECISIONS.md)
- [Retensi toko: hasil dan cara penggunaan](docs/RETENTION_PROGRESS.md)
- [Hak akses staff](docs/STAFF_PERMISSIONS.md)
- [Domain](docs/DOMAINS.md) dan [deployment](docs/DEPLOYMENT.md)
- [Riwayat validasi](docs/FOUNDATION_VALIDATION.md), [checkpoint notifikasi/voucher](docs/NOTIFICATION_PROGRESS.md), [audit UI](docs/UI_READABILITY_AUDIT.md)

- [Integrasi OtomaX dan progres](docs/OTOMAX_PROGRESS.md), [rancangan serta batas sinkronisasi URL](docs/OTOMAX_INTEGRATION_PLAN.md)

Pusat panduan publik tersedia di `/panduan` dengan 22 tutorial bergambar. Tombol Panduan pada header menu panel membuka topik terkait di tab baru. Lihat [pemeliharaan panduan](docs/TUTORIALS.md).

Halaman publik `/paket` menyediakan pilihan platform/durasi dari harga aktif admin, simulasi biaya sewa dan pengecekan subdomain tanpa membuat toko. Total final (voucher/kode unik bila ada) tetap ditentukan melalui invoice setelah owner memenuhi verifikasi.

Domain pribadi toko dapat diatur owner melalui Tampilan & pengaturan. Panduan teknis DNS/HTTPS dan contoh VPS ada di [docs/CUSTOM_DOMAINS.md](docs/CUSTOM_DOMAINS.md).

Penanganan sewa awal gagal disiapkan tersedia di Langganan & aktivasi dan Detail toko admin: alasan, periksa ulang, laporan/refund, approval admin dan refund idempoten ke saldo Hanshop. Paket yang sudah aktif tidak termasuk; deployment memerlukan migrasi web/API sesuai DEPLOYMENT.md.

Pembaruan1November2026: failover beberapa periode pascabayar memiliki regresi Digiflazz/OtomaX dua arah, normalisasi daftar bulan dan penolakan mismatch. QRIS HansDev menerima gambar langsung, aplikasi sumber package/nama bebas, tautan APK/panduan instalasi, dan konfirmasi penerimaan manual berizin dengan audit/idempotensi. Tanpa migrasi schema. APK release harus disalin ketika deploy (DEPLOYMENT.md); uji perubahan ini terisolasi, bukan pembayaran atau instalasi HP produksi.

Panduan `/panduan/aktifkan-listener` menyediakan aktivasi listener USB langkah demi langkah (mode pengembang, USB debugging, RSA dan ADB tiap OS). APK diunduh terpisah dari tools. Pilih OS komputer pada dropdown sebelum mengunduh ZIP Windows/macOS/Linux; tiap ZIP berisi SDK satu OS dan script aktivasi sejajar dengan folder platform-tools. Deploy empat file rilis sesuai [deployment](docs/DEPLOYMENT.md); biner tidak termasuk Git.


## Navigasi pemasok dan QRIS terkini — 2 November 2026

- **Koneksi supplier**: API Hanshop, Digiflazz, Supplier OtomaX, serta Saldo & deposit pemasok. Akun dan webhook Digiflazz dikelola di halaman koneksi; perubahan kredensial Hanshop/Digiflazz tetap khusus owner.
- **Produk & harga → Sumber produk**: tabel, pencarian/filter, pagination, sinkronisasi katalog dan impor. Pilih sumber pada filter untuk impor semua/per layanan/per kategori; checkbox untuk pilihan massal. Data koneksi/parsing existing tetap dipakai.
- **Pembayaran pelanggan**: unggah QRIS, Hans Forward, penangkap format notifikasi, uji contoh, dan konfirmasi penerimaan manual berizin. Format cocok bukan bukti pembayaran lunas; server tetap memeriksa token, sumber aplikasi, waktu, nominal dan invoice.
- **Panduan publik**: 22 topik, 111 langkah; QRIS custom 10 langkah dan aktivasi listener 12 langkah. Gambar memakai screenshot panel simulasi, 10 screenshot HP asli, serta ilustrasi berlabel. APK dokumentasi dengan data contoh bukan APK rilis tenant.

Perubahan terbaru tidak memerlukan migrasi atau dependensi tambahan. Jika memperbarui instalasi, deploy seluruh source/aset, build frontend, salin empat file rilis bila menyediakan unduhan HansDev, serta muat ulang konfigurasi/proses sesuai [DEPLOYMENT.md](docs/DEPLOYMENT.md). Catatan validasi bertanggal berada di [FOUNDATION_VALIDATION.md](docs/FOUNDATION_VALIDATION.md); tidak menyatakan semua tes atau layanan produksi diuji ulang pada setiap perubahan dokumen.
