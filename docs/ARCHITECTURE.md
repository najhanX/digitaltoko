# Arsitektur DigitalToko

Status source lokal: **3 November 2026**. Dokumen ini menggantikan rancangan awal yang masih menyebut skeleton, trial baru, dan checkout sebagai pekerjaan berikutnya. Catatan pengujian/deployment tidak disimpulkan dari keberadaan kode.

## Runtime dan batas aplikasi

`apps/web` memakai Laravel 13, Inertia 3, React 19/TypeScript dan Vite. Panel dan storefront berada dalam satu aplikasi. Database Laravel terpisah dari database API Hanshop; MySQL menjadi target deployment, SQLite dipakai untuk development/test tertentu.

`apps/bot` memakai Node/TypeScript dan Baileys 7.0.0-rc14. Satu manager pada loopback (default 3099) menjalankan child worker per toko dengan port loopback dinamis, token serta direktori auth sendiri. Registry dan session adalah file privat, bukan state browser.

API Hanshop adalah backend Node/Express + MySQL/Redis di proyek terpisah. Bot `bot_wa_v2` melayani toko Hanshop sendiri. HansDev Forwarder (Flutter + Java Android) hanya menangkap/mengirim notifikasi pembayaran; server yang memvalidasi dan menyelesaikan invoice.

## Peta source

| Alur | Source utama |
| --- | --- |
| Routing toko berdasarkan host | `apps/web/app/Support/PlatformHost.php`, middleware `ResolvePlatformHost` / `RequirePlatformHost` |
| Identitas owner dan KTP | controller OwnerAuth/OwnerOnboarding/Verification, `Support/HanshopIdentity.php` |
| Hak toko dan staff | `Models/Store.php`, `Support/CatalogAccess.php`, `Support/StaffPermissions.php`, controller terkait |
| Langganan | `Services/SubscriptionService.php`, `SubscriptionController.php`, `PlanOption`, `SubscriptionInvoice` |
| Pemasok dan harga | `SupplierCatalog`, `SupplierCache`, `StoreCatalog`, `ProductPricing`, `CatalogTaxonomy`, `CategoryDestination` |
| Pesanan dan pembayaran | `CustomerCommerce`, `CustomerInquiryService`, `CustomerPayments`, `CustomerFulfillment`, job `ProcessCustomerOrder` |
| Wallet dan koreksi hasil | `CustomerWallet`, `CustomerOrderDecision`, `CustomerLedger`, `DigiflazzWebhookController` |
| OTP/notifikasi | `CustomerOtp`, `CustomerDelivery`, `StoreMail`, `BotManager`, `CustomerNotice`, `PanelNotifications` |
| Voucher | `Vouchers`, `DiscountVoucher`, `VoucherController`, `CommerceModules` |
| Storefront | `resources/js/pages/Storefront`, komponen desain/banner/checkout bersama |
| Artikel | StoreArticle/PublicArticle controller, `ArticleContent`, Tiptap editor dan `ArticleBody` |
| Worker WhatsApp | `apps/bot/src/manager.ts`, `index.ts`, `commerce.ts`, `catalog.ts`, `direct-message.ts` |

## Paket dan capability

| Paket | Kanal transaksi | Sumber produk |
| --- | --- | --- |
| Web Topup + Bot WhatsApp | Web + Bot | Digiflazz, API Hanshop, OtomaX, stok premium |

Sejak 3 November 2026 satu paket mencakup Web Topup + Bot WhatsApp, dengan variasi durasi saja. Definisi bersama: `apps/web/resources/js/packageCatalog.json`. Kunci internal `category=full`, `plan=galaxy` dipertahankan. Harga/durasi penawaran lengkap tetap dipakai; penawaran kanal tunggal dinonaktifkan. Entitlement semua toko diperluas tanpa mengubah masa aktif atau snapshot invoice lama. Pembelian berikutnya menjadi perpanjangan; jadwal lama tetap dihormati dengan entitlement lengkap.

Setiap toko dapat memakai web dan satu bot WhatsApp untuk OTP/notifikasi/katalog/saldo/pemesanan. Trial baru tidak tersedia; promo memakai voucher platform termasuk 100%. Aktivasi pertama mensyaratkan WA terhubung dan subdomain bawaan siap; domain pribadi tetap opsional.

