# Modul supplier OtomaX — hasil implementasi

Tanggal: 21 Oktober 2026. Lokasi: `apps/web`. Checkpoint sebelum perubahan: `0ff49bd`. Perubahan tahap ini belum dibuat commit baru.

## Yang selesai

- Menu **Produk & harga → Supplier OtomaX** untuk owner/staff: koneksi, kelompok jawaban, profil impor, riwayat impor, tiket deposit dan callback. Daftar utama memakai filter serta pagination atas/bawah; tambah/edit memakai modal. Pemilih koneksi/profil mencari data melalui dropdown floating dengan pemuatan bertahap.
- Beberapa koneksi OtomaX per toko, masing-masing nama, alamat IP/domain dan port, member ID, PIN/password, path transaksi/saldo/tiket/status, IP callback dan batas usia katalog. Credential terenkripsi, tidak dikirim dalam props halaman. Field PIN/password kosong pada edit mempertahankan nilai lama. Endpoint hanya menuju IP publik, resolusi DNS dipin dan redirect ditolak.
- Signature SHA-1 **binary**, Base64 URL-safe tanpa padding. Menggunakan formula OtomaX transaksi, CheckBalance, ticket; produk dikirim dalam huruf kapital. RefID aplikasi/attempt dan ID transaksi supplier dipisahkan.
- Kelompok jawaban reusable dalam toko, banyak pola per jenis/status, duplikasi/edit/hapus profil, placeholder terbatas, mapping status query maupun `[status]` teks, serta alat uji tanpa transaksi. Pola ambigu, status bertentangan atau asing tidak menjadi sukses/gagal pasti. Perubahan profil memakai pemeriksaan versi; pesanan lama menyimpan salinan profil/credential.
- Impor XLSX/CSV melalui **PhpSpreadsheet 5.10.0**, tanpa perlu mengubah file supplier. Profil menyimpan sheet, posisi header (0 untuk file tanpa header), kolom kode/nama/modal/status/kategori/layanan/stok, baris kelompok, format angka, pemisah CSV/encoding dan mapping status. File tanpa status secara bawaan diimpor nonaktif; tersedia hanya melalui pilihan eksplisit.
- Pratinjau menampilkan jumlah valid/baru/harga berubah/ditolak, sampel 50 baris, dan maksimal 100 alasan penolakan. Harga kosong/nol/rusak, formula, SKU duplikat (termasuk perbedaan huruf kapital) serta data melewati batas ditolak. Nama kelompok dapat digabungkan ke nama produk. Stok tidak dilaporkan disimpan null, bukan stok nol atau klaim stok tanpa batas.
- File maksimal 10 MB, 20.000 baris dan 32 kolom. XLSX dibatasi ukuran ekstraksi 40 MB/500 entri serta 200.000 sel sheet; formula tidak dihitung. File/pratinjau privat, payload terenkripsi. Tidak ada CSV/XLSX supplier atau credential asli yang dimasukkan ke Git.
- Setelah konfirmasi, job mengimpor bertahap 150 baris dengan lock per koneksi. Mode/credential/akses/purge dan snapshot katalog diperiksa. Impor ulang upsert identitas sumber yang sama; tidak menggandakan produk jual atau menimpa markup/status produk jual. Kegagalan tidak mengklaim selesai; chunk yang sudah masuk dapat diproses ulang tanpa duplikasi.
- Produk lama yang tidak ada pada file secara bawaan dipertahankan; opsi nonaktif hanya tersedia untuk file lengkap tanpa baris ditolak. Katalog OtomaX kemudian dapat diimpor ke toko lewat **Sumber produk**: semua/per layanan/per kategori/pilihan massal. Prefix kode produk jual `O-`, dengan penanganan benturan kode existing. Bot dan web memakai katalog/parsing yang sama.
- Mesin pesanan bersama mendukung OtomaX. Marker dispatch dan waktu kirim pertama ditulis sebelum HTTP; timeout/hasil belum pasti tetap pending. Revisi 22 Oktober: retry request identik dengan RefID sama diizinkan pada hari kirim pertama (WIB) jika tidak ada endpoint status. Parsing termurah mengikuti mesin existing; pindah hanya setelah gagal pasti, dan parsing terakhir gagal menutup pesanan/refund sesuai kebijakan wallet/guest.
- Callback GET/POST diperiksa token per koneksi, IP, store/connection, refID, serta produk/tujuan jika tersedia. Pembaruan status/SN sesudah sukses/gagal/manual memakai rekonsiliasi refund existing, termasuk saldo minus yang menghalangi transaksi baru. Sukses dari parsing lama setelah parsing lain dikirim masuk review untuk kemungkinan transaksi ganda.
- Saldo memakai cache akun/lingkungan; tiket deposit punya request ID idempoten dan riwayat instruksi. Tiket hanya meminta instruksi, tidak mentransfer uang atau menandai pembayaran lunas. Sandbox tidak menghubungi supplier nyata.
- Izin staff terpisah: suppliers.manage/responses/import/deposit/balance. Riwayat tiket membutuhkan izin deposit. Akses lintas toko ditolak. Paket sumber PPOB sendiri (Orbit/Galaxy internal) mendukung OtomaX; Space stok premium saja tidak.
- Dua migrasi sudah diterapkan pada MySQL lokal setelah dump backup: struktur modul/slot koneksi dan longText encrypted config/payload/snapshot agar file ribuan produk tidak tertahan batas TEXT MySQL. Indeks store_id dipertahankan untuk foreign key. Rollback struktur ditahan jika masih ada beberapa koneksi sejenis dalam toko.
- Retensi toko mencakup tabel, file privat OtomaX dan job terkait. Scheduler membuang file/payload pratinjau kedaluwarsa; riwayat ringkas tetap tersedia sampai lifecycle toko berakhir.

## Cara mulai

1. Buka toko dengan paket sumber PPOB sendiri, lalu **Produk & harga → Supplier OtomaX**.
2. Buat **Kelompok jawaban**. Tersedia contoh sukses callback, respons awal pending dan saldo yang ditambahkan secara eksplisit lewat Tambah penangkap; sesuaikan dengan jawaban supplier. Tambahkan beberapa pola sukses/gagal/pending jika formatnya berbeda. Uji contoh teks dan query tanpa transaksi sebelum dipakai.
3. Tambahkan koneksi, pilih kelompok, isi akun IP Center dan IP pengirim callback. Path awal `/trx`, `/balance`, `/ticket`; path status kosong. Uji saldo, lalu salin URL callback lengkap dan daftarkan sebagai URL Report pada supplier. Pastikan APP_URL publik/ngrok sesuai dan whitelist IP keluar VPS di supplier bila perlu.
4. **Impor file** → pilih koneksi → upload XLSX/CSV asli. Cocokkan pengaturan kolom bila format berbeda, simpan profil untuk penggunaan berikutnya, dan periksa pratinjau. Setujui impor sebagian hanya setelah membaca alasan penolakan.
5. Jalankan worker dari apps/web: `php artisan queue:work --timeout=180`; scheduler lokal `php artisan schedule:work`. Default retry_after database/Redis kini 240 detik; jika environment mengisi nilai sendiri, pastikan lebih besar dari timeout 180 detik. Jangan menjalankan antrean produksi memakai driver array untuk lock/cache.
6. Setelah riwayat impor selesai, buka **Produk sumber**, filter koneksi OtomaX, lalu impor ke katalog toko. Atur markup/parsing seperti sumber lain. Perbarui harga/stok dengan impor ulang ekspor terbaru.

