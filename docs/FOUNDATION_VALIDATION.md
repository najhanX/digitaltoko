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
