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


## Tema panel dan billing — 13 Oktober 2026

Tema light/dark/system diverifikasi melalui browser pada login, dashboard owner/admin/staff, form paket dan layar ponsel 390 px. Tema tersimpan lintas reload/navigasi. Screenshot di assets/panel-light-validation.png dan assets/panel-dark-validation.png.

Billing mencakup CRUD penawaran admin, invoice scoped per toko, gateway Hanshop, snapshot harga/durasi, aktivasi pertama/manual dan otomatis 24 jam, trial sekali owner, renewal, perubahan paket/downgrade, grace tujuh hari dan pemeriksaan staff. Tes Laravel mencakup replay pembayaran, snapshot, batas timestamp, pembayaran mismatch/transport gagal, cross-store dan staff hanya renewal. Tes API MySQL memakai database sementara `dt_billing_test_*`, QR stub, saldo dummy: retry paralel, mutasi sekali pakai, saldo tidak bertambah, expiry, ambiguitas, pembayaran terlambat. Database test dibuang setelah selesai. Tidak ada pembayaran atau pengiriman OTP live dilakukan.

Harga demo nonaktif; penyelesaian transaksi QRIS nyata dari perangkat Android hingga aktivasi masih perlu uji operator. Worker bot disiapkan operator, katalog/checkout/pelanggan dan purge bukan klaim selesai dari milestone ini.

## Perapihan panel owner/staff — 14 Oktober 2026

- 65 tes Laravel / 701 assertions lulus, termasuk navigasi yang terisolasi per owner/toko, pilihan toko lintas halaman, pembuatan toko langsung ke paket tanpa memulai durasi, pengaturan staff sesuai permission, tujuan login/logout staff, dan pembayaran sebelum aktivasi trial yang belum digunakan. Suite langganan tetap mencakup replay pembayaran, trial, renewal, perubahan paket, aktivasi, serta batas tenggang.
- TypeScript/Vite produksi dan Pint berhasil. Tampilan ringkasan, daftar toko, profil, pembuatan toko, pengaturan/staff, pilihan paket/durasi, pembayaran QRIS, dan aktivasi menggunakan satu layout. Harga terpilih mengikuti durasi; penjelasan pembayaran membedakan pembelian awal, renewal, perubahan langsung, dan downgrade terjadwal.
- Pemeriksaan browser menggunakan harness GET-only dengan SQLite/sesi terpisah dan data simulasi; seluruh mutasi diblokir. Tidak memakai akun pelanggan, melakukan pembayaran, mengaktifkan toko nyata, atau mengubah sesi WhatsApp. Sidebar desktop, light/dark, drawer ponsel 390 px, pemilihan toko, konfirmasi pembelian/aktivasi (dibatalkan), dan pembatasan tombol staff diperiksa.
- Bukti tampilan ada di `assets/owner-overview-after.jpg` dan `assets/owner-subscription-after.jpg`. Harness hanya berada dalam storage yang diabaikan Git dan server pemeriksaannya dihentikan setelah selesai. Server development pengguna tidak dihentikan.
- UI pairing mandiri dan provisioning otomatis worker belum tersedia; panel menjelaskan kebutuhan koneksi bot, menampilkan status kesiapan, dan mencegah aktivasi sampai terhubung. Katalog, stok, checkout pelanggan, integrasi saldo pemasok, serta purge tetap pekerjaan tahap berikutnya.
- Catatan tahap di atas diperbarui pada 14 Oktober: UI pairing dan manager worker sekarang tersedia, sebagaimana validasi berikut. Modul transaksi produk dan purge tetap belum tersedia.

### Modal untuk tindakan tambah/edit/hapus

Buat toko dari ringkasan, daftar toko, dan profil sekarang membuka modal. URL pembuatan langsung tetap tersedia dan menampilkan modal yang sama. Tambah/edit staff, edit identitas/template toko untuk owner maupun staff berizin, serta tambah/edit penawaran admin menggunakan modal bersama. Konfirmasi hapus memakai komponen yang sama; penghapusan hanya diajukan setelah konfirmasi.

