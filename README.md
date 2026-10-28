# DigitalToko — workspace Hansbot

Dokumentasi disinkronkan dengan source lokal pada **23 Oktober 2026**. Ini status implementasi, bukan pernyataan bahwa seluruh perubahan telah dideploy atau diuji pada layanan produksi.

DigitalToko adalah platform SaaS multi-store untuk menyewakan web toko produk digital, bot WhatsApp, atau keduanya. Satu owner dapat memiliki banyak toko; setiap toko memiliki langganan, katalog, stok, pelanggan, wallet, pesanan, konfigurasi, dan sesi WhatsApp sendiri.

## Peta proyek

| Folder / proyek | Tanggung jawab |
| --- | --- |
| `apps/web` | Laravel + Inertia React: landing, admin, owner, staff, storefront, backend bisnis dan scheduler |
| `apps/bot` | Node/TypeScript + Baileys: satu manager dan worker/sesi terpisah per toko |
| `apps/storefront` | Catatan lokasi; halaman publik dijalankan oleh `apps/web`, bukan deployment kedua |
| `assets` | Logo/branding dan bukti tampilan lokal |
| `deploy` | Contoh Nginx dan PM2; harus disesuaikan dengan server tujuan |
| `../API Hanshop` | Backend Hanshop: identitas/saldo owner, sumber produk, bridge dan pembayaran sewa |
| `../bot_wa_v2` | Bot toko Hanshop sendiri, terpisah dari worker tenant |
| `android-listener` | Proyek eksternal HansDev Forwarder di direktori HansDev/projects: notifikasi pembayaran HP ke server |

## Implementasi yang tersedia

