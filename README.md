# DigitalToko — workspace Hansbot

Dokumentasi disinkronkan dengan source lokal pada **21 Oktober 2026**. Ini status implementasi, bukan pernyataan bahwa seluruh perubahan telah dideploy atau diuji pada layanan produksi.

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
- Sembilan paket Mercury sampai Andromeda, harga/durasi admin, invoice sewa, aktivasi pertama, renewal, perubahan paket dan masa tenggang per toko. **Trial baru dihapus**; voucher platform mendukung diskon 100% tanpa gateway.
- Admin mengelola toko, paket, verifikasi, pembayaran, voucher dan harga modul. Penangguhan admin dan koreksi paket/masa aktif memiliki audit.
- Sumber Digiflazz/Hanshop, cache akun bersama Redis, layanan → kategori → produk, impor semua/per layanan/per kategori/pilihan, parsing, markup dan stok premium privat.
- Checkout web dan bot, inquiry pascabayar, akun pelanggan, wallet/topup/refund, guest checkout, Midtrans merchant toko dan QRIS custom berlisensi.
- Webhook Digiflazz dan rekonsiliasi status/SN, keputusan manual yang dibatasi transaksi Digiflazz eligible, koreksi refund, serta blok pembelian bagi pelanggan bersaldo minus.
- WhatsApp toko untuk OTP/notifikasi termasuk paket Web. Perintah transaksi chat tetap memerlukan paket Bot/Full. Email memakai pengirim platform atau SMTP toko.
- Aurora, Nebula dan Atlas memakai katalog/checkout bersama: desain warna/section/banner, flash sale, trending, artikel, leaderboard, cek pesanan, profil dan pengecekan akun game sesuai konfigurasi.
- Mode pengujian per toko: Digiflazz development, pembayaran simulasi atau Midtrans Sandbox, wallet/kuota uji terpisah; sumber Hanshop dan stok premium disimulasikan.
- UI panel responsif, tema terang/gelap, sidebar collapsed, modal aksi, tabel berfilter/pagination, notifikasi lonceng dengan infinite scroll dan toast.

## Menjalankan

Ikuti [web](apps/web/README.md) dan [bot](apps/bot/README.md). Web memerlukan database aplikasi sendiri, cache/lock, queue worker dan scheduler; integrasi membutuhkan konfigurasi layanan terkait. Jangan menjalankan manager kedua pada port/sesi yang sama.

Domain memakai `APP_URL`, `PLATFORM_DOMAIN`, alias, dan mapping custom host verified; pergantian domain tidak memerlukan salinan frontend per toko. Wildcard DNS, TLS dan onboarding custom domain tetap pekerjaan infrastruktur.

## Batas saat ini

Penghapusan otomatis seluruh data toko setelah retensi, pengelolaan backup/purge lintas aplikasi, otomatisasi DNS/TLS, refund sewa gagal provisioning, dan hardening worker multi-host belum merupakan alur lengkap. Panel topup saldo Hanshop owner juga belum tersedia; jangan menyamakannya dengan topup wallet pelanggan yang sudah tersedia.

Source di working tree dapat lebih baru daripada commit/deployment. Pengujian historis tercatat dengan tanggal dan lingkungannya; keberadaan source/test tidak membuktikan layanan produksi sudah diperbarui.

## Dokumentasi

- [Arsitektur dan alur source](docs/ARCHITECTURE.md)
- [Aturan bisnis dan batas implementasi](docs/BUSINESS_RULES.md)
- [Keputusan dan pekerjaan terbuka](docs/DECISIONS.md)
- [Hak akses staff](docs/STAFF_PERMISSIONS.md)
- [Domain](docs/DOMAINS.md) dan [deployment](docs/DEPLOYMENT.md)
- [Riwayat validasi](docs/FOUNDATION_VALIDATION.md), [checkpoint notifikasi/voucher](docs/NOTIFICATION_PROGRESS.md), [audit UI](docs/UI_READABILITY_AUDIT.md)
