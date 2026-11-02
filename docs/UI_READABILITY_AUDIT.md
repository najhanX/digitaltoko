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


## 20 Oktober 2026 — konsistensi seluruh panel admin

- Ringkasan, kelola/detail toko, verifikasi owner, paket/harga, pembayaran sewa, voucher, modul pembayaran, dan login admin memakai skala kontrol serta jarak yang konsisten dengan panel owner/staff.
- Filter dan pagination atas disatukan dengan tabel dalam kartu berpadding. Pagination bawah, header semibold/rata tengah, pemisah sel, radius, pencarian, serta scroll tabel dipertahankan. Identifier panjang dapat membungkus; pembayaran kini memiliki tautan langsung ke toko.
- Paket ditampilkan dalam tabel untuk membandingkan harga/durasi; rincian fitur tersedia di modal. Modal paket, tindakan toko, verifikasi, dan harga QRIS diringkas dengan kelompok isian dan bantuan yang dapat dibuka. Peringatan penangguhan dan kewajiban alasan tetap terlihat.
- Gaya admin dibatasi melalui `admin-workspace` di WorkspaceLayout dan `admin-panel.css`; halaman owner/staff tetap memakai gayanya masing-masing. Login admin mendapat penyelarasan kartu, spasi, dan indikator pemrosesan.
- Browser diperiksa dengan fixture SQLite dan HTTP simulasi pada tema terang/gelap, sidebar terbuka/tertutup, serta viewport 390 × 844. Tabel lebar tetap di dalam area scroll tanpa membuat halaman melebar; modal ponsel tetap dapat dijangkau. Tidak menyimpan keputusan verifikasi, perubahan paket/harga, atau mengirim notifikasi nyata.
- 72 tes backend terkait (1.320 assertions), 21 tes frontend, TypeScript, build produksi, serta `git diff --check` lulus. Tidak ada dependency atau migrasi database baru pada perapihan admin ini.
- Bukti tampilan akhir: `assets/admin-panel-clean-preview.png`.


### Penjualan owner/staff dan tombol aksi — 20 Oktober 2026

- Diseragamkan: voucher, transaksi, detail transaksi, pelanggan, mutasi saldo, serta pembayaran pelanggan. Kartu/tabel memakai padding, filter, pagination atas-bawah dan ukuran kontrol yang sama dengan katalog.
- Detail pesanan memisahkan ringkasan dan tindakan. Pengaturan pembayaran memakai kartu gateway utama dan tiga kartu ringkas untuk biaya, checkout dan lisensi QRIS. Modal tetap digunakan untuk perubahan data.
- Tombol aksi teks diberi kelas tombol sekunder; tab modal, tombol ikon dan aksi berbahaya memiliki latar, fokus, hover dan status nonaktif yang jelas. Pilihan quick order Aurora dan kategori Atlas mengikuti warna storefront.
- Pemeriksaan lokal memakai database simulasi terpisah: owner/staff, desktop/ponsel, terang/gelap, modal gateway/kebijakan/voucher; audit tombol beranda Aurora/Nebula/Atlas dan modal voucher admin. Tidak ada pembayaran atau pengiriman notifikasi nyata.
- Validasi: 61 tes fitur (734 assertions), 21 tes frontend, TypeScript/Vite build dan git diff --check lulus.


### Susunan storefront khusus mobile — 22 Oktober 2026

- Aurora, Nebula dan Atlas kini memiliki komposisi khusus layar hingga 700 px. Navigasi dipindahkan ke tombol Menu dengan dropdown, dapat ditutup dari tombol yang sama, klik luar, Escape atau pemilihan tautan. Header desktop tetap menampilkan navigasi lengkap. Tidak memakai percabangan viewport pada render React sehingga markup awal tetap konsisten.
- Banner berteks dipendekkan menjadi 240 px; banner gambar saja mempertahankan rasio. Teks promosi pendukung berulang diringkas pada mobile. Flash sale dan trending memakai deretan kartu yang digulir horizontal; kategori Nebula memakai tiga kolom (dua pada layar hingga 360 px), dengan gambar dan judul lebih ringkas. Footer dan tombol CS disederhanakan; label aksesibel CS tetap tersedia.
- Aurora menonjolkan quick order dengan pengantar singkat. Daftar kategori Atlas tetap vertikal dengan tinggi terbatas. Grid bagian bersama diberi minmax(0,1fr) agar kartu promo tidak memperlebar halaman Atlas. Form pesanan, kontak dan ringkasan ditata satu kolom, pilihan nominal menjadi baris agar nama dan harga tetap terbaca. Aturan tema, urutan bagian owner, transaksi, harga dan API tidak diubah.
- Pemeriksaan browser memakai pratinjau/data contoh: beranda tiga template pada iframe viewport 390 px, halaman pesanan/akun/leaderboard pada 320 px. Lebar isi masing-masing tidak melampaui viewport; scrollbar vertikal browser menyisakan lebar konten 382/312 px. Menu Nebula diuji buka/tutup tombol yang sama dan dropdown Aurora diperiksa. Navigasi desktop Nebula/Aurora/Atlas tetap muncul. Ini verifikasi browser pada ukuran mobile, bukan uji perangkat fisik atau pembayaran nyata.
- Build TypeScript/Vite berhasil; 30 tes frontend existing lulus. Tidak ada perubahan backend, migrasi atau dependensi. Bukti beranda: apps/web/storage/framework/testing/screenshots/storefront-mobile-three-templates.png. Server dan berkas harness sementara dibersihkan setelah pemeriksaan.