## Data multi-store

Satu database aplikasi menyimpan data banyak toko dengan scope `store_id`, bukan satu database atau salinan frontend per toko. Owner global dapat memiliki banyak toko; staff dan customer lokal hanya memiliki konteks toko masing-masing. Host publik memetakan toko; sesi/permission/token menentukan otorisasi.

Produk, stok, ledger, order, invoice, media, credential, notifikasi dan job harus membawa konteks toko. Customer dengan nomor sama di toko berbeda memiliki wallet berbeda. Cache pemasok merupakan pengecualian yang disengaja: data baca dibagikan hanya untuk akun pemasok dan operasi/mode yang sama, bukan credential atau wallet tenant.

## Alur owner dan langganan

Owner OTP → profil dan verifikasi email → review KTP approved → toko draft → pilih paket/durasi/voucher → invoice → provisioning → siap → aktivasi owner atau otomatis setelah 24 jam siap.

Invoice berbayar diterbitkan/diperiksa melalui bridge billing Hanshop dan tidak mengkredit saldo Hanshop. Invoice voucher Rp0 dilunasi lokal tanpa gateway. Pembelian pertama tetap menunggu aktivasi. Semua toko memerlukan slug dan WA CONNECTED. Ini tidak membuktikan DNS/TLS custom domain sudah siap.

Renew aktif menambah dari expiry, renew setelah habis dari paid_at. Upgrade/perubahan efektif memakai harga penuh paket tujuan dan masa aktif baru. Pengurangan fitur yang masih aktif dijadwalkan pada akhir periode. Grace tujuh hari masih menerima pesanan; penangguhan admin terpisah dan tidak hilang karena pembayaran. Lifecycle sebenarnya berada di `SubscriptionService` dan `Store::acceptsOrders()`.

## Katalog dan fulfillment

Owner mengatur akun melalui Koneksi supplier (Digiflazz/API Hanshop/OtomaX) → sync snapshot → impor/mapping layanan-kategori-produk → konfigurasi parsing/harga/stok. Impor tidak membeli barang pemasok. Markup dan auto-update diwariskan secara independen: produk → kategori → layanan; aturan tidak dijumlahkan antarlevel. Dasar markup diatur pada aturan kategori/layanan: termurah (`cheapest`) atau termahal (`highest`, bawaan), dengan prioritas kategori lalu layanan. Pengaturan ini memilih dasar harga jual, sedangkan urutan fulfillment tetap dari kandidat termurah. Harga snapshot pelanggan tidak dinaikkan diam-diam ketika failover; sumber lebih mahal tetap tunduk batas modal dan kepastian hasil request.

`SupplierCache` memakai cache Redis default 120 detik (dibatasi 60–180) untuk saldo dan katalog Hanshop, fingerprint akun HMAC, lock dan cooldown error. Katalog Digiflazz memakai cache 300 detik sesuai limit price-list; penolakan RC 83/HTTP 429 menahan percobaan katalog berikutnya selama 300 detik. Cache katalog tetap terpisah menurut akun dan mode prepaid/pasca. Scheduler berjalan setiap dua menit, tetapi memakai snapshot cache yang masih berlaku; pembacaan katalog juga meminta refresh bila perlu. OtomaX dikecualikan dari command katalog Digiflazz/Hanshop; katalog URL HTML-nya diperbarui melalui digitaltoko:otomax-catalogs dan job SyncOtomaxCatalog, dengan interval per koneksi. Fondasi impor berkas tetap ada tetapi UI-nya disembunyikan. Alasan validasi/koneksi Digiflazz yang aman, kode RC, dan IP yang ditolak (RC 45) disimpan pada `last_error`, tanpa menampilkan body respons mentah/kredensial. Kegagalan refresh mempertahankan snapshot, tetapi sumber remote melebihi batas umur default 600 detik tidak eligible untuk pemesanan.