## Bukti validasi

- Seluruh backend: **423 tes, 420 lulus, 3 dilewati**, 5.629 assertions. Tes yang dilewati membutuhkan prasyarat khusus, termasuk file ekspor opsional.
- OtomaX dengan OTOMAX_SAMPLE_XLSX menunjuk file pengguna: **21/21 lulus**, 5.722 assertions. Mencakup signature, field/parser konflik, SKU duplikat, tanpa header/status, formula, sumber stok-only, credential terenkripsi, izin/isolasi, impor ulang/markup, mode berubah, sandbox/no HTTP, callback/refund idempoten, saldo minus dan parsing terakhir gagal.
- Frontend existing: **21/21 lulus**; typecheck dan build produksi berhasil. Pint dirty lulus. Build masih memberi peringatan aset hero Nebula yang sudah ada sebelumnya; bukan kegagalan build OtomaX.
- Browser menggunakan SQLite simulasi terpisah: buat kelompok, uji parser dan lihat semua field, tambah koneksi dummy, pilih dropdown, upload file Webreport asli dan konfirmasi impor sebagian. **5.608 valid berhasil diimpor, 791 ditolak**. Source menampilkan 5.608 data pada filter koneksi. Diuji pada 1280×800 dan 390×844, tema terang/gelap, serta modal/dropdown. Pengujian browser tidak mengirim request supplier nyata; job impor fixture dijalankan sync untuk memeriksa hasil.
- MySQL lokal: dua migrasi Ran; backup privat `storage/app/private/backups/before-otomax-20261021-125255.sql`. File workbook asli tidak diubah. Data simulasi tidak dimasukkan ke database toko pengguna.

## Batas integrasi nyata

- Belum dilakukan transaksi, cek saldo, tiket deposit atau callback dari supplier nyata oleh agent. Seluruh HTTP tes dipalsukan/diblokir. User sebelumnya membuktikan respons awal dan callback supplier dalam percakapan; ini dasar preset, bukan bukti seluruh konfigurasi supplier universal.
- Belum ada endpoint katalog IP Center universal yang terverifikasi. Impor file adalah sumber snapshot harga/status; tidak ada auto-fetch katalog, scraping Webreport atau janji stok realtime. Batas usia katalog dapat diatur 1–168 jam (bawaan 24), setelah lewat perlu impor baru agar produk dapat dipakai.
- Tidak ada jaminan batas harga beli di protokol supplier contoh. Checkbox koneksi menjelaskan risiko harga impor yang sudah berubah. Harga hasil transaksi yang tidak aman masuk penanganan review existing, bukan membatalkan transaksi supplier yang sudah terjadi.
- Scope awal prabayar/PPOB dengan tujuan tunggal. Inquiry/pay pascabayar khusus OtomaX, input multi-parameter dan endpoint katalog khusus supplier belum diimplementasikan.
- Endpoint status opsional hanya aman bila supplier menjamin read-only dan mendukung `cek=1`. Bila tidak, biarkan kosong dan andalkan callback atau keputusan manual toko. Tidak ada polling `/trx` yang mengirim ulang pembelian.
- Pemetaan kolom dilakukan sekali per format, bukan AI yang menebak seluruh format tanpa tinjauan. Profil dapat dipakai ulang; kategori/layanan default perlu disesuaikan jika file tidak menyediakan kolomnya.
- Callback yang tidak cocok pola direkam unmatched bila attempt dapat dikenali; callback tanpa referensi yang dapat dikorelasikan diabaikan. Riwayat callback menampilkan metadata/status, belum menyediakan editor event mentah atau replay. Tidak ada retensi umur khusus untuk event selain purge toko.
- Profil dan koneksi tidak dibagikan lintas toko; beberapa supplier dalam toko yang sama dapat memakai kelompok/profil yang sama. Koneksi dinonaktifkan melalui edit agar koreksi callback pesanan lama tetap dapat diterima.


## Revisi editor penangkap jawaban — 21 Oktober 2026

Keluhan: saat memilih penangkap pending lalu menyimpan/membuka kelompok, editor kembali ke sukses OtomaX. Data seluruh pola sebenarnya tetap tersimpan; openProfile selalu mengatur ruleIndex=0. Pengujian juga memakai event callback bawaan walaupun editor sedang menampilkan respons awal transaksi.

Perbaikan:
- Pilihan editor disimpan per toko/kelompok pada sessionStorage, dengan fallback memori jika storage ditolak. Pencocokan nama/event/template mempertahankan pilihan ketika urutan penangkap berubah; data browser rusak atau penangkap dihapus kembali ke penangkap valid.
- Kelompok baru dimulai tanpa penangkap otomatis. Pilih contoh atau buat sendiri, lalu klik **Tambah penangkap**. Kelompok existing mempertahankan semua pola sebelumnya.
- Form dibagi menjadi nama kelompok, kumpulan penangkap dan uji contoh. Jumlah penangkap aktif/total dan jumlah yang ikut disimpan ditampilkan. Pemilih **Penangkap yang sedang diedit** hanya mengganti editor, bukan memilih satu pola untuk aktif. Dropdown floating dapat dicari dan discroll.
- Pengujian otomatis mengikuti event penangkap yang sedang diedit. Label menampilkan Balasan awal transaksi/Callback/Balasan saldo, serta Berhasil/Gagal pasti/Sedang diproses. Pengaturan query/status lanjutan dipisahkan dalam bagian lipat.
- Simpan ditahan jika belum ada penangkap atau nama/template belum lengkap; data profil lama tidak dihapus.

Validasi: 24/24 tes frontend lulus termasuk tiga regresi editor baru. Tes backend OtomaX 20 lulus, satu tes file eksternal opsional dilewati. Typecheck/build lulus. Browser fixture terisolasi: pilih pending dari kelompok tiga pola → uji teks pending berhasil → simpan → refresh → edit tetap pending dan ketiga pola tetap ada; tambah kelompok baru dengan satu contoh pending juga berhasil disimpan. Tidak ada transaksi/request supplier nyata atau perubahan data toko pengguna.


## Penangkap seperti konsep OtomaX & alat uji terpisah — 22 Oktober 2026

Referensi primer yang dibaca:
- https://www.otomax-software.com/kb/jawaban-dari-provider/ — kelompok jawaban, status, awalan/akhiran, ketelitian spasi dan penguji terpisah.
- https://www.otomax-software.com/en/552/how-to-catch-successful-response-of-vodafone-india/ — prioritas serta named group tujuan/SN pada RegEx.

