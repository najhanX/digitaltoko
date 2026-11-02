# Aturan bisnis DigitalToko

Status: kebijakan pengguna, disinkronkan dengan source lokal pada **3 November 2026**. Retensi/purge dan refund sewa awal setelah approval admin sudah memiliki implementasi; aktivasi purge otomatis dan kesiapan provider/DNS/TLS nyata tetap harus diverifikasi terpisah. Lihat ARCHITECTURE.md untuk peta implementasi; catatan di bawah membedakan alur yang tersedia dari target lanjutan.

## Identitas dan akun Hanshop

- Owner memasukkan nomor HP Hanshop, ID user Hanshop, atau email akun. Login tetap OTP, dengan pilihan pengiriman ke WhatsApp atau email akun tersebut. Setelah login, owner wajib melengkapi data diri/email dan mendapat persetujuan KTP sebelum memilih/membayar paket.
- Data diri wajib: nama sesuai KTP, nomor WA terverifikasi, email terverifikasi, dan alamat. Form juga memuat NIK, tempat/tanggal lahir dan foto KTP; dokumen tetap pada penyimpanan privat verifikasi, bukan profil publik.
- Rancangan keamanan: identifier harus menunjuk akun yang sama; tujuan OTP dibaca dari profil server, bukan alamat tujuan bebas dari request. OTP email hanya tersedia setelah email tersimpan dan terverifikasi. Akun lama tanpa email dapat login WA untuk melengkapi dan memverifikasi email. OTP memiliki expiry, batas percobaan/pengiriman, konsumsi satu kali, dan purpose login; jawaban request OTP tidak membuka daftar akun existing. Pengiriman platform memakai konfigurasi mail Laravel; customer dapat memakai SMTP toko melalui StoreMail.
- Email owner harus disimpan juga pada akun API Hanshop. Bridge identitas/profile dan migration email tersedia di API Hanshop; verifikasi penerapan SQL pada lingkungan tujuan tetap diperlukan.
- Pendaftaran owner baru dapat langsung di DigitalToko dan otomatis membuat akun Hanshop. Akun ditautkan lewat `id_user` stabil. Provisioning akun harus idempotent dan menangani nomor/email existing tanpa membuat akun ganda atau menautkan tanpa verifikasi kepemilikan. Bridge register/profile sudah tersedia; keaktifan layanan dan konfigurasi token tetap perlu diperiksa pada deployment.
- Identitas akun boleh digunakan bersama, tetapi sesi dan hak admin API Hanshop tidak dibagikan kepada penyewa. Staff DigitalToko memiliki scope toko, bukan akses admin Hanshop.
- Satu saldo akun Hanshop digunakan untuk transaksi pribadi melalui bot Hanshop dan pembelian sumber Hanshop dari toko. API Hanshop tetap menjadi sumber kebenaran saldo dan ledger; DigitalToko tidak membuat salinan saldo yang dapat didebit secara independen.
- Partner/integrasi toko ditautkan ke akun Hanshop yang sama. Akun partner per toko, jika dipakai, bukan berarti dompet baru. Catat `store_id`, kanal, dan ID pesanan sebagai referensi ledger/riwayat agar transaksi toko dapat dipisahkan dari transaksi pribadi dalam laporan.
- Target pengisian saldo owner: dari panel tenant ataupun bot Hanshop ke akun Hanshop yang sama. Source DigitalToko saat ini membaca saldo pemasok, tetapi belum menyediakan checkout topup saldo Hanshop owner dari panel; ini berbeda dari topup saldo customer yang sudah tersedia. Staff tidak boleh otomatis memiliki hak memakai seluruh saldo owner.
- Jumlah toko per owner tidak dibatasi secara komersial. Tiap toko tetap punya sewa/resource sendiri; rate limit dan kontrol provisioning operasional tidak berarti kuota jumlah toko. Saldo Hanshop owner sama, tetapi staff tidak diberi akses wallet global. Saldo pelanggan per toko berbeda dari saldo owner.
- Akun tanpa paket dapat mengakses onboarding/verifikasi. Pemilihan dan pembayaran paket hanya dibuka setelah data diri lengkap dan KTP disetujui. Fitur operasional toko terkunci sebelum aktivasi. Akses akun Hanshop di luar sewa tidak dicabut otomatis oleh DigitalToko.
- Staff dibuat owner sebagai akun lokal toko, tidak dimasukkan ke users Hanshop. Login memakai username atau email dan password, dengan konteks toko yang eksplisit. Password disimpan sebagai hash dan tidak ditampilkan kembali; mekanisme reset belum ditentukan. Maksimal lima akun staff per toko, tidak termasuk owner. Akun nonaktif tetap dihitung dalam batas lima; owner dapat menghapus akun untuk menggantinya.
- Owner/staff berpermission mengatur guest checkout atau wajib login. Nomor WA pembeli wajib diisi, email opsional. Kontak digunakan untuk bukti dan tindak lanjut pesanan; nomor yang diketik tidak otomatis menjadi bukti kepemilikan akun atau memberi akses riwayat. Pembeli login dan guest berscope toko, bukan akun owner pemasok.
- Pembeli login dengan WA + OTP atau email + password. Pendaftaran web baru wajib nama, email, WA dan password. Guest tetap boleh tanpa email. Email/password memerlukan email verified; akun lama/jalur bot diperlakukan sesuai bukti identitas yang sudah ada. Penautan dua jalur login memerlukan bukti kontrol kedua identitas, bukan mencocokkan teks nomor/email saja.

## Pembayaran pembeli toko

Dua gateway customer tersedia dalam source:

1. Gateway eksternal, dengan Midtrans sebagai integrasi awal yang diminta.
2. Gateway custom untuk toko dengan QRIS sendiri, menggunakan notifikasi Android dan pencocokan server.

