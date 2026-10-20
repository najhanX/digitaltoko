# Checkpoint UI dan pemisahan notifikasi — 20 Oktober 2026

## Selesai sebelum checkpoint

- Kartu tabel Produk & harga memiliki padding yang konsisten.
- Judul kelompok sidebar dapat diklik; sidebar desktop dapat dikecilkan dan pilihannya disimpan.
- Ikon D DigitalToko menjadi favicon bawaan dan logo sidebar collapsed.
- Transisi sidebar menjaga ukuran ikon/baris; area aktif Ruang Owner/Toko seragam 44 × 44 px.
- Navigasi bagian Layanan/Kategori, Produk/Aturan harga, dan Pengaturan/Desain memakai tombol yang jelas, termasuk penanda aksesibilitas bagian aktif.
- Banner tidak memiliki scroll internal; teks dan tombol ditata dalam bingkai tetap.
- Perapian format source katalog yang ada di working tree ikut disimpan pada checkpoint.
- Build TypeScript/Vite berhasil; pemeriksaan browser menggunakan preview/data simulasi, bukan bukti pengiriman layanan nyata. `git diff --check` lulus.

## Keputusan pengguna untuk tahap berikutnya

- Pisahkan pengiriman OTP/notifikasi pelanggan toko dari OTP/notifikasi platform DigitalToko.
- Semua toko, termasuk paket Web saja, boleh menghubungkan satu nomor WhatsApp toko untuk notifikasi dan OTP.
- Perintah katalog, saldo, dan pemesanan chat hanya boleh berjalan dengan paket Bot/Web + Bot; koneksi notifikasi tidak memberikan fitur tersebut.
- OTP pelanggan menampilkan nama toko dan dikirim melalui koneksi WhatsApp toko. Jangan memakai bot pusat sebagai fallback tersembunyi.
- Sediakan pengirim email bawaan platform dan konfigurasi SMTP milik toko; email pelanggan tetap memakai identitas nama toko.
- Saat WhatsApp tidak tersedia, sediakan jalur email yang dapat dipakai pelanggan termasuk pendaftaran/verifikasi, bukan hanya akun yang sebelumnya sudah login.
- Konfigurasi kredensial email harus terenkripsi, disamarkan di panel, dan terisolasi antartoko; akses staff mengikuti izin yang relevan.

## Temuan source sebelum implementasi

- `CustomerOtp` memanggil `OwnerOtp`; WhatsApp diteruskan ke endpoint identitas API Hanshop, dengan pesan tetap "Kode OTP DigitalToko".
- Email OTP pelanggan memakai `OwnerOtpMail` dan mailer global.
- `CustomerDelivery` masih memiliki fallback WhatsApp melalui API Hanshop serta email global.
- Panel koneksi dan worker masih membatasi koneksi WhatsApp berdasarkan kategori paket Bot/Full.

## Rencana implementasi dan verifikasi

1. Pengirim pesan/email toko dan OTP pelanggan dipisahkan dari layanan identitas owner.
2. Buka koneksi WhatsApp notifikasi pada paket Web, pertahankan pembatasan perintah transaksi di backend dan worker.
3. Tambahkan pengaturan email per toko (bawaan/SMTP), validasi dan penyimpanan aman, UI modal serta petunjuk status koneksi.
4. Selesaikan login/pendaftaran pelanggan melalui WhatsApp atau email, pesan bermerek toko, dan penanganan kanal yang tidak tersedia.
5. Uji isolasi toko, routing pengirim, kerahasiaan kredensial, lisensi bot, OTP salah/kedaluwarsa/sekali pakai, serta jalur email; jalankan build web/worker dan pemeriksaan panel.

Status: rencana disetujui; checkpoint ini dibuat sebelum mengubah modul notifikasi.

## Implementasi selesai setelah checkpoint

