# Audit keterbacaan UI — 20 Oktober 2026

## Cakupan

Audit sumber mencakup 38 komponen halaman React dan seluruh stylesheet aplikasi web: halaman utama DigitalToko, guest/login, admin, owner, staff, serta storefront Aurora, Nebula, dan Atlas. Komponen bersama mencakup navigasi, tabel, filter, pagination, modal, dropdown, notifikasi, formulir, dan editor desain/artikel.

Pemeriksaan browser menggunakan database SQLite simulasi terpisah melalui server lokal sementara port 8010. Tidak ada perubahan konfigurasi tenant produksi, pembayaran nyata, atau penggunaan saldo pemasok untuk pemeriksaan ini.

## Temuan dan perbaikan

| Temuan | Perbaikan |
| --- | --- |
| Banyak aturan teks operasional menggunakan 8–12 px, termasuk aturan khusus ponsel | Ganti deklarasi tersebut dengan token ukuran bersama dalam rem; teks pendukung 14 px pada pengaturan browser standar, teks utama 15 px |
| Ukuran isian dan select kecil/tidak konsisten | Input, select, dan textarea menggunakan 16 px; tetap mengikuti zoom/preferensi ukuran huruf browser |
| Navigasi, label, dan tombol kurang tegas | Semibold 600 untuk navigasi, label, legend, dan tombol; medium 500 untuk label checkbox terkait; judul 700; paragraf tetap regular agar nyaman dibaca |
| Keterangan panel terlalu samar | Sesuaikan warna teks sekunder panel terang dan gelap, tanpa menimpa palet storefront yang dipilih tenant |
| Elemen `small` tanpa aturan khusus mengikuti ukuran bawaan browser 12,5 px | Terapkan ukuran bersama 14 px; diverifikasi ulang pada kartu paket admin |
| Status artikel terbelah menjadi beberapa baris setelah huruf diperbesar | Badge status tidak membelah kata; kolom status/penulis/tanggal dan lebar minimum tabel artikel disesuaikan; gulir horizontal tetap berada di pembungkus tabel |
| Navigasi Nebula terlalu rapat pada lebar 390 px | Menu boleh tersusun menjadi dua baris dengan jarak dan tinggi target klik yang cukup; dropdown kalkulator tetap dapat mengambang |
| Header fixed dapat menutupi konten ketika teks membungkus | Header Aurora/Nebula diukur dengan ResizeObserver; spacer mengikuti tinggi aktual, dan observer dibersihkan saat komponen dilepas |

Skala utama disimpan di `apps/web/resources/css/typography.css`. Deklarasi lama diperbaiki langsung di stylesheet terkait, termasuk media query, agar aturan khusus halaman tidak mengembalikan teks operasional ke ukuran kecil. Ukuran ilustrasi miniatur dan pilihan format huruf artikel yang sengaja ditetapkan penulis dipertahankan; keduanya bukan ukuran default teks antarmuka.

## Verifikasi browser

| Area | Halaman/alur yang diperiksa |
| --- | --- |
| Platform/guest | Halaman utama, login owner, login admin, login staff |
| Admin | Ringkasan, daftar/detail toko, verifikasi, paket/harga, pembayaran sewa, modul pembayaran |
| Owner | Ringkasan, daftar/buat toko, profil/verifikasi, tampilan/pengaturan, desain, staff/hak akses, katalog/harga, layanan/kategori, sumber, stok, artikel, pesanan/detail, pelanggan/mutasi, pembayaran, langganan, bot, penutupan |
| Staff | Ringkasan toko, pengaturan, katalog, halaman akses fitur |
| Storefront | Beranda tiga template, kategori, checkout, bukti/status pesanan, cek pesanan publik, login/daftar pelanggan, akun/profil/mutasi, daftar/detail artikel, leaderboard, kalkulator |
| Tema dan responsif | Panel terang/gelap; viewport desktop 1280 px; pratinjau responsif dalam iframe 390 px untuk Aurora, Nebula, Atlas, owner, dan staff |

Ukuran teks operasional juga diperiksa dari computed style pada halaman desktop yang diuji; temuan `small` 12,5 px di paket admin telah diperbaiki dan diuji ulang. Halaman desktop yang diperiksa tidak menambah overflow horizontal pada dokumen. Tabel, daftar kategori, dan navigasi yang memang memiliki area gulir tetap mempertahankan perilaku tersebut. Ini pemeriksaan lokal dengan data simulasi, bukan pengujian seluruh variasi data/tema tenant atau perangkat fisik.

## Validasi otomatis

- `npm run build`: lulus, termasuk pemeriksaan TypeScript. Peringatan aset hero Nebula yang sudah ada sebelumnya tetap muncul dan tidak menggagalkan build.
- `npm run test:frontend`: 21 tes lulus.
- `php artisan test --compact tests/Feature/StoreDesignTest.php tests/Feature/PanelWorkflowTest.php tests/Feature/CustomerAccountPanelTest.php`: 34 tes, 738 assertions, lulus.
- `git diff --check`: lulus sebelum commit.

## Hasil UI sebelumnya yang ikut disimpan dalam checkpoint

- Tabel: header rata tengah/semibold, kolom isi yang diminta rata tengah, garis tipis per sel, sudut membulat.
- Staff/hak akses: ringkasan kuota, tabel ringkas, modal akun dan grup izin; tanpa filter/pagination karena maksimum lima staff.
- Penutupan toko: status, langkah penyelesaian kewajiban, tabel kewajiban, dan konfirmasi akhir yang lebih jelas; aturan bisnis tetap.
- Tampilan/pengaturan: kartu identitas/template/mode terpisah; desain dibagi Logo & warna, Beranda, Promosi & CS; teks petunjuk dipersingkat dan penyuntingan tetap melalui modal.

## Bukti tampilan

- `assets/typography-admin-light.png`: paket admin, tema terang.
- `assets/typography-owner-mobile.png`: ringkasan owner, lebar 390 px, tema gelap.
- Bukti UI sebelumnya: `assets/staff-panel-clean.png`, `assets/store-closure-clean.png`, `assets/store-settings-clean.png`. Gambar staff merupakan tahap sebelum filter/pagination dihapus; implementasi final sudah menghapus keduanya.

Server pemeriksaan sementara dihentikan setelah audit; server development milik pengguna tidak dihentikan. Pembungkus responsif sementara hanya berada pada fixture yang diabaikan Git dan telah dihapus setelah pemeriksaan.