Modul custom dibeli satu kali per toko, tidak dapat dipindahkan atau direfund, termasuk ke toko owner yang sama. Hak tetap tercatat selama toko tersimpan dan pemakaian memerlukan entitlement yang sah. Setelah purge dan membuat toko ulang, beli lisensi baru. Ketentuan lisensi tidak otomatis menentukan refund sewa jika provisioning gagal.

Akun merchant Midtrans milik toko; satu gateway aktif. Fee mengikuti biaya merchant/metode Midtrans, refund produk ke saldo pelanggan tidak termasuk fee. Rincian kanal/pembulatan mengikuti kontrak provider. Custom QRIS kode unik dipisahkan dari harga produk; owner/staff berpermission memilih refund harga produk saja atau termasuk kode unik untuk pesanan login gagal. Snapshot invoice menyimpan policy/komponen sehingga setting baru tidak mengubah invoice lama. Secret di server, scope toko. Pindah gateway hanya memengaruhi invoice baru.

## Transaksi gagal dan saldo pelanggan

- Guest: tindak lanjut transaksi gagal menjadi kebijakan toko, baik pembelian ulang maupun refund manual. Platform menyediakan status, bukti pembayaran/pesanan, dan kontak WA wajib untuk owner/staff menghubungi pelanggan. Tidak ada refund otomatis ke wallet atau akun Hanshop untuk guest.
- Pembeli yang login: transaksi terminal gagal yang sudah dibayar mengembalikan dana ke saldo akun pelanggan toko. Saldo ini per pelanggan per toko, bukan saldo Hanshop owner atau saldo toko lain. Pesanan belum dibayar tidak menghasilkan kredit refund.
- Saldo pelanggan dapat ditopup melalui gateway aktif toko selain menerima refund. Kredit hanya setelah settlement server sah, berbeda dari pembelian produk dan saldo Hanshop owner. Midtrans mengkredit nominal topup saja, tanpa fee: topup Rp50.000 menghasilkan saldo Rp50.000 meskipun tagihan juga memuat fee.
- Custom QRIS: owner/staff berpermission menentukan apakah kode unik ikut dikredit ke saldo. Contoh topup Rp50.000, kode unik Rp123, pembayaran Rp50.123: saldo bertambah Rp50.000 bila kode unik tidak dikredit, atau Rp50.123 bila dikredit. Setting topup berbeda dari setting refund produk; jangan menganggap salah satunya otomatis berlaku pada yang lain.
- Simpan nominal dasar, kode unik, fee, total tagihan, nominal kredit, dan policy topup sebagai snapshot invoice. Perubahan setting setelah invoice dibuat tidak mengubah nominal kredit invoice lama. Satu settlement hanya mengkredit satu kali, dengan ledger jumlah yang dikredit dan komponen yang tidak dikredit tercatat terpisah; jangan menyamakan kode unik yang ditahan dengan laba bersih toko.
- Pencairan tersedia ketika toko berhenti karena expiry/suspend layanan atau penutupan manual, bukan tarik rutin saat aktif. Untuk manual tutup, semua saldo/transaksi menggantung harus dituntaskan sebelum penutupan final. Rancangan CLOSING menolak pesanan/topup baru sambil membuka penyelesaian/refund oleh toko; CLOSED hanya setelah kewajiban selesai. Grace yang masih bertransaksi bukan status berhenti. Ini bukan jaminan mencegah seluruh penyalahgunaan dana; tidak ada transfer antar-wallet pada rancangan awal.
- Refund otomatis atomik/idempotent, satu kredit per pesanan yang benar-benar dibayar/didebit. Custom QRIS mengikuti policy produk saja/termasuk kode unik; Midtrans produk saja tanpa fee. Refund wallet berbeda dari refund tunai provider.
- Gunakan identitas/jenis checkout saat pesanan dibuat sebagai snapshot: guest yang login sesudah pembayaran tidak otomatis mengubah refund menjadi wallet. Pending/tidak pasti tidak memicu refund sebelum terminal gagal sesuai aturan bisnis.
- Pembelian ulang guest harus terkait pesanan asal sebagai tindak lanjut dengan kontrol akses dan audit, bukan retry bebas atas request pemasok pending. Panel mencatat refund manual atau pembelian ulang yang sudah dilakukan, bukan menjalankan pembelian ulang pemasok secara otomatis.
- Keputusan terbaru: saldo pelanggan, transaksi nonterminal, dan refund belum selesai menahan penghapusan. Mengkredit refund ke wallet belum menyelesaikan saldo tunai; saldo tersebut tetap diperhitungkan. Ini menggantikan forced failure/purge tanpa syarat pada hari ke-30.

Payment adapter menerima status valid server-side, tidak percaya redirect/browser sukses. Midtrans memakai verifikasi notification/status API sesuai dokumentasi. Semua invoice memiliki tujuan eksplisit: pesanan pelanggan, deposit saldo Hanshop, sewa, atau pembelian modul. Satu mutasi hanya menyelesaikan satu invoice yang sesuai.

## Pembayaran sewa dan aktivasi

