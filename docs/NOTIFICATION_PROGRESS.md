# Checkpoint UI dan pemisahan notifikasi — 20 Oktober 2026

> Checkpoint historis. Status source terkini dan koreksi trial/voucher 100% ada pada bagian sinkronisasi 21 Oktober di akhir dokumen serta ARCHITECTURE.md. Bagian rencana sebelum checkpoint bukan status implementasi terakhir.


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

## Perbaikan OTP lokal dan benturan manager

- Ditemukan siklus tunggu: request OTP Laravel menunggu manager, sementara worker memanggil endpoint akses Laravel kembali. Server `php artisan serve` lokal memakai satu proses, sehingga panggilan balik gagal saat request awal belum selesai.
- Pengiriman langsung kini memakai otorisasi request Laravel dan hak notifikasi yang sudah dibaca worker saat koneksi/heartbeat; tidak ada panggilan balik Laravel dalam pengiriman OTP. Pemeriksaan nomor, kondisi socket, status worker, dan lisensi tetap berlaku.
- Timeout acknowledgement worker 15 detik dan HTTP Laravel 20 detik; error membedakan manager versi lama, worker belum aktif, dan pengiriman yang belum dikonfirmasi.
- Benturan port manager kini menghasilkan pesan bahwa manager lain sudah berjalan. Error startup worker tidak lagi selalu menyebut sesi terkunci; kondisi sesi terpakai memiliki alasan `SESSION_IN_USE` sendiri.
- Manager tambahan yang sebelumnya dijalankan agen dihentikan agar tidak berbenturan dengan terminal pengguna. Tidak menghapus sesi WhatsApp; pengguna menjalankan satu `npm run manager:dev` di `apps/bot`.
- 40 tes backend terkait lulus (540 assertions) dan 43 tes worker lulus. Tes runtime worker mengirim OTP melalui transport simulasi ketika backend sengaja tidak dapat menerima panggilan akses; pengiriman berhasil tanpa request balik. Tes manager juga memverifikasi proses kedua tidak merusak manager pertama saat port berbenturan.
- Tidak melakukan pengiriman OTP ke nomor nyata dalam pemeriksaan ini.


## Kelanjutan 20 Oktober 2026: katalog, artikel, dan voucher

- Menyelesaikan perapihan empat halaman produk (sumber, layanan/kategori, katalog/harga, stok), modal, bantuan ringkas, dan dropdown mengambang. Perubahan sebelumnya tetap dipertahankan; tidak mereset data atau sesi WhatsApp.
- Badge lonceng memakai ukuran tersendiri agar pembesaran tipografi panel tidak mengubah bentuk/posisinya.
- Renderer artikel mengikuti warna teks tema storefront; warna foreground/background inline lama tidak lagi menimpa tema. Format huruf, tautan, daftar, dan gambar dipertahankan. Editor tetap putih; kontrol warna manual dihapus agar konsisten dengan hasil publik. Tabel artikel menggunakan warna sel/header panel yang sama dengan tabel lain (putih pada tema terang).
- Modul **Voucher diskon** berada di Penjualan toko; staff memerlukan `vouchers.view` atau `vouchers.manage`. Admin memiliki **Voucher promo** di sidebar platform. Form tambah/edit berbentuk modal; sasaran memakai dropdown pencarian, multi-pilih dan pemuatan saat scroll; tabel utama memakai filter dan pagination atas/bawah.
- Voucher toko mendukung semua produk atau gabungan beberapa layanan, kategori, dan produk (cocok salah satu sasaran). Voucher platform mendukung Bot, Web, Bot+Web, beberapa paket/durasi, serta modul QRIS custom. Kode uppercase unik per ruang toko/platform. Kode yang dihapus tidak dipakai ulang; invoice lama tetap memakai snapshot.
- Potongan nominal/persen, batas maksimum potongan, minimum belanja, jadwal, status aktif, dan kuota tersedia. Satu voucher per invoice; potongan setelah flash sale; tidak berlaku untuk topup saldo. Promo ditanggung toko dan tidak menurunkan batas harga pemasok yang sudah dikutip. Refund berdasarkan harga setelah diskon, ditambah kode unik hanya jika kebijakan toko mengizinkannya; fee gateway mengikuti aturan sebelumnya.
- Invoice pending menahan kuota hingga dibatalkan/terkonfirmasi expired. Pembayaran produk yang sudah gagal tetap mengonsumsi voucher. Kuota simulasi toko dipisah dari produksi. Row lock dan current read menjaga kuota pada MySQL repeatable-read, termasuk permintaan platform dari toko berbeda. Total produk harus positif; tagihan layanan DigitalToko minimal Rp1.000 mengikuti API billing Hanshop.
- Input voucher tersedia di checkout bersama seluruh template, modal pembelian langganan, dan modul QRIS. Bot mendukung `#beli KODE TUJUAN | KODEVOUCHER`; konfirmasi tombol maupun teks membawa kode yang sama, harga dihitung ulang di server.
- Migrasi `2026_10_20_122931_create_discount_vouchers` sudah diterapkan pada MySQL lokal setelah dump penuh ke `storage/app/private/backups/before-vouchers-20261020-124436.sql`. Fixture UI SQLite dicadangkan dan dimigrasikan secara terpisah. Tidak ada perubahan database/source API Hanshop.
- Validasi: 132 tes backend (1.358 assertions), tes MySQL terisolasi dengan proses bersamaan (41 assertions), 44 tes bot, 21 tes frontend, Pint, TypeScript dan build web lulus. Tes voucher terakhir setelah perapihan: 9 tes/62 assertions lulus.
- Pemeriksaan browser memakai data simulasi: membuat voucher kategori, memilih sasaran, pratinjau potongan Rp10.000 menjadi Rp9.000 tanpa membuat pesanan, panel admin, badge lonceng, tabel artikel tema terang, dan warna artikel lama mengikuti `--sf-text`. Bukti: `assets/voucher-panel-preview.png` dan `assets/voucher-checkout-preview.png`. Tidak mengirim pembayaran/OTP/WhatsApp/email nyata.
- Deploy: backup lalu `php artisan migrate --force`, build web dan bot, restart queue worker serta satu bot manager untuk memuat parser voucher. Jangan menghapus sesi WhatsApp.