### Pencarian storefront memuat hasil saat digulir — 22 Oktober 2026

- Dropdown pencarian bersama Aurora/Nebula/Atlas sekarang mengambil halaman berikutnya ketika penanda bawah mendekati area terlihat. Endpoint /search mempertahankan kata kunci dan lingkup toko, dengan batch maksimal lima kategori dan delapan produk, urutan nama/ID stabil dan penanda has_more tanpa menghitung seluruh hasil.
- Hasil tambahan ditambahkan sebagai kelompok berikutnya agar posisi hasil sebelumnya tidak bergeser. ID duplikat diabaikan. Permintaan lama dibatalkan saat kata kunci berubah/unmount; guard request mencegah permintaan halaman yang sama berjalan bersamaan. Cache lokal singkat juga menyimpan halaman yang telah dimuat. Pencarian tidak menyinkronkan supplier pada setiap ketikan.
- Pemuatan lanjutan menampilkan spinner dan teks Memuat hasil lainnya, minimal 300 ms agar indikator dapat terlihat; kelompok baru muncul dengan transisi singkat, mengikuti prefers-reduced-motion. Kegagalan halaman tambahan mempertahankan hasil lama dengan tombol Coba lagi. Setelah habis, ditampilkan keterangan seluruh hasil yang cocok sudah tampil. Tidak memakai tombol prev/next.
- Validasi backend StorefrontSearchTest: empat tes lulus/124 assertions, termasuk 21 kategori/produk lintas halaman tanpa pengulangan, relevansi kata kunci baru, isolasi toko, produk nonaktif, akhir daftar, parameter halaman invalid, dan tidak ada HTTP supplier. Tes frontend existing 30/30, Pint, build TypeScript/Vite dan diff whitespace lulus.
- Browser pada toko fixture terpisah memverifikasi hasil Demo bertambah 8 → 16 → 23 produk melalui scroll, indikator loading, akhir daftar, serta perubahan ke Spotify tanpa membawa hasil Canva/Telkomsel sebelumnya. Tidak menggunakan transaksi/pembayaran nyata atau mengubah katalog toko aktif. Bukti: apps/web/storage/framework/testing/screenshots/storefront-search-infinite.png. Tidak ada migrasi/dependensi.


### Gabungkan hasil infinite scroll pencarian — 22 Oktober 2026

- Mengganti pembagian kelompok per halaman pada entri sebelumnya: hanya satu judul Kategori dan satu judul Produk. Hasil baru masuk ke kelompok masing-masing dan jumlah pada judul merupakan jumlah kumulatif; tidak lagi ada Produk lainnya/Kategori lainnya.
- Urutan keyboard mengikuti daftar gabungan. Item aktif dipertahankan, dan posisi item terlihat disimpan/dipulihkan saat tambahan kategori masuk sebelum produk, agar scroll tidak meloncat. Guard pembatalan, anti-duplikasi, loading dan retry tetap digunakan.
- Build TypeScript/Vite berhasil. Browser fixture membuktikan satu kelompok Produk bertambah 8 → 16 → 23, lalu akhir daftar, tanpa membuat kelompok per halaman. Bukti: apps/web/storage/framework/testing/screenshots/storefront-search-merged.png. Tidak ada perubahan backend, dependensi atau migrasi.


### Urutan harga produk storefront — 22 Oktober 2026