- Pembayaran paket DigitalToko memakai gateway custom owner platform yang sudah ada di API Hanshop dan HansDev Forwarder dari project `android-listener`.
- Setelah pembayaran, provisioning idempotent; durasi awal belum berjalan. Syarat aktivasi: data wajib lengkap, subdomain bawaan siap dan WA toko terhubung. Custom domain opsional dan memerlukan verifikasi DNS/TLS. Setelah siap, ready_at dicatat; konfirmasi aktivasi memulai durasi atau otomatis setelah 24 jam. Jika syarat belum terpenuhi, timer belum dimulai.
- Pembayaran invoice sewa tidak dikonversi menjadi deposit saldo tanpa tujuan invoice yang eksplisit. SubscriptionInvoice dan bridge billing Hanshop mendukung invoice sewa terpisah; invoice voucher Rp0 diselesaikan lokal.
- Pemenuhan verifikasi KTP merupakan syarat owner yang diminta. Pembayaran sukses, status subscription, approval KTP, dan readiness worker/domain adalah state berbeda.
- Flow paid: login/daftar -> data diri/email -> KTP approved -> pilih paket/subdomain -> bayar -> provisioning -> siap -> manual/auto-aktif 24 jam -> durasi berjalan. Gagal provisioning diperbaiki admin terlebih dahulu; jika layanan tetap tidak dapat diberikan dan belum aktif, pembayaran sewa direfund. Kriteria/batas waktu usaha perbaikan dan metode refund teknis perlu dirinci; refund sewa tidak mengubah lisensi custom nonrefund secara otomatis.
- Perpanjangan dan pemilihan durasi dilakukan pada panel tenant, memakai harga dari admin. Pembelian upgrade/downgrade kanal baru tidak tersedia karena web dan bot sudah termasuk.
- Perpanjangan saat aktif menambah durasi dari tanggal kedaluwarsa existing. Perpanjangan setelah habis dimulai dari waktu pembayaran berhasil, termasuk bila masih dalam masa tenggang. Status pembayaran sukses harus berasal dari server, bukan waktu callback diterima belakangan.
- Invoice/jadwal perubahan kanal yang sudah ada sebelum kebijakan satu paket tetap diproses sesuai waktu dan durasi historis, tetapi hak akses menjadi web dan bot. Pembelian baru selalu memakai full/galaxy.
- Masa tenggang tujuh hari tetap mengizinkan transaksi baru di web/bot dan integrasi Hanshop sesuai entitlement paket terakhir. Panel owner dan staff menampilkan notifikasi bahwa toko dalam masa tenggang, tanggal berakhir, dan deadline penghapusan. Aksi perpanjangan hanya tersedia bagi role yang berwenang.
- Setelah tujuh hari web/bot diblokir, data tetap disimpan. Hari ke-30 memeriksa penghapusan: saldo pelanggan/transaksi/refund belum selesai menahan purge; jika tidak ada kewajiban dan tidak diperpanjang, seluruh data toko dihapus. Akun/saldo/riwayat owner Hanshop tetap dipertahankan.
- Gangguan provisioning ditangani melalui alasan kegagalan, periksa ulang/laporan owner dan persetujuan admin. Refund hanya untuk sewa awal yang belum aktif dan gangguan platform sesuai modul penanganan persiapan.

## Promo langganan melalui voucher

Trial otomatis Bot WhatsApp tujuh hari dihapus. Semua toko memilih paket dan durasi yang tersedia; promo diatur admin melalui voucher, termasuk diskon 100%. Voucher mengikuti sasaran, jadwal, dan kuotanya. Total Rp0 dicatat lunas secara lokal tanpa QRIS, kode unik, atau pemotongan saldo Hanshop. Total berbayar tetap minimal Rp1.000. Voucher 100% juga dapat berlaku untuk modul berbayar jika sasaran voucher mengizinkannya.

Pembelian pertama tetap menunggu kesiapan layanan dan konfirmasi aktivasi (atau aktivasi otomatis setelah 24 jam siap). Perpanjangan, perubahan paket, dan downgrade mengikuti aturan langganan biasa. Permintaan checkout yang sama hanya menghasilkan satu invoice dan satu pemakaian voucher, termasuk jika paket gratis langsung diterapkan.

Trial lama yang sudah aktif dihormati sampai tanggal kedaluwarsa awal tanpa masa tenggang. Trial yang belum dimulai dikembalikan ke pilih paket tanpa menghapus konfigurasi toko. Penanda trial historis tetap disimpan; tidak ada endpoint atau tombol untuk membuat trial baru.

## Masa tenggang, suspend, dan penghapusan toko

Ketentuan pengguna: durasi langganan, perpanjangan, upgrade/downgrade, masa tenggang, suspend karena expiry, dan penghapusan berlaku per toko (`store_id`), bukan per akun owner. Setiap toko mempunyai subscription, `expires_at`, `grace_until`, serta `deletion_due_at` sendiri. Tidak ada expiry owner global yang ikut menonaktifkan semua tokonya. Contoh: Toko A kedaluwarsa, Toko B masih aktif; hanya A menjalani grace/suspend/purge. Perpanjangan B tidak memperpanjang A, dan penghapusan A tidak mencabut akun owner atau akses B.

Interpretasi kerja: batas 7/30 hari dihitung dari `expires_at` yang sama, bukan tambahan 30 hari setelah masa tenggang. Frasa pengguna 'bot ataupun bot wa' ditafsirkan sebagai web toko dan bot WhatsApp. Perlu konfirmasi bila yang dimaksud berbeda.

| Waktu | Status akses toko | Penyimpanan |
| --- | --- | --- |
| Sebelum expires_at | Aktif sesuai kategori/paket | Normal |
| expires_at sampai sebelum expires_at + 7 hari | Masa tenggang, tetap dapat bertransaksi; notifikasi panel owner/staff | Tetap disimpan |
| expires_at + 7 hari sampai sebelum expires_at + 30 hari | Web toko/bot WA tidak dapat diakses | Source/artifact tenant dan setting tetap disimpan |
| Mulai expires_at + 30 hari, ada saldo/transaksi/refund belum selesai | DELETION_HOLD, web/bot tetap diblokir | Penghapusan ditunda, data toko tetap disimpan |
| Mulai expires_at + 30 hari, tanpa kewajiban dan tanpa renewal | Layak dihapus | Seluruh data toko dipurge; akun/saldo/riwayat owner Hanshop tetap ada |

