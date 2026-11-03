# Keputusan DigitalToko

Disinkronkan dengan source dan keputusan pengguna sampai **3 November 2026**. Keberadaan implementasi lokal tidak menjamin konfigurasi/deployment produksi.

## Keputusan yang berlaku dan implementasi

- Brand DigitalToko, warna Indigo/Cyan/Navy; ikon D untuk favicon platform dan sidebar collapsed. Domain aplikasi dapat diganti melalui konfigurasi; hansdigi.biz.id adalah domain awal yang direncanakan dan digitaltoko.com domain final yang diinginkan. Kepemilikan/routing aktif tidak diaudit pada sinkronisasi dokumen ini.
- Laravel/Inertia React melayani seluruh panel dan tiga template storefront; Baileys berada di proses terpisah. Owner multi-store, staff/customer scope toko, satu database aplikasi terpisah dari Hanshop.
- Satu paket Web Topup + Bot WhatsApp, variasi hanya durasi. Semua toko mendapat Digiflazz, API Hanshop, OtomaX dan stok premium. Harga/durasi berasal dari admin. Penawaran bot/web lama dinonaktifkan untuk pembelian baru; invoice historis dan masa aktif dipertahankan. Trial baru tidak tersedia; voucher platform mendukung diskon 100%.
- Trial otomatis tujuh hari dihapus. Voucher admin dapat menutup 100% tagihan sewa/modul tanpa gateway. Trial lama yang sudah aktif dihormati sampai expiry awal tanpa grace; trial belum dimulai kembali ke pilih paket.
- Aktivasi pertama setelah layanan siap dan konfirmasi owner, otomatis setelah 24 jam siap. Renewal aktif dari expiry; setelah habis dari paid_at; perubahan/upgrade harga penuh memulai periode baru; downgrade saat periode berikutnya. Semua per toko.
- Grace tujuh hari masih bertransaksi; selanjutnya akses operasional ditolak. Evaluasi purge expiry+30 dengan hold kewajiban sudah diimplementasikan. Evaluasi dijadwalkan per jam; penghapusan membutuhkan `STORE_PURGE_ENABLED=true` setelah pratinjau diperiksa.
- Identitas/saldo owner milik Hanshop. Staff maksimal lima (termasuk nonaktif), akun lokal toko. Profil/KTP approved wajib sebelum pembelian. Pendaftaran owner, sinkron email, OTP, dan review manual tersedia.
- Pendaftaran customer web membutuhkan nama, email, nomor WA dan password. Jalur login WA OTP/email-password serta OTP email tersedia sesuai verifikasi. Guest wajib WA, email opsional. Verifikasi email tidak sekaligus memverifikasi WA.
- Setiap toko memperoleh satu sesi WhatsApp untuk OTP, notifikasi dan transaksi chat. Tidak ada fallback WA Hanshop pusat untuk pesan pelanggan toko. Email memakai pengirim platform atau SMTP toko.
- Satu gateway customer aktif: Midtrans merchant toko atau QRIS custom berlisensi permanen per toko. Modul tidak dipindah/refund. Fee, topup kode unik dan refund kode unik disnapshot per invoice. Midtrans topup/refund mengecualikan fee.
- Wallet customer terpisah per toko. Gagal dibayar customer login direfund ke wallet; guest ditindaklanjuti toko. Pencairan manual hanya saat berhenti. Penutupan final menunggu kewajiban selesai.
- Produk mengikuti layanan → kategori → produk. Provider quick order, tujuan/format dan cek nickname di kategori. Parsing modal termurah dahulu, fallback hanya kegagalan pasti; ambigu tidak dipaksa gagal/retry.
- Markup nominal/persen/kombinasi, prioritas produk → kategori → layanan. Markup dan auto-update diwariskan terpisah. Modal melebihi batas harga yang disetujui tidak diteruskan sebagai pembelian diam-diam.
- Keputusan manual Digiflazz eligible dan callback terbaru dapat mengubah status/SN termasuk terminal. Refund bisa ditarik kembali sampai minus; pembelian customer minus diblokir. Bukan otorisasi replay pembelian pemasok baru.
- Voucher toko satu per pesanan produk, setelah flash sale, tidak untuk topup; multi-sasaran layanan/kategori/produk. Produk setelah diskon harus positif. Voucher platform sewa/modul boleh Rp0; sisa berbayar minimal Rp1.000.
- Aurora/Nebula/Atlas dapat dikustom melalui schema section/media/warna tervalidasi, bukan kode bebas tenant. Artikel memakai Tiptap dan renderer JSON; Powered by DigitalToko wajib.
- Mode Pengujian per toko terpisah dari Produksi, mencakup saldo/kuota uji dan simulasi pembayaran/sumber. Pergantian mode menunggu pesanan/inquiry berjalan selesai.

