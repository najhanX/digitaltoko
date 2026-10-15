# Worker WhatsApp Hansbot

Worker TypeScript + Express + Baileys 7.0.0-rc14. Helper persistence/version dan patch Baileys diadaptasi dari bot_wa_v2; project bot lama tidak diubah.

Tahap awal memakai satu proses worker per toko dengan kode dasar yang sama. Tiap worker memiliki identitas toko, credential internal dengan scope toko, sesi WhatsApp, konfigurasi, dan namespace cache yang terpisah.

Worker menerjemahkan pesan menjadi permintaan ke backend bisnis dan mengirim respons. Worker tidak menjadi sumber kebenaran harga, saldo, stok, paket, atau status transaksi.

## Menjalankan

Node 22.12+ dan Git diperlukan. `npm ci` menerapkan patch dengan git apply. Untuk panel tenant, salin `.env.example` ke `.env`, isi `BOT_MANAGER_TOKEN` minimal 32 karakter acak yang sama dengan `apps/web/.env`, serta `WEB_BASE_URL` ke URL Laravel yang dapat dijangkau VPS. Gunakan `http://127.0.0.1:8000` untuk development, atau URL platform/jaringan privat saat deployment. Token manager berbeda dari token Hanshop dan token worker per toko.

Jalankan `npm run manager:dev` untuk build dan menjalankan manager saat development. Produksi: `npm run build`, lalu `npm run manager` atau PM2 menggunakan `deploy/ecosystem.config.cjs`. Hanya satu manager per VPS. Laravel menghubungi `BOT_MANAGER_URL=http://127.0.0.1:3099`; port ini tidak perlu dibuka ke internet. Owner membuka menu **Koneksi WhatsApp**, memilih QR/kode dan nomor, kemudian menautkan perangkat di HP. Staff membutuhkan `bot.manage`; `bot.view` hanya melihat status tanpa QR/kode.

Manager membuat worker, token, port loopback sementara, dan folder `sessions/<uuid-store>` yang terpisah. `sessions/.manager.json` menyimpan registry/token privat (mode 0600 di Unix); jangan publikasikan folder sesi atau memasukkannya ke Git. Manager memulihkan worker saat restart, menghentikan anak proses ketika manager ditutup, dan membatasi restart setelah kegagalan berulang. `MANAGER_MAX_WORKERS` adalah kapasitas operasional VPS, default 100, dan dapat disesuaikan.

Mode worker manual tetap tersedia: isi STORE_ID dan WORKER_TOKEN melalui `php artisan digitaltoko:worker-token <uuid-store>`, lalu `npm start`. Jangan merotasi token manual pada toko yang sedang dikelola manager; lakukan hubungkan ulang melalui panel. `npm test` menguji pairing/manager dengan transport lokal, tanpa menautkan akun WhatsApp nyata.

WA_ENABLED=false tetap menjadi default mode manual. Manager mengaktifkan WhatsApp hanya untuk worker toko yang diminta dari panel. Worker memeriksa akses langganan sebelum membuka socket: toko provisioning, aktif, atau masa tenggang berbayar; draft dan trial kedaluwarsa tidak diizinkan. QR dibuat sebagai PNG lokal dengan `qrcode`; kode pairing diminta melalui Baileys setelah event QR dari server. Event connecting awal belum menjamin socket siap. Metode kode memakai `Browsers.macOS("Chrome")` seperti bot_wa_v2 agar companion_platform_id adalah CHROME (1); QR tetap memakai identitas `Google Chrome` yang sudah berjalan. Startup membersihkan metadata me/pairingCode hanya pada permintaan pairing yang belum mempunyai identitas akun bertanda tangan. Identitas QR yang sudah diterima tetap dipertahankan meskipun registered masih false. Timeout permintaan kode 60 detik mengikuti default query Baileys. Nomor tertaut harus sesuai nomor yang dimasukkan. Pairing memiliki batas waktu, event menggunakan ID percobaan, dan token lama ditolak setelah hubungkan ulang. Lock sesi mencegah dua proses menggunakan auth sama; lock dengan PID yang sudah mati dapat dilepas otomatis. PID yang masih hidup tidak ditimpa.

