# Worker WhatsApp DigitalToko

Status source: **21 Oktober 2026**. Bagian pengujian bertanggal adalah riwayat, bukan hasil uji live terbaru. Katalog, saldo, topup, checkout, status dan notifikasi sudah terhubung ke Laravel. Trial baru sudah dihapus.

Worker TypeScript + Express + Baileys 7.0.0-rc14. Helper persistence/version dan patch Baileys diadaptasi dari bot_wa_v2; project bot lama tidak diubah.

Tahap awal memakai satu proses worker per toko dengan kode dasar yang sama. Tiap worker memiliki identitas toko, credential internal dengan scope toko, sesi WhatsApp, konfigurasi, dan namespace cache yang terpisah.

Worker menerjemahkan pesan menjadi permintaan ke backend bisnis dan mengirim respons. Worker tidak menjadi sumber kebenaran harga, saldo, stok, paket, atau status transaksi.

## Menjalankan

Node 22.12+ dan Git diperlukan. `npm ci` menerapkan patch dengan git apply. Untuk panel tenant, salin `.env.example` ke `.env`, isi `BOT_MANAGER_TOKEN` minimal 32 karakter acak yang sama dengan `apps/web/.env`, serta `WEB_BASE_URL` ke URL Laravel yang dapat dijangkau VPS. Gunakan `http://127.0.0.1:8000` untuk development, atau URL platform/jaringan privat saat deployment. Token manager berbeda dari token Hanshop dan token worker per toko.

Jalankan `npm run manager:dev` untuk build dan menjalankan manager saat development. Produksi: `npm run build`, lalu `npm run manager` atau PM2 menggunakan `deploy/ecosystem.config.cjs`. Hanya satu manager per VPS. Laravel menghubungi `BOT_MANAGER_URL=http://127.0.0.1:3099`; port ini tidak perlu dibuka ke internet. Owner membuka menu **Koneksi WhatsApp**, memilih QR/kode dan nomor, kemudian menautkan perangkat di HP. Staff membutuhkan `bot.manage`; `bot.view` hanya melihat status tanpa QR/kode.

Manager membuat worker, token, port loopback sementara, dan folder `sessions/<uuid-store>` yang terpisah. `sessions/.manager.json` menyimpan registry/token privat (mode 0600 di Unix); jangan publikasikan folder sesi atau memasukkannya ke Git. Manager memulihkan worker saat restart, menghentikan anak proses ketika manager ditutup, dan membatasi restart setelah kegagalan berulang. `MANAGER_MAX_WORKERS` adalah kapasitas operasional VPS, default 100, dan dapat disesuaikan.

Mode worker manual tetap tersedia: isi STORE_ID dan WORKER_TOKEN melalui `php artisan digitaltoko:worker-token <uuid-store>`, lalu `npm start`. Jangan merotasi token manual pada toko yang sedang dikelola manager; lakukan hubungkan ulang melalui panel. `npm test` menguji pairing/manager dengan transport lokal, tanpa menautkan akun WhatsApp nyata.

WA_ENABLED=false tetap menjadi default mode manual. Manager mengaktifkan WhatsApp hanya untuk worker toko yang diminta dari panel. Worker memeriksa akses langganan sebelum membuka socket: toko provisioning non-trial, aktif, atau masa tenggang berbayar; draft, trial yang belum dimulai dan trial kedaluwarsa tidak diizinkan. Paket Web boleh koneksi OTP/notifikasi, tetapi command transaksi chat tetap ditolak. QR dibuat sebagai PNG lokal dengan `qrcode`; kode pairing diminta melalui Baileys setelah event QR dari server. Event connecting awal belum menjamin socket siap. Metode kode memakai `Browsers.macOS("Chrome")` seperti bot_wa_v2 agar companion_platform_id adalah CHROME (1); QR tetap memakai identitas `Google Chrome` yang sudah berjalan. Startup membersihkan metadata me/pairingCode hanya pada permintaan pairing yang belum mempunyai identitas akun bertanda tangan. Identitas QR yang sudah diterima tetap dipertahankan meskipun registered masih false. Timeout permintaan kode 60 detik mengikuti default query Baileys. Nomor tertaut harus sesuai nomor yang dimasukkan. Pairing memiliki batas waktu, event menggunakan ID percobaan, dan token lama ditolak setelah hubungkan ulang. Lock sesi mencegah dua proses menggunakan auth sama; lock dengan PID yang sudah mati dapat dilepas otomatis. PID yang masih hidup tidak ditimpa.

