# Pusat panduan DigitalToko

Disinkronkan dengan source lokal pada **2 November 2026**. Isi di bawah menjelaskan kondisi aktif; hasil tes bertanggal bukan bukti deployment produksi atau pembayaran nyata.

## Halaman dan sumber konten

- `/panduan`: daftar publik dengan pencarian, topik dan pagination. `/panduan/{slug}`: langkah, persiapan, gambar yang dapat diperbesar, perintah bila relevan, kendala, rujukan dan panduan terkait.
- `apps/web/resources/js/tutorialCatalog.json` memuat 22 panduan dan 111 langkah. Perubahan konten tidak memerlukan database/migrasi.
- `TutorialController` hanya mengirim ringkasan pada index dan satu panduan lengkap pada detail. `tutorialRoutes.ts` memilih tautan header menu. Tautan dibuka di tab baru agar form panel tetap tersedia.
- Sumber produk mengarah ke panduan katalog; halaman koneksi Digiflazz/Hanshop mengarah ke panduan masing-masing. OtomaX memiliki panduan tersendiri. Kartu penangkap QRIS dan popup Hans Forward menautkan panduan khusus QRIS/listener.

## Gambar dan asal datanya

111 langkah memakai 119 visual (beberapa langkah memiliki gambar tambahan), dengan **86 aset unik yang dirujuk**: 71 JPEG, 10 PNG perangkat asli, dan 5 ilustrasi SVG. Aset overview/versi lama dapat tetap berada di public/guides tanpa dipakai oleh visual langkah terbaru.

Screenshot panel berasal dari fixture terisolasi. Sepuluh screenshot HP diambil melalui ADB pada Redmi Note 10 Pro, Android 13 / MIUI 14: Tentang telepon, USB debugging, akses notifikasi, baterai, Ringkasan, Sumber, pemilih aplikasi, Koneksi, simpan koneksi, dan Riwayat. Menu Android lain dapat berbeda. Gambar tidak memuat token atau transaksi produksi; gunakan nilai pada panel toko sendiri, bukan URL/IP dari contoh gambar.

Layar aplikasi berasal dari APK dokumentasi terpisah `id.hansdev.forwarder.tutorial`, memakai source UI 1.0.3 dan bridge data contoh. APK utama tetap memakai FLAG_SECURE; konfigurasi, antrean dan listener asli tidak diubah. APK dokumentasi tidak termasuk unduhan tenant. Manifest utama dokumentasi tidak mempunyai service/receiver; debug Flutter menyertakan INTERNET tetapi bridge dokumentasi tidak mengirim HTTP atau memakai platform channel. Status Sedang mendengarkan/Terkirim pada gambar dokumentasi bukan bukti koneksi atau mutasi nyata.

Dialog RSA tidak dimunculkan ulang dengan mencabut otorisasi HP. Bagian RSA dan terminal yang belum difoto tetap memakai ilustrasi berlabel; tidak diklaim sebagai screenshot perangkat. Penanda panah dan kotak dirender sebagai overlay SVG, bukan mengubah isi screenshot.

## Format visual dan tampilan

Setiap langkah memiliki `visual` dengan image, width, height, caption, dan markers. `additionalVisuals` opsional untuk gambar lanjutan pada langkah yang sama. Koordinat penanda berupa persen titik tengah x/y dan ukuran w/h; maksimal tiga penanda per gambar.

- `illustration: true`: wajib pada SVG ilustrasi berlabel ILUSTRASI.
- `capture: "device"`: caption Screenshot HP asli. Tambahkan `exampleData: true` untuk layar APK dokumentasi/data contoh.
- Screenshot panel tanpa metadata tersebut memakai caption Screenshot simulasi.

`TutorialStepImage` memakai dimensi eksplisit dan lazy loading. Gambar HP dibatasi 360px pada langkah dan 540px pada modal perbesar; overlay memakai koordinat berskala agar label terbaca. Galeri HP dua kolom pada desktop berubah menjadi satu kolom pada mobile. Screenshot desktop pada modal tetap dapat digeser. Petunjuk bernomor di bawah gambar menyampaikan instruksi yang sama tanpa bergantung pada warna/panah.

## Unduhan APK dan tools

`ForwarderDownloads` dipakai bersama oleh popup Hans Forward dan kedua panduan QRIS/listener. APK Android diunduh sendiri. Tools memerlukan pilihan OS pada dropdown; satu ZIP hanya berisi SDK OS yang dipilih dan script sejajar folder platform-tools. Tombol tools nonaktif sebelum OS dipilih; OS yang berkasnya belum tersedia dinonaktifkan.

| Berkas | URL | Env opsional |
| --- | --- | --- |
| HansDev-Forwarder.apk | `/downloads/hansdev-forwarder.apk` | HANSDEV_FORWARDER_APK_PATH |
| HansDev-Listener-windows.zip | `/downloads/hansdev-listener/windows.zip` | HANSDEV_FORWARDER_TOOLS_WINDOWS_PATH |
| HansDev-Listener-macos.zip | `/downloads/hansdev-listener/macos.zip` | HANSDEV_FORWARDER_TOOLS_MACOS_PATH |
| HansDev-Listener-linux.zip | `/downloads/hansdev-listener/linux.zip` | HANSDEV_FORWARDER_TOOLS_LINUX_PATH |

