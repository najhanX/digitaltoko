# Retensi dan penghapusan toko — 21 Oktober 2026

## Pekerjaan selesai

- Timeline per store: expiry, penghentian tepat +7 hari (trial lama tanpa grace), evaluasi +30 hari. Draft/provisioning tanpa expiry tidak memperoleh jadwal penghapusan.
- Pemeriksaan bersama untuk retensi dan penutupan manual: saldo produksi positif/minus dihitung terpisah, pesanan nonterminal, guest gagal berbayar tanpa penyelesaian, refund customer belum selesai, inquiry yang belum kedaluwarsa/belum dipakai, invoice/jadwal sewa dan invoice modul. Tidak memaksa pending menjadi gagal pada hari ke-30.
- Owner/staff: Langganan & aktivasi menampilkan timeline dan alasan hold. Admin: filter retensi, status, kewajiban dan preview inventaris pada Kelola toko. Staff membutuhkan subscription.view.
- Scheduler per jam, CLI digitaltoko:retention dengan --store, --preview dan --apply. Penghapusan nyata **bawaan nonaktif**, termasuk instalasi lokal. Preview read-only terhadap data bisnis; evaluasi biasa mencatat status dan menyiapkan notifikasi.
- Reminder pelanggan bersaldo pada hari 6, 21, 29 dan hold. Notifikasi panel owner/staff; admin saat ready/hold. Dedup berdasarkan expiry cycle/penerima. Setelah WA toko berhenti, email platform digunakan bila siap; tanpa email/transport, tindak lanjut manual. OTP tidak dialihkan ke WA pusat.
- Renewal/koreksi expiry membatalkan retensi dan notice expiry lama; penerima yang sudah tercatat menerima diberi klarifikasi. Saldo, riwayat, kebijakan closing dan lisensi tetap ada. Sesudah habis, durasi dari paid_at. Pending/refund tidak diulang atau dibatalkan karena renewal.
- Purge dua fase: row lock owner/toko → claim purging → cek ulang → ACK disconnect manager → cleanup. Purging memblokir renewal, wallet, callback dan perubahan toko. Kegagalan disimpan sebagai error untuk retry; tidak menjanjikan restore setelah claim.
- Cleanup FK MySQL: order/ledger sebelum customer, lalu cascade toko. Produk/parsing/stok, supplier secrets, staff, customer/wallet/riwayat, invoice lokal, artikel/aset, branding, voucher toko, notifikasi/audit dan domain binding dihapus. Sesi/registry WA melalui manager; direktori storefront/UUID dan articles/UUID divalidasi tetap di disk lokal. Source/resource platform bersama tetap ada.
- Job order, notice dan callback Digiflazz terenkripsi pada jobs/failed_jobs dicocokkan dengan properti ID tepat dari kelas dikenal, tanpa meng-unserialize objek. Cache state bot/challenge OTP dibersihkan; cache pemasok bersama tidak di-flush.
- Kuota voucher platform dipertahankan sebagai counter agregat anonim setelah redemption dihapus. Akun/saldo/riwayat Hanshop, KTP/profil owner bersama dan toko lain tidak dihapus. Slug bisa dipakai ulang setelah purge; toko/lisensi baru mulai dari awal.
- Migrasi lokal diterapkan setelah backup privat before-retention-20261021-044643.sql. Tidak mengubah source/database API Hanshop dan tidak menghapus toko pengguna. Preview lokal serta schedule:list memastikan penghapusan otomatis tetap false.

## Pengujian

