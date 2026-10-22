# Rencana integrasi supplier OtomaX

Tanggal: 21 Oktober 2026. Status: rancangan awal yang telah dilanjutkan menjadi implementasi. Cakupan nyata dan hasil pengujian: [OTOMAX_PROGRESS.md](OTOMAX_PROGRESS.md).
Checkpoint sebelum perencanaan: `0ff49bd` — retensi/penghapusan manual toko, perapihan admin dan kontrol UI. Working tree bersih setelah checkpoint.

## Tujuan dan batas tahap ini

DigitalToko mendukung koneksi supplier berbasis OtomaX selain Digiflazz, Hanshop, dan stok privat. Owner dapat menambahkan lebih dari satu supplier OtomaX, memetakan produknya ke katalog toko, membaca saldo, meminta tiket deposit, dan menerima pembaruan transaksi melalui callback. Bot dan web memakai mesin pesanan yang sama.

Bagian rancangan di bawah merekam keputusan sebelum implementasi; untuk source saat ini gunakan OTOMAX_PROGRESS.md. Penyusunan rancangan ini tidak mengubah konfigurasi produksi. Tidak ada request ke IP supplier pengguna, transaksi, cek saldo, atau pembuatan tiket deposit. Kredensial/nomor tujuan/sign asli dari percakapan tidak disalin ke dokumentasi maupun Git.

## Temuan dokumentasi publik

1. Dokumentasi resmi OtomaX menjelaskan input signature transaksi, cek saldo, dan tiket. Signature adalah Base64 URL-safe dari SHA-1 atas byte string template, tanpa padding `=`; bukan SHA-1 hex yang kemudian di-Base64. Dalam PHP penggabungan string memakai titik, walaupun contoh lama situs memakai tanda tambah.
2. Dokumentasi IP Center resmi menunjukkan `trx` dan `balance`, tetapi contoh tiket menggunakan `saldo?cmd=ticket`. Dokumentasi supplier Pulsa Plus menggunakan `ticket`. Path dan nama parameter harus mengikuti supplier/versi yang dipakai, bukan satu path universal yang dipaksakan.
3. Belum ditemukan kontrak daftar produk universal untuk seluruh IP Center OtomaX. DFlash mendokumentasikan feed harga terpisah di `https://home2.dflash.co.id/harga/pricelist_json.php`; MRF MEDIA menyediakan API tambahan `/api/products` dan `/api/operator`. Keduanya contoh milik penyedia tersebut, bukan endpoint yang terkonfirmasi pada IP supplier pengguna.
4. Respons transaksi dapat berupa teks/query-string dan bersifat asinkron. Pulsa Plus mencontohkan status awal diproses, kemudian callback dengan status, refid, price, dan message. Mapping status itu tidak otomatis diterapkan ke semua supplier.
5. Dokumentasi resmi pembaruan harga membahas penangkapan harga dari balasan transaksi, berbeda dari pengambilan seluruh katalog. Harga dari transaksi sebelumnya tidak cukup untuk menjamin harga terkini sebelum pesanan berikutnya.

Sumber:
- Signature resmi: https://www.otomax-software.com/en/1567/an-example-of-php-script-to-generate-otomaxs-signature/
- IP Center resmi: https://www.otomax-software.com/en/3468/setting-ip-center/
- Pembaruan harga resmi: https://www.otomax-software.com/en/kb/update-harga-beli-otomatis/
- Dokumentasi supplier Pulsa Plus: https://pulsaplus.co.id/api-docs
- Dokumentasi supplier DFlash: https://home2.dflash.co.id/koneksi-via-api/ (cuplikan indeks pencarian terbaca; halaman penuh mengalami timeout)
- API tambahan MRF MEDIA: https://integrasimrfmedia.my.id/

## Kontrak yang perlu diperoleh dari supplier pertama

- Nama supplier, versi/interface yang didukung, base URL HTTP/HTTPS, port, whitelist IP, timeout dan batas request.
- Endpoint katalog, autentikasi, contoh respons, pagination, harga khusus member/grup, status produk, stok, satuan nominal/qty dan batas harga transaksi jika tersedia.
- Contoh respons cek saldo, tiket deposit, permintaan transaksi, sukses, gagal, pending, refID duplikat, dan saldo tidak cukup. Sampel wajib disamarkan.
- Endpoint cek status yang bersifat read-only atau prosedur cek dengan refID lama yang dijamin tidak membeli ulang; kontrak sudah dikonfirmasi owner pada 22 Oktober 2026: `/trx` dapat dikirim ulang dengan RefID, SKU, tujuan dan credential yang sama selama masih pada hari pengiriman awal (WIB). Setelah berganti hari tidak boleh mengirim ulang request transaksi.
- Format callback, cara mendaftarkan URL report, autentikasi/validasi pengirim, identitas supplier/member, retry dan urutan event. Jangan menganggap sign request juga merupakan sign callback.
- Jika katalog tidak tersedia: minta feed/API resmi tambahan atau ekspor CSV dari supplier. Input manual tetap mungkin, tetapi sinkron harga/stok otomatis tidak boleh dijanjikan.

## Rancangan model dan adapter

Kondisi source: `SupplierConnection` sudah mengenkripsi credentials, tetapi koneksi dibatasi unique `(store_id, kind)`; controller, fitur paket, saldo, dan fulfillment masih memiliki cabang khusus Digiflazz/Hanshop. Menambahkan pilihan UI saja tidak cukup.