Checkout web/bot membuat snapshot harga, tujuan, gateway, voucher dan referensi idempotensi. Pesanan wallet didebit atomik; gateway menunggu pembayaran terverifikasi. Setelah commit, fulfillment dijadwalkan melalui queue. Stok ready diklaim atomik; sumber remote memakai parsing termurah dan fallback hanya setelah kegagalan yang pasti. Timeout/hasil ambigu tetap pending dan tidak berpindah sumber tanpa kepastian. Pengiriman ulang pemasok hanya melalui tombol panel owner/staff, bukan polling scheduler. Khusus OtomaX, request transaksi dapat dikirim ulang dengan identitas/RefID yang sama pada hari pengiriman awal (WIB), sesuai kontrak deduplikasi supplier.

Callback Digiflazz: satu URL platform `/api/commerce/digiflazz`, validasi signature dan hubungan attempt dengan pesanan. Midtrans dan Forwarder menggunakan URL per toko. Status/SN baru Digiflazz dapat mengoreksi hasil terminal/manual; refund yang sudah diberikan dapat ditarik kembali, termasuk menjadi saldo minus. Ini bukan fitur keputusan manual universal untuk semua pemasok.

## Pemisahan uang dan notifikasi

Empat domain berbeda: saldo Hanshop owner, saldo customer toko, pembayaran produk/topup customer, dan pembayaran sewa/modul. Tidak ada wallet tenant yang menjadi salinan saldo Hanshop.

OTP owner/platform tetap lewat jalur identitas platform. OTP customer memakai WA toko atau email bermerek toko, tanpa fallback WA pusat tersembunyi. Pengiriman email memakai platform atau SMTP tenant terenkripsi. CustomerNotice menyimpan outbox; manager diberi sinyal setelah commit, polling worker dan scheduler menjadi cadangan. Gangguan antara pengiriman WA dan ACK dapat menyebabkan notifikasi ulang, bukan pesanan/debit baru.

## Mode pengujian

`StoreEnvironment` menyimpan sandbox per toko; perubahan ditolak jika ada order berjalan atau inquiry yang masih valid. Digiflazz memakai development key dan testing. Hanshop/stok premium disimulasikan, wallet uji terpisah, pembayaran lokal dapat disimulasikan atau memakai Midtrans Sandbox. Mode sandbox toko berbeda dari setting sandbox merchant; jangan menganggap QRIS Sandbox dapat dibayar dengan uang nyata.

## Proses berkala dan batas tersisa

`routes/console.php` menjadwalkan invoice/langganan dan commerce tiap menit, sinkron Digiflazz/Hanshop tiap dua menit dan antrean URL OtomaX tiap menit, outbox notice tiap lima detik, purge KTP ditolak tiap menit, pembersihan upload artikel harian, serta evaluasi retensi toko setiap jam. Retensi hanya menghapus jika `STORE_PURGE_ENABLED=true`; mode bawaan hanya memeriksa/mengirim pengingat. Queue worker menjalankan job setelah pembayaran/commit.

`StoreObligations` memeriksa saldo positif/minus, pesanan, refund/guest, inquiry, invoice sewa dan modul. `StoreRetention` menghitung expiry+7/+30, menahan purge, menghapus notice expiry lama ketika diperpanjang, serta menjalankan purge dua fase dengan lock owner/toko. Claim `purging` memblokir renewal dan callback/mutasi; kegagalan cleanup disimpan sebagai error untuk retry. Data relasional toko, media lokal, job database/failed job terkait, cache bot/OTP dan registry/sesi WA dibersihkan; owner dan API Hanshop tidak dihapus. Kuota voucher platform anonim diarsipkan. Claim owner yang diketahui tetap disimpan tanpa isi transaksi/kontak agar batas sekali per owner bertahan setelah toko dihapus. DNS/TLS provider, penghapusan DNS/certificate eksternal, dan operasi worker multi-host belum sepenuhnya diotomatisasi. Refund sewa awal setelah persetujuan admin tersedia melalui Provisioning; bukan refund otomatis untuk semua pembatalan. Mapping custom host verified sudah ada; workflow Cloudflare for SaaS masih rancangan. Detail konfigurasi dan batasnya di DEPLOYMENT.md/DOMAINS.md.

Rincian batas backup, antrean khusus, kanal reminder, dan hasil validasi retensi: [RETENTION_PROGRESS.md](RETENTION_PROGRESS.md).

