# Worker WhatsApp Hansbot

Worker TypeScript + Express + Baileys 7.0.0-rc14. Helper persistence/version dan patch Baileys diadaptasi dari bot_wa_v2; project bot lama tidak diubah.

Tahap awal memakai satu proses worker per toko dengan kode dasar yang sama. Tiap worker memiliki identitas toko, credential internal dengan scope toko, sesi WhatsApp, konfigurasi, dan namespace cache yang terpisah.

Worker menerjemahkan pesan menjadi permintaan ke backend bisnis dan mengirim respons. Worker tidak menjadi sumber kebenaran harga, saldo, stok, paket, atau status transaksi.

## Menjalankan

Node 22.12+ dan Git diperlukan. `npm ci` menerapkan patch dengan git apply, `npm test` memeriksa token menu/config/API offline, `npm run build`, lalu `npm start` menjalankan produksi. Salin `.env.example` ke `.env`, isi STORE_ID dan token yang dibuat Laravel melalui `php artisan digitaltoko:worker-token <uuid-store>`. WEB_BASE_URL menggunakan URL platform yang aktif, tidak domain tetap. Jangan memakai BOT_INTERNAL_TOKEN Hanshop untuk tenant.

WA_ENABLED=false secara default sehingga menjalankan worker tidak langsung menghubungkan WhatsApp. Dengan true, worker mengambil akses backend, membuka satu QR session toko, mempublikasikan status/QR ke endpoint internal, dan reconnect dengan backoff. Status/QR diteruskan ke backend melalui token hashed per toko; HTTP worker hanya listen loopback. Lock sesi mencegah dua proses memakai folder auth sama. Jika proses crash meninggalkan .worker.lock, operator harus memastikan tidak ada proses yang masih memakai sesi sebelum melepaskan lock.

## Batas implementasi

Menu #menu/#start menyediakan teks dan quick reply untuk perangkat mobile yang dikenali; desktop/unknown memakai teks. Tombol menggunakan token terikat chat/sender, expiry 15 menit, dan konsumsi satu kali. Perangkat nyata belum diuji; relay berhasil tidak membuktikan tombol tampil.

Katalog, saldo, checkout, status transaksi, pairing code, provisioning otomatis, dan kontrol start/logout dari panel belum selesai. Handler tidak membuat transaksi pemasok. QR state tersedia di backend untuk owner atau staff berpermission bot.manage, tetapi UI pairing belum dibuat. Hanya satu QR socket per proses.

Fondasi auth sesi masih file per toko dengan writer atomic untuk creds; penyimpanan key/auth production, pemulihan crash, dan operasi multi-host memerlukan milestone hardening sebelum dipakai penyewa.
