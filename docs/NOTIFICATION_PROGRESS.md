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