- Pisahkan jenis adapter (`otomax`) dari identitas supplier/connection. Simpan nama koneksi dan ID unik, sehingga supplier A dan B dapat mempunyai SKU sama tanpa benturan.
- Migrasi mendatang harus mempertahankan koneksi lama, parsing, dan keunikan Digiflazz/Hanshop yang memang diperlukan. Jangan menghapus constraint lama tanpa pengganti aturan integritas.
- Identitas produk sumber tetap `(connection_id, mode, sku)`. Identitas attempt, callback, cache, snapshot dan audit juga mengandung connection_id; nomor pesanan publik bukan refID semua percobaan.
- Definisikan kontrak adapter: kemampuan katalog/saldo/kirim/status/callback/tiket; kemampuan tidak tersedia ditampilkan secara eksplisit.
- Profil protokol disediakan/dikurasi admin: path, case nama parameter, pola signature terbatas, parser respons dan mapping status. Owner mengisi koneksi/credential, bukan mengeksekusi kode/PHP/template bebas.
- Base URL, scheme, host/IP, port, member ID, PIN, password, sumber katalog dan konfigurasi callback ditampilkan dalam modal ringkas. PIN/password/sign disamarkan, tidak masuk props frontend, log URL, toast atau audit mentah. Pertahankan kredensial terenkripsi.
- Endpoint tenant-configurable perlu validasi tujuan jaringan, blok loopback/private/link-local/metadata dan redirect yang menghindari pemeriksaan. Pengecualian koneksi privat hanya melalui konfigurasi admin yang disengaja. Utamakan HTTPS jika tersedia; HTTP adalah kemampuan supplier yang perlu dicatat, signature tidak mengenkripsi transport.

## Katalog, cache dan harga

- Pilih mode katalog per koneksi: API/feed terverifikasi, impor CSV, atau manual. API bisa berada pada host berbeda dengan transaksi, dengan autentikasi terpisah.
- Normalisasikan kode, nama, layanan, kategori, modal, status, stok opsional dan observed_at. Stok tidak diketahui adalah null; stok nol berbeda dari stok tidak terbatas.
- Harga harus harga member yang akan bertransaksi, bukan harga publik tanpa markup/grup akun. Nilai hilang/rusak tidak diubah menjadi Rp0 atau status tersedia.
- Redis 60–180 detik dengan lock per supplier/endpoint + identitas akun + lingkungan + operasi. Akun/level harga berbeda tidak berbagi snapshot; toko yang memang memakai akun sama boleh berbagi. Cache saldo terpisah dari katalog dan tidak menjadi bukti pelunasan deposit.
- Impor semua/per layanan/per kategori/pilihan dan aturan markup produk → kategori → layanan tetap dipakai. Impor ulang memperbarui sumber/parsing terkait, bukan menggandakan atau menimpa kebijakan toko tanpa pilihan.
- Gangguan sinkronisasi mempertahankan snapshot terakhir dengan penanda usia/error. Kebijakan stale/checkout harus eksplisit; data lama tidak diam-diam dinyatakan segar.
- Kontrol batas harga pemasok hanya dapat dijamin sebelum debit jika supplier menyediakan parameter batas harga/penawaran yang benar-benar mengikat. Jika tidak ada, nyatakan keterbatasan dan tentukan kebijakan risiko; callback tidak dapat membatalkan pembelian yang sudah sukses.

## Transaksi dan callback

- RefID unik per attempt, disimpan sebelum request; retry transport memakai refID attempt yang sama hanya jika supplier menjamin deduplikasi. Failover supplier memakai refID attempt baru.
- Timeout, respons kosong atau tak dikenali menjadi pending/perlu rekonsiliasi, bukan gagal pasti dan bukan alasan langsung membeli ke supplier berikutnya.
- Fallback ke parsing termurah berikutnya hanya setelah penolakan/gagal final yang terkonfirmasi; semua parsing gagal pasti mengakhiri pesanan gagal dan menjalankan refund satu kali.
- URL callback per koneksi memudahkan routing multi-store. Tetap verifikasi pengirim dan cocokkan refID, connection dan data transaksi; URL unik saja bukan bukti autentikasi.
- Callback duplikat/concurrent tidak menggandakan refund/notifikasi. Perubahan status atau SN diproses sesuai kebijakan rekonsiliasi yang sudah disepakati, dengan audit dan pengurutan event bila supplier mendukung. Tanpa sequence, konflik hasil perlu rekonsiliasi, jangan menganggap event terakhir tiba selalu terbaru.
- Koreksi gagal→sukses membalik refund yang terkait tepat satu kali; saldo pelanggan dapat minus dan transaksi baru diblokir sesuai aturan yang ada. Keterlambatan sukses setelah failover juga harus ditandai sebagai kemungkinan pembelian ganda.
- Polling cadangan hanya memakai cara cek status yang disepakati. Jika tidak tersedia, pending ditangani callback/rekonsiliasi admin, bukan resubmit tanpa jaminan.

## Saldo dan tiket deposit

- Dashboard menampilkan saldo masing-masing koneksi dengan waktu pembacaan dan status error, bukan satu angka gabungan semua supplier.
- Tiket menyimpan ID/nominal diminta/nominal transfer termasuk kode unik/rekening/masa berlaku bila diberikan. Bedakan saldo supplier dengan saldo Hanshop owner dan wallet pelanggan.
- Tombol ambil tiket adalah permintaan tiket, bukan transfer dana. Pembayaran dilakukan owner melalui kanal supplier. Saldo bertambah yang terlihat pada refresh tidak otomatis membuktikan tiket tertentu lunas; butuh identitas mutasi/status resmi.
- Staff perlu hak khusus untuk koneksi, lihat saldo dan tiket; mengubah kredensial tidak dibundel otomatis dengan hak edit produk.