Implementasi mengikuti konsep tersebut, bukan menyalin seluruh fitur atau engine .NET OtomaX:
- Penangkap baru dapat memakai **Awalan & akhiran**: kata kunci, field yang ditangkap, teks sebelum/sesudah nilai, wajib/opsional, sensitivitas huruf dan prioritas. Isian tiap field dilipat agar modal tetap ringkas. Spasi awalan/akhiran tidak dipangkas middleware.
- Pilihan **RegEx PCRE** menerima named group canonical seperti tujuan, sn, refid, id_transaksi dan harga. Ukuran, nama group, recursion/control verb, backtracking dan kedalaman dibatasi. Jangan menganggap semua ekspresi .NET atau nama parameter OtomaX otomatis identik dengan DigitalToko; pilih nama field yang disediakan. RefID pesanan berbeda dari ID transaksi supplier.
- **Template lengkap** tetap tersedia untuk data lama; kelompok atau snapshot pesanan existing tidak dikonversi diam-diam. Contoh baru sukses/pending/saldo memakai builder awalan–akhiran.
- Prioritas angka lebih kecil diperiksa lebih dahulu. Data/status yang bertentangan tetap pending untuk ditinjau, bukan memilih satu hasil finansial secara diam-diam. Tombol urutan juga menyelaraskan prioritas.
- Uji contoh di dalam modal profil dihapus. Menu **Supplier OtomaX → Penguji jawaban** membuka `/stores/{store}/otomax/tester`, terpisah dari editor. Pilih kelompok tersimpan dan jenis respons, tempel pesan/query, lalu uji.
- Halaman uji menampilkan ringkasan pengenalan/status, tabel field/nilai/sumber, penangkap yang cocok atau alasan tidak cocok/dilewati, serta sorotan data pada teks asli. Sorotan memakai posisi Unicode agar emoji tidak menggeser field. Query-only status ditandai secara eksplisit.
- Halaman uji dan endpoint dibatasi izin suppliers.responses, entitlement dan isolasi toko. Pengujian offline tidak membuat transaksi, mengubah status, mengirim request supplier atau menyimpan perubahan profil.
- Penangkap wajib dilengkapi sebelum menyimpan. Untuk mencoba perubahan, simpan kelompok lalu muat ulang kelompok pada alat penguji; hasil uji bukan callback transaksi nyata.

Tidak ada dependency atau migrasi baru untuk revisi ini.

Validasi akhir: suite backend 430 tes (427 lulus, 3 tes bersyarat dilewati), 5.667 assertions. Setelah pengamanan hasil tangkapan kosong, tes khusus OtomaX dijalankan ulang: 27 lulus, 1 dilewati, 150 assertions. Seluruh 26 tes frontend, typecheck, build produksi dan Pint lulus; git diff --check bersih.

Browser fixture terisolasi: kelompok baru dengan penangkap awalan/akhiran sukses dan pending berhasil disimpan. Penguji terpisah mengenali contoh sukses dengan ID supplier 264447712, SKU CEKGOJEK, tujuan berawalan nol, SN, harga dan RefID TR12342; contoh akan diproses menghasilkan status pending. Tabel hasil dan sorotan diperiksa pada desktop. Pemeriksaan DOM viewport 390px menunjukkan panel bertumpuk dan tidak ada overflow horizontal halaman. Screenshot desktop disimpan di apps/web/storage/framework/testing/screenshots/otomax-tester.png. Tidak ada request supplier atau transaksi nyata yang dikirim; data pengujian memakai fixture terisolasi.


## Alur editor penangkap lebih ringkas — 22 Oktober 2026

- Modal dibagi menjadi ringkasan kelompok, pemilihan contoh, dan pengaturan satu penangkap. Daftar, contoh dan seluruh editor tidak lagi tampil bersamaan.
- Tambah penangkap menampilkan pilihan contoh sukses, pending, saldo, tiket deposit atau format sendiri. Contoh tiket membaca amount dari `Silakan Transfer Rp. [nilai] (HARUS SAMA)` dengan hasil pending, bukan konfirmasi deposit lunas.
- Editor memakai dua bagian: jenis/hasil jawaban dan data tangkapan. Label pembatas menjadi Teks sebelum nilai/Teks setelah nilai; ringkasan tiap field memperlihatkan pembatas dan [nilai]. Prioritas dan sensitivitas huruf berada pada Opsi lanjutan yang dilipat.
- Selesai mengatur kembali ke ringkasan; Simpan kelompok menyimpan seluruh konfigurasi. Kembali tetap mempertahankan isian dalam modal; Batal membuang perubahan yang belum disimpan. Penguji jawaban tetap menjadi alat terpisah.
- Pemilih penangkap tetap dropdown floating dengan pencarian dan scroll. Ringkasan menyediakan tombol Edit penangkap dan Lengkapi jika format belum lengkap. Form kosong menahan penyimpanan; konfigurasi lama dan versi pesanan dipertahankan.
- Pergantian tampilan mengembalikan scroll modal ke atas dan memfokuskan isian/tombol pertama.

Validasi: 27 tes frontend lulus termasuk contoh tiket yang lengkap, status pending dan salinan independen; typecheck/build produksi lulus. Browser fixture terisolasi: tambah contoh tiket → selesai mengatur → simpan → buka kembali mempertahankan data; tambah format kosong menonaktifkan Selesai mengatur dan Simpan kelompok, lalu Batal tidak menyimpan format tersebut. Modal dan pilihan contoh diperiksa desktop serta viewport 390px; lebar konten modal tidak melampaui area scroll. Tidak ada request supplier/transaksi nyata. Screenshot ringkasan: apps/web/storage/framework/testing/screenshots/otomax-group-compact.png. Tidak ada dependency atau migrasi baru.


## Perbaikan ukuran paket MySQL saat pratinjau Excel — 22 Oktober 2026

Masalah: controller menyimpan seluruh hasil normalisasi rows/errors dalam payload encrypted:array. Enkripsi/base64 memperbesar data, sehingga UPDATE otomax_imports dapat melewati max_allowed_packet walaupun kolom sudah LONGTEXT.

Perbaikan:
- Baris normalisasi disimpan sebagai berkas terenkripsi pada disk local privat: otomax/{store}/previews/{import}/{uuid}.enc. Database hanya menyimpan metadata kecil, referensi dan hash berkas. Error pratinjau tetap dikembalikan terbatas 100 sampel; seluruh error tidak lagi dimasukkan ke SQL.
- Berkas dibatasi ke toko/impor, diverifikasi hash dan isi identitas sebelum dipakai. Berkas hilang/rusak membuat impor gagal sebelum produk ditulis. Pratinjau lama yang masih menyimpan rows dalam database tetap dapat dibaca.
- Pratinjau ulang membuat berkas baru, memperbarui referensi dengan lock database, lalu membersihkan berkas versi sebelumnya. Gagal menyimpan metadata membersihkan berkas baru. Pembersihan terjadwal serta impor selesai menangani unggahan dan berkas normalisasi; direktori mengikuti purge toko.
- Ringkasan status dibatasi 50 label (maksimal 120 karakter per label), agar input status yang beragam tidak membentuk ringkasan SQL tak terbatas. Angka valid/rejected tetap dihitung untuk semua baris.
- Penonaktifan produk yang hilang memakai batch 150 ID, menggantikan satu whereNotIn berisi seluruh SKU. Upsert produk juga tetap 150 baris per batch.
- Tidak ada perubahan dependency, migrasi baru, max_allowed_packet, atau data bisnis toko pengguna. File asli di Downloads tidak diubah.

Validasi: 47 tes OtomaX/retensi lulus, 5.927 assertions, termasuk file ekspor List Harga (2026-10-21).xlsx. Regresi XLSX 4.000 produk membuktikan berkas terenkripsi >1 MB namun write packet metadata <32 KB; seluruh produk masuk dan produk lama yang tidak ada dinonaktifkan. Pengujian MySQL opt-in pada database sementara terpisah juga lulus: 6.000 produk, payload metadata <16 KB, tanpa menaikkan batas paket server. Database uji dihapus setelah pengujian. Tes mencakup pratinjau ulang, data rusak, isolasi file dan kompatibilitas format lama; Pint/diff check bersih. Suite besar memakai memory_limit=512M khusus proses pengujian karena pembuatan workbook dalam satu proses menyentuh batas CLI 128 MB; konfigurasi runtime aplikasi tidak diubah.

