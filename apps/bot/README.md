# Worker WhatsApp Hansbot

Worker TypeScript + Express + Baileys 7.0.0-rc14. Helper persistence/version dan patch Baileys diadaptasi dari bot_wa_v2; project bot lama tidak diubah.

Tahap awal memakai satu proses worker per toko dengan kode dasar yang sama. Tiap worker memiliki identitas toko, credential internal dengan scope toko, sesi WhatsApp, konfigurasi, dan namespace cache yang terpisah.

Worker menerjemahkan pesan menjadi permintaan ke backend bisnis dan mengirim respons. Worker tidak menjadi sumber kebenaran harga, saldo, stok, paket, atau status transaksi.

## Menjalankan

Node 22.12+ dan Git diperlukan. `npm ci` menerapkan patch dengan git apply. Untuk panel tenant, salin `.env.example` ke `.env`, isi `BOT_MANAGER_TOKEN` minimal 32 karakter acak yang sama dengan `apps/web/.env`, serta `WEB_BASE_URL` ke URL Laravel yang dapat dijangkau VPS. Gunakan `http://127.0.0.1:8000` untuk development, atau URL platform/jaringan privat saat deployment. Token manager berbeda dari token Hanshop dan token worker per toko.

Jalankan `npm run manager:dev` untuk build dan menjalankan manager saat development. Produksi: `npm run build`, lalu `npm run manager` atau PM2 menggunakan `deploy/ecosystem.config.cjs`. Hanya satu manager per VPS. Laravel menghubungi `BOT_MANAGER_URL=http://127.0.0.1:3099`; port ini tidak perlu dibuka ke internet. Owner membuka menu **Koneksi WhatsApp**, memilih QR/kode dan nomor, kemudian menautkan perangkat di HP. Staff membutuhkan `bot.manage`; `bot.view` hanya melihat status tanpa QR/kode.

Manager membuat worker, token, port loopback sementara, dan folder `sessions/<uuid-store>` yang terpisah. `sessions/.manager.json` menyimpan registry/token privat (mode 0600 di Unix); jangan publikasikan folder sesi atau memasukkannya ke Git. Manager memulihkan worker saat restart, menghentikan anak proses ketika manager ditutup, dan membatasi restart setelah kegagalan berulang. `MANAGER_MAX_WORKERS` adalah kapasitas operasional VPS, default 100, dan dapat disesuaikan.

Mode worker manual tetap tersedia: isi STORE_ID dan WORKER_TOKEN melalui `php artisan digitaltoko:worker-token <uuid-store>`, lalu `npm start`. Jangan merotasi token manual pada toko yang sedang dikelola manager; lakukan hubungkan ulang melalui panel. `npm test` menguji pairing/manager dengan transport lokal, tanpa menautkan akun WhatsApp nyata.

WA_ENABLED=false tetap menjadi default mode manual. Manager mengaktifkan WhatsApp hanya untuk worker toko yang diminta dari panel. Worker memeriksa akses langganan sebelum membuka socket: toko provisioning, aktif, atau masa tenggang berbayar; draft dan trial kedaluwarsa tidak diizinkan. QR dibuat sebagai PNG lokal dengan `qrcode`; kode pairing diminta melalui Baileys setelah event QR dari server. Event connecting awal belum menjamin socket siap. Metode kode memakai `Browsers.macOS("Chrome")` seperti bot_wa_v2 agar companion_platform_id adalah CHROME (1); QR tetap memakai identitas `Google Chrome` yang sudah berjalan. Startup membersihkan hanya metadata me/pairingCode dari auth belum terdaftar dan menyimpannya secara atomic; sesi terdaftar tidak dibersihkan. Timeout permintaan kode 60 detik mengikuti default query Baileys. Nomor tertaut harus sesuai nomor yang dimasukkan. Pairing memiliki batas waktu, event menggunakan ID percobaan, dan token lama ditolak setelah hubungkan ulang. Lock sesi mencegah dua proses menggunakan auth sama; lock dengan PID yang sudah mati dapat dilepas otomatis. PID yang masih hidup tidak ditimpa.

## Batas implementasi

Menu #menu/#start menyediakan teks dan quick reply untuk perangkat mobile yang dikenali; desktop/unknown memakai teks. Tombol menggunakan token terikat chat/sender, expiry 15 menit, dan konsumsi satu kali. Perangkat nyata belum diuji; relay berhasil tidak membuktikan tombol tampil.

Koneksi QR/kode, hubungkan ulang, dan lepas koneksi sudah tersedia di panel. Mengubah nomor/metode melepas sesi sebelumnya; menghubungkan ulang dengan pilihan yang sama mencoba memakai sesi yang tersimpan. Jika logout remote gagal karena jaringan, sesi lokal tetap dilepas dan perangkat harus dihapus juga melalui HP. Katalog, saldo, checkout, dan status transaksi produk masih belum tersedia; handler tidak membuat transaksi pemasok. Pada 14 Oktober 2026, pengguna mengonfirmasi pairing kode berhasil terhubung dan pengujian pesan/balasan dasar berjalan normal. Tampilan tombol native pada berbagai perangkat masih memerlukan pemeriksaan tersendiri.

Fondasi auth sesi masih file per toko dengan writer atomic untuk creds; penyimpanan key/auth production, pemulihan crash, dan operasi multi-host memerlukan milestone hardening sebelum dipakai penyewa.