## Paket dan keputusan yang belum ditetapkan

- OtomaX belum termasuk entitlement paket existing yang menyebut Digiflazz secara spesifik. Usulan: admin dapat menambahkan capability supplier eksternal/OtomaX pada paket terpilih, atau menjadikannya modul tambahan. Jangan otomatis membuka untuk seluruh toko.
- Tentukan batas jumlah koneksi per toko, siapa yang membuat profil supplier, dan dukungan prepaid dahulu vs inquiry/pascabayar. Usulan awal: admin mengelola profil; owner mengelola akun; prepaid dahulu, postpaid menunggu kontrak inquiry/pay.
- Tentukan apakah input/CSV katalog menjadi alternatif yang diterima bila supplier pertama tidak menyediakan API daftar harga. Dukungan impor bukan jaminan harga real-time.

## Urutan pengerjaan yang diusulkan

1. Kunci kontrak supplier pertama: katalog, respons, callback, cek status, deduplikasi dan batas harga. Hasil: sampel anonim dan profil protokol disetujui.
2. Refactor koneksi/adapter + migrasi aman + entitlement/hak akses, dengan regresi Digiflazz/Hanshop/stok.
3. Koneksi dan cek saldo; katalog/API/CSV; cache; impor dan parsing. UI modal, dropdown pencarian/infinite scroll, tabel utama filter/pagination.
4. Pemesanan + callback + rekonsiliasi + refund/failover pada mesin bot/web bersama. Sandbox fixture tanpa menyentuh saldo nyata.
5. Tiket deposit + riwayat + pembacaan status sesuai kemampuan supplier.
6. Uji staging dua toko/dua supplier/SKU sama; timeout, callback ganda/terlambat, pending, parsing habis, harga naik, stok nol, dan perbedaan akun/cache. Pengujian riil bernilai uang harus disepakati terpisah.

Ukuran selesai: toko berhak dapat mengelola dua koneksi OtomaX terisolasi, mendapatkan katalog sesuai kemampuan supplier, memproses order web/bot tanpa pembelian/refund ganda, menerima callback/status/SN, melihat saldo dan meminta tiket. Seluruh pengujian regresi integrasi lama lulus. Fitur katalog otomatis dan rekonsiliasi otomatis tidak dinyatakan selesai sebelum kontrak supplier terverifikasi.

## Hasil pengujian pengguna — 21 Oktober 2026

Bukti berasal dari respons Postman dan screenshot penerima callback yang dikirim pengguna; agent tidak mengirim transaksi atau mengakses URL penerima callback. Integrasi DigitalToko belum diimplementasikan.

- Signature supplier pertama: dengan credential yang sama, contoh sign transaksi lama cocok persis apabila kode produk pada input hash memakai huruf kapital. Request berikutnya dengan kode kapital dan sign yang dihitung dari nilai tersebut diterima pengguna. Aturan ini dicatat untuk profil supplier ini; jangan mengubah kapital semua field atau mengasumsikan seluruh supplier punya normalisasi identik. RefID dipertahankan persis.
- Respons sinkron transaksi berupa teks: `#<id_supplier> <PRODUK>.<tujuan> akan diproses @<jam>. Saldo <awal> - <harga> = <akhir>`. Ini pengakuan diterima/diproses, bukan sukses final.
- Callback benar-benar diterima setelah URL Report disiapkan oleh pengguna. Method GET, body kosong, data berada pada query string.
- Query yang terlihat: `t`, `refid`, `status`, `price`, `message`. `refid` cocok dengan referensi request. Sampel sukses mempunyai `status=20`, `price=5` dan pesan berisi `SUKSES`.
- Pola pesan sampel: `#<id_supplier> <PRODUK>.<tujuan> SUKSES. SN/Ref: <sn>. Saldo <awal> - <harga> = <akhir> @<tanggal> <jam> R#<refid>`.
- ID supplier ada pada awalan pesan `#...`. Makna query `t` belum dikonfirmasi; nilainya menyerupai HHmmss pada sampel. Jangan memakainya sebagai identitas transaksi/idempotency key tanpa dokumentasi supplier.
- SN/Ref muncul dalam message, belum terlihat sebagai field terpisah. Parser perlu menyimpan hasil sebagai string dan mempertahankan karakter/angka nol, termasuk pemisah yang mungkin muncul pada produk lain.
- Tangkapan layar tidak cukup untuk memastikan autentikasi callback; perlu pemeriksaan header lengkap dan kontrak supplier. IP pengirim yang terlihat konsisten dengan host supplier, tetapi bukan bukti autentikasi aplikasi yang berdiri sendiri.
- Belum terverifikasi: callback gagal/pending/koreksi SN, retry/ACK yang diperlukan, urutan event, endpoint cek status, daftar produk, batas harga, tiket/saldo aktual. Status gagal 40 masih contoh dari dokumentasi supplier lain, bukan hasil pengujian supplier pertama.

Data akun, kredensial, sign, URL unik penerima, nomor tujuan dan ID transaksi asli tidak dimasukkan dalam catatan ini. Tahap berikutnya tetap konfirmasi katalog dan kontrak callback/status, bukan langsung mengaktifkan pemrosesan produksi.

## Kelompok jawaban yang dapat dipakai ulang

Permintaan pengguna: setiap supplier bisa mempunyai banyak bentuk respons; owner/staff dapat membuat kelompok jawaban, menambah pola dan memilih kelompok yang sama pada beberapa koneksi supplier tanpa menulis ulang. Masih tahap rancangan, bukan implementasi.