Operasional: restart worker queue/scheduler yang berjalan lama setelah deploy source. Unggah ulang file yang gagal agar dibuat pratinjau baru. Worker dan web wajib memakai disk local privat yang sama/persistent volume bersama; jangan menaruh berkas preview pada disk publik. Scheduler membersihkan pratinjau kedaluwarsa sesuai lifecycle yang ada.


## Pembungkusan nama sumber & edit massal kategori — 22 Oktober 2026

- Tabel Sumber produk memakai layout kolom tetap dan teks normal yang membungkus, termasuk nama/kode produk serta layanan/kategori. Nama panjang tidak melintasi sel sebelah; tabel tetap dapat discroll horizontal pada layar kecil.
- Menu Layanan & kategori → Kategori utama menambahkan checkbox per kategori/pilih semua halaman, jumlah pilihan, tombol Edit massal dan Batal pilih. Pilihan dibersihkan saat data halaman/tab/layanan berubah; hanya kategori pada halaman aktif yang dipilih.
- Modal massal menyediakan perubahan layanan, provider, dan tujuan pesanan (jenis, kolom game, nickname, format). Pengaturan tidak dipilih tetap dipertahankan. Tujuan non-game menyembunyikan pengaturan game; gambar/nama kategori tidak ikut diganti.
- POST /stores/{store}/categories/bulk dibatasi products.manage dan toko operasional, maksimal 100 ID unik, kategori/layanan scoped ke toko. Transaksi mengunci toko/kategori dan menolak seluruh perubahan jika nama bentrok, provider tidak sesuai layanan, atau kolom game bertentangan dengan validasi nickname.
- Perpindahan layanan memperbarui service_id kategori dan layanan semua produk katalog terkait, kemudian menghitung ulang harga sesuai aturan existing. Identitas kategori dan aturan kategori/produk tetap. Provider dihapus bila pindah ke layanan tanpa quick order; perubahan provider eksplisit hanya boleh pada Pulsa/Paket Data/Voucher Data. Data sumber supplier tidak ditulis ulang.

Validasi: 22 tes taxonomy/destination lulus (333 assertions), termasuk perpindahan produk, preservasi gambar/field lain, penghapusan provider terarah, rollback konflik, akses staff dan isolasi toko. Seluruh 27 tes frontend dan build/typecheck lulus; Pint/diff check bersih. Browser memakai fixture terisolasi: dua kategori berhasil dipindah ke Pulsa sekaligus dan mendapat provider Telkomsel + tujuan Nomor HP. Nama panjang sumber OtomaX membungkus menjadi beberapa baris tanpa keluar dari sel (lebar teks 319px, sel 347px). Modal diperiksa desktop dan 390px tanpa overflow horizontal. Screenshot: source-product-wrapping.png dan category-bulk-edit.png pada apps/web/storage/framework/testing/screenshots. Tidak ada perubahan data bisnis toko pengguna, dependency, atau migrasi baru.


## Hapus massal layanan/kategori & kelompok tombol — 22 Oktober 2026

- Kedua tab Layanan dan Kategori utama memiliki checkbox per baris/pilih semua halaman dan Hapus massal. Edit massal tetap khusus kategori. Jumlah pilihan serta Batal pilih mengikuti tab aktif.
- Edit massal, Hapus massal dan Batal pilih dikelompokkan dalam flex container dengan gap 8px; tidak lagi dibagi space-between ke seluruh lebar toolbar. Layar kecil membungkus tombol secara berdekatan.
- Konfirmasi menyebut jumlah/jenis pilihan. POST /stores/{store}/catalog-structure/bulk-delete memakai products.manage, toko operasional, maksimal 100 UUID unik, scope toko dan lock transaksi.
- Mengikuti aturan hapus satuan: kategori harus tidak memiliki produk, layanan harus tidak memiliki kategori. Satu pilihan masih dipakai/tidak valid menolak seluruh penghapusan. Tidak menghapus produk atau kategori anak secara berantai.
- Penghapusan kategori membersihkan pengaturan gambar/provider/tujuan/game terkait; gambar yang masih dipakai kategori atau desain lain dipertahankan.

Validasi: 19 tes taxonomy lulus (299 assertions), termasuk hapus kategori/layanan kosong, blokir campuran kosong/terpakai, isolasi toko, izin staff dan file gambar bersama. 27 tes frontend, typecheck/build dan Pint lulus; diff check bersih. Browser fixture: pemilihan kategori dan layanan, modal konfirmasi sesuai jumlah/jenis, Batal mempertahankan pilihan, serta toolbar desktop/390px diperiksa. Tidak ada penghapusan melalui browser pada data toko pengguna. Bukti toolbar: apps/web/storage/framework/testing/screenshots/taxonomy-bulk-actions.png. Tidak ada dependency/migrasi baru.


## Perbaikan hydration atribut sidebar — 22 Oktober 2026

Sumber mismatch ditemukan pada initializer SidebarSection: server tanpa sessionStorage menghasilkan accordion tertutup, sedangkan browser dengan preferensi open langsung menghasilkan aria-expanded/class panah/hidden berbeda. Komponen dipisahkan ke SidebarSection.tsx; render awal kini hanya memakai status menu aktif yang tersedia pada server/client. Preferensi sessionStorage dibaca lewat layout effect sesudah hydration; toggle tetap menyimpan pilihan. Tidak menambahkan suppressHydrationWarning atau menonaktifkan SSR.

Validasi: dua tes render komponen nyata membandingkan HTML awal dengan/tanpa sessionStorage open serta atribut menu aktif/collapsed; seluruh 29 tes frontend dan typecheck/build lulus. Browser fixture memverifikasi accordion Penjualan yang tidak aktif tetap terbuka setelah reload, tanpa error/warning pada log browser. Fixture browser memakai SSR nonaktif, sehingga kesetaraan HTML SSR dibuktikan melalui tes render, bukan klaim hydration SSR end-to-end di browser pengguna. Screenshot sidebar-hydration-fixed.png tersimpan di storage/framework/testing/screenshots. Tidak ada dependency/migrasi baru. Acuan React: https://react.dev/reference/react-dom/client/hydrateRoot#handling-different-client-and-server-content .


## Toggle dropdown penangkap — 22 Oktober 2026

PickerDropdown sebelumnya memakai handler click yang memeriksa :popover-open lalu show/hide. Light-dismiss browser pada klik trigger dapat menutup panel sebelum handler click berjalan, sehingga handler justru membukanya kembali. Trigger kini memakai popoverTarget + popoverTargetAction=toggle, agar browser mengenali invoker dan menangani satu aksi toggle. Sinkronisasi aria-expanded, posisi floating, pencarian, klik luar serta penutupan setelah memilih radio tetap dipertahankan. Perbaikan berlaku juga pada pemilih bersama yang memakai komponen ini.

