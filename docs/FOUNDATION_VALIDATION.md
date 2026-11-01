# Validasi fondasi 13 Oktober 2026

> Riwayat pengujian bertanggal, bukan inventaris fitur saat ini. Untuk kondisi source per 21 Oktober 2026 gunakan ARCHITECTURE.md, BUSINESS_RULES.md dan README aplikasi. Pernyataan lama seperti fitur belum tersedia, trial, renderer artikel berwarna manual, atau pengiriman customer lewat bot pusat dapat sudah digantikan revisi berikutnya. Jumlah tes di sini adalah hasil saat catatan dibuat, bukan rerun pada pembaruan dokumentasi.


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

## Katalog toko — 14 Oktober 2026

- `php artisan test --compact`: 109 tests / 1395 assertions lulus. 13 tes katalog mencakup cache per akun/kegagalan, sync atomic, mode pasca/prabayar, entitlement, isolasi toko/staff, import idempotent/kode bentrok, preservasi spasi credential, markup/inheritance/ceil, sumber termurah/expiry/stale, stok terenkripsi/reveal, dan kesamaan proyeksi web/bot.
- `npm run test:frontend`: dua tes parser stok batch lulus, termasuk CRLF dan preservasi spasi credential. `npm run build` memeriksa TypeScript dan build frontend.
- Bot: 17 tes lulus; katalog/paging/pencarian/validasi respons publik dan token navigasi ditambah pada regression pairing/manager existing.
- API Hanshop: check/build lulus; 148 tes lulus dan satu tes MySQL billing opt-in dilewati. Reader katalog memakai mock pool; tidak ada pembelian/debit/live supplier pada pengujian ini.
- Predis diuji read/write/lock pada Redis lokal. Migration additive Laravel diterapkan pada MySQL lokal setelah backup privat. Seeder dummy hanya dijalankan pada database pengujian.
- Browser fixture terisolasi: sinkron pasca, impor, tambah produk dengan beberapa parsing, markup produk mengalahkan kategori, stok batch, reveal berizin, staff baca, template Aurora/Nebula/Atlas dan modal mobile gelap. Harga contoh 7000 + 5% + 5000 = 12350 terlihat sama di panel dan storefront.
- Bukti ini tidak menyatakan credential Digiflazz/Hanshop tenant nyata sudah dikonfigurasi atau pesan katalog WhatsApp live sudah diuji. Tidak ada perubahan VPS/DNS.

## Perbaikan sidebar dan koneksi Hanshop — 15 Oktober 2026

Sidebar workspace sekarang memiliki overflow vertikal, tinggi viewport dinamis, dan elemen menu yang tidak menyusut. Browser fixture diuji dengan scroll mouse pada desktop 1280×600 dan ponsel 390×600: menu Pengaturan toko serta Staff & hak akses dapat dijangkau, dan Escape menutup drawer.

Kegagalan koneksi Hanshop dibedakan dari rate limit: jaringan/token/endpoint/server memakai backoff 15 detik dengan pesan penyebab yang tetap tampil saat retry; HTTP 429 memakai 120 detik. Cache snapshot per akun tetap dipertahankan. Marker cooldown boolean versi lama tidak lagi menyembunyikan alasan kegagalan. Respons/error transport mentah tidak ditampilkan.

Validasi: 111 tes Laravel / 1451 assertions lulus, Pint lulus, frontend TypeScript/build lulus. Dua regression test mencakup server tidak terjangkau, token ditolak, endpoint belum tersedia, HTTP 503/429, retry tanpa membanjiri API, dan pemulihan setelah backoff. Pemeriksaan read-only lokal menemukan API Hanshop 127.0.0.1:4000 belum berjalan; koneksi nyata berhasil belum diklaim.

## Pemulihan QR bot — 15 Oktober 2026

Penyebab pada source: clearIncompletePairingState memakai registered sebagai satu-satunya tanda berhasil, sedangkan pair-success QR Baileys rc14 mengirim identitas me/account sebelum registered berubah. Cleanup sekarang mempertahankan identitas bertanda tangan; deadline pairing ditutup saat isNewLogin, antrean credential ditunggu sebelum reconnect, dan restartRequired 515 menggunakan jeda 1 detik. Diagnostic status code ditambahkan tanpa payload rahasia.

Build TypeScript dan 21 tes bot lulus, termasuk dua tes proses worker dengan transport lokal, penyimpanan lambat/gagal, backend event asli melalui HTTP loopback, dan file sesi sementara. Phone scan nyata masih memerlukan percobaan pengguna. Manager lokal merespons health SUCCESS dengan nol worker pada pemeriksaan akhir; worker yang dimulai berikutnya akan memakai dist terbaru. Tidak ada sesi pengguna yang dihapus untuk perbaikan ini.

## Paket planet dan checkpoint pekerjaan — 15 Oktober 2026

Nama/rincian sembilan paket dipusatkan di resources/js/packageCatalog.json: Mercury, Venus, Terra, Mars, Jupiter, Saturn, Uranus, Neptune, Andromeda. Landing menampilkan sembilan kombinasi berurutan; panel admin memakai pemilihan paket lengkap dan rincian fitur; owner/staff menampilkan nama sesuai kategori/sumber, termasuk trial Jupiter. Filter paket memeriksa kanal dan sumber sekaligus.

PackagePlanSeeder dijalankan pada MySQL lokal setelah backup privat plan_options (9 row). Hasil: sembilan nama diperbarui, jumlah row tetap sembilan; ID/harga/durasi/aktif/kategori/sumber tetap sama. Hash state langganan toko dan snapshot invoice sebelum/sesudah identik. Seeder dapat diulang dan tidak membuat akun dummy atau menghubungi API. DatabaseSeeder lokal ikut memakai katalog paket baru.

Validasi: 114 tes Laravel / 1596 assertions lulus, dua tes parser frontend lulus, TypeScript/build berhasil, Pint lulus. Tiga tes paket mencakup sembilan kombinasi dan fitur, seed berulang, preservasi penawaran khusus serta invoice/langganan, urutan admin serta filter toko admin/owner tanpa mengubah angka ringkasan. Browser fixture: admin memilih Neptune/Andromeda di modal; owner memilih Mercury 90 hari tanpa membuat invoice; sembilan nama landing; layar 390px tanpa overflow horizontal.

Checkpoint ini juga menyimpan perbaikan yang sudah selesai pada turn sebelumnya: sidebar dapat discroll, pesan kegagalan Hanshop membedakan penyebab, respons halaman stok kompatibel HTML/JSON Inertia (termasuk regression navigasi/filter), serta pemulihan QR bot dengan 21 tes lulus. Bukti WhatsApp nyata sesudah perbaikan tetap menunggu percobaan pengguna; API Hanshop source di repo terpisah tidak dimasukkan ke commit Hansbot ini.

## Pengelolaan toko admin — 15 Oktober 2026

Menu Kelola toko (`/admin/stores`) menampilkan toko lintas owner dalam tabel dengan pencarian toko/subdomain/kontak owner, filter paket/kanal/status/penangguhan, filter satu owner, dan pagination di header serta footer. Ringkasan tetap menghitung seluruh toko. Detail toko memuat kontak owner, paket, domain, masa aktif, status bot tanpa QR/kode rahasia, jumlah staff/produk/stok siap/pemasok, serta tautan pembayaran toko.

Modal admin menyediakan edit nama/template, koreksi tanggal berakhir langganan berbayar yang sudah aktif, penangguhan, dan pemulihan. Alasan wajib; audit menyimpan admin, waktu, nilai sebelum/sesudah; owner dan staff aktif menerima notifikasi panel. Koreksi masa aktif diblokir untuk trial, toko belum aktif, dan invoice pending/paid/review agar jadwal pembayaran tidak dilangkahi. Harga, invoice, subdomain, kepemilikan, dan hak paket tidak diedit oleh tindakan ini.

Penangguhan disimpan terpisah dari status langganan sehingga pembayaran invoice lama tidak menghapusnya. Gerbang pesanan storefront/bot, pairing bot, akses operasi katalog dan pembacaan saldo pemasok menolak toko ditangguhkan; sinkronisasi otomatis melewatinya. Worker membaca gerbang melalui akses/heartbeat existing, tanpa menghapus sesi WhatsApp. Masa sewa tetap berjalan; pemulihan menghitung kembali masa tenggang/kedaluwarsa dan tidak menambah durasi. Penangguhan hanya berlaku pada toko terkait. Konteks dan notifikasi admin tetap memakai guard web meskipun sesi staff juga tersedia.

Validasi: suite Laravel 121 tes / 1703 assertions lulus; sesudah penambahan regression sesi admin+staff dan koreksi ringkasan, 14 tes terkait admin/pagination / 351 assertions lulus. TypeScript/build frontend dan Pint lulus. Browser SQLite terpisah: edit template, tangguhkan/pulihkan, koreksi tanggal 8 ke 10 November, audit dan toast, pagination atas dari halaman 1 ke 2, modal 390px tema terang/gelap tanpa overflow halaman. Bukti: assets/admin-stores.jpg, assets/admin-store-detail.jpg, assets/admin-store-mobile.jpg. Pengujian UI tidak mengubah toko/transaksi nyata.

Migrasi additive diterapkan pada MySQL lokal sesudah backup stores ke storage privat yang diabaikan Git; hash seluruh kolom existing sebelum/sesudah migrasi identik. Tidak ada penghapusan toko, perubahan VPS/domain, pembelian, atau pengiriman WA nyata dalam pemeriksaan ini.

### Ubah paket melalui admin — 15 Oktober 2026

Tombol Ubah paket pada detail toko membuka modal dengan sembilan nama/rincian fitur. Koreksi administratif langsung berlaku tanpa membuat invoice, debit, atau refund; tanggal mulai/akhir tetap. Store.category/plan dan snapshot kategori/sumber/features diperbarui atomik, sementara harga/durasi yang telah dibayar serta snapshot invoice historis tetap. Draft tidak otomatis berlangganan; trial, provisioning, purging, pilihan identik/tidak valid, alasan kosong, serta invoice pending/paid/review ditolak. Penangguhan tidak dicabut. Riwayat dapat difilter ke tindakan Ubah paket dan menampilkan nama paket, kanal, serta sumber sebelum/sesudah; owner/staff aktif menerima notifikasi berisi perubahan dan alasan.

Validasi: 40 tes terkait admin/langganan/katalog/paket / 704 assertions lulus; tiga regression tambahan mencakup sembilan kombinasi, perubahan entitlement snapshot, preservasi riwayat/tanggal/penangguhan, pembatasan akses/invoice/trial, dan draft. TypeScript/build dan Pint lulus. Browser SQLite terpisah berhasil mengubah Andromeda ke Mercury, mencatat alasan/notifikasi serta mempertahankan tanggal, lalu memulihkannya ke Andromeda. Modal 390px dan filter riwayat diperiksa. Bukti: assets/admin-package-change.jpg. Tidak ada perubahan paket toko nyata atau pemanggilan pemasok/payment gateway dalam pemeriksaan.

## Format balasan dan klik tombol bot — 15 Oktober 2026

Bot toko memakai format heading/border serta pola Format/Ket yang mengacu source Hanshop, dengan branding tenant dan panduan command yang tersedia. Katalog menampilkan nama, kode, kategori, harga, ketersediaan/stok dan paging yang mempertahankan pencarian. Saldo/status belum memiliki backend transaksi pelanggan sehingga balasannya jelas menyebut belum diaktifkan, tanpa membocorkan saldo Hanshop owner atau nominal palsu.

Parser lama membaca satu path native tanpa normalisasi wrapper. Parser baru menormalisasi ephemeral/view-once memakai Baileys rc14, mendukung id/selected_row_id dan legacy button/template/list. Dispatcher tombol dan command berbagi handler. Token terikat identitas chat yang dinormalisasi beserta alternate PN/LID dari key Baileys, bukan payload customer; sekali pakai dan expiry 15 menit dipertahankan. Tombol invalid/terpakai/expired mengirim arahan dan menu baru. Relay/generasi native yang gagal tetap menyisakan fallback teks tanpa retry kirim ambigu.

Build TypeScript dan 28 tes bot lulus. Tujuh regression tambahan mencakup balasan asli hasil generator quick_reply → wrapper → PN/LID → handler saldo yang sama dengan #saldo, variasi reply, token lintas chat, expiry/restart, endpoint katalog/paging/search, fallback dan batas akses. Worker existing dimuat ulang oleh manager dari sesi yang sama, tanpa logout atau penghapusan sesi. Pengujian tidak mengirim pesan ke WhatsApp pelanggan; bukti render/klik pada HP nyata tetap menunggu percobaan pengguna.

## Navigasi katalog bot bertingkat — 15 Oktober 2026

Alur bot menjadi layanan → kategori utama → produk, mengikuti kolom service/category existing. `#list`/`#produk` membuka layanan, `#list <nama layanan>` membuka kategori, dan `#stok <kategori> [--layanan <nama layanan>]` membuka produk. Kategori identik pada beberapa layanan memunculkan pilihan konteks; kategori unik mengembalikan konteks parent untuk tombol kembali. Pencarian langsung #produk beserta paging lama tetap didukung. Native single_select dipakai pada daftar layanan/kategori, quick_reply pada paging/kembali; teks memuat command berparameter dan tetap dikirim dahulu.

Endpoint protected baru mengagregasi/paginate SQL, hanya toko dari worker token, hanya produk aktif, dan memakai StoreCatalog public projection. Tidak ada migrasi atau perubahan data produk. 19 tes Laravel katalog/navigasi lulus sebelum penambahan pemeriksaan parent kategori unik; empat tes navigasi dijalankan ulang setelah penyempurnaan parent dan paging produk scoped. Build dan 33 tes bot lulus, termasuk full chain dari payload list Baileys nyata, kembali/paging mempertahankan konteks, nama berspasi/quote/Unicode, recovery error, serta token lintas penerima. Endpoint database lokal juga merespons HTTP 200 dengan view services. Worker lokal dimuat ulang dari registry/sesi existing; tidak ada pesan pelanggan nyata yang dikirim oleh pengujian.

## Pengelolaan layanan dan kategori di panel — 15 Oktober 2026

Menu Layanan & kategori tersedia setelah memilih toko di panel owner/staff. Layanan dan kategori utama memiliki tabel, pencarian, pagination atas/bawah, modal tambah/edit serta konfirmasi hapus. Kategori terikat satu layanan; produk memilih kategori melalui pemilih berpencarian dan pagination. Tautan jumlah kategori/produk membuka daftar terkait, dan tambah produk dari kategori terpilih mengisi parent secara otomatis. Staff products.manage dapat mengelola, sedangkan akses baca tidak menampilkan tindakan. Seluruh lookup dan mutasi dibatasi toko serta status operasional.

Migrasi additive membuat catalog_services/catalog_categories dan category_id pada produk. Berbeda dari tahap navigasi bot sebelumnya, panel kini menyimpan struktur tersendiri agar layanan/kategori kosong dapat dibuat terlebih dahulu. Backfill memakai label lama; backup privat dibuat sebelum migrasi MySQL lokal. Hash seluruh kolom produk existing (selain category_id baru) dan seluruh aturan harga sebelum/sesudah identik, dan seluruh produk tertaut. Rename meneruskan nama ke produk serta memindah/menyalin aturan markup sesuai konteks; konflik aturan target diblokir. Hapus kategori berproduk atau layanan berkategori ditolak.

Validasi: tujuh tes khusus / 103 assertions lulus, mencakup CRUD, isolasi owner/toko, staff baca/kelola, status operasional, rename dan preservasi harga, kategori duplikat antar layanan, pemilihan produk, pagination/search, serta backfill legacy. Suite Laravel lengkap 136 tes / 2064 assertions lulus. Pint dan TypeScript/build lulus; build dijalankan ulang setelah penyempurnaan prefill kategori dan fokus modal.

Browser memakai SQLite simulasi terpisah: owner membuat layanan, kategori dan produk dengan satu parsing; produk tersimpan dalam kategori yang tepat dengan toast sukses. Staff pengelola melihat tindakan dan staff baca hanya melihat Akses baca. Modal staff pada viewport 390×844 dan tema gelap diperiksa. Bukti: assets/catalog-taxonomy.jpg dan assets/catalog-taxonomy-mobile.jpg. Pemeriksaan UI tidak mengubah katalog toko nyata atau memanggil pemasok/WhatsApp/payment gateway.

### Kejelasan aturan harga dan dropdown — checkpoint 15 Oktober 2026

Form harga produk dan aturan kategori/layanan membedakan Sumber markup dari Pembaruan harga otomatis. Atur markup sendiri menampilkan input wajib (termasuk nilai 0 yang eksplisit); mengikuti markup menyembunyikan input dan mengirim null agar aturan parent berlaku. Pembaruan otomatis tetap diwariskan secara independen. Pilihan layanan menyebut bawaan markup 0 dan pembaruan nonaktif karena tidak ada parent. Tabel dan bantuan form menyebut sumber yang diikuti, menggantikan label ambigu Ikuti tingkat berikutnya.

Pemilih nama aturan harga terbuka saat fokus, mendukung pengetikan, keyboard, dan pemuatan bertahap saat scroll, tanpa tombol prev/next. Query dibatasi toko, nama kategori duplikat antar layanan digabung karena aturan harga memakai nama kategori. Delapan tes struktur/pemilih / 120 assertions lulus. Pemeriksaan browser fixture membuktikan append batch, reset pencarian, simpan markup 0, ubah ke inherit tanpa menyimpan draft tersembunyi, serta produk mengikuti kategori 10% dengan modal 7.000 menjadi 7.700. Tema gelap desktop/390px diperiksa tanpa overflow horizontal. Bukti: assets/pricing-target-dropdown.jpg dan assets/pricing-settings-clear.jpg. Build frontend dan 23 tes katalog terkait / 324 assertions lulus. Perubahan tidak memigrasikan harga existing atau mengirim transaksi sungguhan.

Checkpoint ini menyimpan seluruh pekerjaan sejak checkpoint paket sebelumnya: pengelolaan toko dan perubahan paket admin, format/dispatch tombol bot, navigasi layanan-kategori-produk, struktur katalog owner/staff beserta migrasi, dan perbaikan pemilihan serta penjelasan harga. Tahap berikutnya adalah transaksi pelanggan bersama web/bot, gateway per toko, fulfillment, akun/saldo pelanggan, dan panel pemantauan; status implementasinya dicatat terpisah setelah checkpoint.


### Modul transaksi pelanggan bersama web/bot — 15 Oktober 2026

Checkpoint sebelum tahap ini: `bf51c2f` (`feat: checkpoint store administration bot catalog and pricing controls`). Riwayat pekerjaan sebelum checkpoint sudah dicatat di atas. Implementasi berikut menambahkan:

- Mesin `CustomerCommerce`, invoice/pesanan DT+ULID, tujuan/kontak, kanal, snapshot harga/aturan fee/refund/credential, idempotency dan percobaan pemasok per referensi. Pembayaran, saldo, refund dan stok memakai transaksi/row lock; payload credential/hasil privat terenkripsi. Satu event tidak menambah saldo atau memproses stok dua kali.
- Akun pelanggan per toko: WA OTP lima menit, satu kali pakai, pembatasan kirim/percobaan; email/password sesudah OTP verifikasi email; session guard customer terpisah dari owner/staff. Guest dapat diizinkan/dilarang toko, WA wajib. Riwayat, topup, mutasi, dan saldo pelanggan tidak memakai saldo Hanshop owner.
- QRIS Midtrans merchant masing-masing toko: snapshot server key/sandbox, charge dengan nomor order stabil, webhook signature plus GET status authoritative. QRIS custom berlisensi: EMV/CRC/IDR, nominal unik terkunci per merchant lintas toko, token Hans Forward privat dan deduplikasi event, pencocokan nominal/waktu/paket aplikasi. Kebijakan kode unik topup/refund berdiri sendiri dan dibekukan di invoice. Midtrans mengecualikan fee dari topup/refund produk.
- Pembelian lisensi custom satu kali per toko memakai billing existing API Hanshop. Admin mengatur harga di Modul pembayaran; awal 0 berarti penjualan belum dibuka. Lisensi tidak dipindah/refund, pemakaian membutuhkan sewa aktif. Ini terpisah dari saldo owner dan masa aktif toko.
- Fulfillment stok premium atomik mengambil stok ready termurah dan mengikatnya pada nomor order. Sumber remote mencoba parsing termurah dengan snapshot credential, harga maksimum dan referensi stabil. Kegagalan pasti boleh berpindah; timeout/status asing/pending tidak berpindah dan tidak direfund. Modal naik melebihi harga jual menggagalkan sebelum dispatch. Hasil sukses dengan harga tidak wajar masuk review.
- Pascabayar Digiflazz standar: inquiry, nama/periode/nominal sebenarnya, batas lima menit/hari yang sama, pay-pasca memakai referensi inquiry. Penanda sebelum I/O mencegah pengulangan pay-pasca setelah crash/hasil ambigu; pemeriksaan berikutnya memakai status-pasca. Inquiry hanya dipakai satu order. Umur hampir 90 hari masuk review tanpa request baru untuk mencegah topup prabayar baru saat cek status.
- Jembatan internal baru di repo API Hanshop: `src/modules/digitaltoko-identity/transactions.ts`, route supplier/transaction dan message. Memakai preparation/orchestrator/queue dan saldo owner existing; customer wallet tetap di Laravel. Reference scope/id_user/produk/tujuan diperiksa. Upstream GAGAL dengan saldo belum dikembalikan tetap PENDING, bukan izin fallback. Token dan key tidak diteruskan ke pelanggan.
- Bot `#beli`, konfirmasi tombol/teks, `#topup`, `#saldo`, `#status [nomor]` kini memakai backend transaksi nyata. PN dari key pesan menjadi identitas customer, LID tanpa PN tidak ditebak. Referensi pesan mencegah pembelian/debit ulang; tombol terikat penerima dan sekali pakai. Worker mengirim outbox ke penerima asli; kegagalan pengiriman tidak membuat order baru. Manager menyediakan encoding QRIS lokal melalui `/qr`, terpisah dari QR pairing.
- Checkout dan receipt web pada subdomain/custom domain; login, akun, topup, riwayat dan ledger. Hasil hanya pada receipt berotorisasi/token privat atau modal panel berizin. React/Inertia navigation diperiksa, termasuk perbaikan return type Response agar JSON Inertia tidak menyebabkan TypeError.
- Owner/staff: Transaksi pelanggan, detail percobaan, hasil privat, kirim ulang ke penerima asli, pencatatan tindak lanjut guest, Pelanggan & saldo, pencairan manual saat berhenti, serta Pembayaran pelanggan. Semua form tindakan memakai modal; daftar filter/pagination atas dan bawah. Permission kontak, hasil, resend, kebijakan pembayaran dan settlement dipisah. Owner bisa menyelesaikan pembayaran review yang belum pernah dispatch: topup dikreditkan, produk gagal mengikuti refund. Pending yang sudah dispatch tetap tidak dapat dipaksa gagal.
- Scheduler setiap menit merekonsiliasi pembayaran/fulfillment, mengirim pending notice satu kali setelah lima menit, lalu notifikasi perubahan status. Antrean pengiriman disimpan, fallback bot Hanshop mengirim receipt link bila bot tenant tidak tersedia; SMTP untuk email. Notifikasi berhenti ke pelanggan bersaldo, penutupan final diblokir selama kewajiban ada. Perpanjangan tidak menghapus saldo/order/ledger. Tidak ada pemaksaan gagal/purge otomatis setelah 30 hari.

Migrasi additive MySQL lokal selesai setelah backup privat penuh `before-customer-commerce-20261015-074613-d814b5.phpser.gz` (35.929 byte, serialized PHP data/schema digzip). Seluruh tabel bisnis existing dibandingkan sebelum/sesudah dan identik. Dummy commerce tidak dimasukkan ke database aplikasi nyata. Backup, fixture SQLite, key, sesi, hasil stok dan credential tidak masuk Git.

Validasi: suite Laravel lengkap 161 tes / 2254 assertions lulus (1 tes MySQL opt-in dilewati dalam suite default), termasuk 24 tes commerce; uji opt-in MySQL membuat database acak terisolasi, menjalankan proses PHP bersamaan untuk debit/idempotency, stok, nominal unik dan kredit pembayaran, lalu menghapus hanya database fixture yang divalidasi namanya. Uji tersebut lulus 1 tes/22 assertions. Bot 38 tes lulus. API Hanshop 154 tes lulus (1 uji DB billing opt-in dilewati); enam tes adapter baru lulus, TypeScript dan kontrak response lulus. Build TypeScript/Vite web dan bot, serta Pint lulus.

Browser menggunakan SQLite simulasi dan HTTP fake, bukan toko nyata: pagination header berpindah halaman; owner membuka hasil melalui modal; modal fee/kode unik tersimpan dengan toast; staff pengelola membuka transaksi dan staff tanpa izin mendapat 403; viewport 390 px tidak membuat overflow halaman; pembelian saldo dari checkout sampai stok berhasil, receipt dan saldo/riwayat akun diverifikasi. Bukti: `assets/customer-commerce-panel.png`. Tab dan server fixture 8010 sudah ditutup, server development milik pengguna tetap berjalan.

Batas bukti: belum ada pembayaran merchant nyata, OTP/pesan pelanggan nyata, pembelian Digiflazz/Hanshop nyata, atau deploy/DNS. Operator perlu konfigurasi merchant/lisensi/SMTP, menjalankan scheduler, serta restart manager/worker agar kode baru dimuat; jangan menghapus sesi WA. Prosedur dicatat di DEPLOYMENT.md dan apps/bot/README.md. Form tagihan khusus multi-parameter perlu adapter tersendiri. Perubahan API Hanshop berada di repo sebelah yang sudah mempunyai banyak pekerjaan lain; tidak dilakukan commit massal atas perubahan repo tersebut.

## Nebula khusus toko topup game — 15 Oktober 2026

Nebula diubah menjadi storefront gaming: banner carousel dengan ilustrasi original lokal WebP (229.774 byte), pencarian, navigasi bagian, flash sale, trending, katalog berfilter/pagination, leaderboard mingguan/bulanan, panduan order, dan footer Powered by DigitalToko. Aurora dan Atlas tetap tersedia.

Owner/staff berizin `web.manage` mengatur judul/deskripsi/gambar HTTPS banner, nama kategori promo, dan waktu berakhir melalui modal Pengaturan toko. Branding existing dipertahankan. Flash sale adalah sorotan produk tersedia dari kategori tersebut dengan harga katalog yang sama di checkout; tidak menerapkan diskon tersembunyi atau mengubah harga saat waktunya habis. Trending berdasarkan pesanan berhasil 30 hari. Leaderboard hanya nilai produk pesanan sukses/lunas, tanpa refund/topup saldo/fee, dipisah per toko; identitas memakai alias HMAC, tanpa nama/nomor/email/ID pelanggan publik. Angka contoh hanya pada route preview.

Validasi: 37 tes katalog/foundation/Nebula (444 assertions), Pint, TypeScript dan build Vite lulus. Browser pada SQLite/HTTP fake: banner berganti, pencarian menyaring game, tab periode berubah, menu anchor, pagination, modal promo dari halaman katalog lain, dan checkout menampilkan harga yang sama; pengaturan simulasi tersimpan dan terbaca pada storefront. Viewport 390 px tidak overflow horizontal. Tidak membuat invoice atau pembayaran nyata. Bukti: `assets/nebula-gaming-desktop.png`. Banner: `apps/web/public/storefront/nebula-gaming-hero.webp`.

## Editor desain owner/staff — 15 Oktober 2026