## Infrastruktur yang telah memiliki fondasi

- Satu manager dan child worker per toko, session file privat dan loopback HTTP; ini implementasi, bukan lagi sekadar usulan.
- Cache saldo/Hanshop Redis 60–180 detik per akun/operasi, katalog Digiflazz 300 detik per akun/mode, dan snapshot URL OtomaX 300 detik per URL lengkap/profil. Semuanya memakai lock dan cooldown. Limit Digiflazz per akun/API key menurut konfirmasi owner; akun berbeda tidak disatukan karena IP server sama.
- Host resolver untuk subdomain configured domain/alias dan custom host active+verified. DNS wildcard/cert provider harus disiapkan di luar resolver.
- Laravel queue, scheduler, outbox dan webhook menangani pemrosesan ulang yang aman. API Hanshop bridge internal berbeda dari Public Partner API HMAC.

## Kondisi implementasi tambahan 22 Oktober

- OtomaX mendukung URL ALL produk Webreport HTTPS dengan pratinjau, format pembacaan per koneksi, sinkronisasi manual/terjadwal, history, dan penandaan tidak tersedia untuk SKU hilang. Upload Excel/CSV disembunyikan pada UI tanpa menghapus backend/data lama. Feed belum dipasang ke koneksi asli user oleh proses pengembangan; pemetaan akun tetap dipilih owner.
- Storefront ketiga template memiliki susunan mobile, dropdown pencarian infinite scroll tanpa kelompok berulang, quick order Aurora ringkas dengan pemuatan scroll minimal 500 ms dan 24 preset warna. Perubahan desain tetap melalui editor owner/staff; tidak ada pergantian tema toko existing otomatis.
- Urutan produk katalog/halaman kategori berdasarkan `sale_price` naik sebelum pagination. Pencarian navbar, urutan kategori, trending dan promo memiliki alur masing-masing.

## Pekerjaan atau keputusan yang masih terbuka

| Area | Kondisi / tindak lanjut |
| --- | --- |
| Domain | Klaim TXT/arah DNS, proof HTTPS, pelepasan dan adapter Caddy tersedia. Deploy TLS nyata, Cloudflare for SaaS API, alias www/apex dan rename slug berbayar tetap pekerjaan operasional/lanjutan |
| Purge dan backup | Hold/reminder, pratinjau, purge data/media/WA dan pembatalan renewal sudah tersedia. Backup terenkripsi/retensi/restore-drill lokal tersedia (3 November). Aktivasi produksi, replika luar perangkat, backup database Hanshop/log bersama dan pelepasan DNS/TLS provider masih tugas operator. |
| Gagal provisioning | Pelaporan, retry, review admin dan refund sewa awal ke Hanshop sudah tersedia. SLA operator dan uji refund operasional masih perlu ditetapkan/diverifikasi; bukan refund otomatis tanpa approval. |
| Upgrade kanal | Evaluasi UX/readiness kanal tambahan; implementasi change berlaku saat invoice lunas, readiness aktivasi pertama tidak otomatis menjadi gerbang upgrade |
| Akun | Reset password mandiri dan prosedur cleanup lanjutan; endpoint membersihkan data diri owner tanpa toko sudah ada, jangan menganggap label permission sama dengan endpoint fitur |
| Wallet owner | Saldo/deposit Hanshop owner sudah tersedia di pemantauan pemasok; validasi tiket/pembayaran nyata dan rekonsiliasi layanan perlu uji operasional. Wallet pelanggan tetap terpisah. |
| Operasional | Monitoring aplikasi admin tersedia (28 Oktober: layanan, queue, WhatsApp, pemasok, callback). Spesifikasi VPS/kapasitas pilot, pemantauan eksternal saat host/web mati total, backup recovery, secret rotation, domain/SMTP/provider aktual tetap perlu diverifikasi lingkungan |
| Worker | Durability auth/key, crash recovery, multi-host perlu hardening; purge sekarang meminta ACK disconnect manager sebelum menghapus data toko |
| Layanan pihak ketiga | Validasi pemakaian komersial endpoint nickname dan hak penggunaan aset brand; fallback saat layanan tidak tersedia |
| Kesiapan peluncuran | Pilot pembayaran/pemasok/OTP nyata terkontrol dan pengujian idle Android jangka panjang belum dapat disimpulkan dari tes lokal |

## Sumber acuan