## Sinkronisasi dokumentasi — 21 Oktober 2026

- Source terbaru menghapus route/tombol trial dan requestTrial. Trial aktif lama dihormati sampai expiry tanpa grace; trial belum dimulai kembali draft. Seeder demo-bot menggantikan skenario trial tanpa menghapus record existing.
- Voucher platform dapat melunasi invoice sewa/modul Rp0 secara lokal tanpa QRIS/API billing. Pembelian awal tetap provisioning/aktivasi; renewal/change/downgrade mengikuti lifecycle normal. Request checkout langganan membawa request_id; replay invoice yang sama tidak menggandakan durasi atau kuota. Voucher produk customer tetap harus menyisakan harga positif; total platform berbayar minimal Rp1.000. Ini menggantikan keterangan minimal Rp1.000 tanpa pengecualian pada checkpoint sebelumnya.
- Admin dan seluruh Penjualan owner/staff sudah memakai perapihan tabel/modal/kontrol; tab modal, tombol sekunder/ikon/retry dan pilihan storefront diberi permukaan sesuai tema. Bukti serta hasil pengujian saat perubahan dibuat ada di UI_READABILITY_AUDIT.md.
- Katalog/checkout/OTP/notifikasi bukan lagi skeleton. README root/web/bot/storefront, arsitektur, keputusan, domain, hak staff dan panduan deployment diperbarui untuk memisahkan implementasi dari rencana. Custom-domain self-service/DNS/TLS dan purge toko penuh tetap belum tersedia. Endpoint cleanup profil owner tanpa toko sudah ada; file KTP privat tidak sama dengan enkripsi file aplikasi.
- Pembaruan dokumentasi ini tidak mengubah source aplikasi, dependensi, database, konfigurasi layanan, atau deployment. Pemeriksaan berupa konsistensi route/command/permission, path/tautan dan diff; tidak menjalankan pembayaran/OTP/pembelian pemasok atau mengklaim rerun tes historis.


## Checkpoint 4 November 2026 — profil, maintenance, email dan logo

### Pekerjaan yang sudah tersimpan

- `269edc9`: email owner wajib diverifikasi melalui popup, profil akun owner, dan perubahan nama/WA/email pelanggan. Kontak baru diverifikasi OTP; profil owner disinkronkan ke API Hanshop melalui ID akun yang sama. Data KTP tetap terpisah. Pasangan perubahan API Hanshop ada pada commit `d3d1393` di repositori API, bukan di repositori Hansbot.
- `b7f5c91`: permintaan Inertia yang ditahan saat peninjauan restore dialihkan ke `/recovery-review`; API/JSON mempertahankan penolakan yang sesuai. Halaman menjelaskan login admin dan langkah Backup & pemulihan → Buka kembali layanan. Tidak menjalankan resume otomatis atau menghapus hold sebagai jalan pintas.
- `dfa9488`: maintenance global admin dan maintenance per toko owner, pesan pelanggan, tenggat opsional, countdown dan pembukaan otomatis berdasarkan waktu server. Landing platform/panel, pembayaran serta pembaruan pesanan lama dan callback tetap tersedia. Pengecekan baru dibedakan dari inquiry internal untuk failover pesanan pascabayar paid. Bot tetap terhubung dan melayani status/saldo. Email OTP/notifikasi memakai layout HTML bermerek beserta alternatif text/plain.
- `e77e343`: logo lokal halaman maintenance memakai path relatif storefront-media, mengikuti host halaman yang dibuka. Sebelumnya URL memakai alamat APP_URL laptop yang tidak lagi dapat dijangkau. File logo toko yang diperiksa tersedia dan endpoint gambar melalui host aplikasi saat itu membalas HTTP 200.