Batas timestamp di atas adalah usulan presisi implementasi. Gunakan timestamp server konsisten, tampilkan waktu Asia/Jakarta. Masa tenggang mengizinkan transaksi baru dan API Hanshop sesuai paket; pemeriksaan hak layanan memakai status ACTIVE atau GRACE, bukan ACTIVE saja. Suspend manual untuk penyalahgunaan tetap dapat menolak layanan walaupun waktu sewa/grace belum habis.

Banner masa tenggang ditampilkan konsisten pada panel owner dan staff, menyebut tanggal kedaluwarsa, batas tujuh hari, dan batas penghapusan 30 hari. Staff tanpa permission billing tetap mendapat pemberitahuan, tetapi tidak mendapat hak membeli/mengubah paket. Notifikasi tidak mengubah status transaksi menjadi gagal.

Panel akun/pembayaran perpanjangan pada domain platform harus tetap tersedia saat layanan toko diblokir. Menonaktifkan web/bot tidak menghapus akun atau mematikan seluruh akses owner ke bot Hanshop pribadi.

Dalam arsitektur bersama, 'source code toko' berarti artifact/deployment milik tenant bila ada, bukan source aplikasi platform, template bersama, atau kode bot bersama. Penghapusan hanya scope toko yang sudah melewati deadline. Toko owner lain dan toko aktif milik owner yang sama tidak terdampak. Lepaskan binding domain/perangkat toko dan hapus secret/sesi/config tenant secara terkoordinasi.

Job purge memeriksa deadline/subscription, saldo pelanggan positif, transaksi nonterminal, dan refund belum selesai dengan lock. Jika ada kewajiban, masuk DELETION_HOLD; jangan memaksa pending gagal hanya karena hari ke-30. Panel penyelesaian/renewal dan rekonsiliasi backend tetap tersedia, web/bot publik tetap diblokir. Penundaan bukan layanan operasional gratis. Setelah kewajiban selesai, periksa eligibility kembali. Saldo tidak boleh direset untuk melewati pemeriksaan; refund manual dicatat dengan bukti/audit.

## Notifikasi saldo dan renewal ketika penghapusan ditunda

Implementasi 21 Oktober: pelanggan bersaldo positif mendapat outbox pengingat pada hari ke-6, ke-21, ke-29, serta saat hold hari ke-30; dihitung sejak expiry, bukan sejak toko dibuat. Pesan memuat nama toko, saldo, dan nomor WA owner untuk penyelesaian. Satu notice per toko/pelanggan/tahap/expiry; scheduler yang sempat mati hanya mengejar tahap terkini. Saat WA toko masih berlisensi/terhubung, worker toko dapat mengirim. Setelah layanan berhenti, email platform dipakai jika siap dan pelanggan memiliki email; jika tidak, notice tetap belum terkirim dan admin/owner perlu menghubungi pelanggan secara manual. Tidak ada fallback WA pusat tersembunyi. Keberadaan outbox tidak membuktikan pesan diterima.

Implementasi renewal: selama purge belum dimulai, pembayaran perpanjangan membatalkan jadwal purge/reminder expiry lama, memulihkan data/paket/lisensi custom existing, dan memulai durasi dari pembayaran sukses karena sewa sebelumnya sudah habis. Data toko disimpan selama DELETION_HOLD agar tidak perlu membuat toko ulang. Operasi publik dipulihkan setelah readiness worker/domain; jika sesi WA tidak valid, minta pairing ulang tanpa dua sesi aktif. Pending tidak dikirim ulang otomatis dan refund yang selesai tidak ditarik kembali.

Owner/staff mendapat pemberitahuan toko aktif lagi. Pelanggan yang sudah mendapat pesan berhenti diberi klarifikasi pemulihan; pengajuan refund existing tidak otomatis batal karena renewal. Purge/renewal memakai row lock owner/toko, status purging, serta pengecekan ulang kondisi langsung sebelum penghapusan; tidak mengandalkan pratinjau lama. Jika PURGING sudah dimulai, renewal tidak boleh menjanjikan restore; late payment perlu resolusi invoice/refund. Jika toko sudah dihapus, pembuatan/lisensi baru tetap berlaku. Detail kanal platform, penanganan hold panjang, dan pembersihan backup perlu dirinci.

Keputusan penanganan DELETION_HOLD: admin platform turun tangan langsung setelah lewat 30 hari untuk menindak toko dan mengarahkan pelanggan menghubungi owner memakai data yang tersimpan. Ini tidak membuka toko otomatis atau menggantikan tanggung jawab refund owner. Rancangan kontak yang dibagikan adalah nama/kontak usaha/WA/email owner yang relevan, bukan foto KTP, NIK, atau seluruh alamat pribadi. Dokumen identitas tetap reviewer-only. Penghapusan tetap menunggu kewajiban selesai; admin mencatat tindakan dan bukti penyelesaian, tidak sekadar menandai saldo nol.

Setelah purge toko harus dibuat/provision dari awal, tanpa restore data dan tanpa membawa lisensi modul custom lama. Akun Hanshop owner tetap dapat dipakai untuk memulai toko baru.

Pengguna menetapkan penghapusan seluruh data toko: katalog/produk/parsing, stok dan credential premium, pelanggan toko, pesanan/riwayat transaksi toko/payment lokal/ledger toko, setting/template/branding/media, sesi WhatsApp, secret gateway/source, relasi staff toko, log/cache/job/file/artifact milik toko, serta binding domain. Akun staff/identitas yang digunakan toko lain tidak dihapus karena satu relasi toko hilang. Source platform dan resource bersama bukan data toko.

Pengecualian yang diminta: akun owner, saldo, dan riwayat owner bertransaksi di API Hanshop tetap dipertahankan, termasuk pembelian Hanshop yang berasal dari toko. Record Hanshop tersebut bukan target delete/cascade tenant. KTP yang berada pada identitas owner bersama dan bukti pembelian modul pada level akun memerlukan keputusan retensi tersendiri; tidak boleh menjadi alasan menyimpan salinan data operasional toko tanpa batas.