## Batas implementasi

Menu #menu/#start menyediakan teks dan quick reply untuk perangkat mobile yang dikenali; desktop/unknown memakai teks. Tombol menggunakan token terikat chat/sender, expiry 15 menit, dan konsumsi satu kali. Pengujian perangkat yang pernah dilaporkan dibatasi oleh catatan bertanggal; relay berhasil tidak membuktikan tombol tampil pada semua klien.

Koneksi QR/kode, hubungkan ulang, dan lepas koneksi sudah tersedia di panel. Mengubah nomor/metode melepas sesi sebelumnya; menghubungkan ulang dengan pilihan yang sama mencoba memakai sesi yang tersimpan. Jika logout remote gagal karena jaringan, sesi lokal tetap dilepas dan perangkat harus dihapus juga melalui HP. Katalog #produk, saldo #saldo, topup #topup, pembelian #beli/#pesan dan status #status sudah tersedia. Handler katalog sendiri tetap tidak membuat transaksi pemasok. Pada 14 Oktober 2026, pengguna mengonfirmasi pairing kode berhasil terhubung dan pengujian pesan/balasan dasar berjalan normal. Tampilan tombol native pada berbagai perangkat masih memerlukan pemeriksaan tersendiri.

Fondasi auth sesi masih file per toko dengan writer atomic untuk creds; penyimpanan key/auth production, pemulihan crash, dan operasi multi-host memerlukan milestone hardening sebelum dipakai penyewa.

## Katalog nyata

`#produk` menampilkan sepuluh produk per halaman; `#produk canva` mencari nama/kode/kategori/layanan; `#produk 2` atau `#produk 2 canva` membuka halaman berikutnya. Tombol sebelumnya/berikutnya memakai token terikat chat dan pengirim, dengan fallback perintah teks. Worker membaca `/api/internal/bot/products` menggunakan Bearer token toko existing; harga, kategori dan ketersediaan dihitung Laravel. Credential pemasok, modal dan payload stok tidak dikirim ke WhatsApp.

Setelah perubahan source: `npm run build`, lalu restart manager melalui pengelola proses Anda. Registry/sesi tidak dihapus. Pada PM2 gunakan restart proses manager yang sudah ada; jangan menjalankan instance kedua. Pengujian katalog menggunakan transport/mock lokal, sehingga tampilan pesan/tombol pada WhatsApp nyata tetap perlu dicoba setelah restart.

## Pemulihan setelah scan QR (15 Oktober 2026)

Baileys rc14 mengirim me/account pada pair-success sebelum registered berubah. Worker mengenali identitas bertanda tangan tersebut, menutup tampilan QR saat isNewLogin, dan menunggu antrean simpan credential selesai sebelum membuka socket berikutnya. RestartRequired (515) memakai jeda 1 detik; gangguan biasa 3 detik; penolakan 405 tetap memakai backoff. Kegagalan penyimpanan menghentikan reconnect dengan CREDENTIAL_FAILED. Log penutupan hanya memuat status code, indikator identitas, dan jeda; payload sesi/QR tidak dicetak.

`npm test`: 21 tes lulus. Dua tes worker memakai transport lokal yang menggantikan WhatsApp dan version lookup, dengan direktori sesi sementara: QR → pair-success → penulisan lambat → restart → CONNECTED; serta penyimpanan gagal tanpa reconnect. Ini tidak menyatakan scan HP nyata sudah diverifikasi. Setelah build, worker baru yang dimulai manager memakai hasil terbaru; worker existing perlu dimuat ulang.

## Format pesan dan respons tombol — 15 Oktober 2026