- StoreRetentionTest: **16 tes / 134 assertions** lulus. Batas 7/30 hari, preview, saldo positif/minus, hold, dedup, renewal/klarifikasi, disabled purge, stale preview, kegagalan manager/file, retry, isolasi akses/data, cleanup file/job, kuota voucher dan fallback email.
- StoreRetentionMysqlTest: **1 tes / 21 assertions** lulus pada MySQL acak terpisah. FK RESTRICT/cascade serta tiga percobaan renewal-versus-purge bersamaan memakai proses PHP terpisah. Database fixture dibersihkan; tidak menghubungi pemasok/WA nyata.
- Suite Laravel: **389 lulus, 2 integrasi opt-in dilewati; 5.417 assertions**. MySQL retensi di atas dijalankan tersendiri dengan opt-in; tidak mengklaim tes MySQL commerce lama diulang.
- Tes lama tujuan/nickname diisolasi dari fulfillment sinkron supaya validasi pesanan kedua tidak gagal karena stok fixture habis. Ekspektasi Aurora mengikuti fitur flash sale/trending lintas template yang sudah ada, tanpa mengubah perilaku produksi untuk mengakali tes.
- **44 tes bot**, **21 tes frontend**, TypeScript dan build Vite lulus. Build masih memberi peringatan aset nebula-gaming-hero.webp yang sudah ada sebelumnya.
- Browser visual belum diperiksa: tool mengembalikan “Local browser use is disabled in Work settings”. Akses/props panel diperiksa lewat Inertia, bukan klaim screenshot desktop/ponsel atau hasil produksi.

## Cara mencoba

Dari apps/web:

```bash
php artisan digitaltoko:retention --preview
php artisan digitaltoko:retention --store=UUID --preview
php artisan digitaltoko:retention
```

Admin: Kelola toko → detail toko → Jadwal retensi → Lihat pratinjau data yang akan dihapus. Owner/staff: Langganan & aktivasi. Toko aktif tetap menampilkan timeline tanpa hold. Jalankan scheduler dan queue untuk evaluasi/outbox berkelanjutan.

