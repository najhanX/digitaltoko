# Keputusan DigitalToko

Disinkronkan dengan source dan keputusan pengguna sampai **21 Oktober 2026**. Keberadaan implementasi lokal tidak menjamin konfigurasi/deployment produksi.

## Keputusan yang berlaku dan implementasi

- Brand DigitalToko, warna Indigo/Cyan/Navy; ikon D untuk favicon platform dan sidebar collapsed. Domain aplikasi dapat diganti melalui konfigurasi; hansdigi.biz.id adalah domain awal yang direncanakan dan digitaltoko.com domain final yang diinginkan. Kepemilikan/routing aktif tidak diaudit pada sinkronisasi dokumen ini.
- Laravel/Inertia React melayani seluruh panel dan tiga template storefront; Baileys berada di proses terpisah. Owner multi-store, staff/customer scope toko, satu database aplikasi terpisah dari Hanshop.
- Sembilan paket Mercury/Venus/Terra/Mars/Jupiter/Saturn/Uranus/Neptune/Andromeda mengikuti tabel ARCHITECTURE.md. Hanshop tersedia pada semua paket aktif. Harga/durasi ditentukan admin, bukan hardcoded komersial.
- Trial otomatis tujuh hari dihapus. Voucher admin dapat menutup 100% tagihan sewa/modul tanpa gateway. Trial lama yang sudah aktif dihormati sampai expiry awal tanpa grace; trial belum dimulai kembali ke pilih paket.
- Aktivasi pertama setelah layanan siap dan konfirmasi owner, otomatis setelah 24 jam siap. Renewal aktif dari expiry; setelah habis dari paid_at; perubahan/upgrade harga penuh memulai periode baru; downgrade saat periode berikutnya. Semua per toko.
- Grace tujuh hari masih bertransaksi; selanjutnya akses operasional ditolak. Purge hari ke-30 dengan hold kewajiban adalah kebijakan yang belum diimplementasikan sebagai penghapusan otomatis.
- Identitas/saldo owner milik Hanshop. Staff maksimal lima (termasuk nonaktif), akun lokal toko. Profil/KTP approved wajib sebelum pembelian. Pendaftaran owner, sinkron email, OTP, dan review manual tersedia.
- Pendaftaran customer web membutuhkan nama, email, nomor WA dan password. Jalur login WA OTP/email-password serta OTP email tersedia sesuai verifikasi. Guest wajib WA, email opsional. Verifikasi email tidak sekaligus memverifikasi WA.
- Semua paket boleh menghubungkan satu WA toko untuk OTP/notifikasi. Paket Web tidak memperoleh fitur command transaksi chat. Tidak ada fallback WA Hanshop pusat untuk OTP/customer notice toko. Email dapat memakai pengirim platform atau SMTP toko.
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
- Cache pemasok Redis 60–180 detik per akun + operasi/mode, lock dan cooldown. Limit Digiflazz per akun/API key menurut konfirmasi owner; akun berbeda tidak disatukan karena IP server sama.
- Host resolver untuk subdomain configured domain/alias dan custom host active+verified. DNS wildcard/cert provider harus disiapkan di luar resolver.
- Laravel queue, scheduler, outbox dan webhook menangani pemrosesan ulang yang aman. API Hanshop bridge internal berbeda dari Public Partner API HMAC.

## Pekerjaan atau keputusan yang masih terbuka

| Area | Kondisi / tindak lanjut |
| --- | --- |
| Domain | Otomatisasi ownership/DNS/TLS/Cloudflare for SaaS, apex, pelepasan binding dan rename slug berbayar belum lengkap |
| Purge dan backup | Implementasi hold hari ke-30, reminder, audit, purge media/cache/session/provider serta kebijakan backup/restore |
| Gagal provisioning | SLA penanganan dan mekanisme refund sewa otomatis belum tersedia |
| Upgrade kanal | Evaluasi UX/readiness kanal tambahan; implementasi change berlaku saat invoice lunas, readiness aktivasi pertama tidak otomatis menjadi gerbang upgrade |
| Akun | Reset password mandiri dan prosedur cleanup lanjutan; endpoint membersihkan data diri owner tanpa toko sudah ada, jangan menganggap label permission sama dengan endpoint fitur |
| Wallet owner | Topup saldo Hanshop dari panel tenant belum memiliki alur tersendiri; saldo customer sudah bisa topup |
| Operasional | Spesifikasi VPS/kapasitas pilot, monitoring, backup recovery, secret rotation, domain/SMTP/provider aktual perlu diverifikasi lingkungan |
| Worker | Durability auth/key, crash recovery, multi-host dan pembersihan sesi saat purge perlu hardening |
| Layanan pihak ketiga | Validasi pemakaian komersial endpoint nickname dan hak penggunaan aset brand; fallback saat layanan tidak tersedia |
| Kesiapan peluncuran | Pilot pembayaran/pemasok/OTP nyata terkontrol dan pengujian idle Android jangka panjang belum dapat disimpulkan dari tes lokal |

## Sumber acuan

ARCHITECTURE.md untuk peta source, BUSINESS_RULES.md untuk kebijakan/batas implementasi, DEPLOYMENT.md untuk operasi, STAFF_PERMISSIONS.md untuk grant aktual. FOUNDATION_VALIDATION.md dan checkpoint bertanggal merupakan riwayat; klaim lama tidak mengalahkan kondisi source terbaru.
