# Rancangan Hansbot

Status: aturan bisnis dan fondasi implementasi lokal. Modul bisnis belum lengkap; belum ada deployment/WhatsApp live.

Brand publik: DigitalToko. Domain yang dipilih: `digitaltoko.com`; pembelian dan konfigurasi belum diverifikasi. Warna yang dipilih: Indigo #4F46E5, Cyan #06B6D4, Navy #0F172A, dengan latar putih. Ringkasan keputusan dan hal yang belum ditetapkan ada di `docs/DECISIONS.md`.

Keputusan bisnis lanjutan untuk pembayaran, akun/saldo Hanshop bersama, fallback, template, staff, serta KTP ada di `docs/BUSINESS_RULES.md`. Dokumen ini menjadi acuan detail kebutuhan pengguna terbaru.

## Tujuan

Panel admin/owner/staff dan storefront memakai satu Laravel + Inertia React di apps/web. Worker Node/TypeScript + Baileys terpisah apps/bot. Deploy VPS/domain awal hansdigi.biz.id lalu digitaltoko.com; URL/config domain/alias dan mapping custom host tidak menanam domain produksi dalam kode.

## Peran

- Admin platform mengelola toko, akun owner, paket, masa aktif, suspend, provisioning, dan dukungan. Aksi bantuan pada data toko dicatat dalam audit.
- Owner mengelola toko yang menjadi hak aksesnya: koneksi WhatsApp, produk, harga, stok, pelanggan, transaksi, dan konfigurasi pembayaran.
- Pelanggan bertransaksi melalui WhatsApp toko tanpa memiliki akses admin panel.

## Paket awal

| Kode internal sementara | Nama publik | Digiflazz milik toko | Stok premium milik toko | Integrasi API Hanshop |
| --- | --- | --- | --- | --- |
| PPOB | Orbit | Ya | Tidak | Ya |
| PREMIUM | Space | Tidak | Ya | Ya |
| COMBO | Galaxy | Ya | Ya | Ya |

Nama publik Orbit, Space, dan Galaxy sudah dipilih pengguna. Kode di atas hanya penanda desain internal, bukan nama paket yang ditampilkan.

## Web toko untuk penyewa

Web toko publik berbeda dari panel tetapi memakai Laravel/Inertia React yang sama, di apps/web/resources/js/pages/Storefront. apps/storefront hanya catatan, bukan runtime kedua. Template awal Minimal/Promo/Kategori tersedia, katalog/checkout belum terhubung.

Pisahkan hak sumber (`own_digiflazz`, `own_premium_stock`, `hanshop_api`) dari hak kanal (`whatsapp_bot`, `web_storefront`) dan domain (`custom_domain`). Pengguna sudah menetapkan tiga kategori sewa: Web Topup saja, Bot WhatsApp saja, dan Paket Lengkap (Web Topup + Bot WhatsApp). Masing-masing kategori dapat memakai Orbit, Space, atau Galaxy, sehingga terdapat sembilan kombinasi. Semua kategori memiliki panel pengelolaan. Harga, durasi, limit, dan nama publik kategori selain deskripsi fungsinya belum ditetapkan.

Katalog, harga, saldo pelanggan, persediaan, dan pesanan menggunakan sumber kebenaran backend yang sama untuk web dan WhatsApp. Satu stok tidak boleh terjual dua kali lewat kanal berbeda. Identitas pelanggan web dan WhatsApp hanya digabung setelah kepemilikan nomor diverifikasi, bukan karena pengunjung memasukkan nomor yang sama.

Tema, logo, warna, banner, dan domain toko adalah konfigurasi tenant; bukan salinan aplikasi/deployment untuk setiap penyewa. Checkout web tidak memerlukan koneksi WhatsApp untuk diproses. Penyelesaian pesanan dan notifikasi menggunakan kanal yang tersedia.

Rancangan domain dan onboarding ada di `docs/DOMAINS.md`. Nama domain dalam contoh hanyalah placeholder; belum ada pembelian, konfigurasi DNS, hosting, atau penerbitan SSL.

Harga/durasi admin. Tiap toko satu nomor WA, lima staff lokal, satu subdomain tetap dan satu custom domain gratis. Jumlah toko owner tanpa batas; tiap toko sewa sendiri. Trial Bot WA Galaxy tujuh hari sekali per owner, langsung berhenti tanpa grace; retensi/hold tetap berlaku. Konversi paid sebelum habis langsung mulai tanpa tambah sisa trial. Nilai harga/durasi admin belum diisi.