- Daftar katalog beranda Aurora/Atlas, termasuk filter kategori, menggunakan sale_price naik lalu ID sebagai urutan stabil sebelum pagination. Endpoint halaman kategori bersama sudah memakai urutan tersebut dan dipertahankan untuk semua template. Urutan kartu kategori, trending dan pencarian navbar tidak diubah.
- Produk contoh pada pratinjau template juga diurutkan numerik berdasarkan harga sebelum filter/pagination lokal, bukan mengurutkan string rupiah.
- Regresi NebulaStorefrontTest lima tes lulus/252 assertions. Skenario baru menguji enam produk dengan nama/harga yang berbeda urutannya, halaman kategori Nebula/Aurora/Atlas, serta filter kategori beranda Aurora/Atlas lintas dua halaman. Pint dan build TypeScript/Vite berhasil. Tidak ada migrasi/dependensi atau perubahan harga produk tersimpan.


### Quick order Aurora lebih sederhana — 22 Oktober 2026

- Mengganti judul/pengantar besar dengan judul ringkas Pesan cepat (judul custom owner tetap dipakai). Nomor HP dan pilihan provider disusun sejajar, tanpa label deteksi berulang. Provider tetap terdeteksi otomatis dan dapat dipilih manual.
- Kartu pilihan nominal diganti dropdown Produk / nominal, dengan nama/harga serta opsi produk tidak tersedia yang nonaktif. Navigasi halaman produk existing dipertahankan bila masih ada halaman berikutnya. Total hanya muncul sesudah memilih produk dan berada sejajar dengan tombol Lanjutkan. Penjelasan panjang dan placeholder total kosong dihilangkan; validasi serta error tetap terlihat.
- Padding, jarak antarkontrol dan empty state dipadatkan; input dan tombol mobile tetap minimal 44 px. Alur pengambilan produk, harga, normalisasi nomor dan checkout existing tidak berubah.
- Build TypeScript/Vite berhasil. Browser pratinjau desktop dan iframe mobile 390/320 px memverifikasi form awal sekitar 336 px, saat produk dimuat sekitar 367 px, tanpa overflow halaman. Nomor simulasi mendeteksi Telkomsel, pilihan nominal menampilkan harga, dan pemilihan Rp11.000 menampilkan total serta mengaktifkan Lanjutkan. Tidak mengirim pesanan/pembayaran nyata. Bukti: apps/web/storage/framework/testing/screenshots/aurora-quick-compact-mobile.png.


### Dropdown quick order memakai scroll bertahap — 22 Oktober 2026

- Menggantikan native select dan tombol prev/next pada quick order dengan PickerDropdown mengambang yang dipakai bersama komponen proyek. Pilihan berbentuk radio dalam area gulir terbatas, dengan nama/harga/status dan warna mengikuti tema storefront. Memilih produk menutup dropdown, memperbarui ringkasan dan mempertahankan kontrol checkout existing.
- Saat mendekati bagian bawah, halaman berikutnya dari endpoint quick-order/products ditambahkan ke daftar yang sama. Lima produk per permintaan, ID duplikat diabaikan, dan selected tidak dibersihkan oleh pemuatan halaman berikutnya. Guard request mencegah permintaan ganda; pergantian provider/layanan membatalkan request lama dan mereset pilihan/daftar.
- Spinner Memuat produk lainnya tampil minimal 500 ms (permintaan dan timer berjalan bersamaan). Kegagalan mempertahankan daftar lama dengan tombol Coba lagi; akhir daftar menampilkan jumlah produk tanpa paging. Data pratinjau diperluas menjadi 12 produk dalam tiga halaman untuk menguji gulir lokal tanpa supplier nyata.
- Browser desktop: lima → sepuluh → dua belas pilihan melalui scroll, indikator loading terlihat, akhir daftar, memilih produk Rp101.000 menutup picker dan mengaktifkan Lanjutkan, buka ulang mempertahankan daftar, pergantian Paket data mereset pilihan. Iframe mobile 390 px memverifikasi dropdown tidak mendorong form dan scroll memuat halaman berikutnya. Bukti: apps/web/storage/framework/testing/screenshots/aurora-quick-infinite-mobile.png.
- QuickOrderTest existing lima tes/69 assertions dan 30 tes frontend lulus; build TypeScript/Vite serta diff whitespace lulus. Tidak ada perubahan backend, migrasi/dependensi, transaksi atau pembayaran nyata.


### Perpustakaan 24 preset warna storefront — 22 Oktober 2026

