# Arsitektur DigitalToko

Status source lokal: **21 Oktober 2026**. Dokumen ini menggantikan rancangan awal yang masih menyebut skeleton, trial baru, dan checkout sebagai pekerjaan berikutnya. Catatan pengujian/deployment tidak disimpulkan dari keberadaan kode.

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

| Paket | Kanal transaksi | Sumber sendiri |
| --- | --- | --- |
| Mercury | Bot | Stok premium |
| Venus | Bot | Digiflazz |
| Terra | Web | Stok premium |
| Mars | Web | Digiflazz |
| Jupiter | Bot | Keduanya |
| Saturn | Web | Keduanya |
| Uranus | Bot + Web | Stok premium |
| Neptune | Bot + Web | Digiflazz |
| Andromeda | Bot + Web | Keduanya |

Semua paket aktif termasuk akses sumber Hanshop. Nama/rincian bersumber dari `apps/web/resources/js/packageCatalog.json`. Kode internal `category=bot/web/full` dan `plan=space/orbit/galaxy` dipertahankan untuk stok/Digiflazz/keduanya. Hak sumber dan kanal diperiksa backend, bukan hanya menyembunyikan menu. Snapshot subscription menjaga hak yang sudah diberikan.

Semua kanal dapat menghubungkan satu nomor WhatsApp toko untuk OTP/notifikasi. Paket Web tidak boleh menggunakan command katalog/saldo/pemesanan chat. Trial baru tidak tersedia; promo memakai voucher platform, termasuk 100%. Penanda trial lama dipertahankan untuk kompatibilitas.

## Data multi-store

Satu database aplikasi menyimpan data banyak toko dengan scope `store_id`, bukan satu database atau salinan frontend per toko. Owner global dapat memiliki banyak toko; staff dan customer lokal hanya memiliki konteks toko masing-masing. Host publik memetakan toko; sesi/permission/token menentukan otorisasi.

Produk, stok, ledger, order, invoice, media, credential, notifikasi dan job harus membawa konteks toko. Customer dengan nomor sama di toko berbeda memiliki wallet berbeda. Cache pemasok merupakan pengecualian yang disengaja: data baca dibagikan hanya untuk akun pemasok dan operasi/mode yang sama, bukan credential atau wallet tenant.

## Alur owner dan langganan

Owner OTP → profil dan verifikasi email → review KTP approved → toko draft → pilih paket/durasi/voucher → invoice → provisioning → siap → aktivasi owner atau otomatis setelah 24 jam siap.

Invoice berbayar diterbitkan/diperiksa melalui bridge billing Hanshop dan tidak mengkredit saldo Hanshop. Invoice voucher Rp0 dilunasi lokal tanpa gateway. Pembelian pertama tetap menunggu aktivasi. Web siap berdasarkan slug; Bot/Full juga memerlukan WA CONNECTED. Ini tidak membuktikan DNS/TLS custom domain sudah siap.

Renew aktif menambah dari expiry, renew setelah habis dari paid_at. Upgrade/perubahan efektif memakai harga penuh paket tujuan dan masa aktif baru. Pengurangan fitur yang masih aktif dijadwalkan pada akhir periode. Grace tujuh hari masih menerima pesanan; penangguhan admin terpisah dan tidak hilang karena pembayaran. Lifecycle sebenarnya berada di `SubscriptionService` dan `Store::acceptsOrders()`.

## Katalog dan fulfillment

Owner menghubungkan Digiflazz atau identitas Hanshop → sync snapshot → impor/mapping layanan-kategori-produk → konfigurasi parsing/harga/stok. Impor tidak membeli barang pemasok. Markup dan auto-update diwariskan secara independen: produk → kategori → layanan; aturan tidak dijumlahkan antarlevel.

