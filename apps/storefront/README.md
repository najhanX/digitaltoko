# Storefront DigitalToko

Disinkronkan dengan source pada **21 Oktober 2026**. Folder ini hanya catatan; tidak memiliki runtime, dependency, atau deployment terpisah.

Halaman publik berada di `../web/resources/js/pages/Storefront`, route di `../web/routes/web.php`, dan proses bisnis di `../web/app/Services`.

Tiga template utuh **Aurora, Nebula, Atlas** memakai katalog dan mesin checkout yang sama. Aurora memiliki quick order pulsa/data/voucher berdasarkan provider kategori; Nebula berfokus pada navigasi topup game; Atlas memakai susunan katalog berbeda. Nama minimal/promo/kategori masih dapat muncul sebagai alias kompatibilitas, bukan nama template publik utama.

Yang tersedia: katalog kategori, produk/checkout, akun/OTP, wallet/topup/mutasi/riwayat, cek pesanan publik dengan pemeriksaan akses, leaderboard, kalkulator, artikel, pencarian navbar, banner autoplay, flash sale, trending, iklan awal, CS mengambang, serta identitas/logo/favicon/warna/urutan section sesuai konfigurasi toko. Tujuan dan cek nickname diatur per kategori. Powered by DigitalToko tetap ditampilkan.

Hostname dipetakan ke toko melalui PlatformHost; host tidak dikenal ditolak. Paket Bot tanpa kanal Web tidak membuka storefront. Guest checkout mengikuti pengaturan toko, sementara saldo customer tetap per toko. Desain bukan HTML/JavaScript bebas; backend memvalidasi schema dan media.

Lihat [web README](../web/README.md), [arsitektur](../../docs/ARCHITECTURE.md), [aturan bisnis](../../docs/BUSINESS_RULES.md) dan [domain](../../docs/DOMAINS.md). Keberadaan halaman/tes bukan bukti domain publik, pembayaran nyata, atau pengiriman OTP telah diuji pada deployment tujuan.