Default berkas berada di storage/app/releases; biner diabaikan Git dan harus disalin ketika deploy. Env kosong menggunakan default. HANSDEV_FORWARDER_BUNDLE_PATH tidak lagi dipakai. Alias `/downloads/hansdev-forwarder` melayani APK; URL ZIP gabungan lama redirect ke panduan listener. Server memakai OS allowlist, MIME/filename tetap, no-store/no-cache dan nosniff; berkas tidak ada menghasilkan404. Jangan mendistribusikan APK dokumentasi, keystore, atau key.properties.

Packaging dari root proyek: `python deploy/package-forwarder.py --source "PATH/arsip-asli.zip" --output "PATH/arsip-kerja.zip" --split-dir "apps/web/storage/app/releases"`. Source dan output wajib berbeda. ZIP gabungan hanya bahan kerja internal; hasil untuk tenant adalah empat berkas terpisah. Detail dan asal SDK/APK: [DEPLOYMENT.md](DEPLOYMENT.md).

## Alur dua panduan pembayaran

`aktifkan-listener` mempunyai 12 langkah: download APK/tools, pasang APK, aktifkan developer, USB debugging, sambungkan/izinkan RSA, buka hasil ekstrak tools, adb devices, jalankan launcher, verifikasi izin/status, atur sumber/koneksi, baterai, dan uji/cabut kabel. Perintah CMD/PowerShell/macOS/Linux dipilih melalui terminal; semuanya dimulai dari folder yang berisi script dan platform-tools sejajar. Launcher tidak otomatis menyetujui RSA atau menyalakan USB debugging. Izin listener bukan bukti listener terhubung atau invoice lunas.

`pembayaran-custom` mempunyai 10 langkah: aktivasi modul, upload gambar QRIS, download/pasang, sumber/koneksi, izin listener, profil penangkap, format, uji/simpan, uji pembayaran/kebijakan, dan konfirmasi manual. Template QRIS wajib [nominal] tepat sekali; [pengirim], [referensi], [waktu] dan [abaikan] opsional tanpa pengulangan. Teks tetap minimal delapan karakter, maksimal 10 format dan minimal satu aktif. Ini berbeda dari template OtomaX yang mengizinkan [abaikan] berulang.

Isi pesan atau judul+isi dipilih pada Pengaturan tambahan. Pesan gagal/dibatalkan/berhasil dikirim selalu ditolak; pengecualian tambahan dapat diatur. Uji draf tidak menyimpan dan tidak mengesahkan invoice. Setelah hasil sesuai, Kembali ke format lalu Simpan penangkap. Webhook nyata tetap memeriksa token, merchant, sumber aplikasi, waktu, nominal dan invoice. Konfirmasi manual adalah penerimaan uang setelah memeriksa mutasi, bukan keputusan sukses produk.

## Memperbarui panduan

1. Cocokkan petunjuk dengan route, form, permission dan source layanan. Jangan menjanjikan fitur hanya berdasarkan label menu atau hasil UI simulasi.
2. Edit JSON dan tautan terkait; pertahankan slug agar tautan panel tidak putus. Perbarui image, caption, penanda, serta dimensi sebenarnya. Ekstensi harus sesuai format berkas; keluaran screenshot browser dapat berupa JPEG.
3. Pakai data simulasi/fixture. Jangan membuka token feed supplier untuk screenshot atau memublikasikan kredensial, notifikasi pribadi dan transaksi produksi. Untuk HP, pertahankan proteksi aplikasi utama dan ambil ulang gambar bila popup pribadi muncul.
4. Jalankan `php artisan test --compact tests/Feature/TutorialTest.php`, `node --experimental-strip-types --test tests/Frontend/tutorials.test.mjs`, serta build bila source/aset frontend berubah. Tes memeriksa seluruh gambar utama/tambahan, dimensi, related, penanda, host/akses dan pemetaan menu.
5. Periksa panah/zoom serta susunan desktop/mobile bila visual diubah. Deploy source, public/guides, public/build dan empat berkas rilis bila dipakai. Membuka panduan tidak menjalankan perintah pada komputer/HP atau menghubungi supplier.

Panduan owner/staff memakai konteks layanan ter-host. IP whitelist disalin dari popup Koneksi supplier; administrator menetapkannya melalui SUPPLIER_OUTBOUND_IP atau override Digiflazz. Instruksi VPS/worker/ngrok tetap pada dokumentasi operator. Rujukan Android/provider tersimpan pada masing-masing panduan.

## Bukti validasi bertanggal

Pada 2 November 2026, batch revisi unduhan lulus 67 tes Laravel, 2 tes frontend tutorial dan 2 tes launcher/packaging. Batch pemisahan koneksi lulus 82 tes Laravel dan 2 tes frontend tutorial; batch-batch ini tumpang tindih dan tidak dijumlahkan sebagai seluruh test suite. TutorialTest terakhir setelah pembaruan screenshot lulus 4 tes/5.764 assertion. Build web serta pemeriksaan visual desktop/mobile lulus pada perubahan source tersebut. Riwayat lengkap dan batas bukti ada di [FOUNDATION_VALIDATION.md](FOUNDATION_VALIDATION.md).

Pemasangan APK dokumentasi melalui ADB ditolak MIUI, lalu pengguna memasangnya manual sebelum pengambilan screenshot. Listener aplikasi utama terikat dan monitor foreground aktif pada pemeriksaan saat itu; ini bukan bukti pengujian idle jangka panjang. Penerbitan TLS publik dan pembayaran produksi tidak dibuktikan oleh tes panduan ini.
