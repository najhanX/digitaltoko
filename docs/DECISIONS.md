# Keputusan DigitalToko

Catatan per 13 Oktober 2026. Dokumen membedakan keputusan pengguna dari usulan desain; belum merupakan implementasi atau bukti layanan aktif.

## Sudah ditetapkan pengguna

- Brand DigitalToko, domain pilihan digitaltoko.com. Pembelian domain belum diverifikasi.
- Stack ditetapkan Laravel + Inertia React untuk seluruh panel/storefront, Baileys untuk bot dengan tombol/fallback. Deploy VPS memakai hansdigi.biz.id dahulu lalu digitaltoko.com setelah dibeli; domain configurable.
- Ikon minimal DT memakai stem kiri bersama, garis atas T memanjang ke kiri, dan aksen piksel kanan atas; bentuk v4 menjadi acuan pilihan warna.
- Warna nomor 1: Indigo #4F46E5, Cyan #06B6D4, Navy #0F172A, latar putih. Ekspor final berwarna/vector belum dibuat.
- Platform melayani banyak toko dengan panel owner dan panel admin platform untuk mengelola sewa/masa aktif.
- Web app dan bot WhatsApp dipisahkan dalam folder project. Web toko publik merupakan kebutuhan tambahan di luar panel pengelolaan.
- Tiga kategori sewa: Web Topup saja, Bot WhatsApp saja, Paket Lengkap.
- Tiga paket sumber: Orbit = Digiflazz sendiri; Space = stok premium sendiri; Galaxy = keduanya.
- Integrasi API Hanshop tersedia pada setiap paket aktif sebagai sumber tambahan, tanpa mengikuti pembatasan jenis produk sumber sendiri.
- Owner dapat menghubungkan WhatsApp dari panel dan mengelola produk tokonya.
- Pembayaran pembeli: hanya satu gateway aktif, Midtrans akun masing-masing toko atau custom QRIS sendiri. Pembebanan fee dapat diatur owner/staff berpermission. Modul custom dibeli sekali per toko; setelah toko dihapus dan dibuat ulang, lisensi harus dibeli baru.
- Akun owner menggunakan identitas akun Hanshop existing bila nomor terdaftar dan terverifikasi. Saldo Hanshop yang sama dipakai bot Hanshop dan pembelian toko; topup melalui panel tenant atau bot Hanshop.
- Admin mengatur harga/durasi. Tiap toko maksimal satu nomor WhatsApp dan lima staff lokal toko (bukan user Hanshop). Rancangan permission didelegasikan pengembang, lihat STAFF_PERMISSIONS.md.
- Satu subdomain tetap; rename berbayar mungkin fase berikut. Custom domain gratis maksimal satu per toko; slug dipakai lagi setelah purge.
- Payment platform memicu provisioning. Masa aktif pertama mulai setelah seluruh kewajiban siap dan owner menekan Aktifkan; jika belum ditekan, otomatis aktif 24 jam sejak siap.
- Owner memilih source/parsing, beberapa parsing per produk dengan modal termurah lebih dulu dan fallback setelah kegagalan yang aman.
- Stok mencontoh perilaku API Hanshop dan frontend admin-panel, dengan tambahan pemisahan tenant.
- Owner daftar langsung DigitalToko sekaligus dibuatkan akun Hanshop. Login nomor HP/ID/email + OTP WA/email. Data diri/email wajib, email disimpan Hanshop. Staff lokal username/email + password. Provider email belum dipilih.
- Checkout guest/wajib login diatur toko; WA wajib, email opsional. Guest gagal ditindaklanjuti toko (ulang atau refund manual); pembeli login gagal mendapat refund ke saldo akun pelanggan toko.
- Pembeli WA OTP atau email/password. Saldo per toko dapat ditopup; pencairan hanya saat toko berhenti. Midtrans fee sesuai merchant/provider; custom QRIS kode unik, refund saldo produk saja atau termasuk kode unik sesuai setting toko.
- Topup Midtrans mengkredit nominal topup tanpa fee. Topup QRIS custom dapat mengkredit nominal saja atau termasuk kode unik, diatur owner/staff berpermission dan disnapshot pada invoice. Policy topup dan refund produk terpisah.
- Refund produk Midtrans tanpa fee. Modul custom tidak dapat direfund/dipindah, tetap melekat toko pembeli.
- Trial Bot WhatsApp tujuh hari sekali per owner terverifikasi pada satu toko. Setelah habis langsung berhenti tanpa grace; data mengikuti retensi/penundaan purge. Hak trial tidak reset ketika toko dihapus.
- Trial memakai Galaxy. Konversi paid sebelum habis langsung memulai masa paid tanpa menambah sisa trial; timer trial lama dibatalkan.
- Markup nominal/persen/kombinasi; produk > kategori > layanan, satu aturan menang tanpa akumulasi antar-level. Manual tutup harus menyelesaikan seluruh saldo/transaksi sebelum final.
- Kesiapan bot wajib WA terhubung; web domain/subdomain ditentukan, provisioning fallback/DNS/TLS harus diverifikasi. Jika provisioning gagal admin mencoba memperbaiki dahulu.
- Bila provisioning tetap gagal dan layanan belum diberikan, refund pembayaran sewa. Data diri wajib nama sesuai KTP, WA/email terverifikasi, alamat.
- Kandidat supplier saldo kurang dilewati ke parsing berikut; modal sama harga jual tetap boleh (margin nol). Notifikasi pending pertama sekali setelah lima menit, berikutnya cek manual dan notifikasi perubahan status.
- Web menyediakan tiga template, branding dan section/iklan/ringkasan produk terbatas. Form order/profil seragam. Powered by DigitalToko wajib dan tidak dapat dihapus.
- KTP manual approved sebelum pilih/bayar paket; satu owner semua toko. Rejected menyertakan alasan dan file dihapus 72 jam kemudian. Approved disimpan saat masih ada toko; owner dapat membersihkan data diri setelah semua toko dihapus, dengan kewajiban yang belum selesai tetap ditangani.
- Jumlah toko per owner tidak dibatasi; tiap toko tetap berlangganan terpisah.
- Durasi, expiry, perpanjangan, upgrade/downgrade, grace tujuh hari, dan purge 30 hari berlaku per toko. Kedaluwarsa/penghapusan satu toko tidak memengaruhi toko lain milik owner yang sama.
- Perpanjangan aktif menambah durasi dari expiry; setelah habis mulai pembayaran berhasil. Upgrade membayar harga penuh paket tujuan dan masa aktif dimulai ulang (termasuk pindah kategori); downgrade periode berikutnya.
- Grace tujuh hari tetap bertransaksi, notifikasi panel; kemudian web/bot diblokir. Pada hari ke-30, purge ditunda jika ada saldo pelanggan/transaksi/refund belum selesai; layanan publik tetap diblokir. Tanpa kewajiban/renewal, seluruh data toko dipurge. Akun/saldo/riwayat owner Hanshop dipertahankan. Berlaku per toko sejak expiry.
- Keputusan terbaru membatalkan forced failure pending hari ke-30. Pelanggan bersaldo diberi notifikasi WA/email dan jalur penyelesaian toko. Rancangan renewal sebelum purge memulihkan data/paket/lisensi existing dan mulai durasi dari pembayaran sukses; toko yang sudah dipurge mulai baru.
- Parsing termurah dahulu; habis kandidat gagal. Pending menunggu supplier aktif dengan satu pemberitahuan lalu cek manual; perubahan status mengirim notif. Modal saat transaksi melebihi harga jual membuat transaksi gagal; auto-update katalog opsional owner/staff.
- Setelah lewat 30 hari admin turun tangan atas toko dengan kewajiban, mengarahkan pelanggan menghubungi owner; penundaan purge tetap berlaku sampai selesai. KTP/NIK tidak dibuka ke pelanggan.
- Stok terpisah per toko; garansi/replacement kebijakan masing-masing toko, bukan modul garansi awal platform.