## Batas implementasi

Menu #menu/#start menyediakan teks dan quick reply untuk perangkat mobile yang dikenali; desktop/unknown memakai teks. Tombol menggunakan token terikat chat/sender, expiry 15 menit, dan konsumsi satu kali. Perangkat nyata belum diuji; relay berhasil tidak membuktikan tombol tampil.

Koneksi QR/kode, hubungkan ulang, dan lepas koneksi sudah tersedia di panel. Mengubah nomor/metode melepas sesi sebelumnya; menghubungkan ulang dengan pilihan yang sama mencoba memakai sesi yang tersimpan. Jika logout remote gagal karena jaringan, sesi lokal tetap dilepas dan perangkat harus dihapus juga melalui HP. Katalog toko sudah tersedia melalui #produk; saldo pelanggan, checkout, dan status transaksi produk belum tersedia. Handler katalog tidak membuat transaksi pemasok. Pada 14 Oktober 2026, pengguna mengonfirmasi pairing kode berhasil terhubung dan pengujian pesan/balasan dasar berjalan normal. Tampilan tombol native pada berbagai perangkat masih memerlukan pemeriksaan tersendiri.

Fondasi auth sesi masih file per toko dengan writer atomic untuk creds; penyimpanan key/auth production, pemulihan crash, dan operasi multi-host memerlukan milestone hardening sebelum dipakai penyewa.

## Katalog nyata

`#produk` menampilkan sepuluh produk per halaman; `#produk canva` mencari nama/kode/kategori/layanan; `#produk 2` atau `#produk 2 canva` membuka halaman berikutnya. Tombol sebelumnya/berikutnya memakai token terikat chat dan pengirim, dengan fallback perintah teks. Worker membaca `/api/internal/bot/products` menggunakan Bearer token toko existing; harga, kategori dan ketersediaan dihitung Laravel. Credential pemasok, modal dan payload stok tidak dikirim ke WhatsApp.

Setelah perubahan source: `npm run build`, lalu restart manager melalui pengelola proses Anda. Registry/sesi tidak dihapus. Pada PM2 gunakan restart proses manager yang sudah ada; jangan menjalankan instance kedua. Pengujian katalog menggunakan transport/mock lokal, sehingga tampilan pesan/tombol pada WhatsApp nyata tetap perlu dicoba setelah restart.

## Pemulihan setelah scan QR (15 Oktober 2026)

Baileys rc14 mengirim me/account pada pair-success sebelum registered berubah. Worker mengenali identitas bertanda tangan tersebut, menutup tampilan QR saat isNewLogin, dan menunggu antrean simpan credential selesai sebelum membuka socket berikutnya. RestartRequired (515) memakai jeda 1 detik; gangguan biasa 3 detik; penolakan 405 tetap memakai backoff. Kegagalan penyimpanan menghentikan reconnect dengan CREDENTIAL_FAILED. Log penutupan hanya memuat status code, indikator identitas, dan jeda; payload sesi/QR tidak dicetak.

`npm test`: 21 tes lulus. Dua tes worker memakai transport lokal yang menggantikan WhatsApp dan version lookup, dengan direktori sesi sementara: QR → pair-success → penulisan lambat → restart → CONNECTED; serta penyimpanan gagal tanpa reconnect. Ini tidak menyatakan scan HP nyata sudah diverifikasi. Setelah build, worker baru yang dimulai manager memakai hasil terbaru; worker existing perlu dimuat ulang.

## Format pesan dan respons tombol — 15 Oktober 2026

Menu/katalog/info saldo/status memakai heading nama toko dan pemisah bergaya bot Hanshop. Respons saldo dan status dibedakan dan menyebut fitur belum aktif tanpa menampilkan nominal palsu. Command #menu/#start/#help/#bantuan/#format membuka menu; #produk tetap utama, #list/#stok menjadi alias katalog. Pencarian tetap terbawa saat paging teks maupun tombol.

