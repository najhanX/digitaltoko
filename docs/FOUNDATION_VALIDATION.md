# Validasi fondasi 13 Oktober 2026

Implementasi lokal di workspace Hansbot; tidak ada deploy VPS, perubahan DNS, transaksi live, atau koneksi WhatsApp pelanggan.

- Laravel 13.35.0, PHP lokal 8.5.5; composer check-platform-reqs berhasil.
- php artisan test: 17 passed, 74 assertions, termasuk domain baru/alias lama, custom domain verified, akses lintas role/toko, worker token dan privasi QR.
- npm run build pada apps/web: TypeScript dan Vite produksi berhasil.
- npm test pada apps/bot: 4 passed, termasuk API offline token tanpa mengaktifkan WA.
- npm ci bot berhasil menerapkan patch Baileys dengan git apply dari instalasi dependency fresh.
- npm audit web dan bot: 0 vulnerability pada snapshot pemeriksaan ini.
- Laravel config:cache dan route:cache berhasil; cache dibersihkan kembali untuk development.
- Migrasi fondasi toko/domain/staff diterapkan di SQLite lokal, bukan MySQL Hanshop/production.
- Browser lokal memuat landing, owner preview, dan storefront promo. Layout landing viewport kecil diverifikasi document width sama dengan client width setelah perbaikan background hero.

## Batas bukti

Perbaikan navigasi berikutnya: CSS dipisahkan menjadi entry yang dirender pada head, navigasi internal memakai Inertia Link. Build TypeScript/Vite berhasil; browser dev dengan SSR memverifikasi stylesheet tersedia pada head, owner -> admin -> storefront -> template Promo berpindah tanpa mengganti dokumen initial SSR. Link domain berbeda tetap navigasi normal. Perbaikan local /login sebelumnya menambah dua test loopback-local/production-boundary (19 test web total).

Menu native Baileys hanya diuji bentuk/kompilasi serta token, bukan render perangkat WhatsApp. QR connect/reconnect/live session belum dijalankan. Owner/admin OTP serta provider email belum disambungkan. Preview local terpisah dari route panel terautentikasi; tidak ada production demo-login.

Role/tenant foundation belum menggantikan seluruh permission/business policy yang akan dibangun. DB/schema subscription/ledger/katalog/payment dan otomatisasi expiry/hold belum tersedia; aturan bisnisnya tetap di BUSINESS_RULES.md.

## Validasi onboarding owner

- 41 tes Laravel / 319 assertions lulus: login/daftar OTP WA, login email terverifikasi, OTP sekali pakai/percobaan/expiry, konfigurasi gagal, verifikasi email ke Hanshop, KTP privat dan encrypted fields, review manual, penolakan+retensi72h, cleanup tanpa toko, satu approval untuk banyak toko, slug immutable/unik/reserved, CRUD staff/cap5/permission/isolasi dan pergantian sesi owner ke staff.
- Build React/TypeScript/Vite lulus. Suite API Hanshop 144 tes lulus (termasuk 7 tes bridge/repository DigitalToko), typecheck dan audit envelope response lulus.
- Browser menjalankan login OTP owner, pengajuan KTP dengan gambar logo dan identitas simulasi, login admin/review, pembuatan toko draft Galaxy/Atlas, pergantian ke Nebula, form edit staff, dan login staff lokal. Sesi staff diarahkan ke login ketika mencoba profil owner.
- Pengujian browser memakai SQLite serta bridge identitas lokal terpisah. Tidak membuat akun atau mengirim WA/email ke pelanggan sebenarnya. OTP provider, MySQL migration Hanshop, DNS/TLS, dan deployment VPS belum dibuktikan live.
- Migration onboarding diterapkan ke SQLite aplikasi normal setelah backup privat. Kebutuhan konfigurasi operator dicatat pada DEPLOYMENT.md.