Validasi: 30 tes frontend lulus termasuk kontrak invoker/target popover hasil render komponen nyata; typecheck/build lulus dan diff check bersih. Browser fixture modal OtomaX: klik pertama expanded, klik kedua collapsed, klik luar menutup, pilih pending mengganti ringkasan dan menutup panel. Tidak ada perubahan profil tersimpan atau transaksi nyata. Bukti: apps/web/storage/framework/testing/screenshots/picker-toggle-fixed.png. Tidak ada dependency/migrasi baru.


## Pembaruan modal dari respons transaksi OtomaX — 22 Oktober 2026

- Harga beli positif pada field harga diperbarui otomatis ke supplier_products.cost setelah respons awal/cek status valid dan cocok dengan referensi, SKU serta tujuan yang dikirim. Callback yang lolos autentikasi/korelasi dan parsing snapshot memakai jalur yang sama, termasuk koreksi hasil terminal.
- Pembaruan dibatasi toko, koneksi OtomaX aktif, mode katalog dan SKU yang sama. Member ID/base URL akun saat ini harus sesuai snapshot transaksi; koneksi lain atau toko lain tidak ikut berubah. Callback parsing lama yang sudah ditinggalkan tidak mengubah harga sumber.
- Harga kosong, nol, negatif, tidak dapat dibaca, melampaui Rp1 triliun, respons konflik/unmatched dan sandbox diabaikan. Ini hanya perubahan harga: stok, available dan observed_at snapshot impor tidak diubah/diperpanjang.
- Setelah commit, hitung ulang hanya produk katalog yang memetakan sumber tersebut. Prioritas markup/auto update tetap produk → kategori → layanan. Harga jual manual tetap, parsing tetap memilih kandidat termurah yang tersedia. Nominal invoice/saldo yang sudah dibebankan dan snapshot harga pesanan tidak ditulis ulang.
- Penangkap harus menangkap Harga beli (harga) atau mapping field query price yang benar. Jika harga tidak tertangkap, modal tetap seperti semula. Tidak mengambil harga dari SN/total transfer/saldo. Tidak ada dependency atau migrasi baru.

Regresi baru mencakup respons pending Rp12.000 → modal Rp12.000/harga jual otomatis Rp17.000 pada markup Rp5.000; harga jual manual Rp20.000 tetap, nominal invoice Rp15.000 tetap; callback harga baru dengan markup kategori 10% menjadi Rp13.200 dan pengulangan callback tidak mengubah saldo dua kali; toko lain dengan SKU sama tetap; respons salah SKU, harga invalid/nol dan sandbox tidak mengubah modal asli. Validasi akhir: suite OtomaX, katalog, transaksi pelanggan dan webhook Digiflazz berjumlah 115 tes (114 lulus, 1 sampel file eksternal opsional dilewati), 1.096 assertions. Pint dan diff check bersih. Seluruh pengujian memakai database/HTTP fixture; tidak ada transaksi supplier nyata atau pembaruan harga pada data bisnis toko pengguna. Restart queue worker yang berjalan lama setelah deploy agar jalur transaksi memakai kode baru.


### Pembungkusan teks Katalog & harga — 22 Oktober 2026

- Tabel produk toko memakai lebar kolom tetap dan pembungkusan nama, kode, layanan/kategori, serta keterangan parsing. Teks panjang tidak menimpa kolom di sebelahnya; tombol tindakan tetap terkelompok.
- Pada layar sempit, gulir horizontal tetap berada di pembungkus tabel, bukan melebar ke seluruh halaman.
- Validasi: `npm run build` berhasil. Browser data simulasi menguji nama 110 karakter yang membungkus empat baris (lebar teks 265 px di dalam sel 293 px). Pada viewport 390 px, tidak ditemukan overflow teks dan lebar halaman tetap 382 px. Tidak mengubah data toko asli.
- Bukti visual: `apps/web/storage/framework/testing/screenshots/catalog-product-wrapping.png`.


### Callback OtomaX 404 pada domain ngrok baru — 22 Oktober 2026

- Diagnosis lokal: route GET/POST callback tersedia dan koneksi supplier ditemukan. Request dengan Host ngrok aktif mendapat 404, sedangkan localhost mencapai validasi token (401 untuk token diagnostik salah). Penyebabnya `PLATFORM_DOMAIN_ALIASES` hanya memuat domain tunnel lama.
- Menambahkan host tunnel aktif ke alias di `.env` lokal tanpa menghapus alias sebelumnya, lalu menjalankan `php artisan config:clear`. `.env` tidak dimasukkan ke Git.
- Verifikasi ulang dengan Host ngrok dan token diagnostik salah menghasilkan 401, membuktikan jalur sudah mencapai pemeriksaan callback, tanpa memproses atau mengubah transaksi. Callback asli dari supplier belum diverifikasi ulang.
- Jika domain ngrok berubah, perbarui alias platform dan URL callback di sisi supplier.


### Callback OtomaX 401 melalui ngrok — 22 Oktober 2026

- Log inspeksi ngrok menunjukkan IP asli supplier `45.77.37.181` dan respons aplikasi `IP pengirim tidak diizinkan.` Token cocok dan IP supplier sudah berada pada whitelist koneksi.
- Penyebab: trusted proxies kosong sehingga Laravel membaca koneksi localhost dari ngrok. `.env` lokal diperbarui dengan `TRUSTED_PROXIES=127.0.0.1,::1`, lalu cache konfigurasi dibersihkan. Hanya proxy loopback dipercaya; whitelist supplier dan pemeriksaan token tetap aktif.
- Pemeriksaan HTTP lokal menggunakan host tunnel, token koneksi, serta header IP supplier menghasilkan 200/IGNORED karena sengaja tanpa RefID/transaksi. Header IP lain tetap ditolak 401. Tidak mengirim ulang atau mengubah status transaksi asli. Menunggu callback supplier berikutnya untuk verifikasi end-to-end.


### Pesan supplier lengkap pada rincian transaksi — 22 Oktober 2026

- Percobaan sumber OtomaX menampilkan pesan supplier terbaru secara lengkap, mempertahankan baris baru dan membungkus teks. Pesan respons awal juga disimpan sampai ada callback baru; pencarian mencakup pesan serta kesalahan parser.
- Callback yang sudah lolos token/IP dan ditemukan percobaannya menyimpan pesan meskipun penangkap gagal, lalu menampilkan alasan parsing terpisah. Penyimpanan pesan tidak mengubah aturan status/refund atau fallback supplier.
- Pesan disimpan di snapshot terenkripsi yang sudah tersedia, dengan penguncian baris agar pembaruan tidak menimpa snapshot permintaan. Hanya field pesan dan kesalahan parser dikirim ke panel owner/staff; kredensial tidak dikirim. Storefront tetap memakai pesan status pelanggan. Tidak memerlukan migrasi/dependensi baru.
- Pesan historis yang tidak pernah disimpan tetap memakai keterangan lama; pesan lengkap tersedia setelah respons/callback berikutnya. Worker antrean yang berjalan lama perlu dimuat ulang setelah deployment.
- Validasi: OtomaxTest + CustomerCommerceTest 73 lulus, 1 dilewati (berkas sampel eksternal), 591 assertions. Mencakup respons panjang, callback tidak cocok, pembaruan sukses, isolasi owner, penyimpanan terenkripsi. Pint dan build web lulus. Pemeriksaan browser memakai database simulasi tanpa transaksi supplier nyata. Bukti: `apps/web/storage/framework/testing/screenshots/supplier-full-message.png`.