### Susunan konfigurasi

- Kelompok jawaban (contoh: OtomaX Format A) berisi sejumlah aturan/pola, bukan hanya satu regex. Koneksi supplier memilih satu kelompok aktif; kelompok yang sama dapat dipakai beberapa koneksi.
- Setiap aturan memiliki nama, sumber respons (respons awal transaksi/callback/cek status/saldo/tiket), input yang dibaca (teks utuh atau field query/JSON/form), prioritas, template, kondisi tambahan, keluaran status internal dan aktif/nonaktif.
- Contoh aturan dalam satu kelompok: Permintaan diterima, Sukses format 1, Sukses format 2, Gagal format 1, Saldo tidak cukup, Sedang diproses. Respons awal dan callback tidak dipaksakan mengikuti pola sama.
- Tambah/edit/duplikat/uji dalam modal. Daftar utama kelompok memakai filter/pagination. Pemilih kelompok di modal memakai dropdown floating, pencarian dan pemuatan bertahap; tidak ada prev/next dalam modal.

### Template field

Template contoh (placeholder kanonis):

```text
#[id_transaksi] [kode_produk].[tujuan] [status]. SN/Ref: [sn]. Saldo [saldo_awal] - [harga] = [saldo_akhir] @[tanggal] [jam] R#[refid]
```

Label UI bisa memakai istilah ramah pengguna, sementara key internal konsisten. Daftar awal: id_transaksi, kode_produk, tujuan, status, sn, refid, harga, saldo_awal, saldo_akhir, tanggal, jam, keterangan. Tanggal dan jam tanpa tahun/timezone tidak dianggap timestamp lengkap; simpan received_at terpisah.

- Editor sederhana menyisipkan placeholder melalui tombol/pilihan field. Teks biasa diperlakukan sebagai literal, bukan kode regex yang dieksekusi.
- Tipe field dapat dipilih: teks/kode, angka sebagai string, nominal, tanggal, jam, teks bebas. ID, refID, tujuan, kode produk dan SN tidak dipaksa integer agar nol awal/huruf tidak hilang.
- Pemisah nominal mengikuti pengaturan profil (contoh 1.016 berarti 1016 dalam format Indonesia). Harga 0 eksplisit berbeda dari field harga tidak ditemukan. Satuan mata uang ditetapkan pada profil, bukan ditebak dari pesan.
- Placeholder opsional/format alternatif membutuhkan pengaturan yang jelas. SN dapat mengandung spasi/titik/slash sehingga penangkapan tidak berhenti pada titik pertama; gunakan batas konteks berikutnya dan sampel pengujian. Sediakan beberapa pola bila variasi terlalu berbeda.
- Dukungan regex lanjutan, bila diperlukan, dibatasi dengan validasi sintaks, batas panjang input dan anggaran eksekusi; tidak ada eval/PHP/custom code. Untuk tahap awal utamakan template terstruktur agar mudah digunakan dan aman.

### Mapping field terstruktur dan status

- Transport callback bisa GET query, POST form atau JSON sesuai profil. Arahkan refid ← query.refid, status ← query.status, harga ← query.price, lalu parser membaca query.message untuk ID supplier, produk, tujuan, SN dan rincian lainnya. Tidak perlu mengambil semua field dari message jika tersedia field terstruktur yang lebih pasti.
- Urutan sumber per field dibuat eksplisit; field identitas/status yang berkonflik antara query dan message ditandai, bukan ditimpa diam-diam. Query status 20 dan teks SUKSES hanya berlaku untuk profil yang sudah diuji; 40 gagal tetap perlu konfirmasi supplier ini.
- Status hasil parsing harus dipetakan ke pending/success/failed/review dengan daftar nilai yang diizinkan, atau aturan hasil tetap (contoh aturan Sukses literal → success). Teks bebas pada placeholder status tidak otomatis menjadi otorisasi refund/failover.
- Contoh template yang lebih tegas untuk aturan sukses dapat memakai literal SUKSES dan hasil tetap success. Template dengan [status] juga didukung asalkan mapping SUKSES/BERHASIL/GAGAL/PROSES ditentukan. Jangan mendeteksi sukses hanya dari pencarian substring, misalnya pesan TIDAK SUKSES.
- Prioritas aturan menentukan evaluasi, tetapi pola aktif bertumpang-tindih dengan hasil status/identitas berbeda harus ditolak saat pengujian atau ditandai review saat runtime. Aturan fallback tak dikenal tidak boleh menandai gagal otomatis.

### Pengujian di modal

Sediakan input Contoh respons dan, bila callback terstruktur, query/JSON/form yang disamarkan. Tombol Uji jawaban hanya menjalankan parser lokal, tanpa hit supplier, pesanan, saldo atau refund. Tampilkan aturan yang cocok, hasil field, status normalisasi, nominal asli/hasil, field wajib hilang, konflik, dan alasan tidak cocok. Tandai bagian teks yang berhasil ditangkap bila memungkinkan. Kelompok menyimpan beberapa contoh sukses/gagal/pending sebagai fixture anonim untuk validasi versi berikutnya.

### Pemakaian ulang, isolasi dan perubahan versi

Usulan lingkup awal: kelompok milik toko dapat dipakai semua koneksi supplier dalam toko itu. Admin menyediakan pustaka template platform yang dapat disalin ke toko. Pemakaian lintas toko milik owner yang sama dapat disediakan sebagai salin/duplikasi; tidak membuat staff suatu toko mengubah kelompok toko lain secara tidak langsung. Berbagi global hanya melalui template admin tanpa sampel/kredensial tenant.