Menu/katalog/info saldo/status memakai heading nama toko dan pemisah bergaya bot Hanshop. Respons saldo/status memakai data commerce backend; jangan mengembalikan nominal palsu ketika layanan gagal. Command #menu/#start/#help/#bantuan/#format membuka menu; #produk tetap utama, #list/#stok menjadi alias katalog. Pencarian tetap terbawa saat paging teks maupun tombol.

readCustomerMessage menormalisasi wrapper Baileys (ephemeral/view-once), membaca native id/selected_row_id serta balasan legacy button/template/list. Token tetap acak, sekali pakai, 15 menit, dan terikat penerima. Alternate PN/LID hanya berasal dari key yang diberikan Baileys; isi pesan tidak dapat memasok alias/command. Klik invalid/expired/terpakai mendapat petunjuk dan tombol membuka menu baru. Format plain text dikirim lebih dahulu; desktop/unknown tetap bisa mengetik command ketika kontrol native tidak dirender.

Build dan 28 tes bot lulus, termasuk payload quick_reply Baileys asli yang diambil dari menu simulasi lalu diklik sebagai pesan terbungkus dengan PN/LID berbeda, handler yang sama dengan #saldo, isolasi token, legacy selection, expiry/restart, fallback desktop, dan katalog error. Test transport memakai mock lokal tanpa pesan WA nyata. Worker lokal dimuat ulang dari registry/sesi existing untuk memakai build ini; verifikasi klik pada aplikasi WhatsApp pengguna tetap diperlukan.

## Navigasi layanan → kategori utama → produk — 15 Oktober 2026

`#list` dan `#produk` tanpa parameter membuka layanan. `#list "Aplikasi Premium"` menampilkan kategori utama dalam layanan tersebut. `#stok "Canva" --layanan "Aplikasi Premium"` menampilkan produk kategori yang dipilih. Jika nama kategori hanya ada di satu layanan, `#stok Canva` cukup; jika ada di beberapa layanan, bot meminta pilihan layanan agar tidak mencampur produk. Nama mengikuti layanan/kategori katalog toko. Struktur CatalogService/CatalogCategory sudah dikelola panel; proyeksi publik hanya menampilkan katalog yang sesuai akses dan produk aktif.

Mobile menerima daftar pilihan single_select untuk layanan/kategori dan quick_reply sebelumnya/berikutnya/kembali. Daftar maksimal sepuluh item per halaman; fallback teks selalu memuat perintah yang dapat dipakai. Parameter `--halaman 2` mempertahankan konteks layanan/kategori. Pencarian global `#produk canva` dan paging `#produk 2 canva` tetap tersedia. Endpoint protected `/api/internal/bot/catalog` membaca data aktif milik store dari token worker, mengagregasi/paginate SQL, dan memakai proyeksi harga/ketersediaan yang sama dengan web. Nama dengan spasi didukung melalui tanda kutip.

33 tes bot lulus, termasuk generator single_select nyata → pilihan layanan → kategori → produk → paging → kembali dan isolasi token. Endpoint Laravel diuji untuk isolasi toko, kategori identik lintas layanan, pagination tiap tingkat, respons publik, validasi, dan penolakan akses saat ditangguhkan/kedaluwarsa/paket web saja. Pengujian transport tidak mengirim pesan WA nyata; coba `#menu` baru setelah build/worker dimuat ulang.


## Transaksi pelanggan (15 Oktober 2026)

Perintah `#beli KODE TUJUAN`, konfirmasi tombol Saldo/QRIS (atau `#pesan` sesuai format balasan), `#topup 50000`, `#saldo`, dan `#status [NOMOR]` tersambung ke commerce Laravel. Harga dibekukan saat konfirmasi dan diperiksa server. Nomor pelanggan berasal dari PN pada key pesan Baileys; LID tanpa PN tidak diterjemahkan menjadi nomor tebakan. ID pesan menjadi idempotency key. Tombol tetap terikat chat/penerima dan sekali pakai; perintah teks tetap tersedia.