- Onboarding owner lewat bridge identitas Hanshop, profil/email, KTP manual, toko draft, serta maksimal lima staff lokal per toko.
- Tiga platform (Web Topup, Bot WhatsApp, Web + Bot WhatsApp), seluruh sumber produk termasuk Digiflazz/Hanshop/OtomaX/stok premium, harga/durasi admin, invoice sewa, aktivasi pertama, renewal, perubahan paket dan masa tenggang per toko. **Trial baru dihapus**; voucher platform mendukung diskon 100% tanpa gateway.
- Admin mengelola toko, paket, verifikasi, pembayaran, voucher dan harga modul. Penangguhan admin dan koreksi paket/masa aktif memiliki audit.
- Sumber Digiflazz/Hanshop serta multi-supplier OtomaX melalui IP Center dan sinkronisasi URL Webreport, cache akun bersama Redis, layanan → kategori → produk, impor semua/per layanan/per kategori/pilihan, parsing, markup dan stok premium privat.
- Checkout web dan bot, inquiry pascabayar, akun pelanggan, wallet/topup/refund, guest checkout, Midtrans merchant toko dan QRIS custom berlisensi.
- Webhook Digiflazz dan rekonsiliasi status/SN, keputusan manual yang dibatasi transaksi Digiflazz eligible, koreksi refund, serta blok pembelian bagi pelanggan bersaldo minus.
- WhatsApp toko untuk OTP/notifikasi termasuk paket Web. Perintah transaksi chat tetap memerlukan paket Bot/Full. Email memakai pengirim platform atau SMTP toko.
- Aurora, Nebula dan Atlas memiliki susunan mobile khusus, navigasi ringkas, pencarian dropdown dengan infinite scroll, quick order Aurora dengan dropdown scroll/loading 500 ms, dan 24 preset warna (terang/gelap/pastel). Ketiganya memakai katalog/checkout bersama: desain warna/section/banner, flash sale, trending, artikel, leaderboard, cek pesanan, profil dan pengecekan akun game sesuai konfigurasi.
- Mode pengujian per toko: Digiflazz development, pembayaran simulasi atau Midtrans Sandbox, wallet/kuota uji terpisah; sumber Hanshop dan stok premium disimulasikan.
- UI panel responsif, tema terang/gelap, sidebar collapsed, modal aksi, tabel berfilter/pagination, notifikasi lonceng dengan infinite scroll dan toast.
- Kesehatan sistem khusus admin (`/admin/system-health`, 28 Oktober): database/cache aplikasi dan pemasok, heartbeat scheduler/queue, API Hanshop, bot manager, koneksi WhatsApp toko, sinkronisasi pemasok, antrean dan ringkasan callback. Pemeriksaan setiap menit; auto refresh panel dan notifikasi lonceng saat indikator gangguan berubah. [Operasional dan batas pemeriksaan](docs/DEPLOYMENT.md#kesehatan-sistem-admin--28-oktober-2026).

## Menjalankan

Ikuti [web](apps/web/README.md) dan [bot](apps/bot/README.md). Web memerlukan database aplikasi sendiri, cache/lock, queue worker dan scheduler; integrasi membutuhkan konfigurasi layanan terkait. Jangan menjalankan manager kedua pada port/sesi yang sama.

Profil laptop menjalankan tujuh layanan pengembangan: web (8000), Vite (5173), queue, scheduler, bot manager, API Hanshop watch dan bot Hanshop watch. Pasang launcher per user lewat `deploy/install-pm2-laptop.ps1`, lalu **`pm2 start` dapat dipanggil dari folder mana pun di terminal baru**. Manager membangun bot sebelum mulai, seperti manager:dev; dependency tetap perlu disiapkan sekali. Profil VPS merupakan contoh terpisah yang perlu ditinjau kembali saat deployment. Cara menyiapkan, menghentikan proses lama dan melihat log: [PM2 satu perintah](docs/DEPLOYMENT.md#pm2-satu-perintah).

Domain memakai `APP_URL`, `PLATFORM_DOMAIN`, alias, dan mapping custom host verified; pergantian domain tidak memerlukan salinan frontend per toko. Wildcard DNS, TLS dan onboarding custom domain tetap pekerjaan infrastruktur.

## Batas saat ini

Retensi per toko sudah tersedia: jadwal 7/30 hari, hold kewajiban, reminder, pratinjau admin, pembatalan saat renewal, dan purge data/media/sesi WhatsApp. Persetujuan penutupan manual tersedia melalui modal admin, termasuk toko tanpa langganan. Penghapusan otomatis dinonaktifkan secara bawaan (`STORE_PURGE_ENABLED=false`) dan terpisah dari persetujuan manual. Kebijakan backup/log server bersama, otomatisasi DNS/TLS dan hardening worker multi-host tetap memerlukan tindak lanjut operasional. Pemantauan saldo dan permintaan deposit Digiflazz/Hanshop/OtomaX tersedia di Produk & harga → Saldo & deposit pemasok; saldo pemasok terpisah dari wallet pelanggan. Deposit nyata dan konfirmasi tiap supplier tetap perlu diuji pada lingkungan operasional.

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

Pusat panduan publik tersedia di `/panduan` dengan 20 tutorial bergambar. Tombol Panduan pada header menu panel membuka topik terkait di tab baru. Lihat [pemeliharaan panduan](docs/TUTORIALS.md).

Halaman publik `/paket` menyediakan pilihan platform/durasi dari harga aktif admin, simulasi biaya sewa dan pengecekan subdomain tanpa membuat toko. Total final (voucher/kode unik bila ada) tetap ditentukan melalui invoice setelah owner memenuhi verifikasi.

Domain pribadi toko dapat diatur owner melalui Tampilan & pengaturan. Panduan teknis DNS/HTTPS dan contoh VPS ada di [docs/CUSTOM_DOMAINS.md](docs/CUSTOM_DOMAINS.md).

Penanganan sewa awal gagal disiapkan tersedia di Langganan & aktivasi dan Detail toko admin: alasan, periksa ulang, laporan/refund, approval admin dan refund idempoten ke saldo Hanshop. Paket yang sudah aktif tidak termasuk; deployment memerlukan migrasi web/API sesuai DEPLOYMENT.md.