### Pengiriman ulang OtomaX pada hari yang sama — 22 Oktober 2026

- Sesuai kontrak supplier yang dikonfirmasi owner, pemeriksaan attempt OtomaX pending tanpa endpoint status boleh mengirim ulang request `/trx` identik (produk, tujuan, qty, sign, RefID dan credential snapshot tetap), tanpa membuat attempt/pesanan/debit baru. Pembatasan pemeriksaan minimal satu menit tetap berlaku.
- Waktu pengiriman pertama disimpan sebelum HTTP dan tidak digeser oleh retry. Batas hari memakai Asia/Jakarta. Setelah berganti hari, request transaksi ulang berhenti dan tetap menunggu callback. Attempt lama tanpa marker waktu memakai tanggal pembuatan attempt. Endpoint cek status yang sudah dikonfigurasi tetap diprioritaskan.
- Parser mendukung format standar `sdh pernah jam ... Hrg ..., status ... SN/Ref: ... Trx ... Saldo ... #... R#...` sebagai fallback jika pola kustom belum menghasilkan status. Status, SN, harga, supplier ID, SKU, tujuan dan RefID dipakai oleh validasi mesin existing; konflik status query/teks tetap pending. Pola kustom yang cocok tetap diprioritaskan. Respons lengkap tetap tampil pada panel.
- Validasi: 99 tes lulus, 1 dilewati (sampel XLSX eksternal), 874 assertions pada OtomaxTest, CustomerCommerceTest, DigiflazzWebhookTest. Termasuk URL/RefID identik, jeda retry, satu debit, SN/id supplier, konflik status, pergantian hari WIB walaupun tanggal UTC belum berubah, attempt lama dan callback sesudah hari berganti. Pint dan diff whitespace lulus. Tidak melakukan transaksi supplier nyata atau mengulang transaksi produksi.
- Tidak ada migrasi/dependensi baru. Restart worker antrean yang masih memuat source lama setelah deployment.


### Kirim ulang pemasok hanya melalui tombol panel — 22 Oktober 2026

- Menggantikan polling/pengiriman ulang otomatis: `CustomerFulfillment::claim` tidak lagi mengambil attempt remote yang sudah memiliki waktu pengiriman. Scheduler, antrean, dan pemuatan/refresh storefront tidak mengirim ulang attempt pending. Pemrosesan pembayaran, pengiriman pertama, pengiriman stok, serta fallback ke parsing yang belum pernah dikirim setelah gagal pasti tetap bekerja.
- Pada rincian transaksi owner/staff tersedia tombol **Kirim ulang ke pemasok**, izin `orders.manage`, terpisah dari Periksa pembayaran dan kirim ulang hasil/notifikasi. Validasi server membatasi transaksi produk lunas dan sumber terakhir yang sudah dikirim, isolasi toko, lock bersama callback, jeda satu menit, batas usia Digiflazz dan batas hari OtomaX. Referensi, produk, tujuan dan snapshot credential tetap.
- Respons terbaru menerapkan status/SN/modal. Pesanan yang sudah sukses/gagal atau diputuskan manual memakai rekonsiliasi status/refund existing; respons tidak pasti tidak menimpa status terminal. Respons identik tidak mendebit/refund dua kali. Callback supplier tetap dapat memperbarui status seperti biasa.
- OtomaX, Digiflazz prabayar/status-pasca, dan Hanshop diuji melalui aksi manual. Tes memastikan scheduler tidak menambah request, staff pembaca ditolak, staff berizin diterima, toko lain ditolak, cooldown, respons gagal manual menjadi sukses dan pembaruan SN.
- Validasi akhir: 102 tes lulus, 1 dilewati (berkas XLSX eksternal), 922 assertions pada OtomaxTest + CustomerCommerceTest + DigiflazzWebhookTest. Pint dan build web lulus. Pemeriksaan visual browser menggunakan data simulasi; tidak melakukan transaksi supplier nyata. Bukti: `apps/web/storage/framework/testing/screenshots/manual-supplier-retry.png`.
- Tidak ada migrasi atau dependensi baru. Restart worker antrean setelah deploy agar tidak memakai perilaku otomatis source lama. Scheduler tetap dijalankan untuk proses lain.


### Sidebar collapsed stabil saat navigasi — 22 Oktober 2026

- Penyebab: WorkspaceLayout dimount ulang dengan state expanded, kemudian memulihkan localStorage ketika transisi lebar sudah aktif sehingga sidebar melebar lalu mengecil saat pindah halaman.
- Menyimpan preferensi collapsed per role dalam memori client agar render navigasi berikutnya langsung memakai ukuran terakhir. Pemulihan storage tetap dilakukan setelah hydration; cache server tidak diisi oleh render SSR.
- Menonaktifkan transisi layout/sidebar hanya selama pemulihan awal, lalu mengaktifkannya setelah satu frame tampilan stabil. Dua requestAnimationFrame dibersihkan ketika unmount agar tidak meninggalkan callback; animasi klik expand/collapse tetap tersedia.
- Validasi: build web dan 30 tes frontend lulus. Browser simulasi menguji Ringkasan → Toko saya → Profil & verifikasi, refresh, serta toggle manual: sidebar collapsed tetap 80 px pada halaman tujuan/refresh, expanded 248 px, dan properti transisi grid tetap aktif setelah pemulihan. Fixture browser memakai CSR; tes frontend hydration existing tetap lulus.
- Bukti: `apps/web/storage/framework/testing/screenshots/sidebar-collapse-stable.png`. Tidak ada migrasi/dependensi baru.


### Nama supplier pada percobaan sumber — 22 Oktober 2026

- Kolom Sumber pada rincian transaksi owner/staff menampilkan `OtomaX - nama supplier`; pencarian mencakup nama tersebut. Nama panjang membungkus di sel sumber dan nilai modal tetap satu baris.
- Nama koneksi disimpan pada snapshot OtomaX untuk pesanan baru, agar riwayat tetap jelas setelah nama koneksi diubah. Attempt lama mengambil nama koneksi existing melalui satu query yang dibatasi store dan jenis OtomaX; jika tidak tersedia tetap menampilkan OtomaX. Tidak mengekspor kredensial/snapshot ke frontend.
- Validasi: 40 tes OtomaX lulus, 1 dilewati (sampel XLSX eksternal), 319 assertions; termasuk snapshot historis, fallback nama lama, isolasi toko dan props tanpa credential. Pint dan build berhasil. Pemeriksaan browser memakai data simulasi. Bukti: `apps/web/storage/framework/testing/screenshots/otomax-supplier-name.png`. Tidak ada migrasi/dependensi baru.


### Digiflazz 422 karena perbedaan kapitalisasi SKU — 22 Oktober 2026