Penghapusan manual memakai DELETE /admin/stores/{store} (admin web saja). StoreClosureController mencatat closure_requested_at dan memberi notice admin. StoreRetention::approveManualDeletion memverifikasi token preview di bawah lock dan mengeksekusi cleanup bersama dengan origin manual; tidak bergantung pada flag purge otomatis atau expiry. Migration add_manual_store_deletion_fields mengakomodasi toko suspended_manual lama. Draft yang tidak pernah punya sesi/subscription dapat dihapus tanpa manager; lainnya tetap perlu ACK disconnect.


## Adapter OtomaX

`OtomaxClient` membatasi host ke IP publik, mem-pin resolusi DNS, menolak redirect, menghitung SHA1 binary/Base64URL, dan memanggil IP Center. OtomaX dapat mempunyai beberapa `SupplierConnection` per toko, dibedakan `slot`; Digiflazz/Hanshop mempertahankan slot primary. `OtomaxProfile` menyimpan kelompok respons atau pemetaan impor yang dipakai ulang. Respons mendukung template placeholder lama, awalan/akhiran per field, serta RegEx PCRE dengan named group allowlist dan batas pemrosesan. Tidak ada eksekusi PHP. Penguji terpisah memakai matcher yang sama dengan transaksi. Konflik atau pola asing tetap pending/review.

`OtomaxImports` membaca XLSX/CSV tanpa mengevaluasi formula, menyimpan pratinjau privat terenkripsi, dan melakukan upsert per connection/mode/SKU lewat `ImportOtomaxProducts`. Lock per koneksi mencegah impor bersamaan saling menimpa. Data sumber dipetakan melalui import existing ke `CatalogProduct`/`ProductMapping`; bot/web memakai mesin pesanan bersama. Profil respons dan credential disalin terenkripsi ke attempt sehingga perubahan profil tidak merusak callback pesanan lama. Marker dispatch dan waktu pengiriman pertama disimpan sebelum HTTP. Jika endpoint cek status tersedia, pemeriksaan memakai endpoint tersebut; jika tidak, tindakan manual Kirim ulang ke pemasok boleh mengirim ulang request OtomaX identik dengan RefID yang sama pada hari pengiriman pertama (Asia/Jakarta), minimal satu menit antarpemeriksaan. Scheduler/queue hanya mengirim percobaan baru yang belum pernah dikirim; attempt remote yang sudah dikirim menunggu callback atau tombol manual. Setelah berganti hari, hanya menunggu callback tanpa request transaksi ulang. Attempt lama tanpa timestamp memakai tanggal pembuatan attempt sebagai batas konservatif. Respons `sdh pernah` harus cocok dengan penangkap aktif untuk event tersebut. Tanpa pola yang cocok, status tetap pending walaupun query menyebut sukses/gagal. Status berasal dari field status yang ditangkap atau literal status pada template cocok; pilihan outcome manual tidak lagi menjadi sumber keputusan. Konflik query/teks tetap ditahan.

Callback memeriksa token, IP, toko/koneksi, refID, SKU dan tujuan jika tersedia. Status terminal dapat dikoreksi menggunakan rekonsiliasi refund existing; saldo minus tetap diblokir untuk transaksi baru. Sukses dari parsing lama setelah sumber lain sudah dikirim masuk review, karena ada risiko transaksi ganda. Detail cakupan dan batas protokol ada di OTOMAX_PROGRESS.md.

Validasi identitas Digiflazz membandingkan SKU tanpa membedakan kapitalisasi karena respons pemasok dapat mengubah huruf besar/kecil; RefID dan nomor tujuan tetap persis. Nomor pesanan berbeda dari referensi setiap attempt, yang ditampilkan sebagai RefID pemasok di rincian transaksi.


### Katalog URL OtomaX

SupplierConnection menyimpan catalog_config terenkripsi, revision, status/error, waktu pembacaan dan jadwal berikutnya. URL lengkap tidak menjadi props panel; hanya hostname, status konfigurasi, profil pembacaan dan pengaturan interval yang boleh dibaca sesuai akses toko. Uji URL menyimpan snapshot privat sebelum aktivasi. OtomaxImport.source membedakan file (fondasi lama) dan url (riwayat/pratinjau sinkronisasi), dengan metadata terenkripsi kecil dan baris preview pada berkas terenkripsi memakai helper existing; tidak menyimpan ribuan produk dalam satu packet kolom database.