Browser dengan fixture GET-only memverifikasi data edit, form kosong saat tambah, validasi field wajib yang mempertahankan modal, Escape/Batal, pengembalian fokus, scroll latar terkunci, light/dark, serta viewport 390 px tanpa overflow. Tidak ada simpan/hapus atau pembayaran sungguhan dilakukan. Build TypeScript/Vite dan Pint lulus; 9 tes alur panel / 270 assertions tetap lulus. Bukti tampilan: `assets/owner-staff-modal-after.jpg`.

### Pagination dan filter daftar

- 71 tes Laravel / 929 assertions lulus. Enam tes baru memverifikasi data per halaman, pencarian lintas halaman, isolasi owner/staff, filter paket/status/layanan, pencarian invoice berdasarkan snapshot, input query tidak valid, karakter wildcard literal, halaman di luar batas, dan opsi toko yang tetap terbatas saat jumlah toko meningkat.
- Daftar toko owner/admin, penawaran admin, pembayaran sewa, pengajuan verifikasi, dan riwayat invoice mengambil data per halaman dari server. Filter tampil di frontend dengan pencarian, status/layanan/paket sesuai jenis daftar, jumlah per halaman, Reset, nomor halaman serta total hasil. Partial reload hanya memperbarui data daftar terkait. Angka ringkasan menggunakan agregasi seluruh toko; invoice aktif tidak ikut tersaring oleh filter riwayat.
- Staff tetap maksimal lima akun sehingga difilter/paginasikan lokal; contoh katalog storefront juga memakai pagination/filter lokal. Katalog transaksi sebenarnya belum terhubung. Opsi layanan, template, permission, dan ringkasan statistik berjumlah tetap dan bukan daftar data yang berkembang.
- TypeScript/Vite produksi dan Pint lulus. Browser fixture terpisah memverifikasi halaman kedua toko/paket, filter yang kembali ke halaman pertama, filter invoice lunas, hasil kosong verifikasi, pembayaran tetap terlihat saat riwayat difilter, pencarian/pagination katalog pratinjau, serta layar ponsel 390 px tanpa overflow. Tidak mengubah pembayaran/data toko nyata. Screenshot `assets/owner-list-pagination-after.jpg`; server pemeriksaan sementara dihentikan setelah selesai.

### Tabel verifikasi/pembayaran dan navigasi atas

Verifikasi owner dan pembayaran sewa di panel admin memakai tabel ringkas. Identitas lengkap, dokumen KTP privat, keputusan, dan alasan penolakan tersedia melalui modal Periksa/Detail; token referensi pengajuan dan validasi backend tetap digunakan. Label status invoice tetap membedakan aktivasi, paket diterapkan, dan downgrade terjadwal.

Semua daftar dengan pagination memiliki navigasi Sebelumnya/Berikutnya di atas data, termasuk daftar kartu, dengan pagination bawah tetap dipertahankan. Kedua kontrol memakai metadata dan callback yang sama. Browser fixture memverifikasi perpindahan halaman dari kontrol atas, sinkronisasi kontrol bawah, modal pemeriksaan/field alasan wajib tanpa menyimpan keputusan, light/dark, dan tabel ponsel yang dapat digeser tanpa overflow halaman. Status fixture yang sementara dibuat pending dipulihkan setelah pemeriksaan.

Build TypeScript/Vite lulus; 29 tes pagination/onboarding terkait / 488 assertions lulus. Bukti tampilan: `assets/verification-table-after.jpg`, `assets/billing-table-after.jpg`, dan `assets/owner-list-pagination-after.jpg`. Tidak ada data pembayaran atau keputusan verifikasi nyata diubah. Server dan tab pemeriksaan sementara ditutup setelah selesai.

### Koneksi WhatsApp mandiri dari panel