Purge meliputi storage/file dan cache, bukan sekadar soft-delete atau menonaktifkan toko. Rincian pembersihan backup/shared log harus ditentukan agar data tenant tidak disimpan selamanya atau terbit kembali lewat restore. Toko yang sudah dipurge tidak memiliki restore setting/transaksi lokal dari panel; pembuatan toko baru merupakan onboarding baru. Source purge sudah tersedia dengan flag bawaan nonaktif. Validasi penghapusan hanya dijalankan pada fixture/database terpisah; tidak ada toko pengguna yang dihapus pada pengembangan modul ini.

## Gateway custom multi-toko dan forwarder

Project acuan: `C:\Users\62878\Documents\Najhan\HansDev\projects\android-listener`.

README/source saat pemeriksaan menunjukkan forwarder generik berbasis Flutter dengan endpoint, Bearer token/header, template JSON/Form, antrean persisten, event ID/waktu saat retry, dan preset mutasi QRIS. Setiap instalasi memiliki satu koneksi aktif. ACK HTTP berarti penerimaan event; validasi pembayaran tetap dilakukan server. Preset Fastcek berbeda kontrak dari endpoint mutasi Hanshop saat ini, sehingga adapter kompatibilitas harus dirancang.

Source `API Hanshop/controller/mutasiController.js` saat diperiksa menerima `name`, `text`, serta PIN global `MUTATION_INGEST_PIN`. Handler yang dibaca belum menyediakan scope merchant/perangkat/tenant atau dedup berbasis `event_id`. Handler ini tidak boleh langsung dianggap gateway multi-toko yang siap digunakan.

Rancangan tambahan yang diperlukan:

- Payment profile per merchant/toko, serta profil platform terpisah untuk sewa/deposit yang relevan.
- Credential perangkat berbeda per profil dengan rotasi/revoke, pemetaan merchant dari identitas terautentikasi, dan allowlist aplikasi sumber.
- Dedup event dan dedup referensi pembayaran, termasuk bila beberapa perangkat melaporkan transaksi yang sama; audit timestamp/asal.
- Pencocokan invoice berdasarkan merchant, tujuan pembayaran, nominal/reference, dan waktu yang sah. Jangan mencocokkan nominal secara global antar-toko.
- Bila QRIS statis memerlukan nominal unik, alokasi unik berlaku di scope merchant untuk invoice yang berpotensi bertabrakan, termasuk penanganan mutasi terlambat. Nominal/reference ambigu masuk review, bukan dipilih secara acak.
- Invoice expired, event palsu, perangkat offline, dan notifikasi terlambat memiliki jalur review/reconciliation yang eksplisit. Tidak ada klaim bahwa aplikasi HP membuktikan pembayaran hanya karena mengirim teks.
- Panduan tenant menghubungkan forwarder beserta uji penerimaan dan pembayaran nyata sebelum fitur diaktifkan.

Usulan pemasangan: setiap tenant modul custom memakai perangkat/notifikasi QRIS miliknya; perangkat platform melayani pembayaran platform. Custom QRIS tenant saat ini menerima package DANA, ShopeePay atau Shopee sesuai validasi koneksi; endpoint platform Hanshop memakai kontraknya sendiri.

## Paket, toko, staf, dan domain

- Satu paket Web Topup + Bot WhatsApp, variasi hanya durasi. Semua toko mendapat Digiflazz, API Hanshop, OtomaX dan stok premium. Harga/durasi berasal dari admin. Penawaran bot/web lama dinonaktifkan untuk pembelian baru; invoice historis dan masa aktif dipertahankan. Trial baru tidak tersedia; voucher platform mendukung diskon 100%.
- Tidak ada trial baru; promo memakai voucher admin, termasuk diskon 100%. Trial aktif lama dihormati sampai berakhir.
- Harga/fitur/durasi disnapshot pada invoice dan subscription; pengeditan paket tidak mengubah invoice lama atau hak sewa existing secara diam-diam.
- Setiap toko maksimal satu nomor/sesi WhatsApp untuk OTP, notifikasi dan transaksi. Pergantian nomor melalui logout/pelepasan sesi lama.
- Owner dapat menambah maksimal lima staff lokal toko dengan permission terkonfigurasi. Rancangan permission didelegasikan kepada pengembang dan dirinci di `docs/STAFF_PERMISSIONS.md`.
- Slug subdomain unik ditentukan saat membuat toko draft dan nama sistem ditolak. Belum ada reservasi checkout dengan TTL; custom domain mempunyai klaim owner, verifikasi TXT/arah DNS/HTTPS, penggantian dan pelepasan di Tampilan & pengaturan. Aktivasi storefront menunggu pemeriksaan HTTPS; sertifikat memerlukan konfigurasi origin sebagaimana CUSTOM_DOMAINS.md.
- Koneksi domain gratis, maksimal satu domain custom per toko selain satu subdomain bawaan; pembelian/perpanjangan registrar tanggung jawab tenant. Rancangan www/apex bila didukung diperlakukan alias satu domain utama, bukan dua domain bisnis. Subdomain tidak diganti melalui fitur biasa; rename berbayar mungkin fase berikut. Slug boleh dipakai lagi setelah purge; custom domain baru wajib verifikasi.
- Semua toko menyimpan subdomain tetap dan memperoleh storefront serta satu bot WhatsApp dalam langganan yang sama.

## Produk, parsing, dan fallback