OtomaxWebCatalog membatasi HTTPS port 443, memvalidasi IP publik, memin koneksi DNS menggunakan CURLOPT_RESOLVE, menolak redirect, membatasi unduhan 10 MiB dan 20.000 produk, serta membaca DOM HTML tanpa mengeksekusi script. Format awal adalah tabel Webreport berkelompok. Penutup tr/td yang opsional secara HTML didukung; table terpotong ditolak. Header/status dapat dipetakan per koneksi dan disalin dari koneksi lain dalam toko. Cache hasil unduhan terenkripsi selama 300 detik dibedakan oleh HMAC URL lengkap dan profil; token akun tidak menjadi key mentah dan host sama dengan URL akun berbeda tidak berbagi snapshot.

Scheduler tiap menit hanya mengantrekan koneksi aktif yang jatuh tempo (5/15/30/60 menit; default 15). Job memakai lock koneksi yang sama dengan importer berkas, memeriksa toko, paket, mode, fingerprint, revisi konfigurasi dan snapshot terakhir sebelum fetch/commit. Produk sumber di-upsert bertahap 100 baris dalam satu transaksi. SKU hilang pada mode prepaid di koneksi yang sama menjadi available=false dengan availability_reason; tidak menghapus mapping/riwayat atau mengubah layanan/kategori/markup/aktif katalog toko. Produk muncul kembali dapat tersedia lagi. Hanya produk auto-update direprice; callback lebih baru dilindungi oleh price_observed_at. Katalog toko dan fulfillment selalu memakai status tersedia sumber.

Harga nol/rusak ditahan sebagai tidak tersedia, bukan produk gratis; harga lama dipertahankan jika sudah ada. Respons kosong/login/parsial/duplikat/status asing tidak menimpa katalog. Penurunan lebih dari separuh dari minimal 20 sumber yang sebelumnya tersedia, atau daftar kosong dengan header/kelompok yang dikenali, memerlukan pratinjau dan persetujuan sebelum penonaktifan massal. Respons kosong tanpa struktur valid tetap ditolak. Tidak menganggap SKU yang sudah nonaktif sebagai penghapusan baru pada setiap run. Gangguan mendapat cooldown lima menit; observed_at tidak dibuat seolah segar saat gagal/menggunakan cache. Queue usang lebih dari 15 menit ditandai gagal agar dapat dipulihkan.

Mode pengujian tidak mengunduh URL nyata; fixture dan HTTP fake dipakai untuk pengujian. Penggantian akun/alamat supplier menghapus konfigurasi URL dan membatalkan generasinya, sehingga akun baru harus diuji ulang. Penutupan/penghapusan toko membersihkan job URL dari jobs/failed_jobs melalui allowlist StoreRetention dan direktori privat existing. Tidak menambahkan request ulang transaksi supplier.


## Komposisi storefront dan editor warna — 22 Oktober 2026

`StorefrontMobileMenu` dipakai AuroraHeader, AtlasHeader dan NebulaLayout, dengan CSS mobile terpisah hingga 700 px; desktop tetap menampilkan navigasi lengkap. `StorefrontSearch` membaca endpoint `/search` bertahap, lima kategori/delapan produk per halaman, tanpa refresh supplier pada ketikan. Hasil digabung per jenis, dideduplikasi, permintaan kata kunci lama dibatalkan, dan posisi item terlihat dipertahankan saat kategori tambahan masuk. Observer bawah, loading/retry dan cache browser singkat mengendalikan pemuatan lanjutan.

`AuroraQuickOrder` mengambil lima produk per halaman, menggabungkan hasil saat area dropdown digulir dan menampilkan spinner minimal 500 ms. `PickerDropdown` memakai popover browser agar daftar tidak mendorong form; pergantian provider/layanan mereset hasil dan membatalkan request lama. Harga/normalisasi tujuan/checkout tetap lewat backend bersama. Katalog Home Aurora/Atlas dan endpoint kategori menggunakan sale_price naik sebelum pagination; preview lokal juga mengurutkan harga secara numerik.