Paket memiliki capability sumber sendiri `own_digiflazz` dan `own_premium_stock`, bukan build bot yang berbeda. Akses `hanshop_api` tersedia pada setiap paket aktif. Pembatasan sumber sendiri tidak membatasi jenis produk yang dapat ditarik dari Hanshop; katalog yang tersedia mengikuti akses partner Hanshop. Langganan menyimpan versi paket atau snapshot capability dan limit; perubahan definisi paket tidak diam-diam mengubah hak pelanggan lama.

Menu panel dan WhatsApp mengikuti capability. Backend juga wajib menolak pembuatan transaksi dan mutasi stok yang tidak diizinkan. Menyembunyikan menu saja tidak cukup. Pemeriksaan paket berlaku untuk command teks, wizard, request panel, dan dispatch job transaksi baru.

## Status yang dipisahkan

- Status toko: ACTIVE atau SUSPENDED.
- Status langganan/lifecycle toko: ACTIVE, GRACE, SUSPENDED_EXPIRED, DELETION_DUE, DELETION_HOLD, PURGING, atau DELETED, dengan waktu mulai/berakhir dan alasan; CANCELLED/manual suspend tetap dibedakan.
- Status koneksi WhatsApp: DISCONNECTED, CONNECTING, PAIRING, atau CONNECTED.
- Status pesanan dan status pengiriman pesan disimpan terpisah.

Koneksi WhatsApp terhubung tidak berarti toko berhak membuat transaksi. Backend membaca status toko, tanggal masa aktif, dan capability saat menerima pesanan baru; tidak hanya mengandalkan scheduler atau state worker.

Semua tanggal subscription dan lifecycle berscope `store_id`. Satu owner dapat memiliki toko dengan tanggal expiry berbeda; grace, suspend, dan penghapusan hanya diterapkan pada toko yang bersangkutan. Pembayaran perpanjangan harus menunjuk toko tertentu, bukan mengaktifkan seluruh toko owner.

Aktivasi pertama setelah provisioning dan konfirmasi owner. Renewal aktif dari expiry; expired dari pembayaran sukses. Upgrade penuh memulai ulang durasi; downgrade periode berikutnya. Grace tujuh hari bertransaksi lalu web/bot diblokir. Hari ke-30 saldo pelanggan/transaksi/refund belum selesai menahan purge (DELETION_HOLD), bukan dipaksa gagal. Pelanggan bersaldo mendapat notifikasi WA/email. Rancangan renewal sebelum purge memulihkan data/paket/lisensi existing, membatalkan reminder/purge lama, tanpa duplikasi pesanan/refund. Tanpa kewajiban/renewal, data toko dipurge; akun/saldo/riwayat owner Hanshop dipertahankan. Setelah purge mulai ulang termasuk lisensi baru.

## Pemisahan data

Data tenant memakai `store_id`: produk toko, stok, pelanggan, pesanan, pembayaran, saldo/ledger, konfigurasi, sesi, dan event pengiriman. Backend menentukan toko dari sesi pengguna atau credential worker yang terautentikasi, lalu memeriksa keanggotaan; ID toko dari request tidak dipercaya sebagai otorisasi.

Query, cache, file, event realtime, background job, dan constraint database harus mengikuti scope toko. Nomor pelanggan yang sama pada dua toko menjadi dua hubungan pelanggan yang terpisah. Pengiriman hasil transaksi selalu menggunakan toko dan bot asal pesanan.

Stok premium milik toko diambil secara atomik dan tidak boleh diklaim oleh dua pesanan. Data akun disimpan dengan perlindungan secret, tidak masuk log, dan hanya ditampilkan/dikirim kepada pihak yang berwenang.

## Integrasi transaksi

API Hanshop menjadi sumber produk yang dapat dihubungkan oleh penyewa selama paketnya aktif. Hansbot menyimpan katalog pilihan toko, harga jual, dan pesanan lokal. Identitas toko dipetakan ke `store_id` dan akun Hanshop owner. Saldo akun Hanshop yang sama digunakan bot Hanshop dan transaksi sumber Hanshop toko; tidak dibuat saldo pemasok baru yang independen. Partner per toko, bila digunakan, ditautkan ke akun yang sama untuk scope/audit. Secret disimpan di backend, bukan browser atau credential internal bot pribadi.

## Akses pemasok Hanshop dari paket

Alur panel: owner membuka Integrasi Hanshop, menghubungkan identitas partner tokonya, menarik katalog yang diizinkan, memilih produk untuk dijual, lalu menentukan markup atau harga jual. Jika onboarding partner nantinya dikelola otomatis, owner cukup mengaktifkan integrasi tanpa menyalin secret ke browser.

Menarik katalog hanya menyimpan mapping SKU pemasok dan konfigurasi produk toko. Tindakan ini tidak membeli produk, memotong saldo, atau memindahkan stok Hanshop ke stok lokal. Ketersediaan dan harga pembelian diperiksa kembali pada checkout; perubahan harga yang memengaruhi persetujuan pelanggan memerlukan konfirmasi ulang.