Tab Pengaturan toko → Desain web menyediakan logo/warna, maksimal lima banner (file JPG/PNG/WebP atau URL HTTPS, urutan, teks/tombol, gambar penuh atau overlay), pemilihan hingga 12 produk flash sale langsung dari katalog beserta jadwal, trending otomatis/manual, serta judul/urutan/visibilitas tujuh bagian Nebula. Katalog wajib tampil dan Powered by DigitalToko tetap berada di footer sistem. Logo, warna dan banner juga dibaca Aurora/Atlas; halaman checkout/akun memakai logo dan warna yang sama. Tambah/edit/hapus memakai modal dan perubahan baru diterapkan melalui Simpan desain; pembatalan modal tidak mengunggah gambar.

Konfigurasi berada pada JSON branding toko, tanpa migrasi baru. Endpoint berizin web.manage, product picker terfilter/paginated dan scoped per toko tanpa modal/credential pemasok. Revisi dikunci untuk menolak overwrite dari editor lama. Media disimpan lokal per UUID toko dan disajikan melalui URL relatif /storefront-media, sehingga tidak melekat pada domain. File SVG/non-image ditolak; unggahan maksimum 4 MB/6000 px. File baru dibersihkan jika penyimpanan gagal, file lama dihapus hanya setelah commit dan tidak lagi direferensikan banner/logo. Flash sale tetap memakai harga katalog/checkout dan tidak membuat aturan diskon lain.

Validasi: 69 tes terkait (723 assertions) lulus; delapan tes desain mencakup upload/asset cleanup/referensi bersama, kurasi dan urutan produk, checkout, isolasi/izin staff, pagination, invalid input, revisi bersamaan, multipart daftar kosong serta boolean. Setelah perbaikan penghapusan jadwal legacy, 11 tes desain/Nebula (204 assertions) diperiksa ulang dan lulus. Pint dan build TypeScript/Vite lulus. Browser dengan SQLite/HTTP fake: unggah banner WebP dan simpan multipart, pindah urutan, ganti flash sale dengan produk kategori lain, ubah judul, sembunyikan bagian, simpan warna hex, lalu verifikasi hasil pada storefront. Editor staff dan modal banner pada 390 px tidak overflow halaman. Bukti `assets/store-design-editor.png`. Tidak ada perubahan desain toko nyata atau transaksi/pembayaran nyata.


## Carousel otomatis dan tema warna toko — 16 Oktober 2026

Editor Desain web kini menyediakan carousel otomatis (interval 3–30 detik, aktif/nonaktif), navigasi manual dan tombol jeda. Perpindahan berhenti sementara saat hover/fokus, dokumen tersembunyi, atau preferensi reduced motion aktif. Setiap banner dapat membuka kategori katalog toko, URL HTTPS/path lokal, atau tidak memiliki tautan. Pemilih kategori memakai pencarian dan infinite scroll dengan hasil terbatasi per halaman, berizin web.manage dan hanya membaca produk aktif toko tersebut. Tautan kategori memakai path relatif sehingga tetap mengikuti domain toko.

Modal Identitas & tema warna menyediakan primary, secondary, background web, kartu/panel, teks utama, teks pendukung dan border, dengan preset Midnight/Daylight/Forest dan pratinjau langsung. Variabel tema berlaku pada Aurora/Nebula/Atlas serta checkout/akun. Prioritas CSS diperbaiki agar warna kartu/kategori bawaan Nebula tidak menimpa pilihan owner. Banner gambar penuh mempertahankan rasio 3:1 pada ponsel. Mengedit gambar unggahan yang sudah tersimpan tidak lagi terhalang validasi input URL HTML.

Tidak ada migrasi atau dependency tambahan. Desain lama tetap dibaca; nilai default baru hanya ditambahkan pada editor sampai desain disimpan. Validasi server menolak warna non-hex, interval di luar batas, kategori toko lain, URL berbahaya, dan revisi editor lama. Branding, media privat dan alur harga/checkout existing dipertahankan.

Validasi akhir: 15 tes desain/Nebula, 258 assertions, dan 4 tes frontend lulus; TypeScript/build Vite, Pint dan git diff --check lulus. Browser pada SQLite/HTTP fake membuktikan perpindahan banner otomatis 3 detik, tombol jeda, banner tanpa tautan, tautan kategori membuka filter Canva, penyimpanan tema dan banner, serta tema pada ketiga template. Storefront dan modal editor staff diuji pada viewport 390 px tanpa overflow; kartu tema terang berwarna putih sesuai pengaturan. Bukti: assets/store-theme-customizer.png. Pengujian tidak mengubah desain toko nyata atau menjalankan pembayaran/transaksi nyata. Tab dan server fixture ditutup setelah pemeriksaan.


## Stabilitas pemuatan UI / refresh Nebula — 16 Oktober 2026

Penyebab tampilan membesar sesaat adalah stylesheet storefront/desain yang diimpor oleh modul React terpisah, sedangkan HTML Nebula sudah dirender server sebelum stylesheet halaman tersebut tersedia. CSS dipusatkan ke entry resources/css/app.css dengan urutan digitaltoko → nebula → store-design dan dimuat melalui link stylesheet di head Blade. Import CSS pada Home/Commerce/StoreDesignPanel dihapus; manifest production hanya mempunyai satu entry CSS, tanpa stylesheet tambahan pada chunk Storefront/Home. Tidak menggunakan hide body, timeout tampilan, atau mematikan React StrictMode.

Logo platform/toko dan gambar banner diberi dimensi intrinsik. Waktu awal storefront/preview disediakan server melalui renderedAt agar hitungan mundur memakai snapshot yang sama pada SSR dan hydration. Countdown tetap berjalan pada komponen sendiri; timer tingkat halaman hanya memperbarui status saat batas jadwal promo tercapai, sehingga katalog/header/footer tidak dirender ulang setiap detik. Cleanup timer tetap berjalan saat unmount dan StrictMode.

Validasi: 16 tes desain/Nebula (269 assertions), termasuk HTML awal berisi stylesheet di head dan timestamp server, lulus. Build TypeScript/Vite dan Pint lulus. Manifest CSS tunggal 132,97 kB / gzip 24,82 kB. Browser pada server development 8000: HTML respons benar-benar memuat markup SSR Nebula dan stylesheet sebelum penutup head; refresh desktop/390 px, countdown, serta navigasi preview owner/staff/admin diperiksa tanpa error/warning React/hydration. Viewport ponsel 390 px, lebar konten 375 px dan font brand 19 px. Bukti assets/nebula-stable-refresh.png. Tidak mengubah data atau menjalankan transaksi toko nyata; server development pengguna tetap berjalan.


## Animasi, iklan awal dan customer service Nebula — 16 Oktober 2026

Pergantian slide Nebula kini memasang ulang lapisan banner berdasarkan ID agar animasi slide/fade 550 ms berjalan pada perpindahan otomatis maupun manual, sementara kontrol carousel tetap stabil. Ditambahkan kemunculan header/bagian halaman, hover kartu/gambar, respons tombol dan animasi dialog. CSS tetap melalui entry awal app.css, tanpa impor CSS dari React. Preferensi prefers-reduced-motion menonaktifkan animasi/transisi.

Desain web → Atur iklan & CS menyediakan popup (judul, pesan, upload JPG/PNG/WebP/URL HTTPS, tombol/tujuan opsional) dan CS WhatsApp (nomor, label, pesan awal). Keduanya nonaktif sampai owner/staff mengaktifkannya. Popup beranda tampil setelah 650 ms, dapat langsung ditutup/Escape, dan muncul sekali per sesi tab untuk isi iklan yang sama; penyimpanan sesi gagal tidak membuat halaman error. Perubahan isi iklan dapat menampilkannya lagi. Nomor CS dinormalisasi ke kode 62; URL wa.me memakai pesan ter-encode, target tab baru dan noopener/noreferrer. Tombol tetap tersedia pada akun/checkout Nebula dengan ruang footer agar tidak menutupi informasi terbawah.

Konfigurasi disimpan di branding.design, memakai otorisasi web.manage/revisi/transaksi/media per toko existing. Gambar iklan termasuk dalam perhitungan referensi aset dan dibersihkan setelah tidak dipakai. Tidak ada migrasi atau dependency baru. Tautan iklan/nomor CS/SVG divalidasi server.

Validasi: 18 tes desain/Nebula (295 assertions), 5 tes frontend, TypeScript/build dan Pint lulus. Browser SQLite/HTTP fake membuktikan unggah gambar iklan lewat modal dan simpan multipart, popup desktop/390 px, tutup lalu refresh tidak membuka ulang, tab baru membuka popup, CSS animasi ng-slide-enter 0.55s, serta tautan CS yang sesuai pada storefront/akun. Tidak mengirim pesan WhatsApp atau membuat transaksi; nomor CS hanya data simulasi. Tidak ada error/warning browser yang tercatat; viewport 390 px memiliki lebar konten 375 px dan tombol CS berada di dalam layar. Bukti assets/nebula-welcome-ad.png. Tab dan server fixture ditutup setelah pemeriksaan.


## Cek pesanan publik dan tinjauan halaman BangJeff — 16 Oktober 2026

Menu Cek pesanan Nebula sekarang menuju /cek-pesanan, terpisah dari /account. Header bersama checkout/akun dan navigasi Aurora/Atlas juga menyediakan halaman tersebut. Preview Nebula memiliki halaman lookup tersendiri; form preview tidak mengirim pencarian ke toko nyata. Pencarian nomor invoice menampilkan produk, status pesanan/pembayaran, nominal dan waktu dari database toko tanpa login. Data tujuan/WA/email/hasil premium/token/ID internal tidak dikirim melalui hasil pencarian. Bukti lengkap dapat dibuka melalui modal dengan nomor WhatsApp yang cocok dengan data checkout; nomor 08/+62 dinormalisasi dan redirect tetap memakai token receipt existing. Akun pelanggan tidak dibuat oleh proses ini.

Pencarian scoped per toko, dibatasi 30 permintaan/menit; pembukaan bukti 5/menit. Nomor tidak ditemukan dan data tidak valid memberikan respons yang jelas tanpa mengekspos toko lain. Halaman memakai no-store/private, no-referrer, noindex dan history terenkripsi. Pemeriksaan lama tetap bisa dilakukan setelah toko berhenti menerima pesanan baru. Pencarian tidak memanggil gateway/pemasok atau membuat transaksi baru.

Validasi: 32 tes commerce/Nebula (387 assertions), TypeScript/build dan Pint lulus. Empat tes lookup mencakup guest, batas data publik, input array/panjang/script, invoice toko lain, WA benar/salah, akses receipt tanpa akun, dan toko kedaluwarsa. Browser SQLite/HTTP fake: cari invoice berhasil tanpa login, buka modal WA lalu bukti transaksi, serta viewport 390 px dengan lebar dokumen 375 px tanpa elemen melebar. Bukti assets/public-order-lookup.png. Tidak ada pembelian/pesan/permintaan OTP nyata. Server fixture dan tab ditutup setelah pengujian; server development pengguna tetap berjalan.

### Temuan referensi halaman publik

Cakupan: 14 halaman publik utama termasuk satu halaman produk perwakilan Mobile Legends. Halaman akun setelah autentikasi dan bukti pembayaran milik pelanggan referensi tidak diakses. Pencarian invoice referensi hanya diperiksa bentuk formnya, tanpa mencari invoice pelanggan lain.