`SupplierCache` memakai cache Redis default 120 detik (dibatasi 60–180) untuk saldo dan katalog Hanshop, fingerprint akun HMAC, lock dan cooldown error. Katalog Digiflazz memakai cache 300 detik sesuai limit price-list; penolakan RC 83/HTTP 429 menahan percobaan katalog berikutnya selama 300 detik. Cache katalog tetap terpisah menurut akun dan mode prepaid/pasca. Scheduler berjalan setiap dua menit, tetapi memakai snapshot cache yang masih berlaku; pembacaan katalog juga meminta refresh bila perlu. OtomaX menggunakan impor berkas sehingga dikecualikan dari sinkronisasi API berkala. Alasan validasi/koneksi Digiflazz yang aman, kode RC, dan IP yang ditolak (RC 45) disimpan pada `last_error`, tanpa menampilkan body respons mentah/kredensial. Kegagalan refresh mempertahankan snapshot, tetapi sumber remote melebihi batas umur default 600 detik tidak eligible untuk pemesanan.

Checkout web/bot membuat snapshot harga, tujuan, gateway, voucher dan referensi idempotensi. Pesanan wallet didebit atomik; gateway menunggu pembayaran terverifikasi. Setelah commit, fulfillment dijadwalkan melalui queue. Stok ready diklaim atomik; sumber remote memakai parsing termurah dan fallback hanya setelah kegagalan yang pasti. Timeout/hasil ambigu tetap pending dan tidak berpindah sumber tanpa kepastian. Pengiriman ulang pemasok hanya melalui tombol panel owner/staff, bukan polling scheduler. Khusus OtomaX, request transaksi dapat dikirim ulang dengan identitas/RefID yang sama pada hari pengiriman awal (WIB), sesuai kontrak deduplikasi supplier.

Callback Digiflazz: satu URL platform `/api/commerce/digiflazz`, validasi signature dan hubungan attempt dengan pesanan. Midtrans dan Forwarder menggunakan URL per toko. Status/SN baru Digiflazz dapat mengoreksi hasil terminal/manual; refund yang sudah diberikan dapat ditarik kembali, termasuk menjadi saldo minus. Ini bukan fitur keputusan manual universal untuk semua pemasok.

## Pemisahan uang dan notifikasi

Empat domain berbeda: saldo Hanshop owner, saldo customer toko, pembayaran produk/topup customer, dan pembayaran sewa/modul. Tidak ada wallet tenant yang menjadi salinan saldo Hanshop.

OTP owner/platform tetap lewat jalur identitas platform. OTP customer memakai WA toko atau email bermerek toko, tanpa fallback WA pusat tersembunyi. Pengiriman email memakai platform atau SMTP tenant terenkripsi. CustomerNotice menyimpan outbox; manager diberi sinyal setelah commit, polling worker dan scheduler menjadi cadangan. Gangguan antara pengiriman WA dan ACK dapat menyebabkan notifikasi ulang, bukan pesanan/debit baru.

## Mode pengujian

`StoreEnvironment` menyimpan sandbox per toko; perubahan ditolak jika ada order berjalan atau inquiry yang masih valid. Digiflazz memakai development key dan testing. Hanshop/stok premium disimulasikan, wallet uji terpisah, pembayaran lokal dapat disimulasikan atau memakai Midtrans Sandbox. Mode sandbox toko berbeda dari setting sandbox merchant; jangan menganggap QRIS Sandbox dapat dibayar dengan uang nyata.

## Proses berkala dan batas tersisa

`routes/console.php` menjadwalkan invoice/langganan dan commerce tiap menit, sinkron katalog tiap dua menit, outbox notice tiap lima detik, purge KTP ditolak tiap menit, pembersihan upload artikel harian, serta evaluasi retensi toko setiap jam. Retensi hanya menghapus jika `STORE_PURGE_ENABLED=true`; mode bawaan hanya memeriksa/mengirim pengingat. Queue worker menjalankan job setelah pembayaran/commit.

`StoreObligations` memeriksa saldo positif/minus, pesanan, refund/guest, inquiry, invoice sewa dan modul. `StoreRetention` menghitung expiry+7/+30, menahan purge, menghapus notice expiry lama ketika diperpanjang, serta menjalankan purge dua fase dengan lock owner/toko. Claim `purging` memblokir renewal dan callback/mutasi; kegagalan cleanup disimpan sebagai error untuk retry. Data relasional toko, media lokal, job database/failed job terkait, cache bot/OTP dan registry/sesi WA dibersihkan; owner dan API Hanshop tidak dihapus. Kuota voucher platform dipertahankan sebagai counter agregat tanpa redemption tenant. Belum ada provisioning DNS/TLS otomatis, penghapusan DNS/certificate provider eksternal, refund sewa gagal provisioning otomatis, atau operasi worker multi-host. Mapping custom host verified sudah ada; workflow Cloudflare for SaaS masih rancangan. Detail konfigurasi dan batasnya di DEPLOYMENT.md/DOMAINS.md.