- Memperluas preset dari tiga menjadi 24: delapan Terang, delapan Gelap dan delapan Pastel. Midnight, Daylight serta Forest beserta nilai warna aslinya dipertahankan. Tambahan mencakup Ocean, Sapphire, Ruby, Amber, Espresso, Graphite, Obsidian, Deep Ocean, Night Forest, Amethyst, Ember, Cyber, Arctic, Lavender, Rose, Mint, Peach, Sky, Butter, Periwinkle dan Sand.
- Modal Identitas & tema warna menyediakan filter Semua/Terang/Gelap/Pastel, empat contoh warna tiap preset, indikator pilihan dan daftar dalam area scroll terbatas. Grid empat kolom di desktop dan dua di mobile. Memilih preset memperbarui primary dan enam token warna serta pratinjau, sementara tiap warna tetap dapat disunting. Metadata kelompok hanya untuk UI; tidak ditambahkan ke payload desain. Tema berlaku bersama pada Aurora/Nebula/Atlas.
- Tidak mengganti tema toko yang sudah tersimpan. Alur Terapkan ke desain dan Simpan desain tetap digunakan. Tidak ada perubahan backend, migrasi atau dependensi.
- 31 tes frontend lulus, termasuk pemeriksaan seluruh 24 nama unik, delapan preset per kelompok, kelengkapan/validitas warna, pemetaan CSS, kontras teks utama/pendukung/aksen terhadap background/kartu serta teks tombol terhadap primary minimal 4,5:1. Ini uji kombinasi token, bukan audit aksesibilitas seluruh komponen. Build TypeScript/Vite dan diff whitespace lulus.
- Browser memakai panel toko fixture terpisah: filter Pastel/Gelap masing-masing delapan, memilih Lavender memperbarui tujuh warna dan pratinjau tanpa menyimpan desain. Bukti perpustakaan: apps/web/storage/framework/testing/screenshots/theme-presets-library.png. Tidak mengubah tema toko aktif milik user.


## 2026-10-27 — Panel mobile lebih ringkas

- Tambahkan workspace-mobile.css pada bundle CSS awal, dibatasi ke workspace panel <=700px, sehingga admin/owner/staff memakai aturan yang sama dan storefront tetap memakai layoutnya sendiri.
- Header 60px, konteks nama toko lebih jelas, pemilih tema menjadi satu tombol dropdown di mobile; desktop tetap memiliki tiga pilihan tema.
- Drawer navigasi maksimal 310px atau lebar layar dikurangi 40px, padding/link/sidebar footer lebih rapat. Drawer tetap scroll, Escape dan focus trap existing tetap dipakai.
- Judul, kartu ringkasan, langkah onboarding, tab, toolbar, tombol, baris tabel, serta modal diringkas melalui jarak/padding. Input tetap 16px agar tidak memicu zoom otomatis pada ponsel. Tabel besar tetap digeser di wadahnya tanpa melebarkan halaman.
- FilterBar panel mempertahankan pencarian/Terapkan/Reset di mobile; jenis/status/jumlah data dibuka melalui tombol Filter dengan indikator jumlah filter aktif. Filter tambahan yang sebelumnya memakai details terbuka dalam satu langkah. Pagination atas memakai tombol panah dengan aria-label tetap tersedia. Desktop tidak menampilkan tombol Filter mobile.
- Validasi build web dan 32 tes frontend lulus. Browser memakai router/data SQLite simulasi terisolasi pada port8010, bukan mengubah data toko nyata: owner dan admin/staff, drawer admin, modal tambah produk tanpa menyimpan, filter staff/per_page5, lebar320/390 serta desktop1440, tema terang/gelap. Lebar dokumen terukur tidak melampaui viewport; tombol mobile terverifikasi tersembunyi di desktop. Tidak melakukan pembayaran/sinkronisasi atau mengubah izin akun nyata.
- Bukti visual: panel-mobile-compact.png pada direktori visualisasi sesi. Server/tab pengujian dan viewport sementara ditutup/dipulihkan setelah verifikasi. Tidak ada perubahan dependency/migrasi database.

## 2026-11-02 — Label dan scroll sidebar

Label menu/sidebar footer panjang membungkus pada mode expanded; lebar desktop 272px/256px, collapsed tetap 80px. Posisi scroll dipisah per toko/peran dan mode tampilan, dipulihkan setelah accordion selesai dirender, serta tidak menimpa scroll pengguna. Kondisi grup aktif dipertahankan saat navigasi. Browser owner/staff/admin, desktop dan mobile 320/390px telah diperiksa dengan data fixture terisolasi. Rincian pengujian dan bukti ada pada FOUNDATION_VALIDATION.md.