- Inspeksi ngrok dan database menemukan callback sukses untuk dua percobaan Digiflazz: snapshot memakai DANA10/DANAUSER sedangkan API mengembalikan dana10/danauser. Signature lolos, tetapi pemeriksaan identitas SKU yang peka kapitalisasi menolak callback dengan 422; pemeriksaan respons sinkron juga menganggap hasil tidak pasti.
- Menyamakan pencocokan SKU melalui CustomerFulfillment::matchesDigiflazzSku pada webhook, respons transaksi/pembayaran pasca/status-pasca, dan inquiry tagihan. Hanya kapitalisasi diabaikan; RefID, nomor tujuan, lingkungan dan signature tetap diperiksa. SKU lain tetap ditolak. Pesan 422 identitas kini menjelaskan ketidakcocokan SKU/tujuan.
- Nomor pesanan dan referensi percobaan memang berbeda, karena tiap parsing harus memiliki referensi unik. Awalan ULID sama karena dibuat pada waktu berdekatan. Tidak mengubah referensi pesanan berjalan. Panel kini menampilkan RefID pemasok di kolom Sumber dan mendukung pencarian referensi tersebut.
- Setelah backup raw terenkripsi kedua pesanan ke berkas lokal di storage/framework/testing/digiflazz-recovery-backup-20261022-081831.json, memproses ulang dua callback asli dari inspector ngrok melalui endpoint lokal dengan body dan signature asli. Keduanya HTTP 200/SUCCESS. Tidak memanggil endpoint pembelian supplier.
- Verifikasi database: DT01M5H0CBAMPT589K8X01509D6S / ref DT01M5H0CBAV687JJ32AVDK91QVY dan DT01M5H3RC2XZGYAQ95WCEYYT4S8 / ref DT01M5H3RC35YCXZ152XBW1DF63Q kini order dan attempt sama-sama success, lunas dan memiliki SN. Pesanan pertama sebelumnya sudah disukseskan manual; callback memperbarui hasil supplier aslinya.
- Validasi: 104 tes lulus, 1 dilewati (sampel XLSX eksternal), 964 assertions pada DigiflazzWebhookTest, CustomerCommerceTest dan OtomaxTest. Meliputi callback beda kapitalisasi, nomor pesanan berbeda dari ref supplier, respons ulang manual, inquiry/status-pasca, penolakan signature/SKU/tujuan lain dan isolasi toko. Pint, build dan diff whitespace lulus. Bukti visual RefID memakai data simulasi: storage/framework/testing/screenshots/supplier-attempt-reference.png.
- Tidak ada migrasi/dependensi. Worker yang berumur panjang perlu restart setelah deploy untuk menggunakan perbaikan respons sinkron.


### Auto-refresh halaman pesanan juga memeriksa pembayaran Midtrans — 22 Oktober 2026

- Penyebab: OrderAutoRefresh memuat ulang prop order setiap 10 detik, tetapi endpoint show hanya membaca status database dan memperbaiki gambar QR; pemeriksaan Midtrans baru terjadi saat tombol manual/webhook/scheduler.
- Endpoint halaman pesanan kini, setelah verifikasi akses toko/token pelanggan, memeriksa status Midtrans untuk invoice awaiting_payment yang belum lunas. Pemeriksaan memakai snapshot key/mode invoice, endpoint GET status, validasi nominal/referensi existing, serta lock/cache per invoice dengan batas 10 detik. Tidak membuat invoice atau pembelian baru, dan berhenti memanggil gateway setelah lunas.
- Setelah Midtrans mengonfirmasi lunas, model dimuat ulang agar partial reload yang sama langsung menampilkan pembayaran lunas. Receipt/notifikasi/pengiriman pertama tetap idempotent melalui CustomerCommerce::paid. Tidak mengaktifkan kembali retry supplier otomatis.
- Gangguan gateway tidak menutup halaman atau mengubah invoice menjadi lunas: indikator pembeli menyatakan pembayaran belum dapat diperiksa dan akan dicoba kembali. Indikator awal menjelaskan bahwa pembayaran serta status pesanan diperiksa setiap 10 detik.
- Validasi: 106 tes lulus + 1 dilewati (berkas XLSX eksternal), 1026 assertions pada CustomerCommerceTest, DigiflazzWebhookTest dan OtomaxTest; StoreSandboxTest 27/27 dengan 247 assertions; frontend 30/30 dan build berhasil. Tes baru mencakup status pending→settlement, pembatasan antar-refresh, tidak polling sesudah lunas, satu receipt/job, error gateway dan pemulihan, serta token salah tidak menghubungi gateway.
- Browser menggunakan invoice/database simulasi: indikator kegagalan status terlihat, lalu perubahan saldo pembayaran fixture dibaca ulang otomatis. Pemeriksaan browser tidak memakai pembayaran Midtrans nyata. Tidak ada migrasi/dependensi baru.

Bukti tampilan auto-refresh pembayaran: `apps/web/storage/framework/testing/screenshots/payment-auto-refresh.png`.


### Kolom tindakan Katalog & harga lebih compact — 22 Oktober 2026

- Tindakan produk diubah menjadi tiga ikon 32 px dalam satu baris: edit/atur harga, aktif/nonaktif, dan hapus. Tab aturan harga memakai gaya ikon yang sama. Tiap tombol memiliki aria-label spesifik dan title; warna latar, fokus keyboard dan warna hapus tetap jelas.
- Tidak mengubah permission, modal/form atau alur konfirmasi existing. Edit membuka modal, perubahan aktif/nonaktif dan hapus tetap meminta konfirmasi. Tidak ada data yang diubah/dihapus dalam pemeriksaan browser.
- Validasi: build TypeScript/Vite berhasil dan diff whitespace bersih. Browser data simulasi memverifikasi group 108 px dalam sel 147 px, semua tombol satu baris, modal edit serta dua dialog konfirmasi dapat dibuka/dibatalkan. Bukti: `apps/web/storage/framework/testing/screenshots/catalog-compact-actions.png`. Tidak ada perubahan backend/dependensi/migrasi.


### Kolom Sumber dan verifikasi SKU sama antar-supplier — 22 Oktober 2026

- Daftar produk pemasok memiliki kolom Sumber tersendiri: jenis koneksi dan nama supplier, menggunakan daftar koneksi yang sudah dibatasi toko. Jenis sumber tidak lagi diulang pada metadata SKU. Lebar tabel disesuaikan untuk lima kolom data, dengan pembungkusan teks dan gulir horizontal tetap pada tabel.
- Identitas produk sumber dikonfirmasi dari schema/implementasi: primary ID UUID internal dan unique(connection_id, mode, sku). Kode sama pada supplier berbeda tidak berbenturan; upsert menyasar koneksi dan jenis katalog yang sama. Pemetaan ke katalog memakai UUID produk sumber. Kode katalog yang berbenturan diberi akhiran berurutan seperti O-T10/O-T10-2.
- Tes baru dua koneksi OtomaX pada satu toko dengan SKU T10: produk dan mapping tetap berbeda, dua kode jual unik, impor ulang tidak menggandakan katalog, dan upsert harga supplier A tidak mengubah supplier B.
- Validasi: CatalogImportAllTest 10/10 lulus dengan 156 assertions; Pint, build dan diff whitespace lulus. Browser data simulasi menampilkan kolom jenis/nama supplier dan tidak menemukan overflow pada strong/small tabel. Bukti: `apps/web/storage/framework/testing/screenshots/source-products-supplier-column.png`. Tidak ada migrasi/dependensi baru atau perubahan data toko asli.
### Sinkronisasi Digiflazz, interval katalog, dan alasan kegagalan — 22 Oktober 2026

