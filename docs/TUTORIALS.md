# Pusat panduan DigitalToko

Diterapkan 23 Oktober 2026. Halaman `/panduan` berada di host platform, dapat dibuka tanpa login, dan mempunyai pencarian/topik/pagination. `/panduan/{slug}` memuat langkah, persiapan, gambar yang dapat diperbesar, kendala umum, rujukan resmi serta panduan terkait. Domain mengikuti deployment; tidak ada host produksi yang ditanam di URL navigasi.

## Konten dan gambar

- `apps/web/resources/js/tutorialCatalog.json`: 22 panduan statis. Perubahan konten tidak memerlukan database/migrasi. Slug stabil digunakan tautan menu.
- `apps/web/public/guides/steps/`: 68 screenshot JPEG dari database SQLite simulasi terpisah dan 7 ilustrasi SVG berlabel untuk whitelist Digiflazz serta Notification URL Midtrans. Sebanyak 108 langkah dalam 22 panduan masing-masing mempunyai gambar, 1–3 penanda panah dan keterangan spesifik. Gambar overview lama tetap disimpan sebagai aset kompatibilitas. Tidak ada key, PIN, password, token supplier nyata atau data pelanggan produksi. Nominal/status/IP pada gambar adalah contoh, bukan konfigurasi rekomendasi.
- Screenshot mempunyai caption data simulasi. Jangan menyalin localhost/store ID dari ilustrasi menjadi callback; panduan meminta URL asli dari panel toko sendiri.
- `TutorialController` mengirim metadata ringkas pada index dan satu panduan lengkap pada detail. Index tidak memuat semua isi/gambar; detail hanya mengunduh gambar yang digunakan.
- Materi meliputi persiapan/KTP, Digiflazz, Hanshop, OtomaX, Midtrans, QRIS custom/forwarder, sandbox, katalog/harga, stok, WhatsApp, notifikasi/email, pelanggan, transaksi, tampilan, staff, artikel, voucher, langganan, penutupan serta admin.

## Tautan kontekstual

`WorkspaceLayout` menambahkan `TutorialLink` pada action header setiap menu. `tutorialRoutes.ts` memilih topik dari path/tab, termasuk detail transaksi dan staff. Dibuka di tab baru agar form panel tetap tersedia. Halaman utama menyediakan navigasi Panduan serta CTA ke pusat panduan. Tutorial tidak memberi akses tambahan ke tindakan panel.

## Memperbarui

1. Cocokkan petunjuk dengan source/form dan dokumentasi resmi provider. Jangan menjanjikan fitur yang belum tersedia.
2. Edit JSON: judul, kategori, kata kunci pencarian, persiapan, langkah, kendala, related dan sources. Pastikan related menunjuk slug valid.
3. Jika UI berubah, ambil ulang screenshot dengan fixture simulasi dan pastikan tidak ada credential/data produksi. Simpan screenshot pada public/guides/steps dengan ekstensi sesuai format aslinya; jangan membuka URL daftar harga supplier bertoken untuk ilustrasi publik.
4. Setiap langkah wajib memiliki `visual`: `image`, `width`, `height`, `caption`, dan `markers` (label, x/y titik tengah, w/h area dalam persen). Ukur koordinat dari elemen UI yang terlihat; cocokkan dimensi dengan file screenshot, bukan hanya innerWidth/innerHeight browser. `illustration: true` wajib untuk gambar buatan yang bukan screenshot panel asli. Penanda dirender sebagai SVG oleh `TutorialStepImage`, sehingga screenshot asli tidak dimodifikasi. Gunakan maksimal tiga target agar jelas.
5. Jalankan `php artisan test --compact tests/Feature/TutorialTest.php`, `npm run test:frontend`, dan `npm run build` dari apps/web. Tes memeriksa akses publik/host, seluruh detail, tautan terkait, keberadaan/dimensi gambar, kelengkapan setiap langkah, batas koordinat/penanda serta pemetaan menu. Periksa juga panah, modal perbesar, dan akses keyboard di browser.
6. Deploy source dan public/guides bersama public/build. Tidak ada dependency atau migrasi baru untuk modul panduan.

Referensi integrasi: [Digiflazz webhook](https://developer.digiflazz.com/api/buyer/webhook/), [Midtrans notification](https://docs.midtrans.com/docs/https-notification-webhooks), [Midtrans authorization](https://docs.midtrans.com/docs/api-authorization-headers). Petunjuk OtomaX mengikuti adapter dan pengaturan yang tersedia pada source DigitalToko; format supplier tetap dapat berbeda.

## Bahasa untuk tenant

Panduan owner/staff ditujukan untuk pengguna layanan yang sudah di-host DigitalToko. Hindari instruksi lokal/ngrok, menjalankan worker/scheduler atau mengelola VPS. Untuk whitelist pemasok, pengguna menyalin IP koneksi DigitalToko yang ditampilkan pada popup pengaturan sumber. Administrator menetapkan nilainya melalui SUPPLIER_OUTBOUND_IP pada env web. Instruksi server tetap berada pada dokumentasi operasional/admin, bukan tutorial tenant. Tutorial Midtrans memakai ilustrasi halaman pembayaran agar contoh URL lokal tidak membingungkan penyewa.

## Penyajian langkah visual

Gambar di-load secara lazy dengan dimensi eksplisit. Tombol Perbesar petunjuk membuka modal; gambar minimal 1000 piksel pada mode ini dapat digeser agar penanda tetap terbaca pada layar kecil. Keterangan bernomor di bawah screenshot menyediakan petunjuk yang sama tanpa bergantung pada warna/panah. Tidak ada pengambilan screenshot otomatis dari akun tenant atau panggilan ke supplier ketika membuka panduan.

### Revisi QRIS HansDev — 1 November 2026

Panduan pembayaran-custom memakai screenshot baru form unggah gambar, popup APK/koneksi (token disamarkan), dan modal konfirmasi manual dari database UI terisolasi. Alur Sumber/Koneksi/Izin Android menggunakan SVG berlabel ILUSTRASI yang mengikuti menu source Flutter, bukan diklaim screenshot HP. Panduan mencakup instalasi/update APK ARM64, izin pemasangan, akses notifikasi, autostart/baterai, kesesuaian package/nama, listener/riwayat dan penanganan manual. Gambar QR pada fixture sintetis, bukan QR merchant produksi; URL lokal/ID pada screenshot bukan konfigurasi yang disalin tenant.

### Panduan aktivasi listener USB — 1 November 2026

Topik baru aktifkan-listener memiliki12langkah dan4SVG berlabel ILUSTRASI, dengan panah per langkah. Bukan screenshot perangkat aktual. Komponen TutorialCommand menyediakan blok kode dan tombol salin; pemilih terminal menghindari empat blok OS tampil bersamaan. Download guide dikirim server hanya bila bundle tersedia. Pembayaran-custom menautkan panduan baru; popup Hans Forward mengarahkan ke sana. Semua perintah berbentuk teks statis dan tidak dieksekusi dari browser. ZIP rilis kini menambahkan launcher portable pada masing-masing ZIP platform-tools; APK dan seluruh berkas SDK asli dipertahankan. Script berada di akar ZIP OS, sejajar folder platform-tools, dan mencari ADB di subfolder itu tanpa drive/path tetap.