Sumber produk disimpan terpisah dari jenis produk: PPOB atau PREMIUM adalah jenis, sedangkan OWN_DIGIFLAZZ, HANSHOP, atau LOCAL_STOCK adalah sumber. Produk dari Hanshop dipenuhi melalui pembelian API ketika ada pesanan; produk premium lokal memakai stok toko sendiri. Jangan menggandakan credential stok pemasok ketika sinkronisasi katalog.

Ketentuan yang disepakati: ada dua jalur sumber. Jalur milik toko mengikuti paket: Digiflazz sendiri saja, stok premium sendiri saja, atau keduanya. Jalur API Hanshop dapat digunakan ketika salah satu paket aktif, termasuk untuk produk berbeda jenis dari sumber sendiri yang diizinkan paket. Contoh: toko dengan paket Digiflazz sendiri tetap dapat menjual premium dari Hanshop, tetapi tidak dapat mengelola stok premium lokal; toko dengan paket stok sendiri dapat menjual PPOB Hanshop, tetapi tidak dapat menghubungkan akun Digiflazz sendiri.

Enforcement harus berlaku pada kedua server: Hansbot memeriksa langganan/capability pada impor dan pembelian baru, sedangkan API Hanshop memeriksa identitas partner, kategori yang diizinkan, serta entitlement sewa yang masih berlaku. Menonaktifkan tombol panel saja tidak mencegah pemakaian credential secara langsung. Implementasi enforcement lintas aplikasi belum tersedia dalam struktur awal ini.

Saat paket habis, hentikan impor katalog dan pembelian baru melalui akses sewa. Pembacaan status pesanan lama, rekonsiliasi, dan delivery hasil tetap tersedia. Bila partner juga memiliki kontrak API tersendiri di luar sewa Hansbot, hak tersebut harus dimodelkan terpisah agar expiry sewa tidak mencabut kontrak lain secara keliru.

Partner API bukan pengganti backend multi-toko. Stok premium penyewa dikelola Hansbot; stok Hanshop tidak dianggap stok milik penyewa. Bot Hanshop pribadi tetap memiliki jalur internal yang terpisah.

Pembayaran pembeli memakai satu gateway aktif (Midtrans merchant toko atau custom QRIS), dengan fee dapat disetting oleh role berpermission. Pembayaran platform memakai API Hanshop + HansDev Forwarder. Owner login OTP nomor Hanshop dan melengkapi data diri/email; email juga disimpan Hanshop. KTP manual wajib disetujui sebelum pilih/bayar paket, berlaku semua toko owner. Staff hanya lokal toko. Checkout guest/wajib login diatur toko. Web memiliki tiga template, branding/section terbatas, order/profil seragam, serta Powered by DigitalToko wajib. Parsing memilih modal termurah, fallback aman, habis kandidat gagal; pending menunggu supplier aktif dan memberi cek status. Policy modal naik dikelola per produk/kategori/layanan.

Keputusan terbaru: pendaftaran langsung sekaligus akun Hanshop; pembeli WA OTP/email-password; saldo pelanggan dapat topup dan pencairan hanya saat toko berhenti. Fee Midtrans sesuai provider/merchant, custom QRIS kode unik dengan policy refund produk/termasuk kode unik. Kenaikan modal saat request di atas jual membuat pesanan gagal, auto-update katalog opsional; satu pending reminder dan notif saat status berubah. Toko siap auto-aktif setelah 24 jam bila owner belum mengaktifkan. KTP rejected file hapus 72 jam, approved disimpan selama toko masih ada; pembersihan owner setelah semua toko dihapus dengan akun/ledger Hanshop tetap dipertahankan. Admin menangani kewajiban setelah 30 hari dengan kontak owner, bukan membuka KTP pelanggan.

Markup nominal/persen/kombinasi dipilih satu aturan produk > kategori > layanan. Refund Midtrans produk saja tanpa fee. Lisensi custom nonrefund/nontransfer. Manual tutup menolak order baru dan menyelesaikan saldo/pending sebelum final. Readiness bot harus WA terhubung; web domain ditentukan dan subdomain/provisioning berfungsi, custom hostname tetap verifikasi DNS/TLS. Admin memperbaiki gagal provisioning terlebih dahulu.

Keputusan penutup aturan bisnis: refund sewa jika perbaikan provisioning tetap gagal sebelum layanan diberikan; source saldo kurang dilewati, modal sama jual boleh margin nol; pending sekali setelah lima menit; profil owner wajib nama KTP, WA/email verified, alamat. Detail teknis rutin didelegasikan pengembang.