### Konfigurasi dan operasional yang dilakukan

- Diagnosis SMTP lokal menemukan port 587 timeout; koneksi TLS dan autentikasi pada port 465 berhasil. Konfigurasi lokal diubah ke MAIL_SCHEME=smtps / MAIL_PORT=465. MAIL_ENCRYPTION=ssl diselaraskan untuk konfigurasi lama; konfigurasi Laravel saat ini membaca MAIL_SCHEME. Username/password tidak diubah atau dicatat. `.env` tetap diabaikan Git.
- Restart web dan sinyal restart queue dilakukan saat pembaruan SMTP; worker/manager juga dimuat ulang saat modul maintenance/email diterapkan. Ini catatan tindakan saat pengerjaan, bukan pemantauan status proses secara terus-menerus.
- Backup terenkripsi terverifikasi dibuat sebelum migrasi `2026_11_03_155820_create_maintenance_windows_table.php` diterapkan lokal. Modul maintenance tidak menambah dependency atau mengubah schema API Hanshop. Petunjuk deploy/migrasi/restore versi lama ada pada DEPLOYMENT.md.
- ZIP pengguna dan arsip `.old-whatsapp-*` dari restore tidak dimasukkan ke commit pekerjaan ini. File/sesi tersebut tidak dihapus atau diubah untuk merapikan status Git.

### Bukti validasi dan batasnya

- Profil: suite web 733 tes, 728 lulus dan 5 opsional dilewati; suite API 221 tes, 216 lulus dan 5 opsional dilewati; frontend 40 lulus. Ini hasil saat fitur profil dikerjakan.
- Respons recovery: 51 tes terkait, 50 lulus dan 1 opsional dilewati. Hold/lease pengujian terisolasi.
- Maintenance/email: suite web 749 tes, 744 lulus dan 5 opsional dilewati; bot 76 dan frontend 40 lulus; build web/bot serta Pint lulus. Browser memakai data simulasi untuk modal admin/owner, deadline/countdown, pembukaan storefront otomatis, template email dan lebar 320/390 px.
- Perbaikan logo terakhir: 25 tes, 239 assertion lulus. Tes mencakup host APP_URL berbeda, gambar tetap dapat diakses ketika maintenance aktif, dan struktur MIME OTP/notifikasi: referensi CID cocok dengan lampiran inline serta byte gambar yang sama. Tidak mengirim pesan SMTP nyata. Log hasil lokal berada di storage/framework/testing dan tidak masuk Git.
- Pembacaan file/logo dan pemeriksaan MIME bukan bukti bahwa klien email penerima sudah menampilkan gambar. Pengujian sebelumnya memakai transport array/fake atau render preview, bukan pengiriman ke inbox pengguna. Folder Sent Items umumnya tidak otomatis terisi oleh pengiriman SMTP aplikasi.

### Hal yang masih menunggu konfirmasi

1. Keluhan logo pada email belum boleh dinyatakan selesai pada sisi inbox: masih menunggu informasi apakah email yang dimaksud OTP owner DigitalToko atau OTP/notifikasi pelanggan toko. OTP owner memang memakai logo DigitalToko; email pelanggan memakai logo toko yang tersimpan.
2. Setelah jenis email diketahui, periksa email baru dan klien email yang dipakai bila gambar tetap tidak terlihat. Bedakan logo yang tidak dimasukkan ke pesan, file gambar yang gagal diakses, dan penampilan gambar oleh klien email. Email yang sudah diterima sebelumnya tidak berubah saat template diperbarui.
3. Validasi pengiriman email nyata pada konfigurasi publik/produksi belum dilakukan. Konfigurasi SMTP, nama domain publik dan DNS email tetap perlu diperiksa saat deployment. Jangan mencantumkan credential atau OTP nyata dalam catatan/repo.

Pembaruan checkpoint ini hanya dokumentasi. Tidak mengubah konfigurasi/data, menyalakan maintenance, mengirim email, atau mengulang suite tes di atas.
