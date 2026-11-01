# Hak akses staff toko

Disinkronkan dengan `apps/web/app/Support/StaffPermissions.php` dan controller pada **22 Oktober 2026**. Maksimal lima akun lokal per toko, termasuk nonaktif; owner tidak dihitung. Login memakai username/email dan password dengan konteks toko. Staff tidak didaftarkan ke Hanshop.

## Aturan akses

Owner terverifikasi mengelola akun/grant staff. Backend selalu memeriksa store_id, guard dan izin; label/menu bukan otorisasi. Izin kelola tertentu mengimplikasikan baca melalui CatalogAccess; izin sensitif tetap terpisah. Staff tidak membaca saldo Hanshop global atau mengganti credential Digiflazz/Hanshop/gateway owner. OtomaX memiliki izin khusus eksplisit untuk mengelola koneksi toko.

Owner-only: pengelolaan staff, identitas/KTP, credential Digiflazz/Hanshop dan gateway, pembelian modul, domain, aktivasi pertama, perubahan paket, pembatalan order dari panel dan penyelesaian pembayaran review. Staff `subscription.renew` hanya memperpanjang kanal/sumber yang sama; trial historis tidak dapat dikonversi oleh staff. `orders.manage` adalah keputusan manual Digiflazz/OtomaX eligible, bukan semua pemasok.

## Allowlist source

| Permission | Label panel |
| --- | --- |
| `vouchers.view` | Lihat voucher diskon |
| `vouchers.manage` | Buat, ubah & hapus voucher diskon |
| `dashboard.view` | Lihat ringkasan toko |
| `products.view` | Lihat produk |
| `products.manage` | Kelola layanan, kategori, produk & parsing |
| `pricing.manage` | Kelola harga & markup |
| `stock.view` | Lihat jumlah stok |
| `stock.manage` | Kelola stok ready |
| `stock.credentials` | Lihat credential stok |
| `suppliers.balance` | Lihat saldo pemasok toko (Digiflazz/OtomaX) |
| `suppliers.manage` | Kelola koneksi, URL daftar harga, dan callback OtomaX |
| `suppliers.responses` | Kelola kelompok jawaban OtomaX |
| `suppliers.import` | Sinkronisasi katalog URL dan fondasi impor berkas OtomaX |
| `suppliers.deposit` | Ambil & lihat tiket deposit OtomaX |
| `orders.view` | Lihat & cek transaksi |
| `orders.manage` | Putuskan sukses/gagal transaksi Digiflazz/OtomaX manual |
| `orders.resend` | Kirim ulang hasil ke penerima asli |
| `guests.resolve` | Tindak lanjut pesanan guest |
| `customers.view` | Lihat pelanggan |
| `customers.contacts` | Lihat kontak pelanggan |
| `customers.manage` | Kelola profil pelanggan |
| `payments.view` | Lihat pembayaran |
| `payments.policy` | Atur fee dan kebijakan kode unik |
| `wallet.view` | Lihat ledger saldo pelanggan |
| `wallet.settlement` | Catat pencairan saat toko berhenti |
| `web.manage` | Kelola template & branding |
| `articles.view` | Lihat artikel toko |
| `articles.manage` | Tulis, terbitkan & hapus artikel |
| `checkout.manage` | Atur guest / wajib login |
| `bot.view` | Lihat koneksi WhatsApp |
| `bot.manage` | Pairing / reconnect WhatsApp |
| `notifications.view` | Lihat pengaturan notifikasi & email |
| `notifications.manage` | Kelola pengirim email & kirim email uji |
| `subscription.view` | Lihat masa aktif |
| `subscription.renew` | Buat invoice perpanjangan |

`customers.manage` tercantum pada allowlist, tetapi belum menyediakan endpoint CRUD profil pelanggan penuh. Jangan menjanjikan fitur hanya karena label izin sudah ada. `stock.credentials` dipakai untuk membuka isi stok/hasil; `customers.contacts` untuk kontak; `wallet.settlement` hanya mencatat pencairan yang sudah dilakukan ketika toko berhenti. Tidak ada transfer uang otomatis dari permission tersebut.

Refund terminal customer login dijalankan sistem tanpa menunggu staff. Callback Digiflazz dapat mengoreksi keputusan manual dan menarik refund kembali; status/riwayat tetap dapat diperiksa meskipun saldo minus membatasi pembelian baru.

Login staff menghapus sesi web owner/admin di browser itu, dan sebaliknya. Password staff kosong saat edit mempertahankan hash lama; reset password mandiri belum menjadi alur lengkap. Lihat BUSINESS_RULES.md dan ARCHITECTURE.md untuk lifecycle serta pemisahan wallet.

## Saldo pemasok (26 Oktober 2026)

suppliers.balance membuka pemantauan saldo, pemeriksaan manual dan snapshot pemasok toko. suppliers.deposit mengizinkan permintaan/rincian deposit Digiflazz/OtomaX melalui modul baru bila staff juga mempunyai suppliers.balance. Saldo/deposit Hanshop hanya owner karena akun dan saldonya dipakai bersama beberapa toko. Tidak memberi staff credential pemasok atau akses saldo pelanggan melalui izin ini.

### Konfirmasi pembayaran QRIS — 1 November 2026

`payments.confirm` adalah izin terpisah untuk mencatat uang masuk pada invoice QRIS HansDev berlisensi. Izin ini menyertakan akses baca transaksi melalui resolver existing dan menampilkan menu transaksi, tetapi tidak memberikan izin memutuskan hasil pemasok, mengubah gateway/token, membuka stok rahasia atau membatalkan pesanan. Owner mempunyai akses bawaan. Staff existing tidak otomatis memperoleh izin baru; owner mengaktifkannya pada Staff & hak akses. Referensi, nominal, waktu, catatan dan identitas aktor dicatat.


`payments.parsers`: kelola dan uji format notifikasi QRIS HansDev. Menyertakan akses baca Pembayaran pelanggan; tidak memberi akses token, mengubah gateway atau melunasi invoice manual. Staff lama tidak otomatis mendapat izin ini.
