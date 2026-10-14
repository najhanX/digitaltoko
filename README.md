# DigitalToko (workspace Hansbot)

Platform penyewaan bot WhatsApp untuk toko produk digital. Setiap toko memiliki owner, konfigurasi, produk, stok, pelanggan, transaksi, sesi WhatsApp, dan langganannya sendiri.

## Struktur awal

- `apps/web/`: Laravel 13 + Inertia 3 + React 19/TypeScript untuk admin/owner/staff panel dan storefront, dengan fondasi akses role/toko.
- `apps/bot/`: Node/TypeScript + Baileys 7.0.0-rc14, QR session per toko, quick reply dengan fallback teks, token chat/sender, dan HTTP loopback.
- `apps/storefront/`: catatan; storefront di apps/web/resources/js/pages/Storefront, bukan app/deployment terpisah.
- `docs/ARCHITECTURE.md`: rancangan pemisahan aplikasi, paket, akses toko, dan urutan implementasi.
- `docs/DOMAINS.md`: rancangan subdomain bawaan dan domain custom toko.
- `docs/DECISIONS.md`: keputusan pengguna dan hal yang masih terbuka.
- `docs/BUSINESS_RULES.md`: detail pembayaran, akun/saldo Hanshop, parsing, stok, template, staff, dan verifikasi owner.
- `docs/STAFF_PERMISSIONS.md`: rancangan permission staff toko (maksimal lima akun lokal per toko).

## Menjalankan dan deployment

Ikuti apps/web/README.md dan apps/bot/README.md. Preview lokal http://localhost:8000/preview/owner hanya local+flag preview, bukan autentikasi. Migrasi SQLite lokal dan frontend build telah dijalankan. Bot default WA_ENABLED=false, tanpa token/session live.

Onboarding owner tersedia: login/daftar OTP melalui bridge identitas Hanshop, verifikasi email, pengajuan dan review KTP manual, toko draft, serta CRUD staff dan permission per toko. Langganan/trial dan koneksi WhatsApp mandiri lewat QR/kode sudah tersedia. Bot manager menjalankan worker terpisah per toko dan menjaga akses sesuai langganan. Sumber Digiflazz/Hanshop, saldo pemasok, katalog bersama bot/web, aturan harga, dan stok premium privat sudah tersedia. Checkout pelanggan, retensi toko, dan hardening sesi produksi masih pekerjaan berikutnya. Pengiriman OTP nyata memerlukan konfigurasi bridge Hanshop dan SMTP; koneksi WhatsApp nyata memerlukan penautan di HP.

Domain configurable melalui APP_URL/PLATFORM_DOMAIN; domain aliases mendukung transisi, custom domain verified dipetakan per toko. DNS/TLS, sender email, callback provider dan WEB_BASE_URL bot tetap perlu dikonfigurasi. Belum ada perubahan VPS/DNS produksi. Panduan: docs/DEPLOYMENT.md, contoh Nginx/worker di deploy/.