- Owner/staff dengan `bot.manage` memilih QR/kode serta nomor lewat modal. `bot.view` tidak menerima QR, kode, atau tombol mutasi. Endpoint memeriksa role/toko, staff aktif, layanan bot, status paket, dan lock per toko. Token worker server-only dirotasi per percobaan; event lama dan nomor tertaut yang salah ditolak. Kesiapan aktivasi membaca status terbaru dari percobaan yang sesuai.
- Tes Laravel mencakup normalisasi 08/+62, akses lintas owner/staff, draft/web/expired/suspended, batas grace/trial, kesalahan manager yang dapat dicoba ulang, kedaluwarsa dan penghapusan secret, login staff khusus bot, dan command yang sedang diproses. Halaman pairing memakai Cache-Control no-store serta enkripsi riwayat Inertia.
- Endpoint internal bot sekarang membatasi 120 permintaan per menit **per toko**, sehingga worker yang berbagi IP VPS tidak saling menghabiskan kuota heartbeat. Token tidak valid memakai batas terpisah per IP. Tes menghabiskan kuota satu worker dan memastikan worker toko lain pada IP yang sama tetap dapat membaca akses.
- Sepuluh tes Node lulus: PNG QR lokal, satu permintaan kode per socket, kode yang terlambat sesudah close, transport gagal, identifikasi nomor PN dibanding LID, token tombol, API worker offline, serta autentikasi/idempotensi/isolasi/restart registry manager. Worker pada tes manager menerima can_connect=false, sehingga tidak membuka socket WhatsApp nyata.
- Migrasi kolom koneksi diterapkan pada MySQL lokal DigitalToko setelah backup penuh 33.111 byte disimpan dalam storage privat. Konfigurasi manager lokal ditulis tanpa menampilkan token. Database Hanshop, saldo, pembayaran, dan sesi bot Hanshop tidak diubah.
- Browser memakai harness GET-only dan database/sesi simulasi terpisah untuk QR, kode, modal nomor/metode, staff lihat/pengelola, serta light/dark dan layar 390 px tanpa overflow. Bukti: `assets/bot-connection-modal.jpg` dan `assets/bot-connection-mobile.jpg`. Penautan WhatsApp nyata serta tampilan tombol di HP masih memerlukan uji dengan nomor pengguna.

### Perbaikan waktu permintaan kode pairing (14 Oktober 2026)

Kode pairing menunggu event QR dari server. Pada Baileys rc14 yang terpasang, event connecting dipancarkan pada nextTick sebelum socket/handshake siap; memanggil requestPairingCode saat itu dapat gagal Connection Closed dan menghentikan percobaan sebelum QR tiba. Tes regresi mensimulasikan connecting dengan transport belum terbuka, lalu QR setelah handshake, dan memastikan kode baru diminta satu kali setelah siap.

Build TypeScript dan 12 tes bot lulus. Diagnostik kegagalan hanya mencatat reason/status_code, ID toko dan metode, tanpa nomor, kode, QR, pesan error mentah atau credential. Worker baru memakai hasil build ketika pengguna menekan Buat pairing baru pada panel. Penautan nyata tetap perlu dilanjutkan pengguna di HP; tidak ada sesi toko atau proses manager aktif yang dihapus/restart selama perbaikan ini.

### Penyelarasan pairing dengan bot_wa_v2 (14 Oktober 2026)

Perbandingan source dan AGENTS.md bot_wa_v2 menemukan bahwa metode kode memakai Browsers.macOS("Chrome"), sedangkan DigitalToko masih memakai "Google Chrome" untuk kedua metode. Helper Baileys rc14 memetakan Chrome ke CHROME/1 dan label Google Chrome ke OTHER_WEB_CLIENT/9. Socket dan patch acknowledgement kedua instalasi sama setelah normalisasi newline; perbedaan browser berada di konfigurasi aplikasi.

DigitalToko sekarang memilih identitas canonical Chrome untuk kode, mempertahankan identitas QR, memulihkan metadata pairing belum terdaftar sebelum socket dibuat, dan memakai batas query 60 detik sesuai default Baileys. Tes memeriksa ID protokol nyata dari helper Baileys yang terpasang dan preservasi credential terdaftar beserta key auth. Build dan 15 tes bot lulus; pengiriman permintaan kode serta penautan nyata masih perlu dicoba pengguna di panel/HP. Tidak ada klaim kode sudah diterima WhatsApp pada pengujian lokal ini.

### Konfirmasi pengguna untuk checkpoint Git (14 Oktober 2026)

Setelah penyelarasan tersebut, pengguna mengonfirmasi bot berhasil terhubung melalui pairing kode, lalu melaporkan pengujian pesan dan balasan bot berjalan normal. Ini konfirmasi runtime dari pengguna untuk koneksi dan balasan dasar, melengkapi 84 tes Laravel/1.156 assertions serta 15 tes bot yang telah lulus. Konfirmasi ini tidak membuktikan transaksi produk atau render tombol native pada seluruh perangkat; modul katalog/saldo/checkout produk tetap belum tersedia.