`storefrontDesign.ts` menyediakan 24 preset penuh: delapan terang, delapan gelap, delapan pastel. Masing-masing mengisi primary dan enam token warna; kelompok/nama hanya metadata UI, payload toko tetap accent/colors. `designStyle` memetakan token ke CSS dan memilih warna teks tombol primary. Tidak ada kode frontend berbeda per toko; editor preset memperbarui draft/pratinjau, bukan otomatis menerbitkan.

Custom domain owner menggunakan StoreDomains/DomainDns dan store_domains: klaim → TXT/arah DNS → proof HTTPS → active. Origin dipin ke IP publik terpercaya, tanpa redirect. Endpoint izin On-Demand TLS terpisah dari routing storefront; domain pending hanya mengakses proof terbatas. Lihat CUSTOM_DOMAINS.md.

## 26 Oktober 2026 — Saldo dan deposit pemasok

- Menu Koneksi supplier → Saldo & deposit pemasok menyatukan akun Digiflazz, Hanshop dan setiap koneksi OtomaX. Daftar/riwayat memakai filter dan pagination; permintaan/rincian memakai modal. Halaman membaca snapshot database dan polling panel 15 detik tidak memanggil supplier langsung.
- Snapshot saldo memakai identitas HMAC owner + akun pemasok + lingkungan, dibagi untuk akun yang sama di beberapa toko owner tersebut. Kredensial/mode berbeda tidak berbagi snapshot; lintas owner tidak dibagikan. Saldo bukan wallet pelanggan. Saldo minus Hanshop dan nol didukung. Status pemeriksaan bukan jaminan supplier online.
- Respons transaksi dan callback yang sudah lolos validasi referensi memperbarui snapshot bila mengandung saldo dan waktu valid. Event lebih lama diabaikan untuk saldo tanpa mengabaikan status transaksi. Waktu sama dengan saldo berbeda, waktu tidak lengkap/invalid atau terlalu jauh di masa depan menandai perlu konfirmasi. Timestamp penerimaan dipisahkan. OtomaX mendukung tanggal d/m[/Y] dan jam HH:mm:ss, dengan zona WIB/WITA/WIT dari koneksi; tanpa tahun dipilih tahun terdekat dalam batas 90 hari. Profil existing tidak ditimpa; tambahkan tangkapan saldo_akhir, tanggal dan jam bila diperlukan.
- Endpoint saldo tetap dipakai untuk rekonsiliasi dan deposit di luar platform. Cache per akun 1–3 menit; waktu mulai permintaan disimpan agar respons/cache lama tidak menimpa event baru. Scheduler digitaltoko:supplier-wallets tiap dua menit mengantrekan job unik per koneksi; lock akun menghindari kerja bersamaan.
- Tiket Digiflazz memakai endpoint deposit resmi dengan bank, nama pengirim dan md5(username+apiKey+deposit). OtomaX memakai endpoint/profil tiket existing dan menyimpan pesan instruksi. Tanpa endpoint bukti pembayaran, keduanya tidak dinyatakan lunas berdasarkan kenaikan saldo. Timeout/hasil tak dikenal disimpan unknown dan request yang sama tidak dikirim ulang.
- Hanshop memakai endpoint internal supplier/deposit dan supplier/deposit-status. Payment bertipe DEPOSIT memakai settlement/ledger asli Hanshop; jumlah termasuk kode unik masuk saldo owner. Berbeda dari invoice sewa TRANSAKSI yang tidak mengkredit saldo. Staff tetap tidak melihat/mengisi saldo Hanshop owner bersama; pemasok lain memakai suppliers.balance dan suppliers.deposit.
- Tiket memakai request UUID, lock dan snapshot instruksi terenkripsi. Sandbox membuat tiket simulasi tanpa request atau kredit saldo produksi. Riwayat baru terpisah mode; riwayat tiket OtomaX lama tetap tersedia di modul OtomaX.

## Penanganan provisioning (26 Oktober 2026)