- Pisahkan kelompok dan versi aturan. Edit menghasilkan draf, pengujian sebelum terbit, dan daftar koneksi terdampak diperlihatkan saat menerapkan versi.
- Simpan versi parser pada attempt. Callback transaksi lama tetap memakai kontrak versi attempt; penggantian parser untuk transaksi pending merupakan tindakan rekonsiliasi eksplisit dengan audit, bukan efek samping perubahan kelompok.
- Kelompok yang dipakai tidak dihapus fisik tanpa pelepasan/arsip; referensi versi historis tetap tersedia sesuai retensi transaksi. Duplikasi kelompok membuat konfigurasi mandiri.
- Hak staff dirancang terpisah untuk lihat kelompok, kelola/uji draf, dan terapkan versi ke koneksi. Hak mengubah template jawaban dapat memengaruhi status/refund sehingga tidak otomatis diberikan kepada semua staff pengelola produk. Owner mempunyai akses penuh tokonya; semua mutasi diaudit.

### Batas pemrosesan

Kelompok jawaban hanya menerjemahkan respons supplier yang sudah dirutekan dan diverifikasi. Pola yang cocok bukan bukti autentikasi callback. Pencocokan order wajib berdasarkan connection_id dan refID tersimpan; ID supplier menjadi identitas tambahan. Bila callback tidak memuat refID, diperlukan mapping ID supplier yang sudah tercatat dan tidak ambigu, bukan mencocokkan berdasarkan nomor tujuan saja. Pending atau respons tak dikenali tidak memicu fallback/refund otomatis. Saldo di pesan supplier bukan perintah mengubah wallet pelanggan. Pengujian wajib mencakup respons mirip, pola ganda, data hilang, callback palsu/toko lain, serta pengulangan/concurrency.

Urutan implementasi disesuaikan: fondasi adapter/multi-koneksi → kelompok jawaban + penguji parser → saldo/katalog → transaksi/callback/rekonsiliasi → tiket. Belum ada perubahan source aplikasi pada tahap perencanaan ini.

## Profil impor Excel/CSV per supplier

Contoh ekspor Webreport diperiksa read-only pada 21 Oktober 2026 menggunakan ekstraksi XML workbook. Tidak mengubah file pengguna dan tidak mengimpor data ke database. Isi file diperlakukan sebagai data, bukan instruksi.

### Temuan struktur contoh

- Satu worksheet List Harga. Judul di baris 1, header A3:F3: Kode Produk, Keterangan, Harga, Harga Jual, Keuntungan, Status.
- Ada 6.399 baris dengan kode dan keterangan produk, serta 528 baris judul kelompok. Judul kelompok memakai sel gabungan horizontal A:D dan menjadi konteks bagi produk di bawahnya sampai judul berikutnya. Jangan mengartikan setiap baris A terisi sebagai SKU.
- Harga sumber tersimpan sebagai teks dengan pemisah ribuan titik. Seluruh Harga Jual produk berisi nol sehingga tidak dipakai sebagai harga jual toko secara otomatis.
- Ada 220 baris produk tanpa Harga dan 496 produk dengan Harga eksplisit nol. Keduanya berbeda. Di antara baris tanpa harga terdapat kode bayar tagihan; tidak cukup untuk menyimpulkan semua produk tersebut gratis atau seluruhnya pascabayar.
- Status: Open 6.225, Gangguan 173, Close 1. Tidak ada SKU duplikat pada baris produk yang diperiksa. Ini jumlah pada file, bukan bukti ketersediaan pemasok saat ini.
- Tidak tersedia kolom jumlah stok. Jangan mengubah stok menjadi 0 hanya karena kolomnya tidak ada.

### Solusi: profil impor yang dapat disimpan

Profil impor (contoh: Webreport OtomaX Format A) dipisahkan dari kelompok jawaban transaksi. Koneksi memilih profil impor dan kelompok jawaban secara terpisah; beberapa koneksi dengan struktur file sama bisa memakai profil impor yang sama. Lingkup awal per toko dan pustaka preset admin/duplikasi lintas toko, mengikuti isolasi kelompok jawaban.

Konfigurasi profil:
1. Jenis file xlsx/csv; worksheet terpilih, baris header, baris awal data. Untuk CSV: delimiter koma/titik koma/tab, encoding dan tanda kutip; auto-detection hanya saran yang dikonfirmasi lewat preview.
2. Mode struktur: tabel datar atau blok berkelompok. Aturan baris kelompok vs produk, lokasi judul kelompok, pewarisan kelompok ke baris produk, pengabaian judul/header berulang/baris kosong/footer. Sel gabungan tidak diratakan menjadi produk duplikat.
3. Pemetaan kolom berdasarkan nama header yang divalidasi, bukan posisi absolut saja. Alias Kode Produk/SKU/Kode dan Keterangan/Nama/Deskripsi dapat disarankan. Header ganda/perubahan struktur wajib dipilih ulang, tidak memilih kolom pertama secara diam-diam.
4. Pemetaan field target: sku, nama, modal, layanan, kategori, status, stok opsional, deskripsi. Nilai konstan dan sumber judul kelompok tersedia; layanan/kategori dapat dipetakan massal ke struktur toko. Nama kelompok supplier adalah source category awal, tidak otomatis dibuat menjadi layanan/game/provider tanpa konfirmasi. Penggabungan nama kelompok + keterangan boleh dipilih agar nama seperti 40 Diamond mempunyai konteks.
5. Format angka: tipe angka asli vs teks, pemisah ribuan/desimal, mata uang, trimming. Preview memperlihatkan 8.700 → 8700 untuk profil Indonesia. Format ambigu tidak ditebak diam-diam. Kode/tujuan tetap string; zero padding yang hilang pada sumber numerik perlu peringatan, jangan direkonstruksi tanpa aturan jelas.
6. Mapping status konfigurable: pada contoh Open → tersedia menurut file, Gangguan/Close → tidak tersedia. Nilai asing/kosong wajib diperiksa atau diblokir, tidak otomatis tersedia. Jumlah stok tak diketahui null, bukan 0/tanpa batas.
7. Kebijakan harga: gunakan Harga sebagai modal untuk contoh, abaikan Harga Jual dan Keuntungan kecuali owner sengaja memetakannya. Pertahankan markup dan harga jual katalog toko. Harga kosong/nol dipisahkan dari valid; prepaid tanpa modal valid ditahan, inquiry/postpaid memerlukan definisi jenis/admin/komisi yang benar. Impor tidak membuka produk Rp0 otomatis.