Detail aturan, batas reuse source, dan usulan implementasi ada di `docs/BUSINESS_RULES.md`.

## Belum ditetapkan

### Klarifikasi limit Digiflazz (13 Oktober 2026)

Menurut konfirmasi owner, limit Digiflazz berlaku per akun/API key, bukan per IP. Rancangan cache Redis, lock refresh dan pembatasan request harus mengikuti identitas akun pemasok + parameter request. Toko yang memakai akun sama boleh berbagi cache; akun berbeda tetap terpisah. Target freshness 1–3 menit. IP keluar bersama pada VPS tidak berarti seluruh akun berbagi kuota. Ini catatan keputusan dari owner; implementasi cache/limiter masih tahap berikutnya.

Fondasi lokal kini memiliki Laravel/Inertia React, migrasi toko/domain/staff, role/tenant gate, password staff, halaman dasar, token/status worker, serta skeleton Baileys interaktif. Modul bisnis/auth owner/payment/provisioning belum selesai. Bukti dan batasnya di apps/web/README.md, apps/bot/README.md, dan docs/DEPLOYMENT.md; source Hanshop/bot lama tidak diubah.

| Area | Keputusan yang diperlukan | Pengaruh |
| --- | --- | --- |
| Pembayaran pelanggan | Metode Midtrans, range kode unik, bukti pencairan/manual tutup, pembelian ulang guest | Checkout dan ledger |
| Modul custom | Harga, perangkat/notifikasi yang didukung; lisensi nonrefund/nontransfer sudah tetap | Payment provisioning |
| Saldo/pembelian pemasok | Kontrak akun/saldo/partner Hanshop, audit lintas kanal, refund | Saldo bersama konsisten |
| Paket komersial | Nilai harga/durasi diisi admin saat operasional; trial/konversi sudah ditetapkan | Billing |
| Siklus sewa | Detail teknis refund gagal provisioning, upgrade kanal baru, manual tutup/pencairan, late payment/backup | Aktivasi dan penghapusan |
| Produk dan sumber | Pembulatan markup/modal multi-source, kontrak max-price supplier, refund antar-attempt | Fallback dan margin |
| Stok premium | Format lanjutan/expiry akun bila diperlukan | Fulfillment |
| Akun pengguna | Reset password, provider OTP email/WA, penautan identitas web-WA dan daftar Hanshop idempotent | Auth/checkout |
| KTP/onboarding | Prosedur pemeriksaan, kontak owner yang dibagikan, pembersihan profil berbeda dari ledger Hanshop | Identitas |
| Web toko | Desain tiga template, daftar section/slot banner, publish/preview | Storefront |
| Domain | Penyedia/apex, reservasi slug checkout, detail rename berbayar fase berikut | Routing/TLS |
| Infrastruktur | Provider/spec VPS, DB/Redis setup, kapasitas pilot, backup, DNS/TLS; stack sudah ditetapkan | Operasional |
| Batas versi pertama | Fitur wajib launch dan urutan pilot | Jadwal dan kriteria siap pakai |

## Usulan desain yang belum menjadi keputusan pengguna

- Satu proses WhatsApp per toko pada tahap awal, memakai kode dasar bersama.
- Satu backend bisnis untuk panel, web toko, dan bot; stok dan transaksi lintas kanal konsisten.
- Subdomain otomatis dan domain custom opsional; Cloudflare for SaaS sebagai kandidat penyedia.
- Per toko memakai identitas partner Hanshop terpisah dan secret hanya di server.
- Kanal notifikasi platform, bukti penyelesaian saldo, hold panjang, backup, dan retensi dokumen owner perlu dirinci. Lisensi toko baru setelah purge membeli ulang.

## Urutan pembahasan yang disarankan

1. Alur uang pelanggan, pembayaran toko, dan saldo pemasok.
2. Harga/limit paket dan aturan masa aktif.
3. Produk, pemilihan sumber, stok, dan garansi.
4. Onboarding, login, staf, dan web toko.
5. Domain, infrastruktur, dan batas versi pertama.