ARCHITECTURE.md untuk peta source, BUSINESS_RULES.md untuk kebijakan/batas implementasi, DEPLOYMENT.md untuk operasi, STAFF_PERMISSIONS.md untuk grant aktual. FOUNDATION_VALIDATION.md dan checkpoint bertanggal merupakan riwayat; klaim lama tidak mengalahkan kondisi source terbaru.


## 23 Oktober 2026 — Paket berdasarkan platform

> Kebijakan historis ini digantikan oleh keputusan 3 November 2026: satu paket Web Topup + Bot WhatsApp, variasi hanya durasi.

- Mengganti sembilan kombinasi sumber/kanal menjadi Bot WhatsApp, Web Topup, dan Web + Bot WhatsApp, masing-masing dengan harga dan durasi dari admin.
- Semua platform mendapat Digiflazz, API Hanshop, OtomaX, serta stok premium. Kanal penjualan tetap mengikuti platform; Web tetap boleh menautkan WA untuk OTP/notifikasi, tanpa command penjualan bot.
- Sesuai persetujuan owner platform, harga/durasi awal memakai penawaran terlengkap lama tiap platform. ID penawaran dipertahankan agar sasaran voucher tetap cocok; penawaran terbatas sumber dinonaktifkan untuk pembelian baru.
- Migrasi memperbarui entitlement toko lama tanpa mengubah masa aktif dan snapshot invoice. Invoice lama tetap dapat diproses dari snapshot historisnya. Pembelian baru wajib memakai penawaran platform saat ini.
- Pembelian ulang platform yang sama adalah perpanjangan meskipun toko masih menyimpan kode sumber lama. Perubahan dengan pengurangan kanal berlaku periode berikutnya; penambahan kanal membayar harga penuh dan memulai ulang masa aktif.

## 26 Oktober 2026 — Persiapan berjualan dan aktivasi

Checklist setelah pembayaran adalah panduan konfigurasi, bukan perubahan kontrak masa sewa. Sumber/stok, katalog/harga, dan gateway ditampilkan sesuai data tersimpan serta akses staff; tidak melakukan request supplier ketika checklist dibuka. Syarat aktivasi pertama dan otomatisasi 24 jam tetap menggunakan SubscriptionService::ready. Subdomain bawaan tersedia tanpa domain pribadi. Domain pribadi serta koneksi WA notifikasi pada platform Web tidak diwajibkan untuk aktivasi. Verifikasi konfigurasi tidak menggantikan uji transaksi/callback operasional.

## 26 Oktober 2026 — Pengirim email bawaan platform

Email bawaan memakai satu alamat pusat dari MAIL_FROM_ADDRESS, dengan nama pengirim mengikuti nama toko melalui StoreMail. Alamat lokal saat keputusan dibuat adalah info@hansdigi.biz.id; alamat domain DigitalToko ditentukan setelah layanan email siap. Tidak membuat mailbox atau alamat pengirim per subdomain tenant, tidak mengintegrasikan provisioning cPanel/Cloudflare untuk email. Pilihan SMTP milik toko tetap tersedia. Migrasi alamat pusat memerlukan konfigurasi SMTP/DNS dan uji pengiriman; koneksi SMTP keluar dari VPS perlu dipastikan. Keputusan ini tidak mengubah source atau kredensial runtime.

## 26 Oktober 2026 — Refund sewa awal gagal provisioning

Disetujui owner platform: admin dapat mengembalikan pembayaran penuh termasuk kode unik ke saldo Hanshop untuk sewa awal yang belum pernah aktif dan gagal karena platform. Tidak otomatis untuk kelalaian persiapan owner/pembatalan sepihak. Paket voucher gratis dibatalkan tanpa uang dan tanpa reset kuota. Invoice sudah aktif atau renewal/change tetap di luar cakupan. Approval dicatat sebelum request uang dan memblokir aktivasi; rekonsiliasi memakai UUID invoice tetap agar timeout/retry tidak menggandakan kredit. Akun Hanshop owner dan toko lain dipertahankan.


## 2 November 2026 — Navigasi pemasok, unduhan dan panduan

Koneksi supplier dikelompokkan terpisah dari katalog sumber. Kredensial Digiflazz/Hanshop tetap owner-only, grant OtomaX tidak berubah. Memindahkan halaman tidak membuat akun/mapping baru atau mengganti URL callback. Saldo/deposit pemasok berada pada grup koneksi yang sama.

Unduhan tenant dipisah menjadi APK dan satu ZIP tools sesuai OS pilihan; tidak lagi mengunduh semua OS sekaligus. Script berada di luar folder platform-tools pada masing-masing ZIP. APK screenshot dokumentasi terpisah tidak masuk distribusi tenant. Panduan QRIS mencakup profil, format, pengujian dan simpan; screenshot diberi asal yang jelas agar data contoh tidak dianggap mutasi nyata.