- Owner memilih source produknya dan dapat memasang beberapa parsing pada satu produk, sesuai entitlement sumber paket.
- Kandidat aktif dan layak diurutkan berdasarkan harga pembelian paling murah. Harga jual ditentukan owner; snapshot modal dan harga jual disimpan saat transaksi.
- Setelah kandidat gagal final secara eksplisit, lanjut ke kandidat berikutnya. PENDING, PROCESSING, timeout, atau hasil UNCERTAIN tidak dianggap gagal yang aman untuk pindah; lakukan status check/recovery terlebih dahulu.
- Satu pesanan mempunyai idempotency key stabil dan riwayat attempt. Reserva/debit dana pelanggan sekali. Debit/refund pemasok yang berbeda harus direkonsiliasi, bukan menganggap wallet Digiflazz, Hanshop, dan stok lokal berada dalam satu transaksi database.
- Jangan mencoba ulang pasangan source/SKU yang sudah gagal final pada pesanan yang sama. Pastikan harga/entitlement/ketersediaan tetap layak sebelum dispatch berikutnya.
- Jika seluruh parsing habis/gagal aman, transaksi GAGAL. Kandidat menunggu aktif berbeda dari daftar kandidat habis; transaksi menunggu sampai ada informasi aktif. Jangan gagal otomatis hanya karena beberapa menit/hari ke-30 berlalu; transaksi nonterminal menahan purge sesuai keputusan terbaru.
- Jika modal saat request naik melebihi harga jual yang disepakati, transaksi GAGAL; jangan memaksakan rugi/menambah tagihan. Refund sesuai jenis checkout. Auto-update katalog opsional owner/staff memakai aturan markup produk/kategori/layanan berikut; ini menggantikan tahan pesanan karena modal naik.
- Markup otomatis menerima nominal (`5000`), persen (`5%`), atau kombinasi (`5% + 5000`). Prioritas produk > kategori produk > layanan; gunakan satu aturan pertama yang terisi, tidak menjumlahkan markup antar-level. Produk markup 5000 mengalahkan kategori 5%. Nilai 0 eksplisit berbeda dari kosong/inherit. Rumus kombinasi: harga jual = modal + (modal x persen / 100) + nominal. Contoh modal Rp100.000, aturan 5% + 5000 menghasilkan Rp110.000. Parser hanya format yang diizinkan, bukan eval ekspresi; implementasi membulatkan komponen persen ke atas dengan aritmetika integer. Harga sumber berdasarkan kandidat eligible; pembaruan otomatis terpisah dari pewarisan markup.
- Cegah dispatch pembelian mahal menggunakan snapshot/modal maksimum bila kontrak pemasok mendukung. Jika pemasok sudah menerima order sebelum kenaikan diketahui, jangan memalsukan gagal pemasok/refund sambil order masih pending; adaptasi kontrak harga masing-masing source perlu dipastikan.
- Pemberitahuan pending pertama sekali setelah lima menit pesanan menunggu tanpa hasil terminal, berikutnya cek manual. Jika sudah terminal sebelum lima menit jangan kirim pesan pending. Perubahan status bermakna tetap kirim notifikasi dengan dedup; jangan timer pending berkala atau menggandakan pembelian.
- Modal sama dengan harga jual tetap boleh transaksi dengan margin nol; fee dipisahkan sesuai gateway. Source termurah dengan saldo tidak cukup dilewati untuk kandidat berikutnya yang layak. Pengecualian: hasil dispatch belum pasti tidak boleh dianggap saldo kurang/gagal aman untuk reroute. Jika seluruh kandidat habis/gagal aman, terminal gagal sesuai aturan existing.
- Rancangan awal menawarkan source Digiflazz sendiri, Hanshop, dan stok sendiri; permintaan parsing tidak otomatis menambah supplier lain di luar tiga source ini.

## Stok premium

Backend acuan API Hanshop, frontend acuan `C:\Users\62878\Documents\Najhan\Codingan\` PROJECT\frontend\admin-panel`.

Basis perilaku yang akan diadaptasi: data akun per stok, kode produk, modal/catatan, status ready/terkirim, waktu input/kirim, hubungan pesanan, filter produk/status, pagination, bulk input, dan bulk edit/delete hanya stok ready. Stok diklaim secara atomik dan item yang dikirim adalah item yang berhasil diklaim. Tambahkan `store_id` dan permission staff untuk layanan multi-toko.

Stok selalu dipisahkan per toko, termasuk dua toko owner yang sama. Fitur platform berupa stok dan pengiriman; garansi/replacement mengikuti kebijakan masing-masing toko, bukan workflow garansi platform versi awal. Format/expiry akun tambahan belum ditetapkan. Reuse perilaku tidak memberikan penyewa akses tabel stok global Hanshop.

## Template dan kustomisasi web toko

- Sediakan tiga template siap pilih. Model layout utuh: Aurora (modern terang), Nebula (gaming gelap), dan Atlas (marketplace dengan sidebar kategori). Masing-masing mencakup beranda, katalog, dan kerangka halaman bersama. Template berbagi komponen dan form order/profil seragam.
- Owner dapat mengganti nama/logo toko, warna, susunan section yang disediakan, penempatan iklan/banner, dan susunan ringkasan produk.
- Form order dan halaman profil dibuat seragam bagi semua toko; mengikuti branding yang diizinkan tanpa layout/logic checkout berbeda per tenant.
- Implementasi editor memakai schema tervalidasi: section tertentu dapat diurutkan/ditampilkan dan media dapat dipilih. Simpan desain memperbarui branding toko; belum ada versi draft/publish desain terpisah seperti status draf artikel. Bukan HTML/JS/CSS bebas.
- Tema dan konfigurasi bersifat tenant dengan schema tervalidasi; pergantian template tidak menghapus katalog/transaksi. Tulisan Powered by DigitalToko wajib dan tidak dapat dihapus/disembunyikan oleh pengaturan template. Section, carousel, banner/link, flash sale/trending, welcome ad dan CS sudah memiliki schema/UI pada StoreDesignPanel dan StoreDesignController; bukan drag-and-drop atau kode bebas seperti editor situs umum.

## Verifikasi owner dengan KTP