- Commit checkpoint: `76251f7`.
- OTP pelanggan memiliki challenge/cache/rate limit sendiri per toko, kanal, dan tujuan. Kode tidak lagi memakai transport identitas Hanshop atau pesan "Kode OTP DigitalToko".
- WhatsApp OTP dikirim ke worker toko melalui manager loopback yang terautentikasi. HTTP baru sukses setelah worker mengonfirmasi pengiriman; OTP tidak dicetak ke log atau dimasukkan ke antrean notifikasi pesanan.
- Paket Web dapat pairing QR/kode untuk OTP/notifikasi, termasuk staf dengan izin koneksi. Endpoint transaksi/katalog bot dan handler pesan tetap menolak paket Web.
- Notifikasi pesanan tidak memakai fallback WhatsApp Hanshop. Antrean tetap tersedia untuk worker toko; email menggunakan konfigurasi pengirim toko. Pengiriman email tetap dicoba ketika sinyal worker gagal.
- Menu Kanal & tampilan → Notifikasi & email menyediakan pengirim bawaan platform atau SMTP toko. Penyuntingan dan pengiriman email uji memakai modal.
- SMTP disimpan dalam kolom terenkripsi dan disembunyikan dari serialisasi model. Password tidak dikembalikan ke browser; input kosong mempertahankan password. Transport dibuat per pengiriman tanpa mengganti mailer global, dengan TLS wajib dan tujuan SMTP publik yang dipin ke IP tervalidasi agar tidak terhubung ulang melalui resolusi DNS berbeda.
- Izin staf `notifications.view` dan `notifications.manage` terpisah dari izin pairing WhatsApp dan branding.
- Pelanggan baru tetap mengisi email, nomor WhatsApp, dan password; dapat memilih OTP WhatsApp toko atau email. Login otomatis email/password dipertahankan, dengan opsi OTP email untuk alamat yang sudah terverifikasi.
- Verifikasi email tidak dianggap sebagai verifikasi nomor WhatsApp. Akun tersebut dapat memakai web/saldo; untuk akses saldo melalui bot harus membuktikan nomor lewat modal verifikasi WhatsApp pada profil. Email yang belum diverifikasi pada akun WhatsApp lama tidak dapat dipakai untuk mengambil alih akun tersebut.
- OTP owner/platform tetap memakai jalur sebelumnya.

## Validasi dan migrasi

- 94 tes backend terkait lulus, 1268 assertions: autentikasi/pendaftaran, transaksi, isolasi toko, izin staf, pengirim SMTP, pembatasan bot, dan antrean notifikasi.
- 43 tes worker lulus, termasuk acknowledgement pesan, pengiriman notifikasi pada paket Web, dan penolakan perintah chat tanpa lisensi.
- 21 tes frontend lulus; build TypeScript/Vite web dan TypeScript worker berhasil; Pint dijalankan.
- Migrasi `2026_10_20_071258_add_notification_settings_to_stores_table.php` diterapkan ke MySQL lokal setelah backup `storage/app/private/backups/before-store-notifications-20261020-072315.sql` (diabaikan Git). Fixture SQLite UI juga dicadangkan dan dimigrasikan secara terpisah.
- Browser diperiksa pada data simulasi: modal SMTP, halaman koneksi paket Web, pendaftaran pilihan email, dan pergantian login email/password. Tidak mengirim WhatsApp/email ke penerima nyata. Bukti panel: `assets/store-notifications-panel.png`.

## Pengoperasian dan batas verifikasi

- Setelah deploy, jalankan `php artisan migrate --force`, build web/worker, restart bot manager beserta worker melalui pengelola proses, dan restart queue worker agar memuat kode baru. Jangan menghapus folder sesi WhatsApp.
- Bot manager lokal yang sudah berjalan perlu dimulai ulang untuk memuat endpoint pesan baru; sesi yang tersimpan tetap dipakai.
- Email bawaan memakai `MAIL_*` milik platform. SMTP toko sendiri harus memakai alamat pengirim yang diizinkan penyedia (SPF/DKIM sesuai penyedia jika menggunakan domain sendiri). Status "Terkonfigurasi" bukan bukti email sudah sampai; tersedia tombol email uji untuk pengelola toko.
- Tidak mengubah konfigurasi SMTP nyata, tidak mengganti pilihan pengirim toko yang ada, dan tidak mengklaim pengiriman layanan nyata sudah diuji.
- Source API Hanshop tidak diubah; tidak ada migrasi MySQL manual untuk API Hanshop pada tahap ini.