Heartbeat worker mengambil outbox dan mengirim status/hasil ke penerima asli, lalu mengakui pengiriman. Gangguan sesudah WhatsApp menerima pesan sebelum ACK dapat menyebabkan pemberitahuan ulang, tetapi tidak membuat pesanan/debit/fulfillment baru. Produk premium tidak dikirim ke grup. Status pending dikirim sekali setelah lima menit melalui scheduler; hasil berubah tetap mengirim notifikasi. Receipt link bersifat privat.

Manager menyediakan POST `/qr` pada loopback dengan bearer manager existing untuk encoding QRIS lokal. Endpoint ini tidak membutuhkan sesi WhatsApp dan tidak mengganti endpoint pairing. Jangan membuka port manager/worker ke internet. Jalankan scheduler Laravel dan build/restart manager/worker existing agar handler commerce aktif tanpa menghapus sesi.


## OTP, voucher dan outbox saat ini

- Manager `/stores/:store/message` mengirim OTP/customer message langsung ke worker toko dan menunggu ACK. Jalur ini tidak melakukan HTTP balik worker ke Laravel untuk memeriksa akses pada request yang sama; ini menghindari deadlock local artisan serve satu proses. Token manager dan akses notifikasi worker tetap diperiksa.
- Semua paket termasuk Web boleh WA notifikasi. Worker memisahkan akses notifikasi dari capability transaksi bot; Web tidak boleh menjalankan command saldo/katalog/beli.
- CustomerDelivery memberi sinyal `/stores/:store/notifications` setelah data tersimpan. Worker mengambil outbox segera; pemeriksaan berkala tetap cadangan. Kegagalan sesudah send sebelum ACK dapat mengulang notifikasi, bukan debit/order.
- Voucher pembelian: `#beli KODE TUJUAN | KODEVOUCHER`. Tombol dan konfirmasi teks mempertahankan kode; Laravel memeriksa sasaran/kuota/harga. Voucher customer tidak membuat harga produk Rp0; voucher sewa/modul 100% berada di panel platform, bukan checkout produk bot.
- Jalankan tepat satu manager per registry/sesi; kesalahan port terpakai dan SESSION_IN_USE dibedakan. Jangan menghapus auth/session untuk sekadar memuat kode baru. Build dan restart melalui supervisor proses yang dipakai operator.
- Source auth masih file per toko; hardening durability/multi-host/purge belum lengkap. Status source ini tidak membuktikan pengiriman WhatsApp nyata di server tujuan.

Untuk laptop, profil PM2 root Hansbot sekarang juga mencakup manager development ini. `pm2 start` (sesudah launcher global dipasang) menjalankan build bot lalu manager beserta enam layanan lainnya. Perubahan kode apps/bot dimuat lewat `pm2 restart digitaltoko-bot-manager`; API/bot Hanshop terpisah memakai tsx watch. Lihat docs/DEPLOYMENT.md pada root Hansbot. Jangan menjalankan manager:dev manual bersamaan dengan manager PM2.

## 27 Oktober 2026 — Respons dan navigasi pelanggan

Pesan bot menggunakan format lebih ringkas tanpa bingkai di setiap baris: nama toko, judul dengan ikon, bagian informasi, dan petunjuk berikutnya. Menu, layanan/kategori, katalog, saldo, konfirmasi, serta bukti pesanan mempertahankan fallback perintah teks. Hasil produk hanya ditampilkan ketika sukses; rincian biaya/kode unik nol tidak memenuhi bukti pesanan.

Tombol saldo menyediakan petunjuk Isi saldo tanpa membuat tagihan. Riwayat menyediakan daftar pilihan pesanan; tombol Cek status pesanan membawa nomor pesanan spesifik, terikat chat/pengirim dan sekali pakai. Cek status hanya membaca melalui action status, tidak mengirim ulang produk atau membeli. Pembuatan topup tetap memerlukan nominal, dan pembelian tetap memerlukan konfirmasi Saldo/QRIS. Saldo minus ditampilkan dengan petunjuk pelunasan, bukan error umum.