Rincian batas backup, antrean khusus, kanal reminder, dan hasil validasi retensi: [RETENTION_PROGRESS.md](RETENTION_PROGRESS.md).

Penghapusan manual memakai DELETE /admin/stores/{store} (admin web saja). StoreClosureController mencatat closure_requested_at dan memberi notice admin. StoreRetention::approveManualDeletion memverifikasi token preview di bawah lock dan mengeksekusi cleanup bersama dengan origin manual; tidak bergantung pada flag purge otomatis atau expiry. Migration add_manual_store_deletion_fields mengakomodasi toko suspended_manual lama. Draft yang tidak pernah punya sesi/subscription dapat dihapus tanpa manager; lainnya tetap perlu ACK disconnect.


## Adapter OtomaX

`OtomaxClient` membatasi host ke IP publik, mem-pin resolusi DNS, menolak redirect, menghitung SHA1 binary/Base64URL, dan memanggil IP Center. OtomaX dapat mempunyai beberapa `SupplierConnection` per toko, dibedakan `slot`; Digiflazz/Hanshop mempertahankan slot primary. `OtomaxProfile` menyimpan kelompok respons atau pemetaan impor yang dipakai ulang. Respons mendukung template placeholder lama, awalan/akhiran per field, serta RegEx PCRE dengan named group allowlist dan batas pemrosesan. Tidak ada eksekusi PHP. Penguji terpisah memakai matcher yang sama dengan transaksi. Konflik atau pola asing tetap pending/review.

`OtomaxImports` membaca XLSX/CSV tanpa mengevaluasi formula, menyimpan pratinjau privat terenkripsi, dan melakukan upsert per connection/mode/SKU lewat `ImportOtomaxProducts`. Lock per koneksi mencegah impor bersamaan saling menimpa. Data sumber dipetakan melalui import existing ke `CatalogProduct`/`ProductMapping`; bot/web memakai mesin pesanan bersama. Profil respons dan credential disalin terenkripsi ke attempt sehingga perubahan profil tidak merusak callback pesanan lama. Marker dispatch dan waktu pengiriman pertama disimpan sebelum HTTP. Jika endpoint cek status tersedia, pemeriksaan memakai endpoint tersebut; jika tidak, tindakan manual Kirim ulang ke pemasok boleh mengirim ulang request OtomaX identik dengan RefID yang sama pada hari pengiriman pertama (Asia/Jakarta), minimal satu menit antarpemeriksaan. Scheduler/queue hanya mengirim percobaan baru yang belum pernah dikirim; attempt remote yang sudah dikirim menunggu callback atau tombol manual. Setelah berganti hari, hanya menunggu callback tanpa request transaksi ulang. Attempt lama tanpa timestamp memakai tanggal pembuatan attempt sebagai batas konservatif. Format standar `sdh pernah` dibaca sebagai hasil transaksi sebelumnya bila pola kustom belum cocok; konflik status query/teks tetap pending.

Callback memeriksa token, IP, toko/koneksi, refID, SKU dan tujuan jika tersedia. Status terminal dapat dikoreksi menggunakan rekonsiliasi refund existing; saldo minus tetap diblokir untuk transaksi baru. Sukses dari parsing lama setelah sumber lain sudah dikirim masuk review, karena ada risiko transaksi ganda. Detail cakupan dan batas protokol ada di OTOMAX_PROGRESS.md.

Validasi identitas Digiflazz membandingkan SKU tanpa membedakan kapitalisasi karena respons pemasok dapat mengubah huruf besar/kecil; RefID dan nomor tujuan tetap persis. Nomor pesanan berbeda dari referensi setiap attempt, yang ditampilkan sebagai RefID pemasok di rincian transaksi.