### Alur modal dan pemrosesan

Pilih supplier + profil → upload → pilih sheet/struktur dan mapping → Uji/pratinjau → konfirmasi → proses background. Pengguna biasa memakai preset tersimpan; pengaturan lanjutan hanya dibuka saat perlu.

- Pratinjau menampilkan sampel dalam area tabel terbatas, pencarian dan scroll/pemuatan bertahap, tanpa prev/next di modal. Ringkasan menghitung seluruh file: baru, berubah, tetap, ditolak, kode duplikat, status tak dikenal, harga kosong/nol, dan produk yang tidak ada di file.
- Gagal validasi struktural memblokir impor. Baris tidak valid tidak menimpa record existing. Impor sebagian hanya jika dipilih eksplisit dengan laporan baris ditolak; jangan diam-diam melewati produk.
- Duplikat kode dalam satu koneksi/mode tidak first-wins/last-wins; tampilkan konflik untuk diselesaikan. Supplier berbeda dengan kode sama tetap terisolasi.
- Impor ke produk sumber dahulu; memasukkan/menautkan ke produk jual tetap memakai workflow impor katalog yang ada. Reimpor update berdasarkan connection_id + mode + SKU, mempertahankan produk katalog/markup/manual-disable toko.
- File yang tidak memuat SKU existing bukan perintah hapus. Bawaan pertahankan dan tandai tidak tercantum; jika memilih snapshot penuh, sediakan opsi nonaktifkan yang hilang dengan preview eksplisit, bukan hapus produk/parsing historis.
- Profil tersimpan/versioned, perubahan nama/urutan kolom diverifikasi pada upload berikutnya. Status Open dari file tidak boleh dianggap hasil refresh live. Simpan waktu unggah/impor dan tanggal snapshot jika supplier benar-benar memberikannya; tanggal nama file bukan bukti otoritatif.
- Snapshot preview mengikat file hash, versi profil, supplier dan opsi yang dikonfirmasi. Eksekusi memeriksa ulang akses/entitlement, koneksi serta snapshot supaya file/target tidak tertukar. Perubahan harga lebih baru dari callback/impor lain tidak boleh tertimpa diam-diam oleh job lama.
- File disimpan sementara pada disk privat dengan batas ukuran/baris/kolom/ekspansi arsip dan dihapus sesuai masa simpan. Baca data tanpa mengeksekusi makro/formula/tautan eksternal. Formula harga tidak dievaluasi otomatis; tolak atau minta ekspor nilai tersimpan yang sah sesuai kebijakan.
- Job berjalan bertahap agar file ribuan produk tidak menahan request web. Retry idempotent, progress dan laporan tersedia pada riwayat impor. Audit meliputi supplier/profil/version/operator dan perubahan modal/status, tanpa menyimpan credential.

Kriteria uji tambahan: file contoh berkelompok, file datar, CSV delimiter berbeda, sheet/header berpindah, kolom harga tertukar, locale angka, nilai kosong vs nol, SKU hilang nol awal, duplikat, status baru, upload ulang, file snapshot parsial, cross-store, dan impor concurrent. Mendukung format berbeda berarti aturan yang dikonfigurasi dan diuji; tidak menjanjikan seluruh layout arbitrer dikenali sempurna secara otomatis.


## Rencana lanjutan: katalog OtomaX dari URL Webreport — 22 Oktober 2026

Status: PERENCANAAN, belum diimplementasikan. Perubahan yang sudah dibuat hanya menyembunyikan UI impor berkas. Bagian ini menjadi arah tahap berikutnya; rancangan impor Excel/CSV sebelumnya tetap menjadi fondasi cadangan.

### Keputusan dan hasil pemeriksaan