Provisioning mengikat satu kasus ke invoice sewa awal lunas dan mengelola status penanganan, alasan, 40 tindakan terakhir serta approval/refund. SubscriptionService menahan grant/aktivasi selama kasus bermasalah atau sedang ditinjau/refund, dan menutup kasus saat grant berhasil. BotManager mencatat kegagalan persiapan yang dapat dideteksi, sedangkan ketiadaan pairing menjadi waiting_owner. Scheduler langganan memantau kasus dan merekonsiliasi refund approved; daftar admin dapat difilter ke kasus perlu perhatian. Refund request memakai billing/refund melalui identitas server, tidak gateway tenant atau saldo customer. StoreObligations menahan purge untuk review/refund unresolved dan mengabaikan invoice refunded yang selesai.


## Pemisahan UI pemasok dan distribusi HansDev — 2 November 2026

`SupplierController::connections` mengirim Owner/SupplierConnections per kind Digiflazz/Hanshop, hanya metadata aman koneksi milik toko. `SupplierController::index` mengirim Owner/Sources berupa katalog sumber; tidak mengirim field webhook/IP pengaturan. Keduanya memakai CatalogAccess, guard/store yang sama. Sidebar WorkspaceLayout mengelompokkan koneksi dan pemantauan saldo pada Koneksi supplier; produk, kategori, harga dan stok pada Produk & harga. POST/DELETE source existing dipertahankan demi kompatibilitas.

`ForwarderDownloads` membangun metadata APK dan tiga pilihan tools dari services.forwarder, hanya jika file lokal tersedia. `CommerceSettingsController` melayani nama/jenis file tetap dan OS allowlist; tidak menerima path berkas dari request. TutorialController dan halaman pembayaran memakai komponen download yang sama. Arsip gabungan menjadi bahan packaging internal, tidak menjadi file unduhan publik.

`QrisNotificationParser` memvalidasi profil/format, nominal tunggal dan placeholder terbatas; uji draf tidak mengubah invoice. Template dibandingkan dengan seluruh isi pesan (atau judul+isi), dan pesan gagal/dibatalkan/berhasil dikirim tetap ditolak. `payments.parsers` hanya mengatur/menguji pola; `payments.confirm` terpisah untuk penerimaan manual. Callback nyata tetap melewati pemeriksaan merchant/token/sumber/waktu/invoice existing.

## Backup/pemulihan — 3 November 2026

PlatformBackup mengekspor MySQL InnoDB lewat proses argumen terstruktur; kredensial berada pada defaults file privat sementara, bukan command line. Inventory media private/public dan auth/registry tenant diperiksa hash sebelum/sesudah snapshot. ZIP AES-256 dibangun pada workspace privat, diverifikasi sebelum rename atomik, lalu replika opsional diverifikasi dan retensi dibatasi nama arsip yang dikelola modul. APP_KEY berada di manifest terenkripsi, BACKUP_KEY independen di konfigurasi operator. Rilis APK/SDK dan backup bersarang tidak direkursi.

Scheduler memanggil digitaltoko:backup harian Asia/Jakarta bila diaktifkan. SystemHealth menambahkan service backup dari status aman lokal, tanpa mengekspor manifest/rahasia ke UI. backup-verify tidak menulis database; backup-restore hanya schema dt_restore_* dan folder baru di luar data aktif/web root. backup-drill memakai schema/folder UUID sementara, memeriksa tabel/dekripsi stok lalu membersihkan hanya schema yang dibuat sendiri. Pemulihan tidak menjalankan worker, bot atau callback. Endpoint `/admin/backups` menyediakan riwayat, download privat dan upload/restore khusus administrator; tidak tersedia untuk owner/staff/publik. BackupRecovery menyalin arsip riwayat di bawah shared lock sebelum enqueue agar retensi tidak menghapus input. RestorePlatformBackup pada queue backups menggunakan ID UUID, target dt_restore_UUID dan folder privat recoveries/UUID. Status/audit permintaan berada pada system_health_signals grup backup_restores; row lock mencegah dua pemulihan berjalan. Verifikasi dan import berjalan di worker; payload job tidak memuat arsip atau kunci. Detail operasi/penyimpanan/konsistensi lintas layanan di DEPLOYMENT.md.