## 3 November 2026 — Satu paket, sesi panel dan voucher sekali per akun

- Satu paket Web Topup + Bot WhatsApp; semua sumber termasuk. Hanya durasi/harga dari admin yang dipilih. Kunci full/galaxy dipertahankan. Invoice dan masa aktif lama tidak ditulis ulang. Harga/durasi full lama tetap, bot/web nonaktif untuk penjualan baru. Entitlement toko lama diperluas; histori paket dan jadwal lama tetap dapat dibaca/diproses.
- Aktivasi awal semua toko mensyaratkan WA terhubung; subdomain bawaan tetap tersedia dan domain pribadi opsional. Checklist dan panduan disesuaikan. Tidak ada pembelian upgrade/downgrade kanal baru.
- Sesi panel hilang/kedaluwarsa: navigasi HTML/Inertia menuju login, API JSON mendapat 401. Pengguna terautentikasi yang mengakses toko lain atau tidak berizin tetap ditolak. Cookie HttpOnly hanya petunjuk halaman login staff, bukan otorisasi.
- Voucher memiliki opsi Sekali per akun. Toko: per akun pelanggan terverifikasi, guest ditolak; bot mengenali akun pelanggan dari nomor pengirim terverifikasi. Platform: per owner lintas toko serta sewa/perpanjangan/modul; pembelian staff memakai jatah owner.
- Invoice pending menahan jatah; expired/cancelled yang belum dibayar melepas jatah. Pesanan sudah dibayar tetap menggunakan jatah walau produk gagal/refund. Kuota sandbox terpisah. Identitas penggunaan dicatat juga saat opsi belum aktif; migrasi menautkan riwayat yang masih dapat diidentifikasi. Riwayat yang telah dipurge sebelum migrasi tidak dapat dipulihkan identitasnya dari counter anonim.
- Claim voucher owner yang diketahui dipertahankan setelah toko dihapus; tidak menyimpan isi transaksi atau kontak toko. Delapan ilustrasi promosi dan prompt Flow 60 detik diperbarui; bukan video final/rekaman UI.

## 3 November 2026 — Validasi MySQL dan backup

Dua proses terpisah mencapai barrier sebelum request dilepas untuk membuktikan lock pada MySQL nyata; voucher sekali per akun tidak menggunakan kuota global sebagai pengganti identitas. Satu akun hanya satu pemenang, akun/owner berbeda tetap eligible. Database uji berawalan dt_commerce_test_/dt_otomax_test_/dt_backup_test_ dan hasil restore dt_restore_; bukan database toko aktif.

Backup memakai ZIP AES-256, key independen, dump InnoDB konsisten, salinan media/sesi dengan pemeriksaan perubahan, publikasi atomik, verifikasi hash dan retensi. Pemulihan hanya ke target baru/offline. Module tidak otomatis membalikkan pembayaran provider atau mengaktifkan antrean hasil restore. Jadwal laptop 03.00 WIB, tujuh arsip; backup dan drill lokal berhasil. Konfigurasi/penyedia replika luar perangkat serta rollout VPS tetap pekerjaan operasional.

## 3 November 2026 — Panel riwayat backup dan pemulihan

Administrator dapat membuat snapshot, mengunduh arsip dan meminta restore dari riwayat/unggahan. Restore tetap ke database/folder baru untuk ditinjau operator; tidak tersedia overwrite live lewat HTTP. Retensi snapshot tidak menghapus hasil pemulihan. Pemulihan sebenarnya diuji lewat queue database/MySQL terisolasi, tanpa menulis database toko aktif. Tidak ada perubahan schema atau dependensi.

## 3 November 2026 — Perubahan restore: opsi aktif dan terpisah

Permintaan user memperluas keputusan isolated-only sebelumnya. Panel sekarang menyediakan dua mode yang dijelaskan eksplisit; isolated tetap default dan tidak mengalihkan aplikasi, sedangkan active menimpa schema/media aktif dengan koordinasi maintenance, PM2, lease HTTP, safety backup dan review. Penimpaan tidak dijalankan pada database utama laptop selama implementasi; dibuktikan pada schema MySQL uji. Tinjauan sebelum resume diperlukan karena restore DigitalToko tidak memutar mundur transaksi/saldo di supplier, gateway, atau API Hanshop. Pratinjau hasil terpisah disediakan lewat CLI server localhost read-only, bukan mengganti .env aktif.