Validasi: 48 tes bot lulus, termasuk alur tombol saldo → petunjuk topup tanpa invoice, pilih pesanan → refresh pesanan yang sama, replay token, saldo minus, dan kerahasiaan hasil sebelum sukses. Build TypeScript lulus. Pengujian memakai socket/API palsu dan transport fixture; tampilan native pada WhatsApp Android/iOS nyata belum diverifikasi. Tidak mengirim pesan nyata, mengubah saldo, atau merestart sesi pengguna. Muat hasil build melalui `pm2 restart digitaltoko-bot-manager` untuk profil laptop yang sudah berjalan; tidak perlu menghapus sesi atau pairing ulang. Notifikasi outbox yang diformat backend serta OTP tidak diubah pada tahap ini.

## 27 Oktober 2026 — Pilih produk sampai konfirmasi melalui chat

Daftar produk dari kategori maupun pencarian sekarang menyediakan single-select **Pilih produk & pesan**, hanya untuk produk tersedia pada halaman yang sedang dibaca. Pemilihan membaca ulang harga/ketersediaan melalui quote dan tidak membuat order. Premium memakai nomor WhatsApp pelanggan sesuai profil kategori; profil lain meminta balasan tujuan, dengan format `ID ; ZONA/SERVER` untuk dua kolom. Server mengembalikan profil dan menormalisasi tujuan mengikuti konfigurasi kategori/format pemasok (termasuk pipe). Tidak menambahkan lookup nickname eksternal pada tahap ini.

Konteks isian berlaku 5 menit, terikat chat/pengirim serta alias PN/LID yang berasal dari key Baileys, sekali dikonsumsi, dan dibatasi jumlahnya. `#batal`, menu/perintah lain, atau memilih tombol lain mengakhiri konteks isian. Balasan tanpa konteks tidak dianggap order. Isian gagal dapat dicoba ulang tanpa membuat pesanan. Setelah tujuan benar, bot menampilkan konfirmasi dan pilihan Saldo/QRIS yang sudah ada; hanya konfirmasi tersebut yang memanggil buy. Bukti pesanan tetap memiliki Cek status/Belanja lagi/Menu utama. Fallback teks tetap ada; tujuan yang mengandung pipe di-encode pada perintah konfirmasi teks agar pemisah tidak rusak.

Validasi: 53 tes bot dan 55 tes web terkait commerce/tujuan/katalog lulus (583 assertions web). Mencakup produk tersedia saja, harga terbaru, tujuan → konfirmasi → buy, premium tanpa isian tambahan, dua kolom/pipe, isian ulang, pembatalan, TTL dan isolasi pengirim; quote tidak membuat order/debit. Tidak mengirim pesan, transaksi atau lookup provider nyata. Tidak ada migrasi/dependency baru. Perlu reload worker manager existing untuk menggunakan build terbaru; pengujian visual daftar native pada WhatsApp perangkat nyata masih perlu dilakukan oleh operator.

## 27 Oktober 2026 — Tahapan checkout mengikuti pola Hanshop

Dari pemeriksaan source bot_wa_v2 (checkoutText/transaksiController), pemilihan metode dan konfirmasi pembelian adalah tahap berbeda. Bot DigitalToko kini mengikuti pola: pilih produk → tujuan (selain APK premium) → pilih Saldo/QRIS → konfirmasi akhir → order. Pilihan pembayaran tidak memanggil buy. Alternatif teks singkat: #pilihbayar saldo / #pilihbayar qris (direct juga diterima), lalu #konfirmasi. #batal/perintah lain mengakhiri alur. Perintah #pesan lama tetap diterima sebagai konfirmasi eksplisit untuk kompatibilitas, tetapi tidak lagi memenuhi pesan panduan.

APK premium bisa dipesan cukup #beli KODE, langsung ke pembayaran tanpa pertanyaan tujuan. Tujuan tambahan pada perintah premium ditolak dengan petunjuk yang benar. Nomor pengiriman berasal dari PN WhatsApp yang terautentikasi, dan API memaksanya kembali pada buy premium. Non-premium selalu meminta tujuan walaupun kategori keliru berprofil premium. Kategori game dua isian tetap memakai normalisasi yang ditentukan toko.