Purge otomatis memerlukan STORE_PURGE_ENABLED=true dan --apply. Penghapusan manual atas permohonan owner disetujui admin melalui panel tanpa mengaktifkan flag otomatis; lihat [operasi deployment](DEPLOYMENT.md#operasi-retensi-toko-21-oktober-2026). Tidak ada opsi melewati kewajiban. Pratinjau dihitung ulang saat mulai; hasil lama bukan izin penghapusan.

## Batas operasional

Backup/log server bersama dan DNS/certificate provider tidak dihapus otomatis. Antrean nonstandar ditahan untuk cleanup operator. Worker/disk tidak rollback bersama SQL; retry melanjutkan cleanup. Pesan yang sudah dikirim ke jaringan bisa tiba terlambat meskipun notice lama dibatalkan. sent_at berarti ACK transport, bukan sudah dibaca. Pelanggan tanpa email saat WA berhenti memerlukan tindak lanjut manual. Produksi, restore backup dan pengiriman pesan nyata tidak diuji.

## Perbaikan penutupan manual dan toko tanpa langganan (21 Oktober 2026)

- Status suspended_manual diperjelas sebagai **Menunggu penghapusan**, bukan sekadar Ditinjau admin. Owner memilih Ajukan penghapusan toko; timestamp permohonan disimpan dan admin menerima notifikasi panel. Submit ulang tidak mengubah waktu permohonan atau menggandakan notice admin.
- Admin membuka Kelola toko → detail → Tinjau & hapus toko. Modal menampilkan dampak, inventaris dan penahan; admin mengetik subdomain sebelum mengonfirmasi. Setelah berhasil kembali ke daftar toko.
- Toko baru tanpa starts_at/expires_at dapat diproses. Penutupan manual tidak perlu menunggu expiry+30 maupun STORE_PURGE_ENABLED. Persetujuan manual tidak mengaktifkan penghapusan otomatis toko lain. Permohonan manual tidak dihapus oleh scheduler, termasuk jika telah melewati expiry+30.
- Saldo, invoice, transaksi, refund, penangguhan admin dan penahan lainnya tetap diperiksa ulang. Preview ditandatangani HMAC dan diverifikasi di bawah row lock; kondisi berubah harus dipratinjau ulang. Staff/owner/guest tidak dapat menyetujui penghapusan.
- Draft yang belum pernah terhubung WhatsApp (seluruh penanda bot kosong dan belum pernah berlangganan) tidak membutuhkan bot manager. Toko yang mempunyai penanda koneksi atau pernah aktif tetap membutuhkan ACK disconnect. Origin purge disimpan agar retry manual hanya dapat dilakukan lewat persetujuan admin, terpisah dari otomatisasi.
- Modal menampilkan error bila cleanup gagal, menyediakan Perbarui pratinjau dan tindakan lanjutkan penghapusan. Penjaga route memberi akses khusus bagi endpoint DELETE admin untuk retry; mutasi lainnya tetap dibekukan.
- Notifikasi sukses setelah toko dihapus tidak lagi memasukkan foreign key toko yang sudah tidak ada. Identitas owner/toko lain tetap dipertahankan, slug dapat digunakan ulang setelah penghapusan nyata.
- Migrasi add_manual_store_deletion_fields sudah diterapkan lokal setelah backup privat before-manual-store-deletion-20261021-051229.sql. Toko lama suspended_manual diisi timestamp berdasarkan updated_at; tidak ada toko pengguna yang dihapus saat perbaikan.
- Validasi: suite 400 lulus / 2 integrasi opt-in dilewati. ManualStoreDeletionTest dijalankan tersendiri dan lulus 11 tes / 101 assertions, termasuk akses staff. MySQL retensi dijalankan terpisah dan lulus (1 tes / 23 assertions). TypeScript/build lulus. Browser visual masih belum tersedia pada sesi alat; tidak mengklaim klik penghapusan pada toko pengguna.

## Perapian Kelola toko dan identitas (21 Oktober 2026)

- Daftar toko memakai ringkasan angka satu baris, tabel lebih padat, tindakan Kelola yang jelas, serta filter lanjutan dalam dropdown. Pencarian, filter, dan pagination atas/bawah tetap tersedia.
- Identitas detail dikelompokkan menjadi Owner, Langganan, Alamat & tampilan, serta WhatsApp & data toko. Statistik sumber daya dipadatkan dan tombol pengelolaan disatukan pada footer kartu. Toko yang belum aktif ditandai Belum berlangganan/Belum aktif.
- CSS dibatasi ke halaman admin toko. Di ponsel informasi menjadi satu kolom, tombol tindakan menjadi dua kolom, dan tabel memiliki scroll sendiri.
- Validasi: TypeScript dan Vite build lulus; AdminStoreTest + ManualStoreDeletionTest lulus 22 tes / 417 assertions. Browser pratinjau terisolasi diperiksa pada 1366×900 dan 390×844, tema terang/gelap, filter status + reset, serta buka/tutup modal Edit toko. Tidak ada mutasi toko pengguna, pembayaran, atau penghapusan nyata.

## Penyelesaian warna tombol bersama (21 Oktober 2026)

- Latar tombol sekunder dipertegas dengan tint warna utama dan border yang berbeda dari kartu. Diterapkan pada panel admin/owner/staff, ikon tindakan, pagination, tab modal/desain/layanan, toolbar artikel, serta kontrol notifikasi.
- Tombol sekunder storefront dan modal pelanggan mengikuti warna aksen toko; pilihan aktif tetap dibedakan. Warna teks pilihan aktif panel mengikuti color-scheme agar terbaca dalam tema gelap.
- Build TypeScript/Vite lulus. Pemeriksaan browser dilakukan pada fixture terisolasi: detail admin, modal Edit toko, dan layanan/kategori owner dalam tema terang/gelap. Computed background tombol edit/reset/aksi/pagination tidak transparan; tombol hapus mempertahankan tint merah. Tidak ada transaksi atau perubahan toko nyata.

- Koreksi lanjutan: tombol Perbarui pratinjau pada modal penghapusan sebelumnya memakai class tautan (text-link), sehingga masih memakai kotak bawaan browser. Diganti menjadi button secondary agar memakai latar, padding, radius, hover dan fokus bersama. Audit tombol dengan class text-link tidak menemukan penggunaan lain. TypeScript/Vite build lulus.
