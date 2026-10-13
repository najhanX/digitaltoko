# Staff toko DigitalToko

Rancangan permission didelegasikan pengguna kepada pengembang. Maksimal lima staff per toko, di luar owner. Staff lokal toko tidak didaftarkan ke API Hanshop. Login memakai username/email dan password dengan konteks toko; hash password tidak dapat dibaca kembali. Reset password dan hitungan staff nonaktif belum dirinci.

## Prinsip

- Default tanpa permission; owner memberi hak per akun. Scope selalu satu toko. Backend memeriksa permission setiap request/mutasi, bukan hanya menu.
- Akun dengan username sama pada dua toko bukan identitas global yang otomatis mendapat akses keduanya.
- Pengelolaan owner/staff/permission, data diri/KTP, credential pemasok/Midtrans/perangkat QRIS, domain, aktivasi pertama, dan pembelian/change kategori paket adalah owner-only pada rancangan awal.
- Staff tidak membaca total saldo Hanshop owner, credential pemasok, atau riwayat pribadi/toko lain. Pembelian customer melalui proses normal tetap dapat memakai source yang sudah dikonfigurasi owner; bukan endpoint debit saldo bebas.
- Permission perpanjangan bisa didelegasikan, tanpa memberi permission upgrade/downgrade atau belanja bebas dari saldo owner. Metode pembayaran renewal mengikuti invoice platform.

## Permission yang dapat dipilih owner

| Grup | Permission |
| --- | --- |
| Ringkasan | Lihat dashboard dan laporan toko |
| Produk | Lihat produk; tambah/edit/nonaktifkan produk; atur parsing source yang sudah dihubungkan |
| Harga | Atur harga/markup; atur policy modal naik per produk/kategori/layanan |
| Stok | Lihat jumlah/status; tambah stok; edit/hapus stok ready; lihat credential stok (hak terpisah) |
| Transaksi | Lihat/cek status pesanan; kirim ulang bukti/hasil ke penerima asli (hak terpisah) |
| Tindak lanjut guest | Hubungi nomor WA pesanan; catat penyelesaian/refund manual; buat pembelian ulang terkontrol (hak terpisah) |
| Pelanggan | Lihat pelanggan; lihat kontak (hak terpisah); kelola profil pelanggan dalam toko |
| Pembayaran | Lihat invoice/payment; atur policy kode unik topup dan refund produk (hak terpisah); ganti gateway yang telah dikonfigurasi owner; konfigurasi Midtrans mengikuti provider |
| Saldo pelanggan | Lihat topup/refund/ledger pelanggan toko; catat bukti pencairan saat toko berhenti (hak sensitif terpisah) |
| Web | Ganti template/branding; atur section/banner; preview/publish |
| Checkout | Atur guest/wajib login dan kontak bukti transaksi |
| WhatsApp | Lihat koneksi; mulai pairing/logout/reconnect (hak terpisah, satu sesi toko) |
| Langganan | Lihat status/deadline; buat invoice perpanjangan |

Refund terminal gagal pembeli login ke saldo toko dilakukan sistem, tidak membutuhkan permission staff. Pencatatan penyelesaian/refund manual guest dapat didelegasikan dengan audit; itu tidak otomatis membuktikan transfer uang sudah dilakukan. Konfirmasi manual pembayaran, adjustment saldo bebas, dan pengiriman pesan bebas belum termasuk permission awal. Label panel membedakan lihat stok dari lihat credential dan lihat payment dari ubah fee.

Owner tidak dapat memberi staff akses admin platform/Hanshop. Notifikasi grace tampil ke seluruh staff walaupun mereka tidak mendapat hak renewal.