- Sesuai permintaan pengguna, tombol Impor file serta tab Profil impor/Riwayat impor disembunyikan pada panel owner/staff, termasuk navigasi Penguji jawaban. Form/profil frontend, endpoint, pembaca Excel/CSV, job, tabel dan data historis tidak dihapus. Ini perubahan visibilitas UI, bukan pencabutan endpoint backend. Modal impor dan tindakan pembuatan/edit profil impor juga tidak dibuka melalui UI selama flag OTOMAX_FILE_IMPORT_UI_ENABLED=false.
- Ketiga contoh yang diberikan (multikom.webreport.info, bospulsabiller.cekreport.com, digitalammunitionstore.webreport.info) diperiksa dengan GET baca-saja pada 22 Oktober 2026: HTTP 200, Content-Type text/html; charset=UTF-8. Isi berupa tabel HTML berkelompok dengan Kode, Keterangan, Harga, Status. Bukan JSON meskipun path bernama harga.js.php. Respons sekitar 0,72–2,25 MB. Ketersediaan sekarang bukan jaminan format/akses tidak berubah.
- URL lengkap mengandung parameter akses akun. Tidak menyalin token URL asli ke dokumentasi, fixture atau Git. Tidak meminta transaksi, tiket deposit, atau pembelian dalam pemeriksaan tersebut.
- Implementasi baru akan memperbarui SupplierProduct yang sudah ada, tetap unik(connection_id, mode, sku). Pengaturan katalog toko, pemetaan produk dan transaksi yang sudah ada dipertahankan.

### Alur panel yang direncanakan

1. Dalam modal Atur supplier, tambahkan kelompok Daftar harga: URL tersimpan secara privat, profil pembacaan (awal: Webreport HTML), aktif/nonaktif sinkronisasi otomatis, interval, dan penanganan produk baru/hilang. URL yang sudah tersimpan ditampilkan tersamarkan; penggantian dan aksesnya mengikuti hak koneksi supplier.
2. Uji URL mengambil daftar harga satu kali melalui server dan menampilkan pratinjau terbatas: kelompok, kode, nama, modal, status serta jumlah valid/ditolak/baru/berubah. Tidak mengubah harga sebelum pemetaan disetujui. User tidak mengedit/menormalisasi file supplier.
3. Aktifkan setelah hasil uji disetujui, simpan fingerprint URL + versi profil dan jadwalkan pembaruan. Mengganti URL/profil perlu pengujian ulang agar tidak menerapkan hasil sumber lama ke koneksi baru.
4. Pada kartu koneksi tampilkan waktu berhasil terakhir, jadwal berikutnya, status sinkronisasi dan pesan kegagalan aman. Tombol Sinkronkan sekarang memakai job dan lock yang sama; tidak menembus cooldown.
5. Riwayat sinkronisasi katalog berupa daftar utama berfilter/pagination: supplier, waktu, hasil, jumlah produk baru/harga berubah/ditolak/hilang. Rincian dalam modal. Riwayat callback transaksi tetap terpisah.
6. Owner dapat mengatur semuanya; staff tetap mengikuti suppliers.manage untuk pengaturan koneksi dan suppliers.import untuk operasi katalog. Penambahan izin baru hanya bila diperlukan, tidak otomatis memperluas akses staff.

### Aturan pembacaan dan perubahan data

- Parser awal membaca DOM tabel Webreport tanpa menjalankan script, CSS atau HTML supplier di panel. Ambil judul kelompok dan cocokkan kolom berdasarkan header, bukan posisi tetap. Pemetaan header/status/locale angka dapat disimpan sebagai profil reusable per toko untuk format lain yang benar-benar diuji.
- Normalisasi internal mengubah harga 10.000 menjadi integer rupiah 10000, mempertahankan kode berbentuk teks/nol awal, serta memetakan status Open/Close/Gangguan secara eksplisit. Status tak dikenal, harga kosong/nol/tidak valid, kode duplikat dan tabel login/error bukan katalog yang sah.
- Perbarui harga beli, status ketersediaan dan data sumber yang valid berdasarkan koneksi + mode + SKU. Status Open tidak dianggap angka stok; jumlah stok tetap null bila feed tidak melaporkannya.
- Produk baru masuk Sumber produk. Tidak otomatis memublikasikan katalog jual: owner/staff tetap memakai fitur impor dari sumber yang sudah tersedia. Pengimporan dari Sumber produk berbeda dari unggah file Excel/CSV yang disembunyikan.
- Layanan/kategori, tujuan, gambar, aktif/nonaktif katalog dan markup yang telah diatur toko tidak ditimpa oleh perubahan kelompok/nama supplier. Perubahan produk sumber boleh dicatat tanpa mengganti pengaturan katalog jual.
- Produk auto-update direprice memakai ProductPricing/StoreCatalog existing: produk lalu kategori lalu layanan. Harga jual manual tetap; aturan modal melebihi harga jual dan snapshot harga pesanan existing tetap berlaku.
- Keputusan pengguna 22 Oktober 2026: setelah snapshot daftar ALL produk supplier dinyatakan lengkap dan valid, SKU yang sebelumnya ada tetapi tidak ditemukan lagi WAJIB diubah menjadi available=false pada koneksi dan mode tersebut. Ini otomatis, bukan pilihan keep. Data sumber, pemetaan, harga historis dan transaksi tidak dihapus; keterangan yang direncanakan: Tidak tersedia — tidak ditemukan pada daftar harga supplier. Produk dari koneksi/toko lain tidak disentuh. Harga nol tidak pernah menjadi harga jual gratis.
- Penonaktifan SKU hilang dilakukan sesudah seluruh hasil berhasil diterapkan, dalam finalisasi snapshot; job gagal/parsial tidak menjalankan rekonsiliasi penghapusan. Respons kosong/login/HTML rusak/unduhan terpotong/daftar terfilter tidak boleh dianggap daftar ALL. Penurunan jumlah ekstrem perlu penanganan anomali sebelum perubahan massal; pratinjau mencantumkan jumlah produk hilang. Kondisi katalog benar-benar kosong memerlukan konfirmasi yang dapat dipercaya, tidak disimpulkan dari respons kosong.
- Ketika SKU muncul lagi pada snapshot berikutnya dengan status tersedia dan harga valid, sumber tersebut dapat diaktifkan kembali. Aktif/nonaktif katalog yang diatur owner tidak ikut berubah. Bila semua pemetaan tidak tersedia, produk toko tidak dapat dipesan; jika masih ada sumber valid lain, hanya sumber itu yang menjadi kandidat.
- Untuk pesanan yang sudah dibuat tetapi belum dikirim, periksa ulang produk sumber sebelum dispatch: jika sudah tidak tersedia, hapus dari kandidat eksekusi (attempt gagal tanpa HTTP) dan lanjut sumber berikutnya. Jika semua habis, gagal/refund sesuai aturan existing. Produk yang menghilang setelah attempt sudah dikirim/pending tidak boleh otomatis memicu fallback/refund karena transaksi supplier mungkin masih diproses; tunggu hasil pasti/callback atau tindakan pemeriksaan manual existing.
- Hasil unduhan disiapkan dan divalidasi sebelum diterapkan. Gunakan job idempoten dengan staging/chunk, pemeriksaan versi koneksi/lingkungan dan lock bersama proses impor berkas. Retry melanjutkan/mengulang penerapan snapshot yang sama tanpa duplikasi.
- Cegah snapshot URL lama menimpa harga yang lebih baru dari callback transaksi: rencanakan metadata asal/waktu pembacaan harga dan pemeriksaan versi saat menulis. Waktu supplier hanya dipercaya jika benar-benar tersedia; waktu fetch bukan jaminan halaman supplier real time.