### Pusat notifikasi panel dan toast tindakan (14 Oktober 2026)

- Panel admin, owner, dan staff memiliki lonceng dengan jumlah belum dibaca, dropdown riwayat, filter Semua/Belum dibaca, tandai satu/semua, serta pagination sepuluh item dengan kontrol atas dan bawah. Riwayat disimpan per akun pada database; staff dibatasi pada tokonya dan tautan mengikuti permission terbaru. Riwayat mulai direkam setelah fitur ini diterapkan.
- Hasil tindakan panel menggunakan flash native Inertia 3 dan toast kanan bawah yang dapat ditutup, hilang otomatis, serta berhenti menghitung waktu saat hover/fokus. Refresh, navigasi kembali, dan polling tidak mengulang toast tindakan yang sama. Validasi field dan peringatan operasional tetap tersedia di halaman. Pembaruan verifikasi, pembayaran sewa, masa tenggang/berakhir, dan koneksi bot juga disimpan dengan kunci event agar tidak duplikat; QR, kode pairing, credential, dan data KTP tidak disimpan dalam notifikasi.
- 95 tes Laravel / 1.239 assertions lulus, termasuk sebelas tes baru untuk pagination/filter, isolasi antarakun/toko, akses staff nonaktif, tautan setelah permission dicabut, read idempotent, flash sekali pakai, validasi tanpa input sensitif, event berulang, dan cascade penghapusan. Build TypeScript/Vite serta Pint lulus. Tidak mengubah dependency.
- Migrasi `panel_notifications` diterapkan pada MySQL DigitalToko lokal setelah backup penuh 35.788 byte di storage privat. Database API Hanshop tidak diubah.
- Browser memakai SQLite/sesi terpisah dengan data simulasi. Hanya edit pengaturan satu toko fixture dan penandaan notifikasi diizinkan; pembayaran, keputusan KTP, dan koneksi bot nyata tetap diblokir. Nama fixture dipulihkan. Dropdown, pagination atas, filter, read/read-all, persistensi sesudah refresh, toast setelah simpan, navigasi kembali, Escape, panel staff/admin, serta light/dark pada desktop dan ponsel 390 px diperiksa. Tampilan ponsel tidak melampaui lebar viewport.
- Bukti: `assets/notifications-dropdown-after.jpg`, `assets/notifications-toast-after.jpg`, `assets/notifications-mobile-dark-after.jpg`, dan `assets/notifications-toast-mobile-dark-after.jpg`. Server/tab pemeriksaan sementara ditutup setelah selesai; server development pengguna tetap berjalan. Ini bukti panel lokal, bukan pengujian pengiriman WA/email atau deploy VPS.

### Riwayat notifikasi dengan scroll otomatis (14 Oktober 2026)

Kontrol halaman atas/bawah pada dropdown notifikasi diganti infinite scroll. Sepuluh item dimuat setiap permintaan, lalu ditambahkan ketika mendekati akhir daftar, dengan indikator loading, retry setelah gagal, dan penanda saat riwayat habis. Cursor berdasarkan waktu dan ID menjaga kelanjutan riwayat ketika notifikasi baru masuk atau item pada filter belum dibaca ditandai dibaca. Polling tidak mengganti daftar saat dropdown terbuka. Tandai dibaca mempertahankan item yang sudah dimuat dan posisi scroll.

Dua belas tes notifikasi / 104 assertions, build TypeScript/Vite, dan Pint lulus. Browser fixture memverifikasi pemuatan dari 10 menjadi 20 item, daftar tetap berisi 10 saat server simulasi dimatikan, retry menambah menjadi 20 tanpa duplikasi, filter belum dibaca, penandaan satu item tanpa mereset 20 item yang sudah dimuat, serta scroll pada ponsel 390 px dalam dark mode. Tidak ada perubahan data pelanggan atau transaksi nyata. Bukti terbaru: `assets/notifications-scroll-after.jpg` dan `assets/notifications-scroll-mobile-after.jpg`; kontrol pagination pada daftar lain tetap tersedia.