- Pemeriksaan koneksi lokal bogor topup berhasil menyinkronkan 1.003 baris Digiflazz. Pembacaan ulang database: last_error kosong, 1.004 baris tersimpan termasuk produk historis yang tidak lagi dikirim pemasok, 691 tersedia. IP dan kredensial diterima pada permintaan tersebut. Penyebab pasti kegagalan sebelumnya tidak dapat dipastikan karena pesan asli ditimpa pesan umum.
- Ditemukan cache katalog hanya 120 detik, sedangkan dokumentasi resmi Digiflazz RC 83 membatasi daftar harga lengkap menjadi sekali per lima menit. Katalog Digiflazz kini memakai cache 300 detik per akun/mode, pembacaan katalog menunggu umur tersebut sebelum refresh, dan RC 83/HTTP 429 memakai cooldown 300 detik. Saldo dan Hanshop mempertahankan aturan cache 60–180 detik. Referensi: https://developer.digiflazz.com/api/buyer/response-code/.
- Pembacaan API kini membedakan RC 41/42 (signature/username), 45 (whitelist beserta IP/mode), 82/88 (akun), HTTP 429, dan gangguan koneksi. Pesan aman disimpan pada last_error maksimal 250 karakter dan dipertahankan scheduler; body mentah serta kredensial tidak ditampilkan. Snapshot lama tidak dihapus ketika respons gagal atau data produk tidak valid.
- Scheduler mengecualikan koneksi OtomaX yang katalog produknya berasal dari impor berkas, agar tidak menandainya gagal sinkronisasi API.
- Validasi: StoreCatalogTest 27/27, 328 assertions; StoreSandboxTest 27/27, 247 assertions. Meliputi cache bersama antar-toko hanya untuk akun yang sama, cache masih berlaku setelah 121 detik, cooldown lima menit dan pemulihan, RC 83 pada HTTP 200, HTTP 429 tanpa JSON, pesan IP/akun yang aman, perlindungan snapshot, dan pesan scheduler. Tidak ada migrasi/dependensi baru. Tes HTTP memakai fake; sinkronisasi nyata di atas hanya membaca katalog, tanpa pembelian/transaksi supplier.



### Pesan asli pada tab Callback — 22 Oktober 2026

- Kolom Rincian tab Callback menampilkan pesan webhook lengkap, dengan baris baru dan pembungkusan teks; referensi tetap pada kolom Peristiwa. Pesan panjang dapat digulir tanpa melewati sel.
- Callback baru, termasuk penangkap yang belum cocok, menyimpan message bersama hasil parsing dalam data event terenkripsi. Masing-masing peristiwa mempertahankan pesannya sendiri ketika callback berikutnya mengubah transaksi. Hanya field pesan yang ditambahkan ke props panel; tidak mengekspor query, kredensial, atau data event mentah.
- Riwayat lama yang hanya menyimpan hasil parsing menampilkan keterangan pesan tidak tersimpan. Tidak merekonstruksi pesan lama dari hasil terbaru transaksi yang mungkin berbeda. Tidak ada migrasi/dependensi.
- Validasi: OtomaxTest 41 lulus, 1 dilewati (sampel XLSX eksternal), 352 assertions; Pint dan build TypeScript/Vite lulus. Tes mencakup pesan unmatched/sukses, penyimpanan terenkripsi, riwayat tetap utuh, data lama dan isolasi antartoko. Browser pada database simulasi memverifikasi pesan lengkap di kolom Rincian; bukti: apps/web/storage/framework/testing/screenshots/otomax-callback-message.png.


### Sembunyikan impor berkas dan susun rencana URL katalog — 22 Oktober 2026

- Tombol Impor file, tab Profil impor/Riwayat impor, tindakan tambah/edit profil impor serta akses modal impor disembunyikan dengan OTOMAX_FILE_IMPORT_UI_ENABLED=false. Navigasi komponen dipakai bersama halaman koneksi dan penguji jawaban, sehingga perubahan konsisten. Teks empty state tidak lagi mengarahkan upload file.
- Pembaca XLSX/CSV, parser, profil, job, tabel, data existing dan endpoint backend dipertahankan sesuai permintaan untuk hanya menyembunyikan menu. Ini bukan penonaktifan endpoint pada server. Fitur impor produk dari Sumber produk ke katalog tetap ada.
- Rencana sinkronisasi URL Webreport telah ditambahkan pada OTOMAX_INTEGRATION_PLAN.md, mencakup uji/pratinjau URL, konfigurasi per supplier, parser HTML reusable, pembaruan SKU existing, perlindungan pengaturan toko dan harga manual, scheduler/queue/cache per URL akun, race callback, error/staleness, izin staff, pengamanan URL dan kriteria uji. Usulan interval awal 15 menit, belum diimplementasikan atau ditetapkan sebagai batas resmi supplier.
- Validasi UI: build TypeScript/Vite berhasil; browser database simulasi pada panel owner dan staff menunjukkan tombol/tab impor tidak tersedia, menu koneksi/jawaban/callback tetap ada. Bukti: apps/web/storage/framework/testing/screenshots/otomax-file-import-hidden.png. Tidak ada perubahan backend, database atau dependensi; implementasi sinkronisasi URL belum dimulai.


### Produk hilang dari daftar supplier dan pemeriksaan sebelum dispatch — 22 Oktober 2026

- Keputusan rancangan URL diperbarui: SKU yang hilang dari snapshot ALL produk yang lengkap dan valid otomatis menjadi available=false, bukan dipertahankan tersedia. Data sumber/mapping/histori tidak dihapus; produk dapat tersedia lagi ketika muncul kembali dengan status/harga valid. Respons error/terpotong/terfilter tidak dianggap bukti penghapusan. Kebijakan lama keep pada rancangan URL telah diganti; opsi importer Excel historis tetap dipertahankan karena UI berkas sedang disembunyikan.
- Implementasi perlindungan sekarang: CustomerFulfillment memeriksa ulang sumber sebelum dispatch pertama berdasarkan store, connection, mode dan SKU. Sumber hilang, nonaktif, atau quantity<=0 tidak dikirim ke supplier; attempt ditandai gagal dengan keterangan produk sumber tidak tersedia/dihapus dan memilih sumber berikutnya pada eksekusi yang sama. Koneksi yang dinonaktifkan sebelum dispatch juga dilewati. Jika tidak ada sumber tersisa, pesanan gagal melalui mekanisme refund existing.
- Katalog existing sudah mengecualikan SupplierProduct.available=false dari kandidat checkout. Tidak mengubah pengaturan aktif/nonaktif produk jual. Sumber yang sudah dikirim dan masih pending tidak dipindah/refund hanya karena katalog berubah; callback dan pemeriksaan manual tetap berlaku agar tidak menggandakan pembelian.
- Validasi: CustomerCommerceTest + OtomaxTest 86 lulus/1 dilewati (sampel XLSX eksternal), 815 assertions; DigiflazzWebhookTest + StoreSandboxTest 51 lulus, 520 assertions. Tes baru membuktikan sumber terhapus/nonaktif/stok nol tanpa HTTP, fallback stok dalam eksekusi yang sama, semua parsing OtomaX habis langsung gagal/refund idempotent, dan transaksi pending yang sudah terkirim tidak otomatis berpindah sumber. Pint dan diff whitespace lulus. Tidak ada request supplier nyata, migrasi/dependensi baru atau perubahan frontend.
- Batas progres: pengunduhan/scheduler URL belum dibuat sesuai tahap perencanaan sebelumnya. Penandaan otomatis berdasarkan ketidakhadiran dalam feed baru aktif setelah modul sinkronisasi URL diimplementasikan; perlindungan dispatch di atas sudah diterapkan pada source saat ini.