- Pengguna meminta verifikasi KTP sebagai prasyarat owner untuk mengurangi penyalahgunaan toko.
- KTP manual wajib approved sebelum pilih/bayar paket; satu verifikasi owner semua toko. Jika ditolak, alasan diberikan dan file submission ditolak dihapus otomatis 72 jam sejak rejected_at. Job delete menunjuk versi submission spesifik, tidak menghapus upload pengganti yang sudah approved. Riwayat alasan/status dapat tetap ada tanpa foto.
- KTP approved disimpan selama owner memiliki toko aktif. Pengguna mensyaratkan data diri baru dapat dibersihkan owner setelah semua tokonya dihapus. Rancangan konservatif: jangan otomatis menghapus identitas saat toko hanya grace/suspend/DELETION_HOLD; tahan untuk kewajiban/penyelesaian, lalu izinkan owner membersihkan profil/dokumen DigitalToko ketika tidak ada toko/kewajiban. Akun/saldo/riwayat owner Hanshop tetap dipertahankan sesuai keputusan sebelumnya; cakupan pembersihan email/profil Hanshop perlu dipisahkan dari akun dan ledger.
- OTP membuktikan kontrol nomor; upload KTP saja tidak membuktikan dokumen asli atau mencegah seluruh penipuan. Mekanisme pemeriksaan, bukti kepemilikan, dan proses sengketa perlu ditetapkan.
- Foto KTP disimpan pada disk privat dan dibaca melalui endpoint owner terkait/admin, dengan reference pemeriksaan untuk admin serta respons no-store. NIK/alamat/tempat/tanggal lahir memakai encrypted casts; foto KTP sendiri belum memakai enkripsi file aplikasi. Enkripsi disk/backup menjadi kebutuhan operasional. Staff/pembeli tidak diberi akses dokumen.
- Tentukan tujuan pengumpulan, dasar pemrosesan, data minimum, masa retensi, penghapusan, dan pemberitahuan privasi sebelum mengumpulkan KTP. Jangan menambah selfie/biometrik tanpa kebutuhan dan keputusan eksplisit.
- Sediakan mekanisme laporan toko/produk dan tindakan suspend sebagai pelengkap verifikasi, dengan keputusan admin dan penyelesaian transaksi lama yang dapat diaudit.

## Koreksi hasil, notifikasi dan batas implementasi

`CustomerOrderDecision` mendukung keputusan sukses/gagal manual untuk produk Digiflazz lunas yang eligible dan sudah memiliki attempt terkirim. Owner atau staff `orders.manage` harus memberi alasan; riwayat disimpan. Callback Digiflazz sah tetap dapat mengubah status/SN dari hasil manual maupun terminal otomatis. Koreksi gagal memberi refund satu kali; koreksi sukses menarik refund kembali, boleh menjadi minus. Saldo minus membatasi pembelian melalui saldo maupun gateway. Callback bukan izin mengulang dispatch pemasok.

WA OTP/notifikasi customer menggunakan worker dan nama toko. Transaksi chat termasuk paket yang sama. Email memakai mail platform atau SMTP toko terenkripsi; tanpa fallback WA pusat. OTP owner tetap memakai identitas Hanshop. Pengiriman outbox tidak menjamin exactly-once bila pesan terkirim sebelum ACK tersimpan.

Mode Pengujian memakai development key Digiflazz, testing=true, wallet/kuota terpisah dan simulasi sumber Hanshop/stok. Pembayaran simulasi lokal tersedia selain Midtrans Sandbox. Pergantian mode ditolak saat masih ada pesanan atau inquiry berjalan.

Retensi per toko sekarang diimplementasikan melalui StoreRetention dan digitaltoko:retention: evaluasi expiry+30, hold kewajiban, reminder, preview, renewal cancel, cleanup data/media/WA dan retry. Flag penghapusan nyata bawaan nonaktif. Saldo simulasi bukan uang nyata; saldo produksi positif maupun minus menahan penghapusan tanpa saling mengimbangi. Penangguhan admin dan antrean nonstandar juga memerlukan peninjauan. Job pada antrean database standar dapat dibersihkan; konfigurasi antrean khusus ditahan. Log/backup server bersama, DNS/TLS eksternal, dan refund sewa gagal provisioning memerlukan prosedur operasional terpisah. Lihat RETENTION_PROGRESS.md untuk bukti pengujian dan batas aktual.

Referensi source: `apps/web/app/Services`, `apps/web/app/Http/Controllers`, `apps/web/routes`, serta bridge `API Hanshop/src/modules/digitaltoko-identity`. Perubahan schema Hanshop menggunakan SQL manual di scripts/migrations; database Laravel memakai migrations sendiri. Riwayat validasi lokal ada pada FOUNDATION_VALIDATION.md dan NOTIFICATION_PROGRESS.md. Sinkronisasi dokumen ini tidak menjalankan transaksi, migrasi produksi atau pengiriman pesan nyata.

### Penutupan sukarela dan persetujuan admin

Penutupan final owner adalah permohonan penghapusan: layanan dihentikan, status suspended_manual, closure_requested_at dicatat, lalu menunggu admin. Setelah seluruh kewajiban tuntas, admin dapat menyetujui penghapusan langsung melalui modal dengan konfirmasi subdomain, walau belum ada langganan atau masa sewa belum habis. Tidak menunggu expiry+30 dan tidak mengaktifkan penghapusan otomatis. Lisensi berakhir tanpa refund/pemindahan. Toko lain dan identitas Hanshop owner tetap ada. Permohonan yang telah masuk tidak dibuka kembali hanya dengan mengganti closing=false; tindak lanjut lewat admin. Retry purge manual tetap memerlukan admin dan pratinjau terkini. Aturan retensi otomatis expiry+30 tetap terpisah dan tidak mengeksekusi permohonan manual.

## 26 Oktober 2026 — Penanganan sewa awal gagal disiapkan