| Halaman BangJeff | Alur yang terlihat | Keadaan DigitalToko setelah perubahan |
| --- | --- | --- |
| [Beranda](https://www.bangjeff.com/id-id) | Banner, flash sale, trending, kelompok produk, artikel | Banner/promo/trending/katalog/CS sudah ada; artikel belum menjadi modul |
| [Mobile Legends](https://www.bangjeff.com/id-id/mobile-legends) | Halaman per game: ID dan server, nominal, jumlah, pembayaran, kontak, promo, deskripsi/FAQ/ulasan | Checkout bersama sudah ada; halaman game dengan input ID/server terpisah, FAQ/ulasan dan promo belum lengkap |
| [Cek transaksi](https://www.bangjeff.com/id-id/invoices) | Pencarian invoice publik dan tabel transaksi terakhir | Lookup publik sudah berfungsi; detail pembayaran/hasil produk memakai kecocokan WhatsApp; riwayat semua pelanggan tidak dipublikasikan |
| [Leaderboard](https://www.bangjeff.com/id-id/leaderboard) | Halaman tersendiri untuk harian, mingguan, bulanan | Bagian leaderboard mingguan/bulanan sudah ada di Nebula; halaman khusus/harian belum tersedia |
| [Masuk](https://www.bangjeff.com/id-id/sign-in) / [pemulihan](https://www.bangjeff.com/id-id/forgot-password) | Akun dan pemulihan dipisah dari cek invoice | Login DigitalToko tetap WA OTP atau email/password sesuai aturan proyek; guest dapat mengecek pesanan |
| [Winrate](https://www.bangjeff.com/id-id/calculator/winrate), [Magic Wheel](https://www.bangjeff.com/id-id/calculator/magic-wheel), [Zodiac](https://www.bangjeff.com/id-id/calculator/zodiac) | Halaman kalkulator interaktif | Belum menjadi modul DigitalToko |
| [Artikel](https://news.bangjeff.com/) | Portal artikel terpisah dengan pencarian | Belum menjadi modul DigitalToko |
| [Kontak](https://www.bangjeff.com/id-id/contact-us) / [ulasan](https://www.bangjeff.com/id-id/reviews) | Bantuan/kontak dan ulasan memiliki halaman tersendiri | CS WhatsApp tersedia; halaman kontak/ulasan khusus belum tersedia |
| [Syarat](https://www.bangjeff.com/id-id/terms-and-condition) / [privasi](https://www.bangjeff.com/id-id/privacy-policy) | Dokumen informasi tersendiri | Pengelolaan halaman kebijakan per toko belum tersedia; isi kebijakan referensi tidak disalin |

Prioritas sesudah lookup: pengalaman halaman produk per game dengan pemilihan nominal dan format tujuan yang benar, lalu halaman leaderboard/kontak/kebijakan toko. Kalkulator, ulasan dan artikel memerlukan tahap modul tersendiri.


### Navigasi Nebula, katalog kategori, dan kalkulator — 16 Oktober 2026

- Header/footer Nebula memakai `NebulaLayout` bersama pada beranda, kategori, checkout, cek pesanan, akun/login, invoice, leaderboard, dan kalkulator. Header fixed dengan latar 86% serta blur setelah scroll >16px; ruang header disediakan sejak render awal. Pencarian menggunakan navigasi Inertia. Pratinjau template mempertahankan pemilih template di atas navbar sticky.
- Beranda Nebula memuat kartu kategori aktif per toko dengan filter/pagination server. Produk biasa tidak lagi dikirim pada daftar beranda. Flash sale tetap berupa produk dan membuka `/kategori/{uuid}?product={uuid}` dengan produk terpilih. Trending memakai kategori, termasuk pemilihan manual maksimal 12 kategori pada editor desain; pilihan produk lama dibaca sebagai kategori untuk kompatibilitas.
- `/kategori/{uuid}` menampilkan identitas/gambar kategori, daftar nominal dengan filter/pagination, formulir tujuan, pembayaran, kontak, dan ringkasan. `CheckoutForm` dipakai bersama dengan checkout langsung; tetap memakai endpoint pesanan/inquiry yang ada, harga harapan, idempotency key, ketentuan guest, saldo, dan refund yang sama. Hasil inquiry dibatalkan saat produk/tujuan/WA/metode berubah agar tagihan lama tidak dipakai.
- Owner/staff berizin `products.manage` mengunggah atau mengganti gambar melalui Layanan & kategori → Kategori utama → Edit. JPG/PNG/WebP maksimal 4 MB, maksimal 6000px; tersedia URL HTTPS dan hapus gambar. Gambar tersimpan dalam branding berdasarkan UUID kategori sehingga aman saat nama berubah dan tidak memerlukan migrasi. Penulisan menggunakan lock toko; file baru dibersihkan saat rollback, file lama yang tidak dipakai lagi dibersihkan setelah sukses. Referensi gambar bersama dengan desain tetap dipertahankan.
- Leaderboard pindah ke `/leaderboard`, dengan periode hari/minggu/bulan (Asia/Jakarta) dan pagination. Beranda tidak lagi menjalankan query leaderboard. Hanya pesanan produk sukses, terbayar, tidak direfund; alias HMAC digunakan tanpa mengirim nama, telepon, email, tujuan, atau ID pelanggan.
- `/calculator` menyediakan Win Rate, Magic Wheel, Zodiac, masing-masing memiliki URL. Win Rate menghitung kemenangan beruntun dengan pembulatan atas dan menangani target 100%/target yang sudah tercapai. Estimasi diamond memakai asumsi eksplisit paket 5 putaran + sisa putaran tunggal, tanpa diskon/tiket/bonus; hasil bukan jaminan hadiah event.
- Referensi visual: halaman Mobile Legends serta kalkulator di BangJeff ditinjau. Alur pemesanan tetap memakai mesin transaksi DigitalToko; tidak menambahkan input promo/jumlah yang belum didukung mesin pesanan.
- Validasi: 78 tes backend terkait lulus (1137 assertion), 7 tes frontend lulus, pemeriksaan TypeScript/build Vite dan Pint lulus. Browser pada fixture SQLite terisolasi memverifikasi unggah kategori, pilihan nominal/ringkasan, header fixed/transparan, leaderboard, menu kalkulator, hasil Win Rate 100/50/60 = 25, Magic Wheel 199 = 60 diamond, dan Zodiac 0 = 1700 diamond. Tampilan ponsel 390px tidak meluap horizontal; pratinjau gelap pada server development 8000 juga diperiksa tanpa error React. Tidak melakukan pembayaran atau pengiriman pesan nyata.


### Perapian filter storefront — 16 Oktober 2026

Filter beranda kategori Nebula dan pilihan nominal memakai varian storefront pada `FilterBar`: pencarian fleksibel, input/select/tombol setinggi 44px, tombol Cari, dan reset ikon hanya saat ada kata kunci. Latar panel admin dihilangkan; warna memakai token storefront. Pada ponsel, pencarian satu baris penuh dengan jumlah data serta aksi di baris berikutnya. Pagination atas/bawah tetap ada. Browser fixture terisolasi memverifikasi pencarian Spotify, reset, dan pencarian nominal melalui Enter tanpa submit checkout; lebar 390px tidak meluap. Build TypeScript/Vite dan 7 tes frontend lulus. Bukti tampilan: `assets/nebula-catalog-filters.png` dan `assets/nebula-order-filters.png`.


### Reveal lintas halaman dan hover navbar Nebula — 16 Oktober 2026

`NebulaLayout` memakai hook `useStorefrontReveal` bersama untuk section, form, judul, kartu, nominal, daftar peringkat, serta footer di semua halaman Nebula. IntersectionObserver memicu animasi fade/geser 480ms sekali saat elemen masuk viewport, dengan stagger kartu maksimal 175ms. MutationObserver menangani elemen baru sesudah filter/pagination; pergantian route mengatur ulang observer. Konten SSR tidak disembunyikan oleh CSS, Web Animations tidak meninggalkan transform setelah selesai, dan panel sticky hanya memakai fade. Semua observer/animasi dibersihkan pada unmount; perubahan preferensi reduced motion menghentikan animasi.

Navbar mendapat sorotan latar, garis bawah dari kiri, gerakan ikon, panah dropdown berputar, dan transisi item dropdown, termasuk fokus keyboard serta aturan reduced motion. Tidak menambah dependensi. TypeScript/build berhasil. Browser fixture memverifikasi perpindahan ke cek pesanan, leaderboard, kalkulator, dan login tanpa error React; computed opacity/transform selama reveal pada leaderboard dan kalkulator membuktikan animasi aktif di luar beranda. Bukti navbar: `assets/nebula-motion-navbar.png`.


Penyempurnaan reveal: durasi diperpanjang dari 480ms menjadi 900ms, stagger kartu 60ms dengan batas 240ms. Elemen yang sudah terlihat saat registrasi tidak dibuat transparan kembali; elemen bersarang tidak mengulang fade milik bagian induknya. Reveal saat scroll dimulai dari opacity 0,25 dengan easing lebih bertahap untuk menghindari kesan kedip. Build TypeScript/Vite lulus.


### Pengecekan akun game otomatis — 16 Oktober 2026

- Integrasi endpoint nickname ihsangan/valid melalui layanan Laravel `GameIdentity`, bukan request langsung dari browser. Daftar konfigurasi mencakup 16 game pada implementasi sumber; Call of Duty menggunakan path `codm` sesuai routing aktual. Tidak menyalin worker atau menambah dependensi.
- Deteksi kategori otomatis ditambah override/disable per UUID kategori pada branding. Owner/staff berizin products.manage memilih game dan format tujuan (otomatis, gabung, kurung, pipa, spasi, atau ID saja) melalui modal kategori. Tidak mengubah schema database.
- `GameAccountFields` memisahkan User ID/Zone ID, menyediakan server bernama untuk LifeAfter/PGR, dan mendukung Riot ID+tag. Debounce 800ms, pembatalan request lama, pemeriksaan identitas input saat respons tiba, cache shared 3 menit, status akun tidak ditemukan/layanan gagal terpisah, dan tombol cek ulang. ID besar tetap string.
- Hasil valid menghasilkan bukti terenkripsi 10 menit, terikat toko/kategori/game/tujuan. Mesin checkout web mewajibkan bukti cocok sebelum pendebitan saldo. Perubahan ID/server langsung mengosongkan bukti; nickname serta ID/server disimpan pada snapshot invoice dan ditampilkan pada ringkasan/bukti pribadi. Kanal bot yang ada dipertahankan.
- Validasi: 68 tes backend terkait lulus (998 assertion), 9 tes frontend lulus, TypeScript/build dan Pint lulus. Meliputi input zona, game tanpa zona/Riot tag, cache lintas toko, ID/zona respons yang tidak cocok, HTTP error/JSON rusak, bukti palsu/kedaluwarsa/berbeda kategori, idempotensi, serta saldo tidak terpotong pada kegagalan verifikasi.
- Endpoint asli berhasil diakses dari PHP/Laravel menggunakan contoh ID publik README (1114917746/13486). Browser memakai SQLite/HTTP fake terisolasi: owner mengaktifkan game di kategori simulasi, dua input tampil, nickname ditemukan otomatis, ID invalid mengosongkan nickname dan menonaktifkan tombol pesan. Tidak membuat pembayaran atau mengirim pesan nyata. Bukti UI: `assets/nebula-game-identity.png`.

### Penyempurnaan panel dan harga — 16 Oktober 2026

- Menu owner/staff dikelompokkan dalam Penjualan, Produk & harga, Kanal & tampilan, serta Administrasi toko. Submenu dapat dilipat, menyimpan preferensi sesi per toko, dan membuka kelompok halaman aktif. Browser desktop/ponsel serta staff simulasi diperiksa.
- Penutupan toko dipindahkan ke halaman dan endpoint khusus owner `/stores/{store}/closure`; pembayaran pelanggan tidak lagi menerima aksi penutupan. Saldo dan pesanan yang belum selesai tetap menghalangi penutupan final. Pengelolaan staff dipisahkan dari tab identitas/desain; tautan login staff berada pada halaman staff.
- Aturan harga memvalidasi nama target terhadap tingkat layanan/kategori dan toko. Aturan Bogor Topup sebelumnya tersimpan sebagai kategori game, sedangkan Game adalah layanan. Setelah backup aturan dan harga lokal, scope dipindahkan ke layanan; ML DIAMOND 100 menjadi Rp32.444 dari modal Rp29.494 + 10%.
- Validasi: 27 tes katalog/taksonomi (393 assertion), 29 tes perdagangan pelanggan (302 assertion), build TypeScript/Vite, Pint, dan diff whitespace lulus. Pemeriksaan browser menggunakan fixture terisolasi; tidak menutup toko nyata.

### Tujuan transaksi per kategori — 16 Oktober 2026

- Modal kategori memiliki pengaturan jenis tujuan (otomatis, game, aplikasi premium, nomor HP, PLN, lainnya), jumlah kolom game, pengecekan nickname, dan format tujuan pemasok. Konfigurasi tersimpan per UUID kategori pada branding tanpa migrasi schema atau kolom pengaturan per produk.
- Checkout kategori dan langsung memakai resolver CategoryDestination yang sama. Premium menyembunyikan input tujuan dan server menggunakan WhatsApp pelanggan terverifikasi atau WhatsApp guest; nomor HP/PLN diperiksa sebelum debit. Game manual satu/dua kolom digabung di server, bukan mempercayai string gabungan dari browser. Pengecekan tagihan memakai normalisasi yang sama.
- Pengecekan nickname tetap per kategori; bukti dapat dipakai pada nominal berbeda dalam kategori yang sama selama belum kedaluwarsa. Pengujian membuktikan dua produk memakai satu lookup. Game API memiliki jumlah kolom sesuai ketentuan API; konfigurasi yang tidak cocok ditolak secara transaksional.
- Validasi: 57 tes backend terkait (773 assertion), 10 tes frontend, TypeScript/Vite build, Pint, dan diff check lulus. Browser terisolasi memverifikasi modal, input game manual dua kolom dengan format pipa, serta premium tanpa input tujuan yang memakai WhatsApp di ringkasan. Tidak membuat pembayaran/pesanan nyata; konfigurasi ML fixture dipulihkan.

### Quick order Aurora dan navigasi floating — 16 Oktober 2026

- Beranda Aurora menyediakan quick order Pulsa, Paket data, dan Voucher data. Nomor lokal/62/+62 dinormalisasi, provider dikenali dari prefix, dan pilihan manual tetap tersedia (termasuk by.U yang tidak memiliki prefix eksklusif). Pemetaan katalog mengikuti nama layanan/kategori dan jenis tujuan nomor HP.
- Endpoint katalog hanya membaca produk aktif milik toko terkait, memakai harga jual/markup serta ketersediaan dari StoreCatalog, dan memuat lima produk per halaman. Request dibatalkan saat pilihan berubah; nomor lengkap tidak dikirim untuk pencarian provider. Toko nonaktif, bot-only, template lain, dan penutupan toko ditolak.
- Lanjutkan pesanan memvalidasi produk/nomor dan menyimpan prefill sesi terikat toko/produk sebelum mengarahkan ke checkout. Nomor tidak dimasukkan ke URL; tahap ini tidak membuat pesanan atau pembayaran. Kontak WhatsApp guest tetap diisi terpisah pada checkout.
- Header Aurora digunakan bersama pada beranda dan halaman perdagangan: fixed floating, backdrop saat scroll, serta navigasi Beranda/Produk/Cek pesanan/Akun & saldo konsisten. Tombol CS mengambang mengikuti konfigurasi desain toko. Banner tanpa overlay diperbaiki agar tidak memperlebar viewport ponsel.
- Validasi: 18 tes backend terkait (305 assertion), 12 tes frontend, TypeScript, Vite build, Pint, dan diff check lulus. Browser fixture terisolasi memverifikasi deteksi Telkomsel, harga produk asli fixture, prefill checkout, pemisahan kontak, empty state Paket data, navbar fixed, CS, serta viewport 390px tanpa overflow. Tidak membuat transaksi nyata. Template fixture dikembalikan ke Nebula dan server pengujian dihentikan. Bukti desktop: assets/aurora-quick-order.png.

### Provider kategori untuk quick order — 16 Oktober 2026

- Owner/staff berizin products.manage kini memilih Provider kategori pada tab Identitas modal kategori untuk layanan Pulsa, Paket Data/Data/Internet, dan Voucher Data. Provider ditampilkan juga pada tabel kategori; pilihan kosong berarti tidak masuk quick order.
- QuickOrderCatalog membaca provider eksplisit per UUID kategori pada branding.category_providers. Nama kategori bebas dan tidak lagi digunakan untuk menebak provider/jenis layanan quick order. Semua produk aktif dalam kategori mengikuti provider dan layanan induknya, tetap memakai harga/ketersediaan StoreCatalog.
- Validasi allowlist provider, batas toko, dan layanan diterapkan di server. Pemindahan kategori ke layanan tidak terkait membersihkan provider; penghapusan kategori membersihkan konfigurasi. Tidak mengubah schema, data kategori lama, atau menambahkan dependensi. Kategori lama perlu dipilih providernya melalui panel agar masuk quick order.
- Validasi: 22 tes backend terkait (301 assertion), 12 tes frontend, build TypeScript/Vite, dan Pint lulus. Mencakup kategori tanpa nama provider, nama yang menyesatkan, edit/pindah/hapus, provider invalid, dan isolasi toko. Browser fixture memverifikasi dropdown provider pada modal Pulsa; perubahan dibatalkan, server uji dihentikan. Bukti: assets/category-provider-setting.png.

### Penjelasan tombol checkout nonaktif — 16 Oktober 2026

- Audit read-only database lokal menemukan gateway pembayaran pelanggan bogor topup masih null (credentials kosong); toko demo Aurora/Nebula juga belum dikonfigurasi. Syarat checkout bersama kedua template menolak metode gateway saat gateway null.
- Checkout kini menampilkan alasan spesifik saat tombol belum dapat digunakan: produk/tujuan/kontak belum lengkap, produk kosong, QRIS belum disiapkan, nickname belum terverifikasi, atau tagihan belum diperiksa. Radio QRIS tanpa gateway dinonaktifkan dengan penjelasan. Customer yang login otomatis memakai saldo jika toko belum memiliki gateway. Tidak mengaktifkan gateway atau mengubah kredensial/pengaturan toko nyata.
- Validasi: 15 tes frontend lulus, TypeScript/Vite build lulus. Browser fixture Nebula membuktikan setelah tujuan dan WhatsApp diisi dengan gateway fixture tersedia, tombol Pesan sekarang aktif. Tidak mengirim order/pembayaran. Server fixture dihentikan. Bukti: assets/checkout-ready.png.

### Pemulihan gambar QRIS Midtrans — 16 Oktober 2026

- Audit invoice sandbox lokal menemukan transaksi sudah dibuat dan URL QR mengembalikan PNG HTTP 200 dari backend. Halaman sebelumnya memuat gambar lintas domain secara langsung. Gambar kini diambil backend dan disimpan sebagai data URI PNG agar browser tidak perlu mengakses host Midtrans untuk menampilkan QR.
- CustomerPayments mengambil URL dari actions generate-qr-code/generate-qr-code-v2 dengan fallback URL transaksi, memvalidasi HTTPS/host sesuai lingkungan/path QR, tidak mengikuti redirect, dan membatasi tipe PNG/ukuran sebelum menyimpan. Server Key tidak diberikan ke browser. Callback status tidak bergantung keberhasilan unduhan gambar.
- Pembuatan pembayaran membaca ulang model sebelum pemeriksaan status karena status/payment_status default database belum terisi pada objek hasil create. QR kini disiapkan pada request pertama. Provider ID tetap disimpan sebelum pengambilan gambar, sehingga percobaan ulang tidak melakukan charge kedua. Invoice lama dipulihkan saat halaman detail dibuka; error gambar memiliki pesan dan aksi coba ulang.
- Validasi: 30 tes CustomerCommerce (310 assertion sebelum tambahan assertion request pertama), build TypeScript/Vite dan Pint lulus. Meliputi URL QR actions, PNG invalid/503 dan retry, penolakan host asing, charge idempoten, serta signature/status webhook. Invoice sandbox terbaru bogor topup dipulihkan hanya dengan GET gambar (tanpa charge atau pembayaran), dan browser mengonfirmasi PNG embedded 630x630 benar-benar termuat. Bukti privat lokal: apps/web/storage/framework/testing/midtrans-qr-fixed.png.

### Countdown pembayaran QRIS — 16 Oktober 2026

- Invoice pelanggan pada komponen bersama seluruh template menampilkan sisa waktu mm:ss (hh:mm:ss untuk lebih dari satu jam) dari expires_at invoice. Komponen countdown memperbarui waktunya setiap detik dengan perhitungan deadline absolut, menyelaraskan ulang saat tab terlihat, serta membersihkan timer/listener saat unmount. Tidak mengulang durasi saat refresh/polling.
- Dalam 60 detik terakhir tampil penanda warna. Ketika mencapai nol, QR dan instruksi bayar disembunyikan dan ditampilkan arahan cek status apabila pelanggan sudah membayar. Countdown tidak mengubah status finansial; tombol pemeriksaan status tetap tersedia. Countdown tidak ditampilkan pada pembayaran lunas/terminal.
- Validasi: 18 tes frontend dan build TypeScript/Vite lulus. Browser invoice SQLite terisolasi membuktikan hitungan 09:18 turun menjadi 09:14, serta 00:00/QR tersembunyi setelah deadline fixture dilampaui. Tidak membuat transaksi/pembayaran Midtrans. Server fixture dihentikan. Bukti: assets/qris-countdown.png.

### Batas invoice guest per sesi dan pembatalan — 16 Oktober 2026

- Guest web dibatasi satu invoice awaiting_payment/unpaid yang belum kedaluwarsa per sesi browser dan toko. Identitas acak disimpan pada session server; hanya hash-nya disimpan pada customer_orders.guest_checkout_hash (kolom nullable terindeks). Login pelanggan dan kanal bot tidak terkena batas ini. Pembatasan berlaku pada invoice baru setelah pembaruan; tidak menebak keterkaitan invoice lama berdasarkan IP/nomor.
- Checkout langsung dan kategori menampilkan invoice guest aktif serta tautan untuk melanjutkan/membatalkan. POST checkout diarahkan ke invoice lama; mesin transaksi memeriksa ulang batas di dalam lock transaksi toko. Route checkout/cancel memakai session blocking untuk tab/request bersamaan. Data hash tidak diterima dari input publik dan tidak dipublikasikan.
- Halaman invoice memiliki tombol Batalkan pesanan dengan modal konfirmasi. Untuk invoice guest terikat sesi, sesi lain ditolak meskipun memiliki tautan detail. Pembatalan Midtrans memeriksa status resmi dan menunggu konfirmasi cancel; paid/processing dan kegagalan konfirmasi tidak dibatalkan. Perlindungan pembayaran menggunakan lock yang sama dengan pembuatan charge; perubahan final memakai lock database dan pemeriksaan ulang. Pembayaran yang telah settled tetap tercatat meskipun permintaan pembatalan ditolak.
- Invoice batal disimpan sebagai status expired dengan penanda snapshot cancelled_by_customer dan judul Dibatalkan. QR dihapus; kode nominal QRIS custom tetap dicadangkan agar pembayaran terlambat tidak tertaut ke invoice baru. Pembayaran terlambat ke invoice batal mengikuti alur review yang sudah ada. Tidak membatalkan transaksi Midtrans nyata saat pengujian.
- Validasi: 46 tes backend terkait (590 assertion), 18 tes frontend, TypeScript/Vite build, Pint, dan diff check lulus. Mencakup satu sesi/beda sesi, invoice kedaluwarsa, pelanggan login, guard server, pembatalan lintas sesi, Midtrans settled dan cancel ditolak, serta reservasi kode unik. Browser fixture memverifikasi modal hingga status Dibatalkan/QR tersembunyi. Migration MySQL lokal dijalankan setelah backup customer_orders ke storage/framework/testing; fixture SQLite dimigrasi dan server pengujian dihentikan. Bukti: assets/guest-order-cancelled.png.
- Batas sesi browser bukan identitas perangkat permanen: cookie/session yang dihapus, profil browser lain, atau incognito dapat memperoleh sesi baru.

### Perbaikan pembatalan Midtrans Sandbox HTTP 500 — 16 Oktober 2026

- Reproduksi langsung pada invoice Sandbox yang dimaksud: GET status pending/201, POST cancel mengembalikan HTTP 500 dengan pesan sistem Midtrans sedang recovering. Endpoint resmi expire berhasil HTTP 200/status_code 407/transaction_status expire.
- Pembatalan kini memeriksa konfirmasi cancel/expire beserta nomor dan nominal, melakukan rekonsiliasi GET status setelah cancel gagal, dan jika masih pending mencoba endpoint expire resmi. Kedua jalur gagal tetap mempertahankan invoice aktif dengan keterangan kode gateway. Respons gagal yang ternyata telah dibatalkan dapat dipulihkan dari status resmi tanpa mengulang permintaan expire.
- refresh mengembalikan data status tervalidasi bagi alur pembatalan. Invoice yang sudah dibayar tetap tidak dapat dibatalkan; pembaruan status pembayaran yang sah tidak di-rollback oleh penolakan pembatalan. Pemeriksaan akhir lock database tetap mencegah menimpa status paid/processing.
- Validasi: 36 tes CustomerCommerce (348 assertion), Pint, dan diff check lulus; tidak ada perubahan frontend/dependensi/schema. Invoice Sandbox terkait sudah dikonfirmasi expire, ditandai Dibatalkan lokal, QR dihapus, dan browser memverifikasi hasil tersebut. Tidak mengubah transaksi Production. Bukti privat: apps/web/storage/framework/testing/midtrans-cancel-fixed.png.
- Referensi resmi: https://docs.midtrans.com/reference/cancel-transaction dan https://docs.midtrans.com/reference/expire-transaction.

### Favicon storefront per toko — 17 Oktober 2026

- Pengaturan Desain web > Identitas visual menyediakan tombol Favicon dengan modal upload/pratinjau/hapus terpisah. Format PNG persegi maksimal 512x512 piksel/512 KB; rekomendasi 32x32 atau 64x64. Terapkan ke desain lalu Simpan desain mengikuti pola editor yang ada, termasuk indikator perubahan belum disimpan dan reset berkas setelah publish.
- StoreDesignController memvalidasi upload, menyimpan favicon dalam branding.design.favicon dan storage privat toko, serta membersihkan berkas lama sesuai referensi desain/kategori yang masih dipakai. Resolusi desain lama tetap kompatibel; payload lama yang tidak menyertakan favicon tidak menghapus favicon tersimpan. Tidak ada perubahan schema/dependensi.
- HTML awal menggunakan favicon tenant berdasarkan toko yang ter-resolve, dengan fallback /favicon.ico. Path aset relatif sehingga tidak terikat domain deployment dan berlaku pada seluruh halaman storefront termasuk login, checkout, dan cek pesanan. Panel platform memakai favicon bawaan.
- Validasi ulang setelah interupsi: 16 tes StoreDesign (213 assertion), 18 tes frontend, TypeScript/Vite build, Pint, dan diff check lulus. Backend mencakup upload, penggantian/hapus dan cleanup aset, head HTML lintas halaman, isolasi toko, serta penolakan SVG/file terlalu besar. Browser fixture terisolasi menjalankan file chooser PNG32x32 -> pratinjau -> Terapkan -> Simpan, memverifikasi rel=icon tenant pada halaman cek pesanan, lalu menghapus/menerbitkan ulang dan memverifikasi fallback favicon.ico. Data fixture dikembalikan; tab dan server uji port8010 ditutup. Tidak mengubah desain toko nyata. Bukti: assets/store-favicon-editor.png.

### Modal flash sale compact — 17 Oktober 2026

- Editor flash sale dipisah ke tab Pilih produk, Terpilih (jumlah), dan Jadwal. Produk baru tidak lagi menambah daftar terpilih di atas pencarian; tab terpilih menyediakan urutan nomor, naik/turun, lepas per item, serta lepas semua. Batas tetap 12 produk dan harga tetap mengikuti katalog.
- Daftar pencarian memuat 5 produk per halaman. Scroll internal/overscroll contain dihapus khusus dalam editor flash sale; hanya modal-body menjadi scroller, dengan footer modal tetap tersedia. Jadwal ditempatkan pada tab tersendiri dan error urutan waktu mengarahkan pengguna ke tab Jadwal. Perubahan belum diterbitkan tetap mengikuti Terapkan ke desain lalu Simpan desain.
- Browser fixture desktop dan viewport390 memverifikasi pilihan bertambah 1->3 tanpa memanjangkan bagian atas, perpindahan tab tetap mempertahankan pilihan, urutan dan pelepasan produk bekerja, pencarian Telkomsel/pagination, serta tab Jadwal. Computed overflow daftar visible dan modal-body auto; modal ponsel tidak overflow horizontal (clientWidth/scrollWidth368). Perubahan fixture dibatalkan; ukuran viewport direset dan server uji8010 dihentikan. Bukti: assets/flash-sale-picker-compact.png.
- TypeScript/Vite build lulus. Tidak mengubah database/dependensi atau aturan transaksi.

### Scroll lanjutan dan diskon per produk flash sale — 17 Oktober 2026

- Pemilih produk flash sale tidak lagi memiliki prev/next/nomor halaman. IntersectionObserver pada akhir daftar memuat 10 produk berikutnya dengan root modal-body; pencarian mengganti hasil, request lama dibatalkan, hasil diappend tanpa duplikasi, pilihan tetap dipertahankan, serta tersedia retry saat gagal. Tidak menambah scroll internal.
- Tab Terpilih memiliki jenis diskon Rupiah atau Persen dan nilai terpisah per produk. Nol/kosong berarti tanpa diskon. Konfigurasi flash_discounts tersimpan pada branding desain per UUID produk terpilih. Server menolak produk asing/tidak terpilih/pascabayar, persen >=100, dan nominal pecahan. Aturan yang dilepas dibersihkan saat publish; multipart kosong dinormalisasi agar penghapusan semua diskon tersimpan dengan benar.
- FlashSalePricing menerapkan diskon dari harga normal terbaru saat bagian flash sale aktif dan di dalam jadwal. StoreCatalog memberikan harga promo/compare_price yang sama untuk home, katalog, checkout, dan bot. Harga core tetap tersimpan pada sale_price; reprice menggunakan regular_price agar diskon tidak berulang/menjadi permanen. Harga promo nol/di bawah modal tidak dapat dipesan. Invoice mengambil snapshot harga promo saat checkout dan tidak berubah saat promo berakhir. Kartu flash sale menampilkan harga normal dicoret.
- Validasi: 59 tes backend terkait (647 assertion), pemeriksaan ulang StoreDesign, 18 tes frontend, TypeScript/Vite build, Pint, dan diff check lulus. Termasuk harga 15000->13500, snapshot checkout, jadwal belum dimulai/berakhir, reprice tidak merusak harga normal, provider scope/validasi diskon, serta batas modal.
- Browser fixture membuktikan pemuatan 10->20 produk dengan scroll tanpa pagination, diskon10% tersimpan, storefront mencoret11000 dan menampilkan9900, form kategori/checkout tetap9900. Diskon dibersihkan dan checkout kembali11000. Tidak membuat order/pembayaran; toko nyata tidak diubah. Tab/server fixture8010 ditutup. Bukti: assets/flash-sale-discount-editor.png.

### Aksi produk individual dan massal — 17 Oktober 2026

- Katalog & harga menyediakan aktif/nonaktif per baris, checkbox per produk, pilih semua pada halaman saat ini, serta toolbar Aktifkan/Nonaktifkan/Hapus/Batal pilih. Konfirmasi memakai modal dengan jumlah pilihan; pilihan dibersihkan setelah sukses, perubahan filter/halaman/toko/tab. Toolbar membungkus di ponsel.
- Endpoint bulk memakai izin products.manage, pemeriksaan toko operasional, validasi UUID unik maksimal100, isolasi toko, dan transaksi database dengan lock toko/produk. Aktif/nonaktif hanya mengubah status, mempertahankan harga, parsing, dan stok. Produk nonaktif tidak tersedia untuk pesanan baru di katalog bersama web/bot.
- Hapus individual dan massal memakai guard yang sama: seluruh batch ditolak bila ada transaksi berjalan atau stok reserved/sent. Ready stock dan parsing mengikuti cascade; riwayat selesai mempertahankan snapshot dengan product_id null. Referensi flash/trending/diskon dibersihkan dan revision desain dinaikkan agar editor lama tidak menimpa perubahan.
- Validasi: 23 tes backend terkait (315 assertion),18 tes frontend, TypeScript/Vite build, Pint, dan diff check lulus. Mencakup isolasi toko, izin staff, produk asing/duplikat, suspensi toko, atomic deletion, histori, desain, serta status/harga/stok.
- Browser fixture terisolasi membuktikan nonaktif2 -> aktif2, checkbox selectall10, pilihan kosong setelah pindah halaman, modal hapus10 dibatalkan, dan toolbar viewport390 tanpa overflow. Status fixture dipulihkan; tidak mengubah produk toko nyata. Tab dan server8010 ditutup. Bukti: assets/catalog-bulk-actions.png.

### Impor seluruh katalog pemasok — 18 Oktober 2026

- Kartu Digiflazz/Hanshop pada Sumber produk menyediakan tombol Impor semua; impor berdasarkan checkbox tetap tersedia. Modal menjelaskan cakupan seluruh snapshot tersinkron (lintas halaman/filter, termasuk produk unavailable dan mode Digiflazz yang sudah tersinkron), aturan harga, dan tidak adanya pemotongan saldo.
- Endpoint JSON memproses100 produk baru per batch dalam transaksi dengan lock toko; frontend melanjutkan otomatis sampai habis, menampilkan progres/hasil, dan menyediakan lanjutkan saat gagal. Batas UUID snapshot mempertahankan cakupan awal selama proses. Request dibatalkan saat komponen dilepas; halaman perlu tetap terbuka selama proses. Setiap batch memeriksa izin products.manage, toko operasional, kepemilikan koneksi, status sumber, dan fitur paket.
- Impor pilihan dan semua memakai helper yang sama. Mapping yang sudah ada dilewati (termasuk produk toko nonaktif), sehingga harga/status/kode lama tidak ditimpa. Produk baru mengikuti markup kategori/layanan dan taxonomy model; memori per batch dibatasi tanpa memuat seluruh katalog. Tidak menambahkan dependensi, migrasi, atau kebutuhan worker queue.
- Format kode lama dipertahankan: prefiks H/D, SKU tersanitasi, dan8 karakter hash ID sumber sebagai pencegah bentrok. Supplier SKU asli tetap pada parsing, dan kode toko dapat diedit terpisah.
- Tes mencakup107 produk lintas2 batch, snapshot saat ada produk baru, filter yang tidak membatasi impor, unavailable/pascabayar, pengulangan tanpa duplikasi, harga lama dan status nonaktif, kompatibilitas impor pilihan, kode SKU sama beda mode, sumber kosong, isolasi toko, izin staff, status toko/koneksi, dan batas paket.
- Browser fixture membuktikan Hanshop12 sudah diparsing tanpa duplikasi, Digiflazz25 total dengan16 baru, impor ulang0 baru, serta modal ponsel390. Database fixture dipulihkan dari salinan sebelum uji, tab/server8010 ditutup; tidak mengubah katalog toko nyata. Bukti: assets/supplier-import-all.png.
- Validasi akhir:26 tes backend terkait (366 assertion), TypeScript/Vite build, Pint --dirty, dan git diff --check lulus. Build mempertahankan peringatan aset hero Nebula yang sudah ada sebelumnya.

### Pilihan impor layanan/kategori pemasok — 18 Oktober 2026

- Tombol Impor produk pada setiap kartu sumber membuka modal dengan4 metode: Semua produk, Per layanan, Per kategori, dan Pilihan massal checkbox. Shortcut Impor pilihan dari tabel tetap tersedia. Dalam modal sumber, pilihan massal hanya menyertakan checkbox dari sumber yang bersangkutan; shortcut tabel tetap dapat mengimpor pilihan campuran.
- Dropdown layanan/kategori memakai CatalogSelect yang bisa diketik, maksimal5 hasil dan tanpa prev/next. Opsi berasal dari supplier_products tersinkron, bukan taxonomy produk toko. Kategori memerlukan layanan terlebih dahulu agar nama kategori sama di layanan berbeda tidak tercampur; mengganti layanan membersihkan kategori. Respons opsi dibatasi ke toko/koneksi yang diizinkan dan memakai pencarian server.
- Batch impor menerima scope all/service/category dengan validasi service/category wajib sesuai metode. Filter scope diterapkan sebelum snapshot/count/pemilihan batch100 dan dibawa setiap request lanjutan. Pilihan kategori/layanan tidak dibatasi filter tabel atau halaman, tetap mencakup unavailable; impor ulang melewati mapping lama. Scope tidak ditemukan ditolak422; izin/paket/koneksi/toko diperiksa pada tiap batch.
- Validasi:24 tes backend terkait (365 assertion), TypeScript/Vite build, Pint --dirty dan diff check lulus. Tes mencakup102 produk kategori lintas2 batch, kategori sama di layanan berbeda, isolasi sumber, impor layanan103 produk dengan hanya1 baru setelah kategori selesai, validasi scope kosong/salah, opsi unik/search5 hasil, serta regresi impor semua/pilihan.
- Browser fixture membuktikan Digiflazz Pulsa->Telkomsel mencakup24 produk dengan16 baru, layanan Pascabayar1 produk, checkbox Hanshop1 produk berhasil dengan notifikasi serta pilihan dibersihkan, dan dropdown kategori Hanshop. Modal viewport390 memiliki dialog370px dan tidak overflow horizontal. Tidak mengubah toko nyata; database fixture dipulihkan dan tab/server8010 ditutup. Bukti: assets/supplier-scoped-import.png.

### Kode impor ringkas dan perilaku impor ulang — 18 Oktober 2026

- Produk impor baru memakai H-SKU atau D-SKU, tanpa hash acak. Jika bentrok, pascabayar memakai -PASCA lalu -PASCA-2 dst; produk lain memakai -2/-3 dst. Kode dibatasi50 karakter termasuk suffix, sementara SKU sumber utuh tetap pada parsing. Kode produk yang sudah ada tidak diubah otomatis.
- Impor ulang tetap memeriksa mapping supplier_product_id, sehingga produk yang masih diparsing dilewati tanpa mengubah harga, status, atau kode toko. Jika produk/parsing dihapus, sumber dapat diimpor lagi sebagai produk baru; collision kode toko tetap ditangani.
- Validasi25 tes terkait (381 assertion), Pint --dirty dan diff check lulus, termasuk prefix berbeda, SKU sama beda mode, penambahan nomor saat kode lama masih dipakai, batas panjang dan keutuhan SKU sumber.

### Popup tanpa navigasi halaman — 18 Oktober 2026

- Audit seluruh penggunaan Pagination/pager pada komponen dan halaman: pagination dalam popup tersisa pada CatalogPicker bersama, dipakai untuk parsing produk, tambah stok premium, dan kategori trending. Pager atas/bawah di komponen ini dihapus; tabel utama tetap memiliki pagination. Dropdown CatalogSelect dan editor flash sale sudah memakai pencarian terbatas/scroll tanpa pager.
- CatalogPicker memuat batch berikutnya lewat IntersectionObserver dengan root modal-body, menambahkan data tanpa duplikasi dan mempertahankan checkbox/radio. Saat memuat/gagal, pilihan lama tetap terlihat; tersedia retry tanpa memuat ulang seluruh daftar. Search mereset hasil/page dan membatalkan request lama. Ganti URL/jenis produk atau tutup modal mereset instance agar hasil lama tidak tercampur.
- Scroll internal pada daftar picker di dalam modal dihapus sehingga hanya body modal yang digulir. Footer aksi modal tetap tersedia. Tidak mengubah endpoint, skema, dependensi, atau pagination tabel utama.
- Validasi TypeScript/Vite build dan diff check lulus. Browser fixture membuktikan0 pager di modal vs2 pager pada tabel katalog, pemuatan5->10 pilihan lewat gulir, checkbox tetap tercentang, pencarian Telkomsel9 mengembalikan1 hasil, modal stok tampil tanpa pager, dan tampilan ponsel390. Tidak menyimpan form/perubahan data toko. Tab/server8010 ditutup dan viewport direset. Bukti: assets/modal-picker-scroll.png.

### Pemilih popup berbentuk dropdown overlay — 18 Oktober 2026

- Koreksi konsep pemilih tanpa pager: daftar tidak lagi ditempel memanjang di body modal. PickerDropdown tertutup default dengan ringkasan pilihan, dan membuka popover overlay bertinggi terbatas (maks360px) yang dapat discroll sendiri. Posisi/ukuran mengikuti ruang viewport dan pergerakan modal, memakai native popover agar tidak terpotong overflow dialog.
- Diterapkan ke CatalogPicker bersama (parsing produk, stok premium, kategori trending) serta ProductChoices flash sale. Search dan load bertahap berada di dropdown; observer memakai scroll container dropdown. Pilihan bertahan di parent saat dropdown ditutup/dibuka. Parsing terpilih dipindahkan dari daftar panjang dalam form ke bagian Terpilih yang dapat dibuka di dalam dropdown, dengan tombol Lepas tetap tersedia.
- Klik luar/Escape menutup dropdown saja. Pilihan radio stok menutup otomatis setelah dipilih; checkbox multi tetap terbuka. Form tidak bertambah tinggi saat dropdown dibuka. Pagination tabel utama dipertahankan.
- Validasi:TypeScript/Vite build,18 tes frontend dan diff check lulus. Browser fixture membuktikan tinggi modal parsing tetap462.9px sebelum/sesudah membuka dropdown; scroll5->10 dengan checkbox tetap terpilih; Escape menyisakan modal terbuka; radio stok menutup dan menampilkan nama terpilih; flash sale awalnya ringkas lalu search Spotify menampilkan1 hasil. Dropdown ponsel332px tetap dalam viewport390 tanpa overflow horizontal; klik luar menutup popover. Tidak menyimpan form atau mengubah data toko. Tab/server8010 ditutup dan viewport direset. Bukti: assets/modal-picker-dropdown.png dan assets/flash-sale-dropdown.png.


### Sandbox toko dan Digiflazz development — 18 Oktober 2026

- Mode per toko dapat diubah owner melalui modal Lingkungan toko pada Tampilan & pengaturan. Pergantian diserialisasi dengan pesanan/inquiry/sinkronisasi; diblokir saat masih ada pesanan berjalan atau inquiry aktif. Fitur paket dan masa sewa tetap diberlakukan. Semua toko existing tetap Produksi setelah migrasi.
- API key development Digiflazz disimpan terenkripsi terpisah dari key produksi; pemilihan key tidak melakukan fallback. Snapshot pesanan menyimpan credential sesuai lingkungan. Transaksi prabayar, inquiry, pay dan status pascabayar meneruskan testing=true pada Sandbox. Katalog Sandbox berisi SKU resmi xld10 dan pln dari dokumentasi test case Digiflazz; koneksi uji menyimpan key tanpa mengklaim verifikasi autentikasi sampai transaksi uji. Saldo pemasok Sandbox Rp1.000.000 ditandai simulasi, bukan saldo API asli.
- Saldo pelanggan uji memakai sandbox_balance dan ledger is_sandbox; debit, topup, refund dan penyelesaian saldo memakai lingkungan pesanan. Pesanan uji memakai awalan SB, ditandai di panel/invoice/notifikasi/bot, serta dipisahkan pada riwayat akun, leaderboard dan analitik storefront sesuai mode. Pergantian mode mempertahankan saldo asli dan hasil pesanan lama; snapshot pemasok dinonaktifkan hingga sinkronisasi ulang. Harga jual tersimpan tidak ditimpa oleh repricing Sandbox.
- Pembayaran Sandbox memakai tombol simulasi lokal, atau Midtrans Sandbox jika gateway tersebut aktif dengan flag sandbox dan server key SB-Mid-server. Custom QRIS asli dan key Midtrans produksi tidak dipakai pada pesanan uji. Endpoint simulasi memerlukan akses pesanan dan menolak pesanan produksi; pembayaran berulang tidak menggandakan kredit/pengiriman. Hanshop disimulasikan tanpa hit transaksi; premium mengirim payload contoh tanpa mengubah/membuka stok asli. Bot mendukung cek nomor SB dan memberi label saldo uji. Tombol batal invoice mengikuti status terkini, termasuk setelah polling.
- Validasi: 253 tes backend (252 lulus, 1 dilewati, 3.565 assertion), termasuk 14 tes Sandbox/85 assertion; 18 tes frontend; 38 tes bot existing serta tes tambahan Sandbox pada commerce; TypeScript/Vite build, build bot, Pint dan diff check lulus. HTTP pengujian memakai fake/preventStrayRequests: belum menjalankan transaksi pada akun Digiflazz/Midtrans pengguna. Browser database SQLite salinan memverifikasi perubahan mode, label development key, pembayaran simulasi sampai Berhasil/Lunas dengan akun contoh. Bukti assets/store-sandbox-mode.png dan assets/store-sandbox-order.png.
- Migrasi MySQL lokal additive selesai setelah backup privat tervalidasi before-store-sandbox-20261018-051255.phpser.gz (12.666 byte). Data toko existing tidak diubah mode otomatis. Pemeriksaan browser hanya mengubah panel-sandbox.sqlite; tidak menyisipkan transaksi uji ke database aplikasi nyata. Server sementara 8010 dan tab pengujian ditutup setelah pemeriksaan.


### Penyelesaian penghalang pergantian mode — 18 Oktober 2026

- Audit read-only Bogor Topup menemukan satu invoice awaiting_payment/unpaid Midtrans Sandbox dengan parsing Hanshop masih ready, tanpa provider_id dan belum kedaluwarsa pada waktu pemeriksaan. Guard lingkungan tetap dipertahankan; tidak memaksa selesai atau membatalkan invoice pengguna.
- Pesan penolakan pergantian mode kini menyebut hingga tiga nomor pesanan penghalang dan mengarahkan ke Transaksi pelanggan. Detail transaksi owner menyediakan Batalkan pesanan dengan dialog konfirmasi; staff/pemilik toko lain ditolak. Pembatalan memakai pemeriksaan/penonaktifan invoice Midtrans yang sama dengan checkout, menolak pesanan lunas/diproses dan mempertahankan status jika gateway belum memberi kepastian. Snapshot menyimpan id owner pembatal; label Dibatalkan berlaku pada panel dan storefront, tombol tidak ditampilkan ulang setelah dibatalkan.
- Pengujian terkait Sandbox dan commerce mencakup pembatalan panel lalu ganti mode, otorisasi, pesanan lunas, Midtrans 404 dan kegagalan gateway; TypeScript/Vite build, Pint dan diff check lulus. Tidak menjalankan pembatalan atau transaksi pada gateway pengguna.


### Pembayaran uji tanpa scan QRIS — 18 Oktober 2026

- Invoice Midtrans Sandbox milik pesanan is_sandbox kini menawarkan Simulasikan pembayaran tanpa QRIS selain simulator resmi Midtrans. Simulasi lokal menyimpan penanda audit payment_simulation dan mengkredit/memproses melalui mesin pesanan yang sama, tanpa menghubungi atau menandai lunas Midtrans. Label hasil menjelaskan perbedaan tersebut pada storefront dan panel transaksi.
- Kelayakan tombol dihitung server dan divalidasi kembali dengan lock toko/pesanan. Produksi, Midtrans Production meskipun order ditandai sandbox, invoice kedaluwarsa/dibatalkan, serta akses pesanan yang tidak sah ditolak. Pembayaran berulang idempotent; topup hanya menambah sandbox_balance sekali. Midtrans Sandbox tetap bisa diuji melalui tautan simulator resmi dan URL gambar QRIS tanpa menampilkan server key.
- Verifikasi: 60 tes Sandbox/commerce, 546 assertion, TypeScript/Vite build, Pint dan diff check lulus. Tidak membayar/mengubah status invoice pengguna; pemeriksaan Bogor Topup hanya membaca kelayakan simulasi. Dokumentasi Midtrans menyatakan Sandbox memakai payment simulator dan bukan pembayaran uang nyata: https://docs.midtrans.com/docs/testing-payment-on-sandbox .


### Notifikasi pelanggan dan callback Digiflazz — 18 Oktober 2026

- Notifikasi pesanan web dibuat sejak menunggu pembayaran; outbox dipicu sesudah commit melalui SendCustomerNotice. Worker bot menerima sinyal manager terautentikasi, dengan pemeriksaan antrean mandiri setiap 2 detik. Status bot dibaca dari BotConnection/cache sesuai connection_id, menggantikan pemeriksaan kolom bot_status yang tidak ada. Kanal Hanshop/email memakai job dan command customer-notices setiap 5 detik, terpisah dari rekonsiliasi pemasok. Kegagalan enqueue tidak membatalkan transaksi yang sudah commit; outbox tetap tersimpan.
- POST /api/commerce/digiflazz memverifikasi HMAC-SHA1 raw body menggunakan secret per koneksi, mencocokkan referensi/SKU/tujuan/lingkungan dan memakai lock serta mesin penyelesaian transaksi yang sama. Referensi memilih koneksi sehingga satu URL bisa melayani beberapa akun/toko. Status final idempotent; seluruh parsing gagal langsung memicu kegagalan/refund sesuai lingkungan; parsing berikutnya diantrekan setelah commit. Callback yang berbenturan dengan proses berjalan disimpan sebagai job terenkripsi dengan retry. Ping hanya memberi respons kesehatan, tanpa perubahan transaksi. Polling tetap menjadi cadangan.
- Owner mengatur secret melalui modal Atur webhook di Sumber produk. Secret tidak ditampilkan kembali; perubahan diblokir selama transaksi masih berjalan. Pemutusan koneksi menghapus API key namun mempertahankan secret callback untuk menyelesaikan pesanan yang telanjur dikirim. URL dibangun dari APP_URL dan harus publik (HTTPS) agar dapat dijangkau Digiflazz; localhost tidak dapat menerima callback eksternal. Akun pemasok yang dipakai beberapa toko memakai URL/secret yang sama pada semua koneksi akun tersebut.
- Audit database aplikasi hanya membaca dua pesanan tujuan 087800001234: SB01M56WQDBTC94BNTEPBJ7AWEYW dan SB01M56WNXNJANK08X1Q3B4GZN1D, keduanya paid/pending dengan attempt Digiflazz pending. Tidak memalsukan callback atau memaksa hasil gagal pada database pengguna. Penerimaan callback publik dan waktu tiba pesan WhatsApp nyata belum diuji. Rujukan: https://developer.digiflazz.com/api/buyer/webhook/ dan https://developer.digiflazz.com/api/buyer/test-case/ .
- Verifikasi: tes callback, outbox, sandbox dan commerce; 40 tes bot; TypeScript/Vite build; Pint dan diff check. Menjalankan worker memerlukan php artisan queue:work --sleep=1 --tries=3 --timeout=90; scheduler php artisan schedule:work tetap berjalan. Restart proses bot manager melalui npm run manager:dev untuk memuat perubahan worker; proses milik pengguna tidak dihentikan otomatis.


### Tunnel publik callback Digiflazz — 18 Oktober 2026

- ngrok resmi v3.39.11 dipasang portabel di %LOCALAPPDATA%/ngrok/ngrok.exe; Authenticode valid dengan penanda tangan ngrok, Inc. Memakai konfigurasi akun ngrok existing tanpa menampilkan token. Tunnel background meneruskan 127.0.0.1:8000, mengubah Host upstream menjadi localhost:8000 dan membatasi akses publik hanya POST /api/commerce/digiflazz melalui traffic policy lokal. Panel/storefront tetap memakai URL lokal. Traffic inspector dinonaktifkan.
- DIGIFLAZZ_WEBHOOK_URL opsional pada config suppliers dan .env.example memungkinkan URL callback tunnel ditampilkan di modal tanpa mengubah APP_URL, subdomain atau routing toko. .env lokal sudah diarahkan ke endpoint tunnel aktif. Jika URL tunnel berubah, perbarui variabel ini dan URL pada panel Digiflazz. Executable, kebijakan tunnel dan log berada di profil lokal, bukan source/dependency aplikasi.
- Verifikasi publik: ping callback HTTP 200 dengan status SUCCESS/event ping; GET /owner melalui tunnel HTTP 404. Tidak mengirim callback transaksi palsu, mengubah status pesanan pengguna, atau mengubah konfigurasi akun Digiflazz. Owner tetap perlu menyamakan secret melalui modal Atur webhook dan panel Digiflazz. Sepuluh tes callback/configuration lulus (83 assertion).


### Pengalihan invoice storefront setelah aksi — 18 Oktober 2026

- Audit read-only menemukan SB01M576D3YT2HHMJ7TT3FZXY6RY dan SB01M576EQF7QEYSK8D9M7QXDG8C sebagai dua pesanan berbeda tujuan 087800001233, keduanya sukses. SB01M575TQNNDVAJ2C28BRT9QTNM adalah pesanan lama tujuan 087800001234 yang sudah gagal; tidak ada penimpaan ID/data.
- Penyebab navigasi direproduksi dengan session _previous.url menunjuk invoice lama dan POST tanpa Referer (halaman invoice menerapkan Referrer-Policy no-referrer). Penggunaan back() setelah aksi mengambil URL invoice lama dari session. CustomerOrderController kini menggunakan redirect 303 eksplisit ke invoice order yang telah diotorisasi beserta access token yang sesuai; diterapkan pada simulasi, refresh dan pembatalan, termasuk error validasi/gateway. Header private/no-store dan no-referrer tetap dipertahankan.
- Tiga skenario regresi gagal sebelum perbaikan dan lulus setelahnya. Pengujian gabungan transaksi/sandbox/callback/redirect 76 tes dan 698 assertion lulus, diikuti tambahan validasi expired/paid: empat tes redirect dengan 31 assertion lulus. Pint dan diff check lulus. Tidak membuat transaksi, mengubah status, atau mengirim pesan pada akun/pemasok pengguna; pengujian memakai SQLite terpisah dan HTTP fake/preventStrayRequests.


### Card pembayaran storefront setelah lunas — 18 Oktober 2026

- Card invoice kini membedakan lunas, menunggu pembayaran dan invoice tidak aktif. Setelah paid, tampil konfirmasi lunas, total dibayar, metode dan waktu pembayaran WIB jika tersedia, dengan pesan mengikuti hasil pesanan (sukses/pending/gagal/review). Keterangan simulasi lokal tetap tampil ringkas sesuai fakta pembayaran.
- Tombol siapkan/periksa pembayaran, QRIS/countdown, simulator dan pembatalan hanya tampil saat awaiting_payment/unpaid. Invoice batal/kedaluwarsa menampilkan penjelasan tanpa instruksi membayar ulang. Polling status pesanan existing dipertahankan.
- Validasi TypeScript/Vite build dan diff check lulus. Perubahan presentasi saja, tanpa perubahan transaksi/data pembayaran pengguna.


### Pendaftaran pelanggan toko — 18 Oktober 2026

- Halaman /account/register dan tautan Daftar pelanggan memisahkan pendaftaran dari pilihan masuk WhatsApp/email. Nama dan WA diperlukan untuk pendaftaran; akun dibuat hanya setelah OTP benar, kemudian masuk ke /account. Email/password dapat disiapkan melalui verifikasi email pada akun existing. Pelanggan tetap hanya tersimpan dalam customers per store_id; tidak membuat akun owner/staff/Hanshop.
- Form kirim OTP, verifikasi dan password memakai state terpisah. Langkah OTP menampilkan nomor tersamarkan, masa berlaku, pesan kedaluwarsa, hitung mundur jeda kirim ulang, serta pilihan ganti nomor. Challenge aktif diverifikasi dari cache/purpose/store/expiry/attempts, sehingga session ID kedaluwarsa tidak membuat tampilan terus menunggu kode. OTP tetap dikirim lewat integrasi Hanshop existing.
- Pendaftaran baru diblokir ketika toko tidak menerima pesanan/closing, termasuk perubahan status saat OTP sedang menunggu; pelanggan existing masih dapat login untuk melihat saldo/riwayat. Nomor existing masuk ke akun yang sama tanpa menggandakan akun atau mengubah nama/saldo. Pengalihan OTP/password/email kini menuju halaman akun yang sesuai, tanpa bergantung pada URL invoice sebelumnya di session.
- Verifikasi: 75 tes backend terkait dengan 759 assertion lulus, termasuk 8 tes pendaftaran; TypeScript/Vite build, Pint dan diff check lulus. Browser pada bogortopup.localhost:8000 memverifikasi halaman daftar, tautan masuk/daftar dan pilihan email/password, tanpa submit OTP atau membuat akun asli. Bukti assets/customer-registration.png. Pengiriman OTP WhatsApp nyata belum diuji; memerlukan API Hanshop dan bot pengirim yang terhubung.


### Email wajib saat pendaftaran pelanggan — 18 Oktober 2026

- Form daftar kini mewajibkan nama, email dan WA. Email dinormalisasi menjadi huruf kecil, terikat pada payload challenge OTP, diperiksa unik per toko sebelum pengiriman dan diulang di dalam lock toko saat verifikasi untuk mencegah konflik bersamaan. Data baru disimpan setelah OTP WA benar; email_verified_at tetap null, sehingga login email/password tetap memerlukan verifikasi email terpisah.
- Login WA nomor baru tidak lagi otomatis membuat akun tanpa email; pengguna diarahkan ke Daftar pelanggan. Akun pelanggan existing tetap dapat login dan pendaftaran ulang tidak menimpa email existing. Email sama boleh digunakan pada toko berbeda. Tidak menambah migrasi atau mengubah akun pelanggan yang sudah ada.
- Verifikasi: 44 tes pendaftaran/commerce (489 assertion) lulus sebelum penambahan tiga skenario email; seluruh 11 tes pendaftaran (161 assertion) kemudian lulus, termasuk email kosong/tidak valid, duplikat, konflik setelah OTP, perubahan email pada request verifikasi dan larangan melewati daftar via login. TypeScript/Vite build, Pint dan diff check lulus. Pengujian memakai HTTP fake, tanpa kirim OTP nyata.


### Password saat pendaftaran pelanggan — 18 Oktober 2026

- Form daftar menambahkan password dan konfirmasi wajib, minimal 12 dan maksimal 128 karakter. Password di-hash sebelum disimpan dalam challenge OTP; plaintext/konfirmasi tidak disimpan pada cache/session payload dan input frontend dibersihkan setelah pengiriman. Akun baru menyimpan hash hanya setelah OTP WA benar. Request verifikasi tidak bisa mengganti password challenge dan akun existing tidak ditimpa passwordnya melalui pendaftaran ulang.
- Verifikasi email tetap wajib untuk login email/password. Endpoint verifikasi email mempertahankan password existing jika kolom password kosong; pelanggan yang belum memiliki password tetap wajib membuatnya. Account summary hanya menambahkan boolean has_password, bukan hash. Form akun memberi petunjuk pilihan mengganti password.
- Verifikasi 50 tes pendaftaran/commerce, 543 assertion lulus, termasuk hash cache/DB, validasi panjang/konfirmasi, larangan penggantian lewat verify, perlindungan akun existing, serta verifikasi email lalu login memakai password pendaftaran. TypeScript/Vite build, Pint dan diff check lulus; tanpa OTP/pesan atau pembuatan akun pada layanan nyata.


### Login pelanggan otomatis email/WhatsApp — 18 Oktober 2026

- Mengganti tab metode dengan satu input Email atau nomor WhatsApp. Adanya @ mengaktifkan kolom password dan tombol Masuk; nomor memakai Kirim OTP dengan cooldown dan challenge existing. Input identifier dipertahankan saat berganti jenis, error dibersihkan dan password dikosongkan ketika berpindah jenis identitas. Payload email hanya berisi email/password; payload OTP hanya phone/intent. Form daftar tetap terpisah.
- TypeScript/Vite build dan diff check lulus. Browser memverifikasi nomor mengaktifkan Kirim OTP tanpa kolom password, email menampilkan Password/Masuk, lalu kembali ke nomor menyembunyikan password. Tidak submit form, mengirim OTP atau login nyata. Bukti assets/customer-auto-login.png.


### Akun pelanggan compact dan dropdown navbar — 18 Oktober 2026

- Halaman /account diatur ulang menjadi ringkasan identitas/saldo dan tab Pesanan, Mutasi saldo, Profil. Riwayat memakai filter ringkas serta pagination atas/bawah; mutasi membedakan saldo masuk/keluar dan status pesanan memakai badge. Profil memuat kontak/status verifikasi serta aksi email/password, tanpa tombol keluar/topup/pengaturan yang bercampur dalam satu baris.
- Modal email dipisahkan menjadi langkah email lalu OTP; password ditampilkan hanya jika belum ada atau pelanggan memilih Ganti password juga. Topup tetap melalui modal dan mesin pembayaran existing. Halaman profil tidak mengambil daftar riwayat; isolasi store/customer/environment dan filter/pagination dipertahankan.
- StorefrontAccountMenu dipakai pada navbar Nebula/Aurora/Atlas untuk tautan Profil (/account?tab=profile) dan POST Keluar. Dropdown menutup ketika klik luar/Escape atau navigasi. Guest/pratinjau mendapat tautan Akun. Shared prop storeCustomer hanya id/nama, dengan pemeriksaan autentikasi dan kecocokan store; pelanggan toko lain tidak ditampilkan. Dropdown Aurora ditempatkan di luar nav yang bisa scroll agar tidak terpotong.
- Verifikasi 54 tes account/pendaftaran/commerce (605 assertion) lulus; setelah koreksi pencarian profil, empat tes account (62 assertion) lulus kembali. TypeScript/Vite build, Pint dan diff check lulus. Browser pada fixture SQLite terpisah memverifikasi ringkasan, dropdown, navigasi profil dan modal email. Tidak mengirim email/OTP atau mengubah akun/saldo pengguna. Bukti assets/customer-account-clean.png. Server fixture 8010 dan tab pemeriksaan ditutup setelah verifikasi.


### 2026-10-18 - Compact storefront leaderboard
- Replaced the oversized centered leaderboard card with a compact heading, segmented period selector, and aligned rank/customer/orders/spend columns using storefront theme tokens.
- Highlighted the first three global ranks; mobile layout hides the redundant orders column and shows successful order counts under customer aliases instead.
- Preserved alias privacy, ranking calculations, environment scoping, and server pagination. Pagination appears above and below when multiple pages exist; single-page lists omit inactive navigation.
- Added a concise empty state and a separate privacy/calculation note.
- Validation: `npm run build` passed (TypeScript and Vite); browser preview showed aligned columns and successful daily-period navigation. Screenshot: `assets/leaderboard-clean.png`. Responsive CSS inspected; no mobile browser verification claimed. No production data modified.


### 2026-10-18 - Feature parity across storefront templates
- Enabled the existing engagement, flash sale, trending, and homepage section editors for Aurora, Nebula, and Atlas, preserving owner/staff authorization and modal pickers. Sections support title/order/visibility; catalog remains required, and an optional leaderboard callout links to the full rankings page.
- Shared the section renderer and supplier-backed promotion data across templates. Aurora retains its quick-order introduction; Atlas retains its sidebar/catalog layout. Shared promotional cards use each template's color tokens.
- Enabled welcome ads and floating customer-service links on the Aurora/Atlas homepage, with CS also available on inner pages. Added leaderboard navigation to both templates and preserved selected-template preview navigation.
- Existing logo/favicon/theme/banner settings remain shared per store. Category-level nickname verification and destination inputs continue through the same checkout/category controllers; no per-template duplicates or database migration required.
- Added a regression covering design publication, section order, discounted promotions, manual category trending, category checkout options, and leaderboard access in all three templates, with foreign store products excluded.
- Validation: 38 relevant Laravel tests / 603 assertions passed; TypeScript and Vite build passed; Pint and diff checks completed. Browser preview checked Aurora/Atlas promo sections, banner autoplay, and Atlas leaderboard navigation. Screenshot: assets/shared-template-features.png. No live transactions or production store settings modified.


### 2026-10-18 - Shared compact storefront visual system
- Added scoped storefront styling for Aurora, Nebula, and Atlas across landing/catalog, category checkout, order/payment receipt, lookup, login/registration, account, leaderboard, and calculators. Reduced oversized type/spacing/cards, standardized controls and focus states, and kept per-store theme colors.
- Added a shared Aurora/Atlas navigation and an Atlas header used on both landing and inner pages, retaining marketplace and quick-order distinctions. Preview links preserve the selected template, including calculator links.
- Removed duplicate headings in category/auth pages while keeping one semantic h1. Initial incomplete checkout guidance is neutral; selected-product blockers remain visible, and server validation errors retain their error presentation.
- Applied the existing reveal hook to Aurora/Atlas pages as well as Nebula. Added product/ranking targets, kept 550ms one-time reveals, supported reduced-motion, and avoided rescanning on timer text-only mutations. No animation dependencies added; simplified repeated game-card background effects.
- Responsive CSS includes stacked checkout, compact scrolling navigation, mobile-friendly control sizes and form font sizes, and two-column mobile nominal choices. Mobile browser viewport was not available in this tool session; responsive rules were source-reviewed.
- Validation: TypeScript/Vite production build passed; 18 frontend tests passed; 48 Laravel tests / 736 assertions passed. Browser checked Aurora landing, Nebula fixture account/dropdown, category/game fields, login, lookup, and Atlas leaderboard-to-home navigation. Screenshots: assets/storefront-aurora-polish.png, assets/storefront-category-polish.png, assets/storefront-account-polish.png, assets/storefront-atlas-polish.png.
- Browser fixture uses isolated SQLite and fake external HTTP; no OTP sent or real order/payment created. Temporary localhost:8010 server is stopped after verification; user services on other ports remain running.


### 2026-10-18 - Customer dropdown wallet and account shortcuts
- Shared customer dropdown now displays the current store balance and links to profile, wallet mutations, transaction history, and logout, plus a topup shortcut that opens the existing account topup modal.
- Header payload remains scoped to the authenticated customer/store, contains no contact/password data, and selects production versus sandbox balance consistently. Topup shortcut is unavailable when the store stops accepting transactions or is closing.
- Validation: 5 account tests / 97 assertions passed, including environment balance and closed-store restrictions; TypeScript/Vite build passed. Browser fixture verified dropdown layout and opening/reopening topup without creating an invoice. Screenshot: assets/customer-wallet-dropdown.png. Temporary fixture server 8010 stopped after inspection.


### 2026-10-18 - Independent floating navbar search
- Replaced header searches that mutated homepage filters with one shared floating search component across Nebula, Atlas, and Aurora (compact search trigger on the floating Aurora navbar). Local catalog filters remain independent.
- Added store-scoped GET /search: at most 5 active categories and 8 active products, with category/product destination links and public price/availability only. Search reads local catalog snapshots without supplier refresh HTTP calls and returns private/no-store responses.
- Added 300ms debounce, cancellation of superseded requests, a bounded 30-second in-component result cache, scrollable groups, loading/empty/error states, clear/outside-click/Escape handling, and arrow-key/Enter selection. No pagination inside the dropdown.
- Validation: 3 search feature tests / 63 assertions passed across all templates, covering tenant isolation, inactive/private data exclusion, bounded results, SKU lookup, query validation, stopped stores, and no supplier calls. TypeScript/Vite production build passed.
- Browser checked Nebula fixture search for Canva without modifying homepage filters/URL, keyboard navigation to the category, and the Aurora compact dropdown using preview data. Screenshot: assets/navbar-floating-search.png. No real orders or supplier requests created; fixture server 8010 stopped after inspection.


### 2026-10-18 - Template-specific category scroll direction
- Aurora category filters stay in a single non-wrapping row with horizontal overflow on all screen sizes.
- Atlas category filters stay vertical with bounded vertical overflow (up to 400px on desktop / 220px on small screens), including wrapped long category labels without horizontal scrolling.
- Category areas are named keyboard-focusable regions. TypeScript/Vite build and diff checks passed; browser previews verified Aurora row and Atlas sidebar layouts. Screenshots: assets/aurora-horizontal-categories.png and assets/atlas-vertical-categories.png. Preview examples fit within their desktop bounds; no populated long-list/mobile browser test claimed.


### 2026-10-18 - Aurora horizontal overflow fix
- Bounded Aurora's homepage grid with minmax(0,1fr) and reset child/catalog/category min-width so long non-wrapping category content no longer stretches the entire page. Quick-order hero columns also have zero minimum track widths, preserving the mobile single-column breakpoint.
- No body overflow clipping workaround: the category row retains its own horizontal scroll and themed scrollbar.
- Validation: TypeScript/Vite build and diff checks passed. Browser checked Bogor Topup's actual Aurora storefront with 24 category labels, scrolled the category row horizontally, and verified header/product columns stayed inside the viewport. Screenshot: assets/aurora-overflow-fixed.png. No account/store settings or orders modified.


### 2026-10-18 - Single storefront input focus indicator
- Standardized text/select/textarea focus to one contiguous themed border indicator. Composite search, quick-order phone, lookup, and catalog filter fields show focus on the wrapper only; inner inputs have no extra outline/shadow. Keyboard indicators for links/buttons and native choice controls remain available.
- Validation: TypeScript/Vite build and diff checks passed. Browser checked focused Aurora quick-order phone and floating navbar search; screenshot assets/storefront-single-focus.png. No account/transaction data modified.


### 2026-10-18 - Global theme-aware scrollbars
- Centralized viewport and nested scroll styling in scrollbars.css for panel/storefront sidebars, tables, dialogs, dropdowns, textareas, navigation, and lists. Removed conflicting hidden/thin component overrides; overflow directions and dimensions remain unchanged.
- Scrollbars use rounded 8px Chrome/Safari tracks/thumbs and thin Firefox colors, hover/active accents, panel light/dark tokens, and storefront palette tokens. High-contrast system colors remain supported.
- Added ScrollbarTheme with initial Inertia page props and navigate subscription (outside page context) to mirror the active storefront palette to html/body viewport scrollbar tokens; leaving storefront removes these overrides.
- Validation: TypeScript/Vite build and diff checks passed. Browser verified the actual Bogor Topup Aurora page and category scrollbars in its dark/purple palette, then navigation to the owner preview without loading failures. Screenshot: assets/global-themed-scrollbars.png. No transaction/settings data modified.


### 2026-10-18 - Aurora / Atlas banner autoplay and animation
- Confirmed Bogor Topup has autoplay=true, interval=5, and two enabled banners without modifying the stored configuration.
- Banner autoplay no longer pauses merely because the pointer is over the banner. Pointer navigation does not latch the keyboard-focus pause; explicit Play clears the focus pause so playback can resume while its button remains focused. Keyboard focus, manual pause, background tabs, and reduced-motion still pause playback as appropriate.
- Shared slide/copy transition selectors now apply to all storefront templates, rather than only .ng-site. Updated editor help to describe actual playback behavior.
- Validation: 19 StoreDesign tests / 347 assertions passed; TypeScript/Vite build and diff checks passed. Timed browser checks on visible Atlas/Aurora previews verified slide 1 to 2 after 5 seconds, continued Atlas autoplay after Next click, stable manual Pause, and automatic movement after Play. Screenshot: assets/banner-autoplay-fixed.png. No real order/payment or store configuration changed.


### 2026-10-18 — Default brand logos for imported categories

- Bundled 20 locally served category logos (approximately 138 KiB total) from Wikimedia Commons pages declaring public-domain text logos or CC0. Per-file source page, original URL, declared copyright status, and SHA-256 are recorded in `apps/web/public/brand/catalog/sources.json`; brand/trademark rights remain with their owners.
- Added curated, whole-word category aliases in `config/category_logos.php`. Unknown/ambiguous names and missing files return no image; no remote asset requests occur during import or storefront rendering.
- Selected, all, service, and category imports share the existing importer. Only categories newly created by that import receive default artwork. Existing/manual categories, uploaded artwork, and intentionally removed images are preserved on later imports. Existing stores are not backfilled or modified.
- Shared storefront art preserves logo proportions against a neutral background; category editor previews and navbar search thumbnails also contain rather than crop bundled logos. Manual categories retain the existing initials artwork.
- Validation: 24 tests / 474 assertions passed across ImportedCategoryImageTest, CatalogImportAllTest, CatalogTaxonomyTest, and StorefrontSearchTest; TypeScript/Vite build passed. Browser checked a real import into the isolated SQLite fixture, showing logos alongside manual and unknown-category initials. Screenshot: `assets/imported-category-logos.png`. No live store data or supplier balances changed.


### 2026-10-19 — Article publishing completed; legacy logos and Nebula navbar

- Completed per-store article management under Kanal & tampilan → Artikel & berita, with separate articles.view/articles.manage staff grants. Create/edit/preview/delete use existing interactive modals; the main list has search, status filtering, and upper/lower pagination. The approved Tiptap editor is loaded with the owner article page; public pages render validated JSON using React rather than raw HTML.
- Editor supports headings, bold/italic/underline/strike, lists, quotations, font size/family, line height, color/highlight, alignment, links, uploaded inline images and an optional cover. Draft/publication status, stable per-store slug, author snapshot, timestamp, tags, excerpts, and revision conflict checks persist server-side.
- Public /artikel and /artikel/{slug} follow the current tenant template and theme, with topic/search filters, featured/latest articles, author/date/time (WIB), sharing links, cover, formatted body, topic chips, and related reading. Added article links to storefront navigation; unpublished, suspended, bot-only, and cross-store resources are denied. Uploaded draft media is private; unused uploads older than 24 hours have a scheduled cleanup command.
- Existing MySQL was backed up before the two additive article migrations (backup: storage/app/private/backups/before-store-articles-20261018-164126.sql). Both migrations were applied in the preceding run. UI checks here used isolated SQLite data: create formatted draft, save, reopen, and render published list/detail with banner, tags, and related articles. No sample articles were published into a real tenant.
- Found why existing category cards lacked logos: earlier logic only decorated newly imported categories. Added digitaltoko:category-logos with dry-run default, optional --store, automatic branding backup before --apply, and preservation of custom/manual/removed-image preferences. Applied to bogortopup: 12 known imported categories filled; unsupported brands retain initials. New manual categories and explicit image removal now record that preference for later backfills.
- Product cards now consume category_image in all templates. Nebula groups currency/account controls at the far right while preserving bounded search width and mobile ordering. Verified the actual Bogor Topup category now shows the Canva logo and the account controls align at the right edge.
- Validation: 33 related tests / 719 assertions passed (articles, import/backfill, taxonomy, navbar search, asset metadata); frontend build and PHP formatting passed. Screenshots: assets/article-editor.png, assets/articles-storefront.png, assets/article-detail.png, assets/bogor-category-logo-navbar.png.


### 2026-10-22 — Checkpoint URL OtomaX dan storefront

- Dokumentasi utama root/web, arsitektur, keputusan, deployment, permission staff dan catatan UI disinkronkan dengan source lokal 22 Oktober. Meliputi modul URL Webreport OtomaX, migrasi konfigurasi terenkripsi/jadwal dan metadata sumber, scheduler/queue, produk hilang/tidak valid serta perlindungan harga callback. Fondasi Excel/CSV tetap ada dan UI disembunyikan. Cache saldo/Hanshop 60–180 detik dibedakan dari katalog Digiflazz dan URL OtomaX 300 detik.
- Storefront: susunan mobile tiga template; pencarian infinite scroll yang menyatu per Kategori/Produk; katalog kategori memakai sale_price naik sebelum pagination; quick order Aurora ringkas, dropdown produk floating tanpa prev/next dengan append saat scroll dan loading minimal 500 ms; 24 preset warna dalam tiga kelompok, tetap mendukung edit manual dan pratinjau.
- Regresi pada checkpoint ini: 183 tes backend lulus, satu dilewati karena sampel XLSX eksternal, 2.103 assertions dalam 184 tes. Cakupan: OtomaxUrlCatalogTest, OtomaxTest, StoreRetentionTest, CustomerCommerceTest, StoreCatalogTest, StoreSandboxTest, NebulaStorefrontTest, StorefrontSearchTest dan QuickOrderTest. Seluruh 31 tes frontend lulus. Build TypeScript/Vite terakhir berhasil setelah perubahan source terakhir; Pint dan diff whitespace lulus sebelum commit.
- Tes MySQL URL 6.000 sumber sudah lulus terpisah pada tahap implementasi (satu tes/13 assertions, database sementara dibuat/dibersihkan); tidak dijalankan ulang pada checkpoint dokumentasi. Detail backup/migrasi dan tiga feed yang dibaca read-only berada di OTOMAX_PROGRESS.md. Pengujian browser memakai fixture/pratinjau dan ukuran iframe mobile, bukan transaksi/pembayaran nyata atau bukti deploy produksi.
- Bukti visual tersimpan lokal pada storage/framework/testing/screenshots (diabaikan Git); jalur spesifik ada di UI_READABILITY_AUDIT.md. Tidak memasukkan .env, cache, backup, session, build generated maupun DigitalToko.zip ke commit source. Tidak ada dependensi baru pada rangkaian perubahan ini. Restart queue worker dan jalankan migrasi saat deploy sesuai DEPLOYMENT.md.


## 23 Oktober 2026 — Paket berdasarkan platform

- Implementasi tiga platform dengan sumber Digiflazz, Hanshop, OtomaX, dan stok premium pada semuanya; filter, pembuatan toko, admin paket, perubahan paket admin, pemilihan langganan, voucher, dan landing page diselaraskan.
- Harga/durasi/status/ID penawaran terlengkap lama dipertahankan. Enam penawaran sumber terbatas lokal dinonaktifkan; empat penawaran kanonik tetap aktif. Toko lama dinormalisasi tanpa memperpanjang atau memulai ulang masa aktif. Invoice historis tidak ditulis ulang.
- Backup MySQL lokal tersimpan privat sebelum migrasi. Migrasi dan PackagePlanSeeder berhasil pada database lokal.
- PHPUnit: 486 tes, 482 lulus, 4 dilewati; 6.332 assertions. Mencakup migrasi berulang, snapshot dan masa aktif tetap, penolakan pembelian baru penawaran lama, pembayaran invoice lama, perpanjangan, downgrade kanal, voucher gratis, retensi, isolasi toko, dan permission staff. Suite memakai batas memori CLI 512 MB untuk tes XLSX existing.
- Frontend: 31 tes lulus. TypeScript dan build produksi berhasil. Pemeriksaan browser memakai database simulasi terpisah, tanpa pembayaran atau request supplier nyata: tiga pilihan platform, modal admin, pergantian platform owner, dan harga mengikuti pilihan durasi tampil sesuai.
- Peringatan build existing: referensi `/storefront/nebula-gaming-hero.webp` tetap diselesaikan saat runtime; tidak menghambat build.


## 23 Oktober 2026 — Pusat panduan publik

- Menambahkan `/panduan` dan 20 halaman tutorial pada host platform. Index memuat metadata saja, dengan pencarian kata kunci, filter topik dan sembilan kartu per halaman. Detail memuat persiapan, daftar langkah, screenshot yang dapat diperbesar, FAQ, rujukan dan artikel panduan terkait.
- Menambahkan navigasi/CTA halaman utama dan tombol Panduan kontekstual pada semua menu WorkspaceLayout admin/owner/staff, terbuka di tab baru. Pemetaan mengenali tab staff dan detail transaksi.
- 18 gambar PNG berasal dari antarmuka database simulasi terpisah. Tidak ada perubahan database produksi, penambahan dependency atau pengiriman transaksi pemasok untuk membuat panduan.
- TutorialTest: 3 tes, 541 assertions lulus, termasuk seluruh 20 detail publik, metadata index, slug tidak dikenal, host tenant, gambar dan related valid. Frontend: 32 tes lulus termasuk pemetaan panduan menu. TypeScript/build produksi berhasil.
- Browser: pencarian Midtrans dan kata kunci OTP, perpindahan ke detail, daftar langkah, serta pembesaran gambar dengan latar modal putih/tautan gambar penuh terverifikasi. Screenshot simulasi tidak dipakai sebagai bukti transaksi/gateway produksi.
- Pemeliharaan konten/gambar: lihat TUTORIALS.md.


## 23 Oktober 2026 — IP whitelist pemasok pada popup

- Menambahkan SUPPLIER_OUTBOUND_IP pada env web/example, config bersama dan penyajian IP publik yang valid saja. DIGIFLAZZ_OUTBOUND_IP tetap didukung untuk konfigurasi Digiflazz existing/override.
- Popup koneksi Digiflazz dan tambah/edit OtomaX menampilkan IP koneksi serta Salin IP. Konfigurasi kosong/tidak valid menampilkan informasi belum diatur, tanpa mendeteksi IP melalui layanan luar ketika membuka menu.
- Tutorial whitelist diperbarui agar penyewa menyalin IP dari popup. Screenshot Digiflazz diperbarui dari fixture terpisah; IP contoh bukan IP produksi.
- Sembilan tes backend terkait lulus (618 assertions), termasuk owner/staff, konfigurasi valid/kosong, diagnostik Digiflazz dan panduan. Build/TypeScript berhasil. Browser fixture memverifikasi kedua popup dan keberhasilan tombol salin.


## 23 Oktober 2026 — Petunjuk visual pada setiap langkah

- Seluruh 20 panduan (89 langkah) mempunyai gambar dengan penanda panah, area kontrol, caption dan petunjuk bernomor. Digunakan 61 screenshot JPEG dari fixture SQLite terpisah dan dua ilustrasi SVG yang secara eksplisit dibedakan dari screenshot dashboard supplier.
- Perbesar petunjuk membuka modal dengan area gambar yang dapat digeser; gambar lazy-loaded mempunyai dimensi file yang eksplisit agar posisi halaman dan penanda stabil. Tidak menambahkan dependency atau migrasi.
- TutorialTest: 3 tes / 4.465 assertions lulus, memeriksa seluruh detail publik, gambar tiap langkah, dimensi aktual, koordinat, jumlah penanda, label ilustrasi serta tautan. Frontend: 32 tes lulus. TypeScript dan build produksi berhasil; peringatan aset hero Nebula yang sudah ada tetap terpisah dari perubahan panduan.
- Browser fixture: gambar Digiflazz dan modal perbesar diperiksa; panah menunjuk kolom username/API key dan tombol simpan. Screenshot memakai data simulasi, tidak memanggil pemasok nyata. Dukungan geser layar kecil tersedia; tidak ada emulasi perangkat mobile pada pemeriksaan ini.


## 23 Oktober 2026 — Halaman publik paket dan simulasi toko

- `/paket` tersedia tanpa login pada host platform. Tautan Paket & harga ditambahkan ke beranda dan pusat panduan. Tiga platform memakai penawaran aktif plan galaxy dari database admin, termasuk harga dan durasi sebenarnya; penawaran nonaktif/legacy tidak ditampilkan.
- Simulasi menampilkan nama toko, alamat subdomain, platform, durasi dan total sewa. Nama toko tidak unik; subdomain diperiksa lewat endpoint GET `/paket/subdomain` dengan debounce 500 ms, pembatalan permintaan lama, status loading/tersedia/tidak tersedia/gagal serta coba ulang. Endpoint dibatasi 30 permintaan per menit dan tidak menulis data atau mengakses DNS.
- `StoreSubdomain` menyatukan normalisasi/validasi dengan pembuatan toko: 3–40 karakter, format slug, daftar nama khusus platform dan keunikan pada database. Status tersedia tidak mereservasi nama dan pengecekan diulang saat pembuatan toko. Tidak mengubah ketentuan verifikasi owner/aktivasi atau membuat invoice dari simulasi.
- Total adalah harga sewa satu toko untuk durasi terpilih. Voucher dan kode unik pembayaran baru dihitung pada invoice. Domain mengikuti konfigurasi platform. Tidak ada dependency atau migrasi baru.
- PublicPricingTest + PanelWorkflowTest + TutorialTest: 17 tes / 4.844 assertions lulus dengan APP_URL localhost untuk host pengujian. Meliputi harga berubah/nonaktif, tanpa penawaran, slug tersedia/terpakai/khusus/tidak valid, input array, isolasi host, tidak ada toko/invoice yang dibuat dan pemeriksaan ulang ketika nama sudah diambil. Browser fixture memverifikasi subdomain tersedia/terpakai/khusus serta perubahan total 30 ke 90 hari. TypeScript/build produksi diperiksa.

## 23 Oktober 2026 — Domain pribadi per toko

- Checkpoint pekerjaan sebelumnya tersimpan pada commit `729bb8e`. Owner terverifikasi toko Web/Web + Bot dapat menghubungkan, mengganti, memverifikasi dan melepas satu domain gratis lewat Pengaturan toko. Subdomain bawaan tetap tersedia. Staff dan owner toko lain tidak dapat mengubah binding.
- Verifikasi memakai TXT kepemilikan, kecocokan seluruh IP DNS dengan origin, serta proof HTTPS dengan sertifikat valid, pin IP dan tanpa redirect. Hasil pemeriksaan usang tidak dapat mengaktifkan domain yang sudah diganti. Klaim belum terverifikasi memiliki batas tujuh hari; hostname tetap unik antar toko.
- Migrasi lokal diterapkan setelah backup tabel `store_domains` ke `storage/framework/testing/backups/store-domains-before-20261023-091948.sql`. Tidak mengubah `.env` atau DNS publik. Contoh konfigurasi Caddy/PHP-FPM dan panduan VPS ada pada `CUSTOM_DOMAINS.md` serta `deploy/Caddyfile.example`.
- StoreDomainTest, PlatformFoundationTest, PanelWorkflowTest dan TutorialTest: **43 tes / 5.180 assertions lulus** dengan APP_URL localhost. Cakupan meliputi isolasi owner/staff, hostname/duplikat, klaim kedaluwarsa, TXT/IP salah atau privat, TLS gagal, proof salah, aktivasi/pelepasan, izin TLS dan perubahan domain saat verifikasi. Pint, TypeScript/build produksi dan pemeriksaan whitespace lulus.
- Browser memakai fixture SQLite terpisah: modal domain/DNS, tombol salin, panduan empat langkah dan pembesaran screenshot berpanah diperiksa. Panduan kini 21 topik / 93 langkah, 65 screenshot JPEG dan dua ilustrasi SVG. Bukti lokal: `apps/web/storage/framework/testing/screenshots/custom-domain-settings.jpg` (diabaikan Git). Data domain dan override target contoh dibersihkan setelah pemeriksaan.
- DNS/HTTPS pada tes disimulasikan; sertifikat sungguhan belum diterbitkan dan contoh Caddy belum divalidasi dengan binary atau VPS pengguna. Uji publik masih memerlukan domain yang diarahkan ke origin serta konfigurasi web server/sertifikat sesuai panduan. Tidak ada transaksi atau request pemasok nyata pada pemeriksaan ini.

## 26 Oktober 2026 — Checklist onboarding toko

- Popup Buat toko memperjelas subdomain bawaan (nama singkat, bukan domain lengkap), memberi pesan ketika input mengandung titik/format tidak sesuai, serta petunjuk domain pribadi dan tautan panduan untuk platform Web/Web + Bot. Domain pribadi tetap diatur setelah toko dibuat; tidak ada field klaim domain pada pembuatan draft.
- Setelah pembayaran awal diterima, Langganan & aktivasi dan Ringkasan toko menampilkan checklist bersama: sumber atau stok premium, katalog/harga, pembayaran pelanggan, alamat web jika didukung, dan WhatsApp. Progress memakai data toko, bukan centang manual. Checklist tetap dapat dilihat setelah aktivasi. Halaman pengaturan toko provisioning menyediakan tombol Persiapan toko untuk kembali ke checklist.
- Sumber dianggap terkonfigurasi bila ada koneksi aktif terverifikasi atau stok premium ready milik toko. Katalog memerlukan produk aktif dengan parsing/stok tersedia, snapshot belum kedaluwarsa dan harga efektif mencukupi modal; pembacaan dibatasi chunk 100 dan berhenti setelah menemukan satu produk yang memenuhi. Pembacaan tidak menyinkronkan/menghubungi pemasok atau mengubah harga. Toko khusus stok dapat langsung membuka Kelola stok.
- Pembayaran mengikuti mode toko: simulasi sandbox, Midtrans dengan key dan mode sesuai, atau QRIS custom dengan lisensi/payload/token forwarder. Status siap menunjukkan konfigurasi tersimpan, bukan bukti gateway/forwarder online atau pembayaran nyata. Pesanan ditutup ditandai perlu disiapkan. Secret/kredensial tidak dimasukkan ke props checklist.
- Syarat aktivasi dan penghitung otomatis 24 jam tetap seperti sebelumnya: subdomain bawaan, serta WhatsApp untuk Bot/Web + Bot. Checklist persiapan berjualan tidak menjadi syarat aktivasi baru; panel menjelaskan kapan masa sewa dapat dimulai dan meminta persiapan transaksi dilengkapi sebelum menerima pelanggan. Domain pribadi dan WhatsApp notifikasi pada paket Web opsional untuk aktivasi.
- Tautan staff mengikuti permission yang ada; kelola domain tetap owner saja. Data/konfigurasi toko lain tidak melengkapi checklist toko ini. Tidak ada dependency/migrasi baru atau perubahan database toko pengguna.
- StoreSetupTest, SubscriptionTest, PanelWorkflowTest dan TutorialTest: 35 tes / 5.151 assertions lulus. Pint dan TypeScript/Vite build lulus. Browser memakai fixture SQLite terpisah: alur provisioning Bot dan toko aktif Web + Bot, tautan domain menuju kartu, input domain lengkap ditolak secara jelas, petunjuk domain pada popup Web, lebar 390 px serta tema terang/gelap diperiksa. Bukti lokal (diabaikan Git): apps/web/storage/framework/testing/screenshots/store-onboarding-checklist.jpg. Tidak ada pembayaran, pembelian pemasok, pairing atau pengiriman OTP nyata.

## 26 Oktober 2026 — Runtime PM2 satu perintah

- Root ecosystem.config.js menyediakan `pm2 start` lokal untuk Laravel serve, Vite, queue, scheduler dan satu bot manager. deploy/ecosystem.config.cjs menjadi profil VPS untuk queue/scheduler/manager tanpa dev server atau path /var/www tetap. deploy/ecosystem.full.config.cjs menambah API Hanshop dan bot_wa_v2 secara opt-in; source/kredensial kedua proyek tidak diubah.
- Konfigurasi shared memakai argv array, interpreter PHP yang dapat dioverride, Node executable aktual, cwd setiap proyek dan fork satu instance. Queue timeout 180/retry_after existing 240, grace penghentian 200 detik; scheduler dan manager masing-masing satu. Port serve/Vite tidak bergeser diam-diam. Build, migrasi, pemasangan dependency, startup boot Windows dan service MySQL/Redis tidak dilakukan otomatis.
- Empat tes konfigurasi Node lulus, termasuk profil lokal/VPS/full, path dengan spasi, override PHP/port dan penolakan port invalid. Smoke nyata lewat `pm2 start --only digitaltoko-web` pada port sementara 8026 dengan path PHP Windows ber-spasi: status online, nol restart, GET /up HTTP 200. Proses probe dihapus dan port 8026 berhenti. Daftar aplikasi PM2 sebelum/sesudah kosong; daemon existing dipertahankan. Proses manual pengguna, public/hot Vite existing dan sesi bot tidak diubah.
- Queue/scheduler/manager/API/bot nyata tidak dinyalakan untuk smoke agar tidak memproses antrean/transaksi/pesan pengguna. Tidak ada bukti live semua integrasi dari tes konfigurasi. README root/web dan DEPLOYMENT.md diperbarui dengan persiapan, profil, log/restart/stop dan larangan scheduler/manager ganda. Tidak menambah dependency atau migrasi.

## 26 Oktober 2026 — Profil laptop lengkap dan launcher PM2 global

- Menggantikan default lokal lima proses pada catatan sebelumnya: root ecosystem.config.js kini memulai ketujuh proses development pengguna. API Hanshop dan bot_wa_v2 memakai tsx watch sumber; apps/bot/scripts/manager-dev.mjs melakukan build TypeScript lalu memuat manager, setara npm run manager:dev. Profil VPS existing tetap terpisah dan tidak diaktifkan/disetel ulang pada tahap laptop ini.
- deploy/install-pm2-laptop.ps1 memasang shim pm2.cmd/pm2.ps1 pada %USERPROFILE%/.digitaltoko/bin dan menambahkan satu entri PATH user di depan entri user existing. File PM2 npm tidak ditimpa. launcher.json hanya memuat path proyek, executable PHP dan CLI PM2, tanpa credential. Instalasi ulang mempertahankan satu entri PATH dan menolak menimpa shim yang bukan milik DigitalToko.
- Bare pm2 start dari folder mana pun diarahkan ke konfigurasi root secara absolut. pm2 status/logs, restart/stop berdasarkan nama atau namespace, dan start dengan target eksplisit diteruskan ke PM2 asli. CMD serta PowerShell berhasil diuji dari C:/Windows dalam dry-run routing dan pemanggilan --version nyata (7.1.1). Terminal lama perlu dibuka ulang agar membaca PATH user terbaru; terminal terintegrasi mungkin memerlukan restart aplikasi induk.
- Tujuh tes Node lulus: tujuh layanan dan argumen watch, profil produksi terpisah, override path/port, routing global tanpa mengubah command eksplisit, serta manager dev dengan compiler/manager palsu pada direktori sementara (build wajib lebih dahulu dan kegagalan build tidak membuka manager). Node syntax checks lulus. Tes tidak memakai sesi atau endpoint WhatsApp nyata.
- Profil tujuh proses tidak dinyalakan otomatis selama pemasangan karena pengguna masih menjalankan beberapa proses manual; installer tidak menghentikan proses, menjalankan queue, scheduler, API, pairing atau transaksi nyata. MySQL/Redis dan APK Android tetap layanan terpisah. README root/web dan panduan PM2 diselaraskan dengan default laptop terbaru.

## 26 Oktober 2026 — Popup terminal Windows pada proses PM2

- Source lokal menunjukkan PM2 sudah windowsHide=true pada proses utama, tetapi PHP CLI/Symfony serve dan schedule:work menjalankan subprocess melalui proc_open. Source PHP 8.5 hanya memakai CREATE_NO_WINDOW otomatis untuk SAPI selain cli. TSX watch juga membuat child Node tanpa windowsHide eksplisit. Konfigurasi background parent saja tidak mencakup seluruh keturunan.
- Menambah host GUI HiddenProcess.cs, dikompilasi installer laptop ke %USERPROFILE%/.digitaltoko/bin/hidden-process.exe dengan compiler .NET Framework Windows existing. Tiga layanan PHP development Windows memakai host ini, CREATE_NEW_CONSOLE + STARTF_USESHOWWINDOW/SW_HIDE sejak pembuatan, handle standar yang diwariskan, argv tanpa shell, exit code dan log tetap diteruskan. Node tetap langsung dipantau PM2, memakai preload NODE_OPTIONS yang mempertahankan opsi existing dan memberi windowsHide=true pada spawn/spawnSync/fork termasuk ekspor ESM.
- Tidak mengubah vendor Laravel/Symfony, PHP.ini, source API Hanshop/bot Hanshop, database atau session WA. Profil VPS/Linux tidak memakai host/preload Windows.
- Sebelas tes Node lulus, termasuk host Windows nyata: roundtrip argv ber-spasi/quote/backslash/metakarakter, stdin/stdout/stderr, exit code, PHP proc_open anak dan preload Node lewat path workspace ber-spasi/backtick. Uji PM2 serve pada port 8027: host native → PHP artisan → shell/PHP server, /up HTTP 200, tidak ada jendela terminal baru terlihat pada inventory CUA. Tidak menjalankan queue/scheduler/pemasok/WhatsApp nyata untuk probe.
- PM2 start terhadap definisi existing ternyata mempertahankan pm_exec_path lama walaupun interpreter/args berubah. Setelah memverifikasi semua layanan pengguna sedang stopped/PID 0 (selain probe web sendiri), definisi namespace digitaltoko didaftarkan ulang untuk probe dan kemudian dibersihkan. Seluruh proses probe/port 8027 sudah berhenti. Saat selesai profil belum dinyalakan kembali; pengguna cukup pm2 start agar ketujuh definisi baru dimuat. Launcher global dan host baru sudah terpasang. Runtime bootstrap/konfigurasi serta langkah pemutakhiran dicatat di DEPLOYMENT.md.

## 26 Oktober 2026 — Checkpoint domain, onboarding, PM2 dan email

- Menyimpan pekerjaan domain pribadi, panduan domain, checklist onboarding dan runtime tujuh layanan laptop termasuk launcher global/perbaikan console Windows. Keputusan email tetap satu pengirim platform dengan nama toko; tidak ada provisioning email cPanel/subdomain tenant.
- Pemeriksaan ulang sebelum commit: 49 tes web terkait domain/onboarding/langganan/alur panel/panduan lulus (5.292 assertions), 11 tes PM2 lulus, TypeScript/Vite build berhasil. Build masih memperingatkan referensi aset existing /storefront/nebula-gaming-hero.webp yang tidak ditemukan saat bundling.
- Pengujian memakai database testing dan transport simulasi. Pengiriman email produksi, DNS/TLS publik, konfigurasi VPS, backup/restore operasional dan topup saldo Hanshop owner dari panel tetap perlu ditindaklanjuti. Arsip pengguna DigitalToko.zip tidak dimasukkan commit.

## 26 Oktober 2026 — Pemantauan saldo dan deposit pemasok

- Menyelesaikan modul owner/staff Saldo & deposit pemasok: daftar akun, snapshot/status pemeriksaan, nominal/waktu/sumber saldo, refresh, tiket deposit Digiflazz/OtomaX, QRIS/status deposit Hanshop, riwayat dan modal instruksi. Staff memakai izin saldo/deposit; akun Hanshop bersama khusus owner. Panel melakukan polling database 15 detik; scheduler dua menit mengantrekan job ber-cache/lock.
- Snapshot mengikuti waktu event pemasok jika valid. Callback terlambat tidak memundurkan saldo; respons tanpa tanggal/waktu lengkap dan konflik timestamp memerlukan endpoint konfirmasi. Tidak mengubah hasil transaksi hanya karena monitoring gagal. Digiflazz/Hanshop tanpa field waktu tidak dianggap memiliki timestamp kejadian berdasarkan waktu penerimaan.
- Migrasi lokal dua proyek sudah diterapkan setelah backup. Script SQL API manual tersedia di API Hanshop/scripts/migrations/20261026_digitaltoko_supplier_deposits.sql; prasyarat payment runtime/ledger dan langkah deploy dicatat di DEPLOYMENT.md dan README API. Source API menambah bridge deposit/status idempoten serta saldo bertimestamp pada respons transaksi. Tidak ada dependency baru.
- Pengujian terakhir: 117 tes web lulus / satu tes opt-in dilewati (1.100 assertions), mencakup pemisahan toko/owner/mode, nol, waktu callback, kegagalan, idempotensi, simulator, parser dan regresi transaksi/retensi. PHP runtime membutuhkan memory_limit=512M untuk suite yang menyertakan fixture XLSX; run pertama pada 128M berhenti di penulisan XLSX, bukan kegagalan modul. Pint lulus. 23 tes API lulus termasuk dua integrasi MySQL terisolasi untuk invoice sewa dan deposit; TypeScript/kontrak response API serta build web/API lulus. Warning aset hero Nebula existing masih ada.
- Browser fixture SQLite terpisah memverifikasi menu, tabel, modal nominal format ribuan, submit deposit Digiflazz simulasi, redirect riwayat dan rincian total/rekening/berita. Bukti lokal: storage/framework/testing/screenshots/supplier-deposit-preview.png (diabaikan Git). Semua akses HTTP fixture dipalsukan; tidak ada tiket pemasok nyata, transfer, mutasi atau OTP nyata. PM2 lokal saat pemeriksaan tidak memiliki aplikasi berjalan; tidak menyalakan worker produksi selama validasi.

- Pemeriksaan lanjutan setelah menyatukan endpoint saldo lama dengan snapshot modul baru: suite katalog/OtomaX/saldo/network lulus 142 tes, satu integrasi opt-in dilewati (1.645 assertions). Tema gelap modal diperiksa pada fixture; angka, rekening dan instruksi tetap terbaca. Endpoint ringkasan saldo dan uji saldo OtomaX ikut memperbarui snapshot yang sama. Tidak ada service PM2 pengguna yang direstart atau deposit nyata dibuat.

## 26 Oktober 2026 — Minimum deposit Digiflazz dan penolakan tiket

- Panduan resmi Digiflazz Isi Saldo via Bank Transfer mencantumkan minimum Rp200.000 (https://digiflazz.com/docs/article/8DL2gM). Modul memakai minimum khusus Digiflazz Rp200.000 untuk permintaan deposit, sementara Hanshop/OtomaX tetap minimum platform Rp10.000. Nilai minimum berasal dari backend dan tampil pada modal; tombol tidak dapat mengirim nominal di bawah minimum. Controller dan service menolak sebelum membuat record/HTTP, termasuk sandbox.
- RC 64 (tarik tiket gagal) kini dicatat sebagai ditolak dengan alasan jelas, bukan unknown. RC dikenali dalam format angka/string. Penolakan menghasilkan toast error; timeout/transport/response tak dikenal tetap unknown dan tidak dikirim ulang otomatis. Riwayat unknown lama tidak diubah tanpa respons asli pemasok.
- SupplierWalletTest: 14 tes/91 assertions lulus; mencakup Rp100.000 ditolak tanpa HTTP/record, nominal Rp200.000 diterima, RC 64 dan replay, serta regresi pemisahan mode/saldo. Pint dan build web diperiksa. Tidak meminta deposit nyata atau mengubah database/kredensial pengguna.

## 26 Oktober 2026 — Perbaikan prasyarat deposit Hanshop

- Diagnosis database API lokal: payment belum mempunyai expires_at/last_checked_at. Dua request Hanshop dari web berstatus unknown tidak mempunyai mapping/payment upstream, karena insert gagal dan transaksi database dirollback.
- Menambahkan script manual MySQL idempoten API Hanshop/scripts/migrations/20261026_digitaltoko_payment_runtime_columns.sql. Jalankan sebelum 20261026_digitaltoko_supplier_deposits.sql; menambah kolom dan index runtime tanpa mengganti status payment/saldo lama. Lokal sudah diterapkan dan dijalankan ulang setelah backup privat hanshop-before-payment-runtime-1793019451445.sql.
- API deposit membedakan DEPOSIT_SCHEMA_NOT_READY, DEPOSIT_NOT_FOUND dan hasil belum pasti tanpa membocorkan detail SQL. Web menyimpan alasan aman; pemeriksaan status gagal menjadi validasi form, bukan error 500. Status check tidak membuat invoice. Request lama tidak dikirim ulang otomatis dan tidak diubah menjadi lunas/gagal tanpa bukti.
- Validasi: 15 tes web / 97 assertions dan 14 tes API lulus, termasuk MySQL terisolasi dengan schema payment legacy yang dimigrasikan dua kali. Pint dan build API lulus. Tidak membuat tiket/QRIS/payment nyata untuk verifikasi.

## 26 Oktober 2026 — Penyelesaian penyiapan sewa awal dan refund

- Checkpoint sebelum tahap ini: Hansbot ab67d47 dan API Hanshop 7fb7d79 menyimpan modul saldo/deposit pemasok serta perbaikan prasyarat database. Arsip pengguna DigitalToko.zip tetap tidak disertakan.
- Menambahkan kasus penyiapan per invoice awal lunas: menunggu owner, siap, gagal, ditinjau, permintaan refund, konfirmasi refund, selesai/refunded. Owner dapat melaporkan kendala atau meminta penyelesaian melalui Langganan & aktivasi; admin menangani dari Kelola toko > rincian toko, dengan filter khusus kasus yang perlu ditangani. Staff hanya melihat panel kasus. Pembayaran yang belum aktif lebih dari 24 jam memberi notifikasi admin yang dideduplikasi.
- Aktivasi serta timer otomatis ditahan saat gagal/ditinjau/refund. Pemeriksaan ulang menilai kesiapan tanpa membuat invoice atau memperpanjang masa sewa; timer kesiapan dimulai kembali setelah penanganan. Toko yang belum memasangkan WhatsApp tidak otomatis dianggap mengalami gangguan platform.
- Kebijakan disetujui pengguna: refund penuh termasuk kode unik ke saldo Hanshop owner hanya untuk sewa awal yang belum pernah aktif dan gangguan platform terkonfirmasi admin. Persetujuan memerlukan alasan, konfirmasi gangguan platform dan subdomain. Voucher gratis dibatalkan tanpa kredit uang; kuota voucher tidak dipulihkan. Pembatalan karena berubah pikiran tidak otomatis mendapat refund. Pengaturan toko tetap disimpan sebagai draft.
- Bridge API /internal/digitaltoko/billing/refund memvalidasi pemilik, nominal, invoice/payment lunas; satu transaksi MySQL mengkredit saldo, menulis ledger dan penanda refund unik. Pengulangan/concurrency tidak menambah saldo dua kali. Timeout mempertahankan status menunggu konfirmasi, menahan aktivasi, dan scheduler mencoba konfirmasi kembali dengan invoice yang sama. Tidak ada persetujuan refund otomatis.
- Migrasi web dan tabel refund API lokal diterapkan setelah backup privat. VPS perlu menjalankan script manual API Hanshop/scripts/migrations/20261026_digitaltoko_rental_refunds.sql setelah prasyarat invoice/payment/ledger, lalu migrasi Laravel dan memperbarui kedua layanan. Urutan dan verifikasi ada di DEPLOYMENT.md.
- Validasi akhir: 83 tes web / 801 assertions lulus; 14 tes API lulus termasuk database MySQL terisolasi untuk replay, concurrency, nominal lengkap dan rollback setelah penulisan saldo/ledger gagal. Pint, kontrak respons API dan build TypeScript/Vite web/API lulus. Warning aset hero Nebula existing tetap ada.
- Browser memakai SQLite fixture terisolasi: panel owner menahan aktivasi saat ditinjau, admin melihat alasan dan modal persetujuan Rp50.123 lengkap dengan checkbox serta konfirmasi subdomain. Bukti lokal: storage/framework/testing/screenshots/provisioning-refund-review.png. Tidak ada refund/pembayaran/OTP nyata, perubahan saldo pengguna, panggilan pemasok, atau restart proses PM2 pengguna. Kredit uang nyata pada VPS belum diuji.

## 26 Oktober 2026 — Landing page layanan DigitalToko

- Checkpoint penanganan penyiapan/refund sebelum revisi landing: Hansbot 7218966 dan API Hanshop 16c2c76. Tidak memasukkan arsip pengguna DigitalToko.zip.
- Merancang ulang Platform/Home dengan identitas ungu/cyan DigitalToko: hero dan ilustrasi panel, navigasi sticky/menu mobile, tiga pilihan platform dari definisi paket bersama, koneksi pemasok, enam kelompok fitur, pilihan ilustrasi Nebula/Aurora/Atlas, empat langkah onboarding, tautan panduan bergambar, FAQ dan CTA ke simulator paket/subdomain.
- Informasi calon penyewa mengikuti source/keputusan saat ini: seluruh sumber pada setiap platform; saldo/stok/biaya gateway/domain terpisah dari sewa; Midtrans akun toko atau QRIS custom berlisensi; maksimal lima staff; domain pribadi; WA notifikasi paket Web dan SMTP/platform email; KTP manual; aktivasi/masa sewa; perpanjangan/ubah paket dan masa tenggang. Tidak menambahkan harga tetap, testimoni, statistik penggunaan atau jaminan keuntungan yang tidak punya data. Tampilan panel/template pada landing diberi label ilustrasi.
- CSS landing dipisah dan dibatasi .dt-home/dt-* agar tidak mengubah panel maupun storefront. Menggunakan hook reveal existing dan transisi CSS, bukan dependency baru. Output HTML awal tetap terlihat; menghormati prefers-reduced-motion. Pilihan template mendukung keyboard, FAQ memakai details native, menu mobile memiliki aria-expanded, dan tersedia skip link.
- Validasi: TypeScript/Vite build lulus; 22 tes PublicPricingTest/PlatformFoundationTest lulus (201 assertions). Browser memeriksa desktop dan viewport mobile 390px (documentWidth=390), buka/tutup menu, pergantian Aurora/Atlas, jawaban FAQ, dan reduced motion (animation-name none). Log browser tidak menunjukkan error/warning saat alur ini. Bukti lokal di storage/framework/testing/screenshots/platform-landing-desktop.png dan platform-landing-mobile.png. Browser memakai fixture SQLite terisolasi; tidak membuat toko, invoice atau transaksi nyata.
- Tidak ada perubahan schema atau migrasi baru pada revisi landing. Warning bundling referensi hero Nebula existing tetap ada, terpisah dari aset landing. Server pengujian sementara dihentikan setelah pemeriksaan.

### Konsistensi navbar publik

- Beranda, paket, daftar/detail panduan dan login owner memakai komponen PlatformHeader yang sama, dengan stylesheet mandiri. Ukuran logo, tinggi, urutan menu, tombol masuk, sticky/backdrop dan menu mobile kini satu sumber. Halaman staff/admin serta storefront tetap memakai navigasi sesuai ruangnya.
- Menu Layanan/Tampilan toko/Cara mulai mengarah ke anchor beranda dari halaman mana pun. Halaman paket/panduan/login menandai halaman aktif; menu mobile tertutup setelah navigasi dan dapat ditutup dengan Escape. Login owner memakai navbar publik dan menghindari logo ganda di kartu formulir.
- Verifikasi browser pada lima rute menunjukkan tinggi header 87px dan logo 153px yang sama di desktop. Mobile 390px memakai tinggi 74px, tidak overflow, dan navigasi dari paket ke /#tampilan berhasil dengan menu tertutup serta posisi section di bawah header. Tidak ada warning/error browser pada alur uji. Build lulus; 25 tes halaman publik/panduan/platform lulus (4.878 assertions). Bukti lokal: storage/framework/testing/screenshots/platform-shared-navbar.png. Server fixture sementara dihentikan setelah uji.

## 27 Oktober 2026 — Perapian respons bot toko

- Respons perintah menu/katalog/layanan/kategori/saldo/konfirmasi/bukti pesanan memakai format ringkas dengan nama toko, ikon dan kelompok informasi; bingkai panjang di setiap baris dihapus. Perintah ketik tetap tersedia saat tombol tidak dirender klien WhatsApp.
- Tombol saldo membuka petunjuk topup tanpa membuat tagihan. Riwayat menyediakan single-select pesanan dan tombol refresh membawa nomor pesanan tertentu; token terikat penerima/chat, kedaluwarsa dan sekali pakai tetap berlaku. Pembelian tetap konfirmasi eksplisit; pengecekan status tidak membeli/mengirim ulang. Saldo minus ditampilkan dengan petunjuk, tidak mengubah saldo maupun pembatasan backend. Hasil produk tetap hanya keluar pada status sukses.
- Build dan 48 tes bot lulus memakai socket/API palsu dan transport fixture, termasuk alur baru serta pairing/manager existing. Tidak mengirim pesan/transaksi WhatsApp nyata, mengubah schema, atau merestart manager/sesi pengguna. Operator perlu memuat ulang worker melalui supervisor existing (laptop: pm2 restart digitaltoko-bot-manager). Native button rendering pada perangkat nyata masih perlu dicoba; fallback teks selalu dikirim. Pesan outbox/OTP backend tetap terpisah dan tidak ditulis ulang.

### 27 Oktober 2026 — Kelanjutan interaksi produk bot

- Menambahkan single-select produk tersedia pada halaman kategori dan hasil pencarian. Harga/ketersediaan dibaca ulang saat memilih. Premium menuju konfirmasi memakai nomor WA; kategori lain meminta tujuan dalam chat, mendukung ID/zona dua isian dan format tujuan kategori. Konfirmasi pembayaran existing tetap menjadi satu-satunya langkah pembuatan pesanan.
- Konteks tujuan dibatasi lima menit/5.000 entri dan terikat chat/pengirim/alias terautentikasi; menu/perintah/tombol lain membatalkannya. Isian invalid dapat diulang. Setelah order, tombol status dan belanja lagi melanjutkan navigasi. Fallback teks dipertahankan termasuk encoding pemisah pipe pada tujuan. Quote backend menambah metadata/proses normalisasi, bukan lookup nickname atau transaksi pemasok.
- 53 tes bot dan 55 tes commerce/tujuan/katalog web lulus (583 assertions); Pint dan TypeScript build lulus. Pengujian memakai socket/API/HTTP palsu, tanpa pesan/transaksi/provider nyata. Tidak ada schema/dependency baru atau restart proses pengguna. Reload manager/worker diperlukan untuk memuat build bot terbaru. Dukungan render list native pada perangkat nyata belum diklaim sebagai sudah teruji.

### 27 Oktober 2026 — Checkout bot dan urutan katalog

- Source bot Hanshop dipakai sebagai acuan alur: produk → tujuan selain APK premium → metode pembayaran → konfirmasi akhir → pembuatan order. Ditambahkan #pilihbayar saldo/qris/direct, #konfirmasi dan pembatalan; native memakai ID checkout terikat penerima/TTL lima menit dan final sekali pakai. Pemilihan metode belum membeli. Token metode alternatif dan checkout lama tidak dapat menghasilkan order kedua. Harga/voucher/inquiry tetap dibawa ke konfirmasi.
- #beli KODE cukup untuk APK premium, tanpa input tujuan; parameter tujuan tambahan diberi petunjuk ulang. Backend memakai nomor WA pelanggan untuk buy premium. Non-premium tetap meminta tujuan, termasuk ketika kategori salah disetel premium. Produk game dua isian mempertahankan format pemasok. Tidak mengubah fitur nickname web atau menambah lookup eksternal.
- Katalog bot sekarang dikelompokkan awalan kode sebelum digit pertama, diurutkan harga jual termasuk promo aktif, lalu angka kode/kode/id sebagai tie-break. Berlaku untuk kategori dan pencarian sebelum pagination; tidak memuat semua produk ke RAM. SQL mendukung MySQL/MariaDB target dan SQLite pengujian, tanpa schema baru.
- Validasi akhir: 57 tes bot lulus dan 56 tes web / 603 assertions lulus; Pint dan build bot berhasil. Tes mencakup checkout native/teks, mandatory tujuan, premium tanpa tujuan, order sekali, replay/TTL, sorting lintas halaman, flash sale nominal/persen, pencarian dan isolasi toko. Tidak ada pesan/transaksi/provider nyata atau restart proses pengguna. Rendering tombol WhatsApp nyata belum diuji; perlu reload worker untuk menggunakan build bot baru.

### 27 Oktober 2026 — Reload worker karena pilihan produk belum terlihat

Pemeriksaan runtime menemukan manager/worker masih dimulai pada 26 Oktober 21.47, sedangkan build pemilihan produk/checkout sudah diperbarui 27 Oktober. Sampel katalog Bogor Topup tetap tersedia (bukan terfilter habis). Memuat ulang hanya digitaltoko-bot-manager melalui PM2; worker baru dibuat dan status cache kembali CONNECTED tanpa menghapus sesi/pairing. 24 tes navigasi/commerce lulus. Tidak mengirim pesan uji atau membuat transaksi; operator perlu membuka #menu baru karena token dari proses sebelumnya tidak dipertahankan. Ini tindak lanjut operasional atas catatan sebelumnya yang belum merestart layanan.

### 27 Oktober 2026 — Pemadatan pesan dan pemisahan navigasi

- Nama toko uppercase, header tanpa bingkai panjang/ikon berulang, menu dan instruksi checkout lebih singkat. Katalog hanya nama produk, harga/kode, stok/ketersediaan dan konteks halaman; label Biaya admin pada postpaid dipertahankan agar tidak disangka total tagihan.
- Navigasi halaman/kategori/menu berada di pesan controls terpisah. Native yang tidak dikirim memperoleh fallback teks terpisah; konteks layanan/kategori/search tetap utuh. Tidak menaruh navigasi/tutorial belanja di pesan daftar produk lagi.
- 58 tes bot penuh lulus, lalu 27 tes terkait katalog/navigasi/commerce lulus setelah label admin dirapikan. Build berhasil; manager PM2 dimuat ulang tanpa logout/purge sesi untuk memuat perubahan. Tidak ada pesan uji, order atau saldo nyata yang dibuat oleh validasi otomatis. Format native perangkat nyata tetap perlu dilihat operator memakai #menu baru.

### 27 Oktober 2026 — Notifikasi pesanan dan saldo

- Menambahkan formatter CustomerNoticeText untuk pesan outbox pesanan dibuat, pembayaran diterima, pending, sukses, gagal, review dan kedaluwarsa. Header nama toko uppercase, satu ikon status, penekanan nama produk/nominal, nomor pesanan, tujuan non-premium, waktu kejadian tersimpan dalam WIB, batas bayar bila menunggu pembayaran, tautan bukti dan atribusi DigitalToko. Tidak menyalin pesan diagnostik pemasok mentah ke notifikasi ringkas.
- Topup sukses membedakan total dibayar dari saldo yang dikreditkan. Refund hanya disebut sudah masuk jika refunded_at terisi; guest diarahkan menghubungi toko untuk penyelesaian manual. Sandbox diberi label pengujian. Tidak menambah klaim nominal/waktu yang tidak tercatat.
- Hasil akun/SN disisipkan sebelum footer pada jalur WA, tetap mentah agar karakter password tidak berubah. Guard mensyaratkan order masih success dan event sukses milik order tersebut; termasuk event revisi/koreksi. Event idempotensi dan ACK pengiriman tidak diubah, pesan lama tidak ditulis ulang atau dikirim ulang. Email tetap memakai transport toko existing dan isi outbox bersama; OTP dan notifikasi langganan platform tidak disentuh.
- 92 tes terkait notifikasi/commerce/mail/webhook lulus (946 assertions), termasuk nominal topup/refund, uppercase, timezone, premium tanpa tujuan, revisi sukses dan pencegahan hasil bocor setelah gagal. Tes outbox juga dijalankan kembali setelah assertion diikat ke ID notice agar tidak bergantung urutan timestamp yang sama. Pint lulus. Tidak ada dependency/schema baru atau pengiriman uji/transaksi nyata. Queue diberi sinyal queue:restart agar pekerja selesai menjalankan job aktif lalu supervisor memuat kode baru, tanpa restart bot atau penghapusan sesi.

### 27 Oktober 2026 — QRIS WA ditarik setelah pembayaran sah

- Menambahkan persistensi key pesan gambar QRIS per store/order, deduplikasi, penyimpanan terenkripsi, endpoint registrasi/ACK terikat worker dan query cleanup berbasis payment_status paid. Worker menyimpan registrasi tertunda pada file privat atomik per sesi agar bertahan saat backend gagal/restart. Tidak mencatat gambar QR atau credential gateway.
- Revoke hanya memakai key fromMe milik bot pada target PN/LID yang tercatat. Kegagalan delete/ACK dicoba kembali dan tidak menahan notifikasi lunas. Setiap gambar QR yang dikirim ulang bisa dibersihkan; bukti teks dan hasil tetap ada. Tidak bisa menghapus QR lama sebelum fitur ini karena key belum dicatat. Mengikuti kemampuan revoke WhatsApp.
- Validasi: 62 tes bot dan 69 tes web / 663 assertions lulus, termasuk restart, concurrency, ACK palsu, store/phone/key asing, paid-before-register dan cleanup cascade. Tes QR diulang setelah pemeriksaan ID ACK. Tidak ada pengiriman/pembayaran uji nyata.
- Full backup lokal dibuat sebelum migrasi bot_qris_messages diterapkan. Dokumen deploy memuat urutan web migration → bot build → reload. Tidak ada perubahan API Hanshop/dependency. Manager/worker dimuat ulang untuk menerapkan kode; sesi tidak dihapus.

### 27 Oktober 2026 — Penarikan QRIS kedaluwarsa

- Scope readyToRevoke menyatukan kriteria query cleanup dan ACK: payment_status paid, status expired, atau unpaid/awaiting_payment yang melewati expires_at. Ini hanya membersihkan gambar WA, bukan mengonfirmasi pembatalan gateway atau mengubah order/saldo. Pending/review belum lunas tidak direvoke berdasarkan tanggal saja.
- 54 tes web QR/notifikasi/commerce lulus / 561 assertions; Pint lulus. Tidak ada migrasi atau dependency baru dan tidak perlu restart bot karena perubahan pemilihan cleanup berada di endpoint web. Batas cakupan key QR sejak versi pencatatan dan kemampuan revoke WhatsApp tetap berlaku. Tidak mengirim QR/pembayaran uji nyata.


### 2026-10-27 — Pesan transaksi WhatsApp mengikuti pembaruan

Pencatatan pesan diperluas dari gambar QRIS ke bukti/status pesanan, notifikasi otomatis, serta tombol pesanan. Saat status pembayaran/pesanan berubah atau notifikasi koreksi hasil dibuat, key pesan versi lama masuk antrean penghapusan; versi terbaru dipertahankan. Notifikasi tertunda yang sudah digantikan pembaruan lebih baru di-ACK tanpa mengirim teks usang. Pesan pemilihan metode pembayaran ditarik ketika masuk konfirmasi; key tahap konfirmasi ikut pesanan setelah order berhasil dibuat. Pesan katalog, pesan pelanggan, dan pesanan lain tidak ikut dihapus.

Tabel/endpoint `bot_qris_messages` dan outbox privat `qris-message-outbox.json` tetap dipakai untuk kompatibilitas. Kolom baru menyimpan jenis pesan, versi notifikasi, serta snapshot status pesanan/pembayaran. Key tetap terenkripsi di database; antrean registrasi tetap tahan restart. Kegagalan revoke tidak menahan notifikasi terbaru dan dicoba lagi oleh pump. Tidak ada perubahan saldo, pembelian ulang, dependency baru, atau perubahan API Hanshop.

Migrasi lokal `2026_10_27_085410_add_order_context_to_bot_qris_messages_table` sudah diterapkan setelah backup penuh `before-otomax-20261027-085756.sql`. Pengujian menggunakan transport WhatsApp palsu dan database terisolasi, bukan penghapusan pesan atau pembayaran nyata. Pesan sebelum pencatatan key tersedia tidak dapat ditarik secara retrospektif; kemampuan revoke tetap mengikuti ketentuan WhatsApp.


### 2026-10-27 — Refresh parsial transaksi panel dan navigasi WA sesudah sukses

Daftar transaksi pelanggan owner/staff dan bagian Percobaan sumber pada detail mendapat checkbox Auto refresh (awal nonaktif). Interval 10 detik memakai partial reload Inertia, mode rest tanpa permintaan tumpang tindih, dan pause ketika tab browser tersembunyi. Indikator putar tampil selama permintaan. Daftar hanya mengambil items/pagination sambil mempertahankan filter, halaman dan scroll; detail hanya mengambil attempts, dijeda saat modal/form tindakan terbuka. Prop detail lain dijadikan closure agar polling attempts tidak menghitung data detail/hak tindakan yang tidak diminta. Ini pembacaan database, bukan polling atau kirim ulang transaksi ke pemasok.

Notifikasi otomatis keberhasilan produk kini mendapat tombol Lihat layanan, Lihat produk, dan Menu utama hanya jika toko memiliki platform bot/full dan masih menerima pesanan. Web-only tetap menerima notifikasi tanpa navigasi transaksi. Token memakai MenuTokens worker yang sama, terikat penerima, serta key tombol ikut registrasi pesan per revisi untuk pembersihan pada pembaruan berikutnya. Notifikasi tanpa incoming device ID memakai native flow tersendiri dengan fallback perintah teks jika pengiriman tombol gagal. Kegagalan tombol tidak memicu pengiriman ulang notifikasi sukses yang sudah terkirim.

Validasi: build TypeScript/Vite web berhasil; 65 tes bot penuh sebelumnya tetap lulus, ditambah tes tombol notifikasi (8 tes file pesan WA lulus). 57 tes web terkait commerce, notifikasi dan pembersihan pesan lulus setelah penambahan tes partial response, filter, akses staff/lintas toko, dan batas lisensi tombol. Tidak ada dependency atau migrasi baru. Tes memakai database terisolasi dan transport WA palsu; belum merupakan bukti tampilan tombol pada perangkat WhatsApp nyata.


Revisi navigasi notifikasi akhir WA: tombol kini memakai definisi yang sama dengan `#menu` (Layanan & produk, Saldo pelanggan, Status pesanan), termasuk alur layanan ke kategori/produk. Dikirim untuk notifikasi akhir success/failed/expired, termasuk topup, selama paket bot/full masih operasional; bukan hanya produk sukses. Notifikasi pending dan toko web-only tidak mendapat tombol transaksi. Validasi revisi: 27 tes bot dan 11 tes web terkait lulus; transport WA tetap disimulasikan.


### 2026-10-27 — Batas bayar pada instruksi bot

Bukti pembayaran bot dan caption QRIS menampilkan expires_at invoice sebagai tanggal/jam/detik WIB serta sisa waktu saat pengiriman. Ini informasi statis dalam pesan, bukan pesan yang diedit tiap detik; countdown berjalan tetap tersedia pada tautan web. Pengecekan status kembali menghitung sisa waktu. Pesanan lunas/selesai tidak menampilkan instruksi tenggat aktif, tanggal kosong/rusak tidak dikarang, dan QR tidak dikirim ulang jika batas bayar telah lewat. Tidak mengubah tenggat invoice atau status pembayaran. Tes format WIB/sisa waktu/lunas/invalid/QR kedaluwarsa menggunakan transport palsu.


### 2026-10-27 — Koreksi tenggat invoice yang bergeser sekitar tujuh jam

Pemeriksaan MySQL lokal menemukan expires_at pada customer_orders/customer_inquiries/otomax_imports memiliki DEFAULT current_timestamp() ON UPDATE current_timestamp() implisit. Setiap update kolom lain mengganti tenggat dengan NOW() database; sesi database memakai SYSTEM (+07) sementara aplikasi menyimpan representasi UTC. Akibatnya invoice bot terbaru terlihat sekitar tujuh jam, padahal batas biasa yang dibuat CustomerCommerce adalah 15 menit (pascabayar mengikuti inquiry).

Migrasi 2026_10_27_094531_fix_customer_order_expiry_automatic_timestamp_updates mengganti tiga kolom menjadi DATETIME tanpa default/on-update implisit. Untuk instalasi yang terdeteksi memakai ON UPDATE lama, invoice biasa tanpa inquiry hanya dipulihkan ke created_at +15 menit bila expires_at tepat cocok pola updated_at + offset waktu database (toleransi dua detik) dan melampaui tenggat standar. Tenggat inquiry dan penyesuaian lain tidak ditebak; status, saldo dan nominal tidak diubah. Rollback tidak mengembalikan perilaku koruptif. Data invoice lama tidak diperpanjang saat diperiksa ulang.

Backup lokal penuh before-otomax-20261027-094709.sql dibuat sebelum migrasi. Metadata MySQL sesudah migrasi terverifikasi DATETIME tanpa EXTRA on update; dua invoice terbaru kembali berdurasi 15 menit. 56 tes web terkait commerce/notifikasi/key pesan lulus (636 assertions), termasuk batas tetap setelah update provider/message. Bukti ini bukan pembayaran gateway baru. Instruksi WA lama yang sudah terkirim tetap statis; #status NOMOR_PESANAN membaca tenggat terkoreksi.


### 2026-10-27 — Notifikasi pembatalan dan kedaluwarsa pelanggan

Jalur CustomerPayments::cancel sebelumnya hanya menyimpan expired/snapshot pembatalan dan menghapus QR tanpa membuat CustomerNotice. Sekarang pembatalan yang telah disahkan membuat event order:<id>:expired:cancelled di dalam transaksi database, sehingga job notifikasi terpicu setelah commit dan worker mengirim status baru beserta menu untuk paket bot/full. Format membedakan Pesanan dibatalkan dari Pembayaran kedaluwarsa dan melarang penggunaan ulang QR; tidak mengklaim refund/pembayaran yang tidak terjadi. Pembatalan pelanggan juga memakai jalur ini, tanpa perubahan hak akses panel.

Kedaluwarsa dari status Midtrans dan rekonsiliasi scheduler turut membuat event order:<id>:expired saat status benar-benar berubah. FirstOrCreate menjaga idempotensi; gateway yang belum mengonfirmasi pembatalan tidak menghasilkan notifikasi final palsu. Pembersihan key/QR dan tombol menu memakai infrastruktur versi pesan yang sudah tersedia. Status/nominal/saldo tidak diubah di luar perilaku pembatalan existing; tidak ada dependency/migrasi baru.

Validasi: 64 tes terkait commerce/notifikasi/cleanup awal lulus, lalu 9 tes terfokus mencakup pembatalan owner/pelanggan, pembatalan berulang, penolakan gateway, format pembatalan, expiry scheduler dan status Midtrans berulang. Semua transport eksternal dipalsukan; tidak melakukan pembatalan pembayaran atau pengiriman WhatsApp nyata. Pembatalan lama yang telanjur tanpa event tidak diputar ulang secara massal.


### 2026-10-27 — Pemulihan perintah bot tidak dikenal

Pesan berawalan # yang tidak cocok perintah apa pun (contoh #meni/#test) mendapat respons beridentitas toko PERINTAH TIDAK DIKENAL, arahan #menu dan tombol Menu utama/Layanan & produk. Tidak mengeksekusi tebakan perintah dan tidak memanggil katalog/mesin pesanan. Pesan percakapan biasa tetap diabaikan; toko tanpa izin bot transaksi tetap tidak merespons. Typo tidak lagi menghapus konteks checkout/tujuan yang belum selesai; pilihan tombol menu yang disengaja tetap memakai aturan pembatalan konteks existing. Test mencakup tombol recovery yang benar-benar diklik lewat token, desktop tanpa native controls, akses ditolak, dan konteks pemesanan tetap tersimpan. Tidak ada dependency/migrasi baru.


Revisi cakupan auto refresh detail transaksi owner/staff: checkbox dipindah ke header halaman dekat Kembali ke transaksi, karena kini memperbarui seluruh data detail (store, order, attempts, izin/tombol tindakan, keputusan). Tetap partial reload Inertia tiap 10 detik, tanpa reload dokumen penuh, menjaga scroll/state dan menjeda saat modal/form aktif. Daftar transaksi tetap hanya memperbarui tabel/pagination. Tes respons detail memastikan status/pembayaran baru diterima, sementara hasil rahasia, kontak tanpa izin dan snapshot pemasok tetap tidak bocor.


## Checkpoint 28 Oktober 2026 — sebelum modul kesehatan sistem

Pekerjaan yang sudah tersedia dan dicatat pada checkpoint ini: landing/navbar publik konsisten; alur bot layanan-kategori-produk, konfirmasi checkout, urutan harga/kode dan respons lebih ringkas; format notifikasi pelanggan; pencatatan/penghapusan pesan QRIS, bukti dan tombol sesuai revisi; menu setelah notifikasi akhir, batas bayar WIB, fallback perintah tidak dikenal; notifikasi pembatalan/kedaluwarsa; perbaikan expires_at MySQL; auto refresh daftar dan seluruh detail owner/staff; kepadatan mobile panel bersama. Audit mobile dan bukti pengujian ada di UI_READABILITY_AUDIT.md. Arsip DigitalToko.zip milik pengguna tidak dimasukkan checkpoint.

Keputusan operasional terbaru: VPS dapat memakai PM2 untuk API/bot Hanshop yang sudah ada, bot manager tenant, queue:work dan schedule:work. Web produksi tetap Nginx/Caddy + PHP-FPM. Tidak memasang cron scheduler kedua bersamaan. Konfigurasi VPS masih contoh dan perlu validasi deployment nyata. Setelah .env Laravel berubah, config:clear dan restart proses terkait diperlukan; --update-env hanya memperbarui environment PM2, bukan menghapus config cache Laravel. Domain ngrok baru harus tercantum sebagai alias platform; 404 callback pernah terjadi pada proses web yang masih memakai konfigurasi host lama.

Tahap berikutnya yang disetujui pengguna: modul Kesehatan sistem khusus admin. Cakupan: bukti heartbeat scheduler dan queue, status bot manager/worker, antrean tertahan/gagal, sinkronisasi pemasok dan webhook bermasalah, ringkasan/diagnostik aman serta tindakan yang jelas. Tidak mengirim ulang transaksi, membatalkan invoice, atau merestart layanan otomatis melalui halaman pemantauan. Data sensitif/kredensial/payload pelanggan tidak ditampilkan. Setelah modul selesai, dokumentasikan konfigurasi dan bukti tes/visual; pengujian produksi tetap dibedakan dari simulasi.

### 2026-10-28 — Modul kesehatan sistem admin

Checkpoint sebelum pengerjaan: `285db2e`, mencakup source sebelumnya dan catatan keputusan PM2. Arsip DigitalToko.zip tidak dimasukkan. Modul baru memakai controller/service/command/job/middleware, dua tabel monitoring, menu admin dan halaman `/admin/system-health`; tidak mengubah source/dependency/schema API Hanshop atau bot.

Enam layanan inti: database web, default cache + cache pemasok, scheduler, queue worker, bot manager loopback dan API Hanshop readiness. Bukti heartbeat punya umur; manual check tidak menandai scheduler/worker aktif. Queue synthetic job tidak membawa data bisnis, idempoten/token-guard, maksimal satu pending; failure handler memberi ruang probe berikutnya. Database queue punya ready/reserved/delayed/oldest/failed, Redis count-only. Status WhatsApp per toko memakai session-id/heartbeat, pemasok memakai snapshot/jadwal/error tersimpan. Halaman read-only dengan tombol pemeriksaan, partial auto refresh 10 detik, filter/pagination, tab dan modal diagnostik. Notifikasi admin hanya saat fingerprint kelompok masalah berubah.

Webhook observer global mencatat kode HTTP/hasil dan agregat menit, termasuk callback route/host404. Tidak menyimpan URL/token, body, query, IP atau error mentah. Riwayat panel 24 jam, retensi 7 hari, kegagalan observer tidak mengganggu penerimaan callback. UI memakai tabel/cell wrap, latar tombol, tema terang/gelap serta wadah scroll mobile existing.

Validasi: 20 tes khusus modul lulus, 185 assertions: admin-only, read-only checks, unconfigured/unavailable services, expiring/real worker and scheduler evidence, bounded/idempotent/late probe, probe failure, admin-only manual recovery untuk probe hilang tanpa retry bisnis, queue counts/Redis counts, safe failed-job listing, DB queue outage, fresh matching WhatsApp session, malformed timestamps/cache outage, stale/manual/failed supplier signals, UTC normalization, search/pagination/effective-stale filters, lease/deduplicated alerts/recovery, safe webhook aggregation/host404/retention/outage. Regresi akhir termasuk 20 tes modul lulus 123 tes/1370 assertions, 1 dilewati karena file ekspor supplier opsional tidak disediakan; meliputi AdminStore, BotConnection, DigiflazzWebhook, Otomax dan PanelNotification serta tes modul kesehatan terbaru. Run regresi membutuhkan PHP memory_limit 512M karena fixture XLSX lama; batas 128M sempat kehabisan memori di tes XLSX, bukan kegagalan fitur health. TypeScript/Vite build lulus; peringatan asset Nebula lama tidak terkait modul ini.

Migrasi MySQL lokal diterapkan setelah backup penuh before-otomax-20261028-093013.sql. Queue existing dimuat ulang melalui queue:restart, scheduler PM2 existing direstart tanpa membuat layanan kedua. Read-only evidence 28 Oktober 16:36 WIB: keenam layanan inti sehat, probe diterima queue, ready/reserved/delayed nol; 28 failed jobs historis tetap tersimpan dan tidak dicoba ulang/dihapus. Tidak menjalankan pembayaran/deposit/OTP/transaksi atau perubahan saldo sebagai pengujian.

Browser memakai SQLite fixture terisolasi pada8010 dengan transport HTTP palsu, bukan mengubah toko nyata. Tema terang/gelap, desktop1440 dan mobile390/320, pagination atas, modal (poll dijeda), tab callback dan Periksa sekarang diverifikasi; lebar dokumen tidak melampaui viewport dan konsol tidak mencatat error. Bukti visual system-health-admin.png pada direktori visualisasi sesi. Server/tab/viewport uji ditutup/dipulihkan setelah validasi. Batas operasional/restore/deployment nyata dijelaskan pada DEPLOYMENT.md.

Revisi diagnostik job gagal: nama proses dan penyebab aman kini tampil pada tabel; modal Rincian memisahkan dampak, langkah pemeriksaan admin/owner dan nomor catatan. Kasus saldo Hanshop membedakan endpoint belum tersedia, pembacaan akun ditolak, token/configuration/koneksi/rate limit/format respons. Kegagalan notifikasi, katalog, transaksi dan probe memiliki arahan sesuai proses. Data JSON metadata dibaca terbatas (32 KB) tanpa unserialize; exception dibaca terbatas (4 KB) dan hanya prefix pesan/kelas yang dikenal diterjemahkan ke teks statis. Kasus tak dikenal memakai fallback tanpa menebak penyebab atau menampilkan data mentah. Tab menjelaskan bahwa catatan gagal adalah riwayat, bukan bukti layanan masih gagal saat ini. Tidak menghapus/mencoba ulang job historis atau menghubungi pemasok. 22 tes health terkait lulus (225 assertions), termasuk diagnosis/kebocoran data/fallback; build TypeScript/Vite lulus. Tidak ada migrasi atau dependency baru.


### 2026-10-29 — Pascabayar OtomaX dan perhitungan komisi Digiflazz

Pascabayar kini memiliki pasangan SKU cek/bayar per parsing OtomaX, dipisah per toko/koneksi. Harga sumber minus disimpan pada billing.adjustment, sedangkan kolom modal unsigned tetap nonnegatif. Harga nol/negatif feed ditandai pascabayar; produk impor baru OtomaX pascabayar nonaktif sampai dikonfigurasi. Dropdown cek dibatasi produk gratis, tersedia, berbeda dari SKU bayar dan berasal dari koneksi sama. Cek yang hilang/usang/tutup membuat pasangan tidak tersedia. Sumber menampilkan komisi; perubahan komisi pada feed ikut terhitung sebagai perubahan harga. Fondasi impor CSV/Excel tetap menyimpan metadata signed tanpa mengaktifkan kembali menu unggah.

Inquiry OtomaX disimpan pending sebelum I/O, menampung format NAMA/TAG/ADM:: atau ADMIN/TTAG/PERIODE atau PTAG. Format PDAM pengguna diuji dengan data pribadi disamarkan. Respons cek tidak menghasilkan order/payment. Pending/ready dengan hash toko/produk/tujuan/WA/mode yang sama didedup selama belum dipakai dan belum expired; request key expired tidak mengirim ulang. Callback cek memakai profil snapshot dan validasi token/IP/koneksi/RefID/kode/tujuan. Quote siap dibekukan selama maksimal5menit dan sebelum tengah malam WIB. Kode/RefID bayar OtomaX berbeda dari cek; hasil bayar mencatat debit aktual dan tidak menimpa komisi katalog. Digiflazz memakai satu SKU/ref dan commands berbeda; selling_price menjadi tagihan standar, price modal, markup ditambahkan satu kali. Sumber quote dipertahankan saat bayar; fallback ke sumber pascabayar lain yang belum dicek tidak dilakukan.

Modal owner/staff menyediakan produk bayar → produk cek → admin supplier, tetap memakai dropdown floating/search/scroll. Web menunggu callback dengan polling inquiry tersimpan5detik, bot menyediakan tombol Cek hasil tagihan. Voucher/checkout menolak inquiry belum ready. Pemeriksaan browser terisolasi SQLite membuktikan pilihan cek satu koneksi, simpan/buka ulang pasangan, dan tampilan desktop/390px; tidak menyentuh transaksi pengguna atau menghubungi supplier.

Validasi:145 tes backend terkait dijalankan,144 lulus/1412assertions,1skip existing;32 tes frontend lulus;71 tes bot lulus; build TypeScript/Vite dan bot lulus. Tes mencakup saldo pelanggan, quote/komisi/markup, SKU/ref terpisah, callback cek berulang, tujuan/kode salah, malformed total, isolasi toko, cek tutup/usang, expiry tanpa kirim ulang, signed commission feed dan persistensi sync. Migrasi lokal diterapkan setelah backup penuh. Tidak ada transaksi supplier atau pesan WA nyata, tidak mengubah database API Hanshop. Lihat DEPLOYMENT.md untuk konfigurasi dan batasan format/pergantian sumber.


### 2026-10-29 — Keterangan hasil cek pascabayar dan respons OtomaX

Respons `Cek PLN Admin 2.000 CEKPLN20.530000000001 GAGAL. Nomor tujuan salah. Sal 941 @09:39 R#...` sebelumnya tidak cocok dengan profil bawaan sehingga inquiry tetap pending; DTO publik juga tidak membawa keterangan. Parser kini mengenali fallback format gagal tersebut untuk balasan awal/status/callback, mengisi kode produk, tujuan, keterangan, saldo, jam dan RefID. Konflik query/pesan tetap pending dan validasi referensi/tujuan/kode tidak dilewati. Contoh penangkap gagal Sal/alasan disediakan per event, ditambah contoh cek berhasil balasan awal berisi SN `RC:00 INQUIRY`; kelompok lama tidak ditimpa otomatis.

Inquiry menyimpan alasan/indikator parser dan pesan pemasok terenkripsi. Riwayat awal disimpan sebagai OtomaxEvent operation=inquiry dan tersedia pada tab Respons & callback; callback pembayaran/cek tetap tercatat di jalurnya. Pembeli web/bot mendapat alasan aman untuk nomor salah, tagihan lunas atau belum ada tagihan; alasan lain memakai arahan menghubungi toko. Pesan mentah/saldo pemasok/kredensial tidak dibagikan ke pembeli. Format tidak cocok diberi keterangan perlu pemeriksaan penangkap, pembayaran tetap tidak dapat dibuat. Hasil siap menampilkan nama, periode, pokok tagihan, admin dan total; biaya/komisi pemasok tetap privat.

Validasi mencakup parsing format pengguna dengan prefix angka/dot, callback gagal, konflik status/ref query, tidak ada order/potongan saldo saat cek gagal, riwayat pesan mentah dan penyamaran alasan publik. Browser SQLite terisolasi membuktikan pesan gagal pada checkout serta riwayat lengkap di panel, tanpa menghubungi supplier nyata. Tidak ada migrasi atau dependency tambahan untuk revisi ini.


Pemeriksaan kasus tujuan PLN 539610815592 (29 Oktober 2026): percobaan10.00WIB tersimpan pending tanpa pesan/event respons sehingga penyebab transport historis tidak bisa dibuktikan; percobaan10.05WIB mempunyai balasan awal diterima dan callback gagal TAGIHAN SUDAH TERBAYAR. Tidak mengirim transaksi baru untuk audit. Jalur ValidationException inquiry yang sebelumnya senyap kini mencatat event unknown dengan pesan statis serta flag transport_uncertain tanpa exception/kredensial mentah; callback menghapus flag setelah jawaban didapat. Dedup pending tetap tidak mengirim ulang otomatis. Parser gagal mendukung Sal/Saldo dan memperkaya keterangan bila profil menangkap status gagal tanpa alasannya. Pesan publik mengenali sudah terbayar; catatan gagal lama dengan alasan kosong dapat dibaca dari pesan tersimpan yang RefID-nya cocok, tanpa mengubah status finansial. Tes timeout/dedup/callback/lunas/rekonstruksi alasan lama lulus.


### 2026-10-29 — Keterangan hasil tangkapan dan loading cek tagihan

Revisi atas permintaan owner: semua field keterangan yang ditangkap pada inquiry OtomaX kini dikirim sebagai description publik, tidak dibatasi daftar alasan yang sudah dikenali. Message ringkasan existing tetap kompatibel; web/bot mendahulukan description saat gagal/pending dan menampilkannya pada hasil berhasil. Hanya field tangkapan keterangan yang dikirim (teks maksimal500karakter, karakter kontrol dibuang), bukan seluruh pesan supplier/saldo/credentials. React menampilkan sebagai teks, bukan HTML. Data lama memakai failure_reason sebagai fallback. Profil tetap dapat menggunakan template lengkap yang diberikan pengguna, termasuk titik pada keterangan/jam operasional.

Frontend menambahkan guard sinkron useRef terhadap klik ganda sebelum React render, spinner berputar, aria-busy dan tombol nonaktif selama HTTP/pending callback. Loading minimal500ms mencegah kedip; polling tetap5detik dan memakai key inquiry yang sama. Permintaan lama yang dibatalkan tidak dapat membuka guard permintaan baru. Mengganti tujuan/produk membatalkan UI request lama; checkout tidak dapat memakai hasil lama ketika pengecekan ulang berlangsung.

Validasi:66 tes backend terkait commerce/postpaid lulus (622assertions),32 tes frontend lulus,73 tes bot lulus, build web/bot lulus. Browser terisolasi memberi respons supplier simulasi lambat4detik: spinner aktif, tombol disabled, klik ganda menambah hanya1inquiry; keterangan nonstandar tampil persis. Tidak mengirim cek/transaksi supplier nyata. Tidak ada migrasi/dependency baru.


### 2026-10-29 — Keterangan pemasok untuk seluruh transaksi produk

Field keterangan tidak lagi terbatas pada inquiry pascabayar. Fulfillment menyimpan deskripsi respons yang telah lolos validasi referensi/produk/tujuan pada snapshot percobaan dan pesanan aktif. OtomaX memakai fields.keterangan; adaptor Digiflazz/Hanshop menerima field eksplisit description/keterangan bila tersedia (tidak meneruskan message mentah otomatis). Stok premium internal tetap mengirim data akun melalui result terenkripsi; catatan stok privat tidak dijadikan keterangan publik. Tidak ada migrasi/dependency/database API Hanshop yang berubah.

Keterangan teks maksimal500karakter muncul terpisah dari status dan hasil pada halaman pesanan pelanggan, detail owner/staff, teks notifikasi WA/email serta balasan status bot. Tidak membaca snapshot pemasok/kredensial ke DTO publik. Ketika berpindah parsing, keterangan aktif dibersihkan; riwayat per percobaan tetap tersimpan. Callback parsing lama tidak mengganti keterangan sumber aktif. Callback/kirim ulang terminal memakai fingerprint termasuk keterangan agar perubahan teks sah ikut diperbarui; refund/reversal tetap dijaga aturan existing dan callback identik tidak membuat perubahan berulang. Keputusan manual membersihkan keterangan pemasok sebelumnya.

Validasi:117 tes web terkait dijalankan,116lulus/1103assertions,1skip existing; setelah penyesuaian panjang teks,7tes terfokus lulus/115assertions.32tes frontend dan74tes bot lulus; build web/bot lulus. Tes alur prabayar dan premium mencakup pending, sukses, perubahan keterangan setelah sukses, callback gagal, refund hanya sekali, DTO owner, notifikasi tanpa kredensial hasil, dan sumber cadangan tanpa keterangan lama. Browser SQLite terisolasi membuktikan hasil premium/detail owner dan prabayar pending di desktop/390px. Tidak melakukan transaksi atau pengiriman pesan ke supplier nyata. Data lama tidak di-backfill otomatis.


### 2026-10-29 — Pemisahan admin layanan dan admin toko pascabayar

Permintaan owner: admin resmi layanan/pemasok tidak boleh terlihat berubah karena markup. PostpaidBilling kini menyimpan admin/supplier_admin sebagai admin layanan asli, store_admin sebagai markup toko, dan bill sebagai pokok. Amount tetap supplier_total + markup; komisi tidak dibebankan ulang. Contoh pokok100000/adminlayanan2000/markup5% (=100)/komisi500 menghasilkan totalpelanggan102100, modal101500, margin600 sebelum biaya gateway. Basis persen tetap admin layanan katalog; nominal dan kombinasi markup memakai ProductPricing existing.

Katalog mempertahankan price sebagai total biaya admin dan menambahkan postpaid_fees service_admin/store_admin. Label Total biaya admin membedakannya dari admin layanan. Owner melihat kedua komponen pada tabel; field harga tetap diberi label total biaya admin (layanan+toko), sedangkan konfigurasi pasangan supplier memuat admin layanan. Sumber produk menampilkan admin layanan dan komisi sekali (duplikasi tampilan komisi dibersihkan). Hasil cek, bukti pelanggan, detail owner/staff, notifikasi dan bot memisahkan pokok/admin layanan/admin toko. Biaya gateway/kode unik/voucher tetap mengikuti alur terpisah yang sudah ada. Tidak mengimplementasikan failover pascabayar pada revisi ini.

PublicBreakdown hanya mengirim tiga komponen pelanggan; modal/komisi tidak masuk rincian publik. Untuk data lama yang cukup lengkap, supplier_admin dan markup dipakai menormalkan admin gabungan tanpa mengubah total/masa berlaku/status/saldo. Data historis yang tidak memiliki rincian lengkap tidak ditebak. Tidak ada migrasi/dependency/database API Hanshop yang diubah.

Validasi:146tes backend terkait dijalankan,145lulus/1455assertions,1skip existing;32frontend dan75bot lulus; build web/bot lulus. Tes mencakup markup5%, adminlayanan tidak berubah, admin toko, komisi terpisah, totalcheckout/saldopelanggan, kebocoran modal, dan data lama dengan admin gabungan. Browser SQLite terisolasi membuktikan admin2000/toko100 pada hasil cek, bukti pesanan desktop/390px, tabel dan modal harga owner. Tidak mengirim permintaan atau pembayaran supplier nyata.


### 2026-10-29 — Revisi basis markup pascabayar menjadi komisi pemasok

Bagian ini menggantikan basis persen admin layanan pada catatan sebelumnya. OtomaX adjustment negatif dinormalisasi menjadi komisi positif; Digiflazz memakai commission positif. StoreCatalog dan inisialisasi produk memakai admin layanan + markup(komisi). Admin layanan tetap utuh. Komisi500/markup5% menghasilkan admin toko25; komisi400 menghasilkan20. Nominal/kombinasi mengikuti pembulatan Rupiah existing; surcharge OtomaX positif tidak dianggap komisi. Prabayar/premium tidak berubah.

Sinkronisasi Digiflazz dan URL harga OtomaX memperbarui metadata komisi serta harga produk dengan auto_update aktif, termasuk perubahan komisi tanpa perubahan admin. Harga tetap dipertahankan. Inquiry Digiflazz memakai komisi aktual selling_price-price; inquiry OtomaX pending menghitung ulang dari metadata SKU bayar terbaru saat jawaban siap. Fingerprint sumber/harga mencegah kunci baru menggunakan hasil cek siap dengan harga lama; permintaan dengan kunci sama, inquiry siap dan pesanan terkonfirmasi tetap menggunakan snapshot. Pending tidak mengirim cek berulang. Komisi/modal tetap privat, hanya admin layanan/admin toko ditampilkan kepada pembeli. Modal harga owner menjelaskan basis komisi dan menampilkan nilainya.

Validasi:166 tes backend terkait dijalankan,165 lulus/1649 assertions,1 skip existing;32 tes frontend lulus, build TypeScript/Vite lulus dan Pint dijalankan. Tes meliputi normalisasi dua pemasok, nol/surcharge, nominal/persen/kombinasi, sinkronisasi500→400, harga tetap, cache/idempotensi, callback pending dan pembekuan harga. Browser SQLite terisolasi membuktikan total admin2025→2020 (layanan2000/toko25→20), tanpa transaksi pemasok nyata. Tidak ada migrasi/dependency/database API Hanshop berubah dan tidak menambahkan failover pembayaran pascabayar.

Backup MySQL lokal sebelum pemeriksaan harga: storage/app/private/backups/before-otomax-20261029-045236.sql. Pemeriksaan/reprice lokal tanpa HTTP pemasok menemukan0 produk otomatis yang perlu diubah saat itu; bukan bukti transaksi live. Fixture browser terpisah dari database pengguna.


### 2026-10-29 — Koreksi penyesuaian modal pascabayar bertanda

Menggantikan formula normalisasi positif dan total supplier+markup pada catatan sebelumnya. Harga OtomaX positif tetap menambah modal, negatif mengurangi modal. sourceAdjustment mempertahankan tanda OtomaX; komisi positif Digiflazz diterjemahkan menjadi pengurang modal. amount=cost+markup; supplier_total tetap pokok+admin. Markup persentase memakai besar penyesuaian sebagai basis, tanpa menghilangkan tanda pada modal; nominal/kombinasi tetap berlaku. Keuntungan bukan lagi komisi+markup: markup adalah margin di atas modal.

Contoh tagihan100000/admin2000/markup1000: OtomaX -500 → modal101500/total102500; +500 → modal102500/total103500. Admin layanan tetap2000. store_admin publik adalah biaya toko neto (adjustment+markup), boleh negatif; markup privat tetap nominal keuntungan. Rincian lama tanpa store_admin direkonstruksi dari amount-bill-supplier_admin bila ada total snapshot, tanpa mengubah harga. Snapshot order kini mempertahankan amount untuk konsistensi fallback tersebut.

Catalog/autoprice dan inisialisasi produk menambahkan penyesuaian bertanda sebelum markup. Pemilihan awal sumber diurutkan berdasarkan admin+penyesuaian. Harga tetap tidak diubah. Quote baru mengikuti harga terbaru, quote siap/paid order tetap dikunci. Kenaikan modal yang melewati harga pesanan lama tetap ditahan review sebelum dikirim; tidak menagih ulang pelanggan. Tidak menambahkan failover pembayaran atau migrasi/dependency/database API Hanshop. Tes mencakup contoh nominal +/-500, checkout saldo serta modal pembayaran nyata melalui HTTP fake, normalisasi Digiflazz, perubahan harga/sinkronisasi, snapshot lama, review kenaikan modal, dan nilai persen negatif pada rincian neto.

Validasi koreksi bertanda:167 tes backend terkait dijalankan,166 lulus/1670 assertions,1 skip existing;32 tes frontend lulus; build TypeScript/Vite dan Pint berhasil. Queue restart signal sudah dikirim. Semua transaksi pengujian memakai HTTP fake/database terisolasi; tidak memotong saldo pengguna/pemasok nyata.


### 2026-10-29 — Harga jual dari modal tertinggi, transaksi dari modal terendah

Permintaan owner mengubah dasar harga otomatis seluruh jenis produk. StoreCatalog kini memilih basis harga tersendiri dari parsing eligible dengan modal tertinggi, sementara candidates tetap diurutkan termurah untuk OrderAttempt. Stok ready memuat agregat min/max: min untuk dispatch, max untuk harga. Produk tidak tersedia/quantity0/snapshot usang/koneksi nonaktif tidak menjadi basis. Harga tetap mempertahankan kebijakan sebelumnya. Inisialisasi harga produk baru tanpa nominal eksplisit menggunakan sumber termahal; prioritas markup produk/kategori/layanan tidak berubah.

Inquiry pascabayar memperhitungkan basis termahal pada hasil final, bukan menghitung ulang hanya dari sumber cek termurah. priceFromSources mengganti estimasi sumber cek dengan rincian aktual, memilih biaya admin+adjustment tertinggi dan menambahkan markup sumber basis tersebut. Amount/customer breakdown dan margin aktual terhadap sumber terpilih tetap konsisten. Pending OtomaX membaca kandidat lokal terbaru tanpa HTTP tambahan; quote siap/order lama tetap dikunci. Failover pembayaran pascabayar belum ditambahkan. Petunjuk katalog, modal harga dan tutorial disesuaikan.

Validasi:173 tes backend terkait dijalankan,172 lulus/1712 assertions,1 skip existing;32 tes frontend lulus. Enam skenario baru mencakup harga/prioritas parsing prabayar dan premium dengan failover nyata pada HTTP fake, dua stok premium berbeda modal dan fallback ke stok mahal, filtering sumber tidak tersedia/qty0, harga tetap, serta kombinasi Digiflazz/OtomaX untuk basis harga dan sumber inquiry yang berbeda. Seluruh pengujian memakai database terisolasi/fake HTTP, tanpa transaksi pemasok atau pemotongan saldo nyata. Tidak ada migrasi/dependency/database API Hanshop berubah.


### 2026-10-29 — Dasar markup fleksibel termurah/termahal per produk

pricing_basis disimpan pada catalog_products dengan default highest, mempertahankan perilaku semua produk lama. Owner/staff berizin pricing.manage dapat memilihnya pada modal tab Harga; form produk lengkap dan endpoint khusus harga memvalidasi cheapest/highest. Staff tanpa izin harga tidak dapat mengubah basis melalui kedua jalur. Data basis hanya masuk DTO panel privat. Harga tetap tidak berubah; inisialisasi produk tanpa harga eksplisit mengikuti basis pilihan. Tidak mengubah aturan prioritas markup atau urutan pemrosesan termurah.

StoreCatalog menggunakan min/max stok ready sesuai basis tanpa mengubah urutan pengiriman stok. Pascabayar menerapkan pilihan yang sama pada harga katalog dan hasil inquiry Digiflazz/OtomaX; basis disimpan pada pricing snapshot pending OtomaX. Quote siap dan pesanan lama tetap terkunci. Semua alur memakai sumber eligible yang sama seperti sebelumnya; tidak menambahkan failover pembayaran pascabayar.

Validasi:176 tes backend dijalankan,175 lulus/1746 assertions,1 skip existing;32 tes frontend lulus; build TypeScript/Vite dan Pint lulus. Tes mencakup default highest, persistensi pilihan, input invalid, isolasi owner/staff, inisialisasi produk baru, harga tetap, dua modal stok premium, serta perpindahan basis pada inquiry dua sumber pascabayar tanpa mengubah quote lama. Browser SQLite terisolasi membuktikan simpan cheapest, muat ulang, buka ulang pilihan tersimpan, serta layout desktop/390px. Tidak ada transaksi/OTP/pengiriman pemasok nyata.

Migrasi MySQL lokal diterapkan setelah backup storage/app/private/backups/before-otomax-20261029-064339.sql. Migrasi terpisah juga diterapkan pada database UI simulasi setelah salinan backup. Tidak mengubah database API Hanshop atau dependency proyek.


### 2026-10-29 — Pemindahan basis markup ke aturan kategori/layanan

Atas permintaan owner, pilihan termurah/termahal dihapus dari form produk dan dipindahkan ke modal Aturan harga. Tabel aturan menampilkan Dasar modal; kategori dapat mengikuti layanan, layanan memakai default highest jika tidak diatur. ProductPricing.resolve menyelesaikan basis kategori→layanan→highest secara independen dari prioritas markup produk→kategori→layanan. Produk dengan markup sendiri tetap memakai basis bersama. Inisialisasi harga produk, StoreCatalog (termasuk min/max stok), serta inquiry Digiflazz/OtomaX memakai basis terselesaikan. Snapshot pending tetap mempertahankan kebijakan saat cek dibuat; quote siap/order tidak dihitung ulang.

Legacy catalog_products.pricing_basis tidak lagi menjadi override, namun kolom/data tidak dihapus. Save/price produk menolak basis per produk. SaveRule memvalidasi nullable cheapest/highest dan reprice; hapus aturan mengembalikan pewarisan. API dan UI tetap mensyaratkan pricing.manage, isolasi antartoko dipertahankan. Tidak menambahkan failover pascabayar atau dependency baru.

Validasi:176 tes backend terkait dijalankan,175 lulus/1753 assertions,1 skip existing. Setelah penambahan pemeriksaan staff berizin, tes khusus aturan lulus1 tes/25 assertions.32 tes frontend dan build TypeScript/Vite lulus, Pint dijalankan. Tes mencakup dampak satu layanan ke beberapa kategori, override kategori/null inheritance, penghapusan aturan/default, penolakan input invalid, harga tetap, produk legacy basis cheapest yang tetap mengikuti aturan, produk baru, stok premium, dan dua jalur inquiry pascabayar. Browser SQLite terisolasi membuktikan simpan pilihan pada aturan, kolom tabel berubah, pilihan bertahan setelah reload, serta tidak ada dropdown basis pada modal produk. Tampilan modal diperiksa desktop/390px; tidak ada permintaan supplier nyata.

Migrasi MySQL lokal diterapkan setelah backup storage/app/private/backups/before-otomax-20261029-105236.sql; migrasi preview SQLite terpisah diterapkan setelah backup before-rule-basis-ui.sqlite. Database API Hanshop tidak diubah.


### 2026-10-29 — Failover pascabayar selesai

Checkpoint sebelumnya disimpan pada 84e2b51 (termasuk modul kesehatan, inquiry/biaya pascabayar, keterangan transaksi, dan basis markup di aturan kategori/layanan). DigitalToko.zip tidak diikutkan.

Inquiry baru menyimpan fallback_candidates privat; CustomerCommerce membuat semua attempt dengan modal estimasi, mapping, snapshot koneksi dan referensi masing-masing. Sumber utama menggunakan hasil cek yang dikonfirmasi, cadangan melewati PostpaidFailover: cek gratis pada mapping tertentu, tunggu bila pending, cocokkan pokok/admin/nama/periode, batasi modal pada harga terkunci dan hari WIB yang sama, verifikasi parsing/koneksi/credential masih sesuai. Endpoint publik tidak menerima mapping khusus. Cache cek cadangan dipisahkan per key/attempt dan hasilnya ditandai failover_order_id agar tidak bisa dipakai membuat order lain. Tidak ada invoice/debit tambahan saat failover.

Claim melanjutkan pemeriksaan tersimpan yang pending tanpa mengirim ulang cek; setelah pay terkirim aturan existing tetap menahan pending/uncertain. Gagal pasti langsung/callback memicu cadangan, kode harga berubah atau sumber di atas budget dilewati pada pascabayar tanpa mematikan calon lain. Semua habis memakai fail/refund idempoten existing. Late success Digiflazz dari attempt lama pada pascabayar menandai review seperti OtomaX agar potensi pembayaran ganda diperiksa. Rincian awal/hasil cek tetap terenkripsi; sumber/kredensial/biaya privat tidak masuk DTO pembeli.

Validasi awal lintas modul:187 tes dijalankan,186 lulus/1805 assertions,1 skip existing. Setelah pengikatan hasil cek internal,91 tes commerce/postpaid lulus/802 assertions; tes khusus anti-checkout ulang lulus1 tes/8 assertions. Cakupan baru: OtomaX→Digiflazz, Digiflazz→OtomaX dengan cek async, OtomaX→OtomaX dengan kredensial/kode sendiri, payment pending tidak failover, cek timeout tidak dikirim ulang dan habis masa berlaku, nama/pokok/admin/periode tidak cocok, budget terkunci, seluruh sumber gagal/refund satu kali, callback gagal melanjutkan dan late success review. Pint dijalankan; tidak ada migrasi/dependency atau perubahan API Hanshop. Tidak melakukan transaksi/OTP/transfer supplier nyata. Order lama tetap snapshot sumbernya dan tidak di-backfill.

Pemeriksaan akhir failover:187 tes dijalankan,186 lulus/1807 assertions,1 skip existing. Tidak ada perubahan frontend pada tahap ini; build frontend terakhir tetap berlaku.

### 2026-10-30 — Supplier Lab terisolasi dan dua toko uji

Tiga supplier OtomaX Express.js pada repositori terpisah `supplier-simulator` kini tersambung ke dua toko sandbox (lab-minimum/lab-maximum) pada web port 8012. Lima produk/toko, masing-masing tiga parsing, profil penangkap, katalog Webreport melalui pipeline sinkronisasi asli, pasangan CEK/BYR PLN/PDAM, aturan dasar termurah/termahal, staff dan pembeli sintetis disiapkan seeder khusus.

Adapter membutuhkan flag eksplisit, local/testing, SQLite khusus `storage/app/private/supplier-lab.sqlite` atau :memory: saat PHPUnit, slug lab sandbox dan kredensial SIM001–003. Hanya loopback port 4101–4103 dan katalog `/harga.js.php` dikecualikan. Runtime lab memblokir seluruh HTTP selain origin simulator/control lokal; mail array, queue sync, session/cache/lock dan env/key terpisah. Callback menyertakan URL per koneksi melalui ekstensi simulator cb_url, tidak mengubah protokol endpoint supplier nyata. Sandbox biasa tetap tidak mengirim HTTP OtomaX.

Queue sinkron lab melanjutkan parsing setelah lock callback dilepas, sehingga kegagalan pasti melalui callback tidak kehilangan pekerjaan cadangan. Penyesuaian modal OtomaX positif tanpa metadata billing kini memakai harga sumber bertanda dalam kandidat pascabayar. Biaya aktual respons sukses yang melebihi harga terkunci tetap review; tidak menjamin batas harga atomik pada API OtomaX nyata.

Verifikasi antarproses lokal: prabayar gateway simulasi sukses; PLN maksimum Rp103.200 gagal pada A/modal101500 lalu sukses B/modal101700; PDAM minimum Rp90.434 seluruh sumber gagal dan refund sandbox; pending→callback sukses; pending→callback gagal→inquiry cadangan→sukses melalui wallet/gateway simulasi. Browser: guest checkout, bayar simulasi, pembaruan otomatis sampai berhasil; login staff dan pembeli email/password. Panel/beranda diperiksa 320/390/1440 px tanpa overflow atau page error; callback HTTP200.

Suite terkait:237 tes,236 lulus/2197 assertions,1 skip existing (OTOMAX_SAMPLE_XLSX opsional).31 tes Node lulus. Tes gabungan Excel awal melewati memory_limit128MB; pengulangan dengan batas512MB khusus proses PHPUnit lulus, tanpa mengubah php.ini/server utama. PHPUnit menetapkan APP_URL localhost agar .env IP LAN tidak mengubah base host tes. Dua fixture Digiflazz sandbox lama dilengkapi selling_price/admin wajib sesuai kontrak inquiry sekarang; validasi tidak dilonggarkan. Pint dijalankan dan router/CLI PHP diperiksa sintaksnya.

Launcher setup membuat backup SQLite lab sebelum migrasi yang sudah ada; tidak menambah migrasi aplikasi, tidak mengubah database MySQL pengguna, kredensial utama, API Hanshop/bot Hanshop, dependensi aplikasi atau PM2 global. `npm run lab:setup` lalu `npm run lab` dijalankan dari simulator; tautan toko/staff tersedia pada panel4100. Profil PM2 terpisah tersedia tetapi belum dijalankan dalam verifikasi ini. Pelaksana penelitian massal, sampling/analisis statistik serta validasi produksi tetap di luar bukti ini.

## 30 Oktober 2026 — validasi hybrid pascabayar

Checkpoint prabayar tersimpan lebih dahulu: aplikasi d6683b5, simulator e5a59ae, penelitian 9a59a2e. Dua order pending lama selesai melalui pemeriksaan resmi dengan RefID sama dan callback asli, tanpa pembuatan order/debit baru.

Tahap pascabayar menambah fixture lab PLN/PDAM untuk Digiflazz Development nyata dan simulator OtomaX A/B/C. Guard mempertahankan isolasi SQLite/local, tujuan/SKU resmi, testing=true dan development signature; konteks scoped hanya mengizinkan inquiry, bukan pembayaran tanpa attempt. Periode Digiflazz kini dibaca dari desc.detail bila top-level kosong, baik saat quote maupun inquiry ulang sebelum pembayaran.

18 skenario langsung lulus, dengan 16 order dan 2 inquiry gagal tanpa order: sukses Digiflazz, failover A/B/C, refund sekali, callback asli PLN/PDAM, rincian tidak cocok tanpa pembayaran, batas modal invoice, dua periode Digiflazz, reverse failover OtomaX→Digiflazz, dan checkout guest lewat browser. Nol order lab berjalan pada pemeriksaan akhir. Harga pelanggan tetap terkunci; signed adjustment OtomaX dipertahankan. P09 minimum menolak modal C Rp11.200 terhadap invoice Rp11.000 dan refund Rp11.000 sekali.

Regresi sembilan file: 242 tes Laravel lulus, 1 skip opsional Excel, 2.225 assertion; 35 tes Node lulus. Bukti JUnit/JSON/Excel/screenshot disimpan terpisah dalam repositori penelitian outputs/uji-pascabayar-20261030/. Tidak ada migrasi atau dependensi baru. Pembayaran/saldo pembeli sandbox, tanpa dana produksi. Failover dua periode, deployment VPS, dan eksperimen statistik masih di luar bukti ini.

## 31 Oktober 2026 — checkpoint dokumentasi akhir

- Implementasi terakhir tersimpan pada aplikasi `19dead7` dan simulator `095af86`; arsip penelitian pada `62e2715`. Ketiganya adalah repositori Git terpisah. Folder penelitian/simulator tetap tidak ikut commit aplikasi utama.
- Poin 1 selesai: dua pesanan pending lama dituntaskan melalui pemeriksaan resmi dengan RefID sama dan callback asli; checkpoint prabayar tujuh skenario tersimpan. Tidak ada status paksa atau pembuatan debit baru untuk penyelesaiannya.
- Poin 2 selesai untuk cakupan hybrid pascabayar satu periode: 18 skenario langsung (16 order, dua inquiry gagal tanpa order), Digiflazz Development nyata + tiga simulator OtomaX, failover dua arah, callback, signed commission, kesesuaian tagihan, batas modal, refund sekali dan checkout browser. Dua periode terbukti pada jalur Digiflazz saja.
- Hasil pengujian terakhir 30 Oktober: 242 tes Laravel lulus, satu tes Excel opsional dilewati, 2.225 assertion, 35 tes Node lulus. Tidak menjalankan ulang transaksi/tes pada checkpoint dokumentasi ini.
- Bukti: repositori penelitian `outputs/uji-pascabayar-20261030/`, termasuk Excel lima sheet, JSON/JUnit, log tersanitasi, screenshot, collector read-only dan manifest SHA-256. Catatan lengkap berada di CATATAN-PEKERJAAN.md penelitian dan PROGRESS.md simulator.
- Pekerjaan berikutnya yang belum dikerjakan: failover tagihan dua periode, protokol eksperimen berulang beserta seed/sampel, analisis statistik minimum-maksimum, deployment VPS/domain dan validasi operasional produksi. Hasil fungsional tidak dianggap hasil akhir skripsi atau estimasi keberhasilan produksi.
- Arsip lokal `DigitalToko.zip` yang sudah ada tidak dimasukkan ke Git. Tidak ada perubahan database, kredensial, konfigurasi layanan atau dependency pada checkpoint ini.

## 1 November 2026 — failover tagihan beberapa periode

Pemeriksaan periode kini membandingkan himpunan bulan lengkap, menyetarakan YYYYMM, YYYY-MM, MM/YYYY dan nama bulan Indonesia/Inggris pada daftar koma/titik koma/pipa/baris. Urutan berbeda diterima; bulan hilang/tambahan/duplikat/tidak valid ditolak. Format rentang ambigu tidak ditebak. Parser OtomaX mempertahankan garis miring dalam periode dan berhenti pada field SN berikutnya. Snapshot harga pesanan tetap; aturan modal, nama, pokok dan admin tidak dilonggarkan.

60 tes Laravel PostpaidCommerce/SupplierSimulator lulus (366 assertion), termasuk failover dua periode dua arah Digiflazz/OtomaX, mismatch/refund sekali dan pending inquiry/callback duplikat tanpa pembayaran kedua. HTTP pemasok difake dan SQLite in-memory; bukan transaksi Digiflazz Development nyata pada tahap ini. 35 tes Node simulator lulus; jumlah bulan JBLN/JMLBLN pada fixture dua periode diperbaiki menjadi2. Tidak ada migrasi/dependency atau perubahan data utama.

## 1 November 2026 — QRIS HansDev gambar, aplikasi bebas dan konfirmasi manual

Ditambahkan pembacaan QRIS PNG/JPG/WebP≤5MB lokal di browser memakai jsqr (disetujui user), preview, status baca/error, pembatasan ukuran dan cleanup objectURL. Teks payload tidak lagi diminta pada panel; server tetap memvalidasi QRIS Indonesia/CRC. Pengaturan sumber menerima package Android lain atau nama aplikasi; callback memeriksa sumber yang dipilih dan format pembayaran masuk. Koneksi/token tetap hanya owner, invoice lama memakai snapshot. Token disamarkan dan dapat disalin.

Unduhan `/downloads/hansdev-forwarder` memakai APK lokal1.0.3 ARM64 (hash/path pada DEPLOYMENT.md), tidak membuild/memasang aplikasi ke HP merchant. APK tidak diikutkan Git; file release wajib disalin saat deploy. Panduan pembayaran-custom diperluas menjadi7langkah, dengan3screenshot fixture dan1SVG Android berlabel ILUSTRASI. Total pusat panduan96langkah/21topik. Tidak mengklaim ilustrasi sebagai screenshot HP.

Owner/staff berizin baru payments.confirm dapat mencatat penerimaan QRIS dari rincian transaksi; tautan manual juga tersedia pada pengaturan gateway. Nominal, waktu, referensi merchant, catatan dan pernyataan pemeriksaan wajib; invoice dibatalkan/Midtrans/wallet ditolak. Invoice aktif mengikuti alur paid normal; expired masuk review. Audit privat, pemakaian ulang referensi ditolak dan callback susulan tidak menduplikasi kredit/dispatch. Staff baru tidak otomatis mendapat izin; navigasi, login dan link notifikasi mengikuti izin tersebut.

Validasi:123tes Laravel CustomerCommerce/PostpaidCommerce/SupplierSimulator/Tutorial lulus,5832assertion;12tes PanelNotification lulus,104assertion;34tes frontend lulus; build TypeScript/Vite dan Pint lulus. Tes mencakup sumber lain/nama aplikasi, akses owner/staff lintas toko, nominal/waktu/ack salah, referensi dipakai ulang, expired/review, invoice batal, topup sekali dan callback menyusul. Decoder diuji memakai piksel QR nyata yang dihasilkan encoder fixture, serta gambar kosong/tipe/ukuran salah. Pembayaran/supplier tes memakai fake HTTP/SQLite, tanpa dana produksi.

Browser lokal port8010 pada SQLite panel-ui terpisah (backup sebelum perubahan): unggah QR sintetis berhasil dibaca dan disimpan untuk com.bank.merchant; popup APK/token tersamarkan tampil; konfirmasi pembayaran manual berhasil sampai Lunas/antre diproses dan audit aktor/referensi terlihat. Pemeriksaan mobile390px tanpa overflow; bounds modal320px tetap di dalam viewport. Input waktu diperbaiki setelah uji browser menemukan transform tanggal kosong; nilai waktu kini dibaca dari field form saat submit dan invalid diberi error, bukan exception. Uji menolak waktu sebelum invoice dan berhasil setelah waktunya sesuai. Tidak mengirim notifikasi atau uang nyata. Tidak ada migrasi schema, perubahan database utama, API Hanshop, source Android atau progres penelitian.

Sinyal `php artisan queue:restart` telah dikirim setelah validasi agar worker lokal memuat source baru secara graceful. Server fixture port8010 dihentikan setelah pemeriksaan; layanan utama tidak dihentikan.

## 1 November 2026 — paket ZIP dan panduan aktivasi listener USB

Atas permintaan user, unduhan HansDev diganti dari APK tunggal menjadi ZIP persis yang diberikan (APK + ZIP SDK platform-tools37.0.1 Windows/macOS/Linux), hash/penempatan tercatat di DEPLOYMENT.md. File41MB disalin ke penyimpanan release lokal, tidak ke Git; paket asli tidak diubah dan CRC arsip diperiksa. APK di dalamnya cocok SHA256 release1.0.3 sebelumnya. Tidak menjalankan ADB, menginstal APK, mengubah izin/USB debugging atau menyentuh perangkat merchant.

Panduan aktifkan-listener baru12langkah,4ilustrasiSVG berlabel, perintah untuk CMD/PowerShell/macOS/Linux dan tombol salin. Menjelaskan ekstraksi bertingkat, mode pengembang7ketukan/PINHP, debuggingUSB, RSA Allow, koneksi device vsunauthorized/offline, perintah allow_listener, pemeriksaan akses vsstatus terhubung, konfigurasi sumber/token, baterai, uji dan pencabutan kabel. Pemilih OS hanya mengganti teks perintah, bukan mengeksekusinya. Tautan dari popup pembayaran dan panduanQRIS; unduhan guide tersembunyi saat file belum disediakan. Pusat panduan kini22topik/108langkah.

Validasi:4tes Tutorial lulus/5371assertion,1tes downloadZIP lulus/5assertion,35tes frontend lulus dan buildTypeScript/Vite berhasil. Screenshot/ilustrasi divalidasi oleh tes. Browser fixture8010 membuktikan pemilih macOS/PowerShell, blok kode, status tombol salin dan tautan ZIP. Desktop serta390px diperiksa; scroll horizontal kode tidak menyebabkan overflow halaman. Tangkapan fixture bukan screenshotHP; tidak mengklaim suksesaktivasi diWindows/macOS/Linux atau OEM nyata. Scroll-margin langkah disesuaikan agar judul tidak tertutup navbar. Tanpa migrasi atau dependency baru.

## 1 November 2026 — cegah cache unduhan APK lama

User melaporkan tombol ZIP masih menghasilkan APK. Pemeriksaan GET langsung server aktif8000 membuktikan respons ZIP41.474.142bytes, SHA2567d90711be2b677ecb6cbdd4e225d9b47986749b9bb298a33618c130a1bf8f01f dan4entry sesuai arsip. Header lama Cache-Control:public dan URL sama dengan distribusi APK sebelumnya memungkinkan cache browser, tetapi cache pada browser pengguna tidak diperiksa sehingga penyebab individual belum terkonfirmasi.

Ditambahkan URL kanonis .zip dan header no-store/no-cache pada kedua endpoint. Semua tombol memakai route name yang baru; URL lama tetap kompatibel. Tes download kedua URL (15assertion) dan tutoriallink (27assertion) lulus; Pint lulus. GET nyata URL baru HTTP200, MIME/filename ZIP, private/no-store dan hash/isi sesuai. Tidak mengubah file asli/ZIP, layanan PM2, database atau dependency.


## 1 November 2026 — launcher listener portable per OS

Permintaan terbaru: script aktivasi ditempatkan di setiap platform-tools, tanpa path C tetap. Ditambahkan source CMD dan POSIX shell pada deploy; packager Python standar memasukkan CMD Windows, .command macOS, dan .sh Linux ke ZIP SDK masing-masing. Windows menggunakan %~dp0; POSIX menggunakan direktori script. Lokasi ekstraksi bebas dan tidak mengandalkan working directory. Script memeriksa satu perangkat, APK terpasang, hasil grant dan daftar izin, lalu membuka aplikasi. Unauthorized/offline/kosong memberi arahan dan retry interaktif; beberapa perangkat, grant gagal, APK tidak ada atau izin belum terbukti tidak dilaporkan berhasil. Tidak memasang APK, mengganti token/endpoint, atau memaksa pengiriman yang dijeda.

Paket rilis lokal sudah dibangun ulang (42.382.376bytes), SHA256 da469c6d6abaaafe8713fff3bee10e7fc8d4e1471ed32c3e9aea0676365b541c. Perbandingan seluruh anggota ZIP membuktikan APK dan berkas SDK/NOTICE asli tidak berubah; hanya satu launcher baru per ZIP OS. Arsip Music asli dan contoh Desktop tidak diubah. Mode0755/LF pada launcher POSIX; CMD CRLF. Bundel rilis ignored Git, source packager dan script dilacak Git.

Validasi:2tes Python lulus, mencakup24skenario launcher (8kondisi x3OS), ADB hilang pada ketiga launcher, preservasi file/mode/line-ending serta penolakan output menimpa arsip asal. CMD diuji di Windows menggunakan executable ADB tiruan; varian macOS/Linux memakai shell POSIX Git Bash Windows dengan ADB tiruan. Folder mengandung spasi dan tanda seru, working directory berbeda. Tidak menggunakan ADB nyata, tidak mengubah izin/perangkat HP; bukan klaim uji fisik macOS/Linux/OEM.

4tes LaravelTutorial/5371assertion dan1tes download/15assertion lulus;35tes frontend dan TypeScript/Vite build lulus;Pint lulus. Peringatan build hero nebula existing tetap ada dan tidak berkaitan. GET server utama8000/downloads/hansdev-forwarder.zip menghasilkan HTTP200 application/zip, attachment ZIP, no-store/no-cache, hash tepat sesuai rilis baru. Tutorial12langkah dan ilustrasi disesuaikan: launcher sebagai jalur utama, raw allow_listener sebagai alternatif, USBdebugging/RSA diHP tetap wajib, izin diberikan berbeda dari status listener terhubung. Tanpa migrasi, dependensi baru, restartPM2 atau perubahan penelitian.


## 1 November 2026 — posisi launcher diperbaiki sesuai permintaan

Script kini berada di akar masing-masing ZIP OS, di luar dan sejajar folder platform-tools. CMD mencari %~dp0platform-tools/adb.exe; POSIX mencari direktori-script/platform-tools/adb. Packager menghapus launcher versi sebelumnya di dalam platform-tools bila ada. Panduan dan ilustrasi diperbarui. Dua tes launcher/packager lulus dengan struktur baru, termasuk24skenario ADB tiruan dan folder/cwd berbeda. Bukan pengujian HP nyata. Paket dibangun ulang; SHA2565335e75823625053a1745902dcc3abfcda7f50ce96f3da5bd66841907fcdebf2.