Tagihan sewa, saldo pembelian toko dari pemasok, saldo pelanggan pada toko, dan pembayaran pesanan adalah domain berbeda. Harga pembelian dan harga jual disimpan sebagai snapshot pada pesanan. Request dan callback berulang diproses idempotent; timeout pemasok tidak otomatis berarti transaksi gagal atau aman diulang sebagai transaksi baru.

Owner memakai nomor HP/ID user/email Hanshop dengan OTP WA/email; staff lokal username/email + password. Pembeli wajib WA, email opsional. Guest gagal diselesaikan toko melalui ulang/refund manual; pesanan login terminal gagal dibayar direfund ke saldo per toko secara idempotent. Saldo/kewajiban pelanggan belum selesai menahan purge; penyelesaian uang tunai oleh toko dicatat.

## Koneksi melalui panel

Owner login ke web, membuka koneksi WhatsApp, dan meminta QR/pairing code. Backend memeriksa hak toko, kemudian memerintahkan worker. Event QR/kode/status hanya dipublikasikan ke sesi panel toko yang berwenang. Kode memiliki masa berlaku dan tidak dipersistenkan dalam log.

Worker berjalan di server meskipun browser ditutup. Credential auth WhatsApp tetap di sisi server dengan penyimpanan durable. Tahap awal memakai satu proses per toko untuk menyesuaikan bot lama yang masih memakai state socket global. Provisioning harus mencegah dua proses menggunakan sesi yang sama.

## Tahapan implementasi

1. Pilih stack web dan model pemasok/pembayaran. Tetapkan kontrak web-backend-worker.
2. Implementasikan login, keanggotaan toko, paket/langganan, audit, dan pemeriksaan scope tenant.
3. Adaptasikan satu worker per toko: konfigurasi, isolasi sesi/cache, pairing panel, dan lifecycle.
4. Implementasikan katalog dan stok premium, pesanan, claim atomik, serta pengiriman hasil.
5. Implementasikan adapter Digiflazz milik toko dan API Hanshop, saldo pembelian, callback, idempotensi, dan rekonsiliasi.
6. Implementasikan penagihan sewa, perubahan paket, monitoring, backup, dan pemulihan.

Validasi wajib sebelum layanan dipakai: owner A tidak dapat membaca/mengubah toko B; sumber sendiri yang tidak termasuk paket ditolak; setiap paket aktif dapat mengakses Hanshop tanpa pembatasan jenis produk dari paket sumber sendiri; expiry/downgrade tidak menghilangkan penyelesaian pesanan lama; worker restart tidak menggandakan transaksi/pengiriman tanpa kendali; pembelian premium bersamaan tidak memakai stok yang sama.

## Modul sumber dan katalog yang tersedia — 14 Oktober 2026

Laravel menyimpan supplier_connections terenkripsi dan supplier_products sebagai snapshot per toko/mode/SKU. Cache Redis 120 detik (60–180) memakai HMAC fingerprint akun pemasok, shared lintas toko dengan akun sama, dengan lock dan cooldown kegagalan. Sync memvalidasi payload utuh sebelum upsert transactional; pembaca storefront/bot tidak melakukan I/O provider. Scheduler setiap dua menit memperbarui mode yang pernah disinkronkan. Snapshot remote >600 detik tidak eligible.

CatalogProduct memiliki beberapa ProductMapping jenis sama. StoreCatalog menghitung kandidat aktif/eligible berdasarkan paket dan sumber, mengurutkan modal termurah, kemudian menerapkan ProductPricing. Markup nominal/persen/kombinasi serta auto_update diwariskan secara independen: produk → kategori → layanan; nilai 0 eksplisit bukan inherit. Harga jual di bawah modal membuat produk tidak tersedia. Fallback transaksi akan memakai kandidat ini pada milestone pemesanan; saat ini belum ada dispatch.

PremiumStock menyimpan payload terenkripsi, terpisah per toko; daftar hanya metadata, reveal memerlukan stock.credentials dan respons no-store. Stok sent immutable. Public projection yang sama digunakan web/bot, tidak memuat modal, credential, parsing privat, atau payload akun. Koneksi pemasok owner-only; products.manage, pricing.manage, stock.manage dan stock.credentials terpisah. Saldo Hanshop global tidak tersedia untuk staff.

Integrasi Hanshop memakai endpoint internal read-only pada bridge tepercaya existing: owner id tertaut, store UUID, access_until. Kredensial layanan tidak diberikan ke tenant. Pembacaan harga mengikuti tier akun existing dan tidak memanggil provider/mengubah saldo. Endpoint transaksi Partner API tetap memakai kontrak HMAC/scope/IP tersendiri dan tidak dipakai tahap katalog.