Checkout berumur 5 menit, terikat chat/pengirim dan ID alur, hanya boleh memilih metode sekali, lalu konfirmasi sekali. Memulai alur baru membatalkan alur lama. Token metode lain/tombol lama, pengirim lain, konfirmasi sebelum metode, dan konfirmasi berulang tidak menghasilkan pembelian baru. 57 tes bot serta 55 tes commerce/tujuan/katalog web lulus sebelum perubahan urutan katalog berikutnya; tidak ada WhatsApp/transaksi nyata atau restart worker pengguna.

## Urutan produk bot

Daftar kategori produk dan hasil pencarian dikelompokkan menurut awalan kode sebelum digit pertama (contoh G-A dan G-B), lalu harga jual termurah di dalam kelompok. Harga sama memakai angka awal secara numerik (A2 sebelum A10), lalu kode dan ID sebagai penentu urutan stabil. Promo flash sale aktif nominal/persen ikut diperhitungkan; aturan admin postpaid tidak diberi diskon flash sale. Pengurutan dilakukan di query database sebelum pagination, sehingga tidak memuat seluruh katalog ke memori worker. Tidak mengubah urutan katalog panel/storefront. Tes terbaru web: 56 lulus / 603 assertions, termasuk dua endpoint, halaman lanjutan, pencarian, harga promo dan isolasi toko. Tidak ada migrasi atau dependency baru.

## 27 Oktober 2026 — Pesan bot lebih ringkas

Nama toko pada formatter perintah menjadi uppercase. Menghapus bingkai/separator panjang, ikon berulang dan instruksi panjang. Menu/topup/saldo/tujuan/pembayaran/konfirmasi dipadatkan; informasi wajib seperti nominal, status, hasil sukses, refund serta label biaya admin tagihan tetap ada.

Isi katalog hanya konteks kategori/halaman dan nama, harga/kode, stok atau ketersediaan. Perintah halaman/kembali/menu serta petunjuk pesan dipindahkan ke body navigasi tersendiri di luar pesan daftar. Jika native navigation tidak dapat dikirim (termasuk desktop), worker mengirim fallback navigasi teks terpisah dengan scope kategori/pencarian/halaman yang sama. Pilihan produk, konfirmasi dua tahap, isolasi dan idempotensi tidak diubah.

58 tes bot penuh lulus; setelah perapian label admin, 27 tes katalog/navigasi/commerce lulus kembali. Build TypeScript berhasil. Manager PM2 dimuat ulang untuk menerapkan format baru, tanpa logout atau penghapusan sesi. Operator perlu membuka #menu baru. Pengujian otomatis memakai socket/API palsu, bukan pengiriman pesan nyata ke pelanggan.

## 27 Oktober 2026 — Penarikan pesan QRIS setelah pembayaran