- Kebijakan owner platform: refund penuh termasuk kode unik ke saldo Hanshop owner hanya setelah admin memastikan gangguan platform dan toko belum pernah aktif. Paket gratis/voucher dibatalkan tanpa kredit uang. Modul permanen, pembatalan sepihak, renewal/change dan paket yang sudah aktif tidak termasuk alur ini. Kuota voucher yang sudah dikonsumsi tidak otomatis dikembalikan.
- Penyiapan lunas memiliki ProvisioningCase per invoice awal: waiting_owner, ready, failed, review, refund_requested, refund_processing, refunded atau resolved. Menunggu owner menautkan WA bukan otomatis kegagalan platform. Kegagalan manager/aktivasi dicatat, owner dapat melapor, dan admin mendapat notifikasi. Kasus belum selesai setelah 24 jam sejak lunas memberi pengingat admin tanpa auto refund.
- Owner/staff melihat penanganan di Langganan & aktivasi; tindakan hanya owner. Admin memakai Kelola toko → Detail toko, serta filter Penyiapan toko → Perlu penanganan/refund. Tambah/tinjau tindakan melalui modal dengan alasan. Riwayat maksimal 40 tindakan ditampilkan dalam area scroll; persetujuan/waktu/admin/nominal refund tetap menjadi field permanen kasus.
- Pemeriksaan ulang tidak membuat invoice atau meminta pembayaran lagi dan tidak memaksa aktivasi. Review, permintaan refund, kegagalan dan refund berjalan menahan aktivasi; ready_at direset pada penanganan agar periode otomatis 24 jam tidak memakai waktu lama. Aktivasi sukses menutup kasus resolved dan memulai masa sewa sesuai aturan existing. Edit paket/masa aktif tetap dibatasi invoice terbuka existing, dan perubahan admin ditahan ketika refund diproses.
- Approval refund mengunci owner/store/invoice/kasus, mensyaratkan konfirmasi subdomain serta gangguan platform. Nilai berasal dari invoice total. Setelah approved, scheduler dapat memeriksa ulang billing/refund dengan invoice UUID tetap. Timeout tidak dianggap lunas/refunded; status refund_processing menahan aktivasi/penghapusan sampai konfirmasi cocok. API menerapkan kredit user + ledger + penanda refund dalam satu transaksi, satu refund per invoice. Saat dikonfirmasi, invoice web refunded dan toko kembali draft tanpa menghapus konfigurasi/media atau toko lain.
- Migrasi Laravel 2026_10_26_152224_create_provisioning_cases_table menambah tabel kasus. API memakai script manual 20261026_digitaltoko_rental_refunds.sql setelah billing dan saldo_ledger lengkap. Migrasi lokal keduanya telah diterapkan sesudah backup privat; tidak ada pembayaran/refund nyata pada validasi.

## 1 November 2026 — QRIS HansDev dan konfirmasi penerimaan manual

Gambar QRIS dibaca lokal di browser dan payload Indonesia/CRC diverifikasi server. Pemilik dapat menentukan package aplikasi lain atau nama aplikasi; token serta snapshot invoice tetap mengisolasi mutasi masing-masing toko. Sumber bebas bukan berarti semua format notifikasi otomatis dikenali.

Konfirmasi manual penerimaan uang hanya untuk modul QRIS berlisensi, oleh owner atau staff berizin payments.confirm. Nominal persis termasuk kode unik, waktu, referensi dan catatan wajib; tidak untuk invoice dibatalkan, Midtrans atau wallet. Referensi mutasi tidak boleh dikreditkan ke dua invoice pada merchant yang sama. Invoice aktif lunas mengikuti antrean pemenuhan/topup existing; expired masuk review. Tindakan ini berbeda dari keputusan sukses/gagal produk. Klik berulang/callback susulan tidak menggandakan pemenuhan atau kredit. Audit privat disimpan pada snapshot. Tidak memberikan staff akses koneksi/token gateway secara otomatis.


## Pengaturan harga dan pemisahan menu — 2 November 2026

Dasar harga otomatis dipilih pada aturan kategori/layanan: kandidat termurah (`cheapest`) atau termahal (`highest`, default). Kategori mengalahkan layanan; tidak perlu mengatur dasar pada setiap produk. Markup dan auto-update tetap diwariskan secara terpisah menurut prioritasnya. Dasar harga jual tidak membalik urutan pemrosesan: parsing eligible termurah tetap dicoba lebih dahulu dan hasil pending/ambigu tidak dianggap gagal aman untuk berpindah. Memilih dasar termahal bukan jaminan semua supplier akan dicoba setelah perubahan harga atau hasil ambigu.

Koneksi supplier menyatukan API Hanshop, Digiflazz, OtomaX dan pemantauan saldo/deposit; Sumber produk hanya katalog dan impor. Tidak ada perubahan hak credential karena pemindahan menu. Penangkap QRIS tidak memberi hak melunasi manual; `payments.parsers` dan `payments.confirm` terpisah. Uji format tidak menciptakan uang masuk atau menyelesaikan invoice.

## Voucher sekali per akun — 3 November 2026

Aktifkan di modal voucher → Batas penggunaan → Sekali per akun. Voucher toko wajib akun pelanggan terverifikasi; guest tidak dapat menggunakannya. Voucher admin dibatasi per owner, lintas toko dan pembelian sewa/perpanjangan/modul oleh owner atau staff. Pending menahan jatah; pembatalan/kedaluwarsa sebelum pembayaran melepas jatah. Pembayaran yang sudah lunas tetap dihitung walau pesanan gagal/refund. Sandbox menggunakan kuota terpisah. Perubahan aturan menjadi sekali per akun ikut menghitung riwayat penggunaan yang identitasnya masih tersedia. Rekaman owner tetap disimpan ketika toko dihapus. Tidak ada perubahan pembatasan satu voucher per pesanan dan larangan voucher topup saldo.