### Scheduler, cache dan perlindungan kegagalan

- Usulan interval awal: 15 menit, pilihan 5/15/30/60 menit, mengikuti ketentuan supplier. Angka ini usulan produk, bukan limit resmi OtomaX/Webreport yang sudah dikonfirmasi.
- Scheduler tiap menit hanya mengantrekan koneksi yang waktunya sudah tiba. Queue worker melakukan GET/parse/update; tidak menahan request halaman panel. Pakai next_sync_at, lock per koneksi, deduplikasi job, jitter dan retry dengan backoff agar semua toko tidak mengunduh bersamaan.
- Cache/lock unduhan bersama hanya jika URL berparameter akun dan versi parser identik, menggunakan fingerprint HMAC. Jangan gabungkan berdasarkan hostname supplier saja karena harga bisa berbeda per member. Pemakaian snapshot cache tidak memperbarui observed_at secara palsu.
- Validasi HTTPS, DNS/IP publik dan target setiap koneksi; cegah akses localhost/jaringan internal/DNS rebinding. Redirect tidak diikuti secara bebas. Batas awal yang diusulkan: 10 MB hasil unduhan terurai, 20.000 produk, timeout 30 detik; dievaluasi dengan sampel sebelum ditetapkan.
- URL/token disimpan terenkripsi dan disamarkan di log/error; tidak dikirim ke browser pelanggan. Pembacaan HTML hanya di server, keluaran teks di React tetap di-escape. Tidak mengeksekusi JavaScript dari halaman supplier.
- Timeout, HTTP error, halaman kosong/login, format berubah, tabel terpotong, atau hasil ambigu mempertahankan katalog sebelumnya dan memberikan alasan aman. Sinkronisasi gagal tidak menaikkan waktu freshness. Batas umur katalog existing (catalog_hours) tetap menentukan kapan sumber dianggap kedaluwarsa.
- Mode Pengujian memakai fixture, tidak mengunduh supplier nyata secara terjadwal. Uji URL supplier nyata dapat direncanakan sebagai tindakan terpisah yang jelas tanpa transaksi atau penggantian data produksi.
- Job yang sudah antre harus memeriksa ulang toko/koneksi aktif, lingkungan dan fingerprint konfigurasi sebelum fetch dan sebelum menulis. Penonaktifan/penghapusan toko menghentikan penerapan; cleanup tetap mengikuti StoreRetention existing.

### Urutan implementasi dan ukuran selesai

1. Parser HTML Webreport dengan sampel tersanitasi dari tiga format, validasi dan pratinjau. Tentukan kebutuhan dependency parser setelah memeriksa ekstensi DOM yang tersedia; belum menambah paket.
2. Penyimpanan URL/pengaturan privat, pengujian URL dan modal panel compact. Pisahkan state respons uji dari konfigurasi aktif.
3. Mesin penerapan snapshot ke produk sumber existing, pemeriksaan race dengan impor/callback serta reprice aman.
4. Queue, scheduler due-only, shared cache, backoff, riwayat dan tombol sinkronisasi manual.
5. Regresi dan pemeriksaan UI owner/staff; dokumentasi migrasi/deployment dan worker diperbarui setelah implementasi selesai.

Ukuran selesai: owner/staff menguji serta mengaktifkan URL; harga/status sumber berubah terjadwal dan hasilnya dapat diaudit; harga otomatis ikut berubah pada bot/web; harga manual dan pengaturan toko tetap; error tidak mengosongkan katalog; tenant dan URL akun lain tetap terisolasi.

Skenario uji: tiga struktur tabel, locale harga, nol awal SKU, nama panjang, status asing, SKU duplikat, HTTP 401/403/429/500, timeout, tabel kosong/parsial, batas ukuran, internal-IP/redirect, token tidak bocor, impor ulang, produk baru/hilang, beda akun pada host sama, shared URL, jadwal/lock berbarengan, profil berganti saat job berjalan, callback lebih baru, harga manual/markup, katalog kedaluwarsa, sandbox, izin staff dan isolasi toko. Integrasi langsung tidak memakai pembelian produk atau pembayaran nyata.