- Worker mencatat key gambar QRIS yang berhasil dikirim (bukan pesan bukti teks atau pesan customer). Referensi order/phone/key ditulis atomik di qris-message-outbox.json pada direktori sesi privat, kemudian diregistrasikan ke web. Antrean lokal dicoba ulang ketika backend sementara gagal dan tetap tersedia setelah worker restart; dihapus dari antrean hanya setelah ACK berisi UUID registrasi yang sah.
- Web menyimpan key terenkripsi pada bot_qris_messages dengan identitas store/order dan hash deduplikasi. Beberapa gambar QR dari pengecekan ulang pesanan yang sama semuanya tercatat. Endpoint worker memeriksa bearer store, phone order, key milik bot, dan target PN/LID. Callback pembayaran tidak diberi kemampuan menghapus pesan langsung.
- Pengambilan outbox juga memuat daftar QR yang perlu ditarik berdasarkan payment_status paid dari database. Notifikasi baru bukan syarat cleanup; antrean kosong pun masih mengembalikan cleanup. Worker mengirim delete memakai key yang tersimpan, lalu ACK revoked hanya setelah send berhasil. Jika gagal, pesan pembayaran tetap dikirim dan QR dicoba lagi. Tidak menghapus bukti teks/hasil produk, tidak membatalkan pesanan, serta tidak mengubah saldo/status pembayaran. QR sebelum pembaruan ini tidak memiliki key tercatat dan tidak dapat ditarik secara retrospektif oleh fitur ini. Penghapusan tetap mengikuti kemampuan/kebijakan revoke WhatsApp.
- 62 tes bot penuh dan 69 tes web terkait QR/notifikasi/commerce/retensi lulus (663 assertions web). Empat tes QR diulang setelah validasi ACK ditambahkan. Mencakup kegagalan registrasi/restart, concurrency, key salah/foreign, hanya gambar QR, beberapa gambar per order, pembayaran mendahului registrasi, ACK berulang, ciphertext dan cascade retensi. Tes tidak mengirim pesan atau transaksi nyata.
- Migrasi lokal web 2026_10_27_082941_create_bot_qris_messages_table diterapkan sesudah full backup privat before-otomax-20261027-083229.sql. Tidak ada perubahan database API Hanshop atau dependency baru. Urutan deploy: backup web, migrate web, build bot, reload manager/worker. Jangan menghapus sesi saat memperbarui. Kegagalan disk/izin tulis dapat menghalangi pencatatan ID dan perlu diperbaiki operator; gambar/credential pembayaran tidak disimpan di antrean lokal.

### QRIS kedaluwarsa

Cleanup QR WA juga berlaku ketika order berstatus expired (termasuk pembatalan yang sudah disahkan), atau masih awaiting_payment/unpaid tetapi expires_at telah lewat. Tidak perlu menunggu scheduler mengubah label order untuk menyembunyikan QR yang sudah melewati batas bayar. Pemilihan cleanup dan ACK memakai scope readyToRevoke yang sama. Penghapusan gambar tidak mengubah status order, status pembayaran, atau saldo. Status pending/review yang belum lunas tidak dianggap kedaluwarsa hanya karena waktu lamanya lewat. Tidak ada migrasi/dependency/rebuild bot baru pada perluasan ini; server web memilih tugas cleanup dan worker existing mengeksekusinya. Validasi: 54 tes QR/notifikasi/commerce lulus (561 assertions), termasuk batas waktu, status expired, status aktif dan isolasi sebelumnya.


### 2026-10-27 — Pesan transaksi WhatsApp mengikuti pembaruan

Pencatatan pesan diperluas dari gambar QRIS ke bukti/status pesanan, notifikasi otomatis, serta tombol pesanan. Saat status pembayaran/pesanan berubah atau notifikasi koreksi hasil dibuat, key pesan versi lama masuk antrean penghapusan; versi terbaru dipertahankan. Notifikasi tertunda yang sudah digantikan pembaruan lebih baru di-ACK tanpa mengirim teks usang. Pesan pemilihan metode pembayaran ditarik ketika masuk konfirmasi; key tahap konfirmasi ikut pesanan setelah order berhasil dibuat. Pesan katalog, pesan pelanggan, dan pesanan lain tidak ikut dihapus.

Tabel/endpoint `bot_qris_messages` dan outbox privat `qris-message-outbox.json` tetap dipakai untuk kompatibilitas. Kolom baru menyimpan jenis pesan, versi notifikasi, serta snapshot status pesanan/pembayaran. Key tetap terenkripsi di database; antrean registrasi tetap tahan restart. Kegagalan revoke tidak menahan notifikasi terbaru dan dicoba lagi oleh pump. Tidak ada perubahan saldo, pembelian ulang, dependency baru, atau perubahan API Hanshop.

Migrasi lokal `2026_10_27_085410_add_order_context_to_bot_qris_messages_table` sudah diterapkan setelah backup penuh `before-otomax-20261027-085756.sql`. Pengujian menggunakan transport WhatsApp palsu dan database terisolasi, bukan penghapusan pesan atau pembayaran nyata. Pesan sebelum pencatatan key tersedia tidak dapat ditarik secara retrospektif; kemampuan revoke tetap mengikuti ketentuan WhatsApp.