readCustomerMessage menormalisasi wrapper Baileys (ephemeral/view-once), membaca native id/selected_row_id serta balasan legacy button/template/list. Token tetap acak, sekali pakai, 15 menit, dan terikat penerima. Alternate PN/LID hanya berasal dari key yang diberikan Baileys; isi pesan tidak dapat memasok alias/command. Klik invalid/expired/terpakai mendapat petunjuk dan tombol membuka menu baru. Format plain text dikirim lebih dahulu; desktop/unknown tetap bisa mengetik command ketika kontrol native tidak dirender.

Build dan 28 tes bot lulus, termasuk payload quick_reply Baileys asli yang diambil dari menu simulasi lalu diklik sebagai pesan terbungkus dengan PN/LID berbeda, handler yang sama dengan #saldo, isolasi token, legacy selection, expiry/restart, fallback desktop, dan katalog error. Test transport memakai mock lokal tanpa pesan WA nyata. Worker lokal dimuat ulang dari registry/sesi existing untuk memakai build ini; verifikasi klik pada aplikasi WhatsApp pengguna tetap diperlukan.

## Navigasi layanan → kategori utama → produk — 15 Oktober 2026

`#list` dan `#produk` tanpa parameter membuka layanan. `#list "Aplikasi Premium"` menampilkan kategori utama dalam layanan tersebut. `#stok "Canva" --layanan "Aplikasi Premium"` menampilkan produk kategori yang dipilih. Jika nama kategori hanya ada di satu layanan, `#stok Canva` cukup; jika ada di beberapa layanan, bot meminta pilihan layanan agar tidak mencampur produk. Nama mengikuti kolom service/category produk toko, tanpa membuat taksonomi/salinan produk lain.

Mobile menerima daftar pilihan single_select untuk layanan/kategori dan quick_reply sebelumnya/berikutnya/kembali. Daftar maksimal sepuluh item per halaman; fallback teks selalu memuat perintah yang dapat dipakai. Parameter `--halaman 2` mempertahankan konteks layanan/kategori. Pencarian global `#produk canva` dan paging `#produk 2 canva` tetap tersedia. Endpoint protected `/api/internal/bot/catalog` membaca data aktif milik store dari token worker, mengagregasi/paginate SQL, dan memakai proyeksi harga/ketersediaan yang sama dengan web. Nama dengan spasi didukung melalui tanda kutip.

33 tes bot lulus, termasuk generator single_select nyata → pilihan layanan → kategori → produk → paging → kembali dan isolasi token. Endpoint Laravel diuji untuk isolasi toko, kategori identik lintas layanan, pagination tiap tingkat, respons publik, validasi, dan penolakan akses saat ditangguhkan/kedaluwarsa/paket web saja. Pengujian transport tidak mengirim pesan WA nyata; coba `#menu` baru setelah build/worker dimuat ulang.


## Transaksi pelanggan (15 Oktober 2026)

Perintah `#beli KODE TUJUAN`, konfirmasi tombol Saldo/QRIS (atau `#pesan` sesuai format balasan), `#topup 50000`, `#saldo`, dan `#status [NOMOR]` tersambung ke commerce Laravel. Harga dibekukan saat konfirmasi dan diperiksa server. Nomor pelanggan berasal dari PN pada key pesan Baileys; LID tanpa PN tidak diterjemahkan menjadi nomor tebakan. ID pesan menjadi idempotency key. Tombol tetap terikat chat/penerima dan sekali pakai; perintah teks tetap tersedia.

Heartbeat worker mengambil outbox dan mengirim status/hasil ke penerima asli, lalu mengakui pengiriman. Gangguan sesudah WhatsApp menerima pesan sebelum ACK dapat menyebabkan pemberitahuan ulang, tetapi tidak membuat pesanan/debit/fulfillment baru. Produk premium tidak dikirim ke grup. Status pending dikirim sekali setelah lima menit melalui scheduler; hasil berubah tetap mengirim notifikasi. Receipt link bersifat privat.

Manager menyediakan POST `/qr` pada loopback dengan bearer manager existing untuk encoding QRIS lokal. Endpoint ini tidak membutuhkan sesi WhatsApp dan tidak mengganti endpoint pairing. Jangan membuka port manager/worker ke internet. Jalankan scheduler Laravel dan build/restart manager/worker existing agar handler commerce aktif tanpa menghapus sesi.
