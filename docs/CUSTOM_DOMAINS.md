# Domain pribadi toko

Diterapkan 23 Oktober 2026. Checkpoint sebelum implementasi: `729bb8e`.

## Alur owner

Buka **Tampilan & pengaturan → Pengaturan toko → Domain pribadi**. Owner terverifikasi pada platform Web atau Web + Bot dapat menambahkan satu hostname. Fitur gratis; registrar tetap milik dan tanggung jawab owner. Staff tidak dapat mengklaim, mengganti, memverifikasi atau melepas domain.

1. Masukkan hostname saja, misalnya `shop.tokoanda.com`. URL, port, IP, wildcard, domain lokal dan domain platform ditolak. Hostname dinormalisasi ke huruf kecil; IDN harus dalam bentuk punycode.
2. Salin record TXT `_digitaltoko-domain.<hostname>` dan nilai `digitaltoko=<kode>` ke DNS manager. Sebagian DNS manager meminta nama relatif, bukan hostname penuh.
3. Arahkan CNAME ke target pada popup. Gunakan DNS only ketika verifikasi. Domain utama (@) memerlukan CNAME flattening/ALIAS atau A/AAAA yang ditampilkan; jika tidak didukung gunakan subdomain seperti www. Jangan biarkan A/AAAA lama yang berbeda.
4. Klik **Verifikasi & aktifkan**. Domain baru menjadi alamat toko setelah TXT, arah DNS dan HTTPS semuanya sesuai. Pertahankan TXT dan record arah DNS. Subdomain bawaan tetap tersedia, termasuk ketika custom domain aktif.
5. Penggantian domain membuat kode baru dan menghapus status verifikasi domain sebelumnya. Pelepasan melepas binding, bukan menghapus toko atau domain di registrar. Akun pelanggan memakai cookie host-only; pembeli masuk kembali pada hostname baru. Bookmark lama pada domain yang dilepas tidak lagi berfungsi.

`www.tokoanda.com` dan `tokoanda.com` adalah hostname berbeda. Fitur ini tidak membuat alias/redirect otomatis antara keduanya. Hostname yang masih terikat pada toko lain tidak dapat diambil. Klaim belum terverifikasi kedaluwarsa setelah tujuh hari dan dapat diambil saat klaim berikutnya; domain terverifikasi memerlukan pelepasan atau penanganan dukungan.

Panduan publik `/panduan/domain` memuat empat langkah dengan screenshot simulasi serta penanda klik. Nilai DNS pada gambar bukan nilai produksi.

## Status dan batas aktivasi

- `pending_verification`: TXT/arah DNS belum cocok atau kode perlu diperbarui.
- `pending_tls`: DNS telah cocok; HTTPS/sertifikat atau respons origin belum sesuai. Ini belum membuka storefront pada hostname tersebut.
- `active`: HTTPS valid dan proof origin cocok; `verified_at`, `dns_verified_at`, `tls_verified_at` tercatat.
- Domain existing yang dahulu diaktifkan admin tetap kompatibel; simpan kembali untuk memperoleh kode sebelum pemeriksaan baru.

Verifikasi memeriksa DNS secara langsung, tidak bergantung pada IP yang diketik pemilik. Seluruh alamat hasil resolusi harus publik dan cocok dengan origin IP yang ditetapkan, atau alamat target CNAME jika origin IP tidak ditetapkan. Probe HTTPS memakai cURL resolve yang dipin ke IP hasil pemeriksaan, validasi sertifikat aktif, proxy nonaktif dan redirect nonaktif. Respons wajib berisi hostname serta token milik binding terkini. Respons lama tidak dapat mengaktifkan domain yang sudah diganti/dilepas. DNS gagal pada pemeriksaan ulang menonaktifkan binding sampai berhasil diverifikasi lagi.

`GET /api/domains/proof` adalah endpoint origin terbatas: hanya mengembalikan token kepemilikan yang juga ada pada TXT publik, untuk domain DNS-verified dan toko web yang belum dipurge. Endpoint ini tidak membuka halaman toko pending atau data akun.

## Konfigurasi aplikasi VPS

Backup database lalu jalankan `php artisan migrate --force`. Perubahan menambah kolom nullable pada `store_domains`; batas unik hostname dan satu baris per toko tetap dipakai. Migrasi lokal telah dijalankan setelah backup tabel di `storage/framework/testing/backups/` (tidak masuk Git).

```dotenv
# Opsional: target stabil agar pergantian brand domain tidak memaksa setiap tenant mengubah CNAME.
CUSTOM_DOMAIN_CNAME_TARGET=shops.hansdigi.biz.id
# IP masuk origin yang melayani toko, bukan otomatis sama dengan IP keluar supplier.
CUSTOM_DOMAIN_ORIGIN_IPS=IP_PUBLIK_IPV4,IP_PUBLIK_IPV6
# Kunci privat minimal 32 karakter; hanya diperlukan untuk izin On-Demand TLS Caddy.
CUSTOM_DOMAIN_TLS_ASK_KEY=KUNCI_ACAK_PRIVAT_MINIMAL_32_KARAKTER
```

Nilai kosong CNAME memakai `<slug>.<PLATFORM_DOMAIN>`. Nilai kosong origin IP memakai A/AAAA target tersebut. Target CNAME harus benar-benar tersedia di DNS, mengarah ke origin dan tidak diproxy selama penyiapan. Bila DNS platform memakai Cloudflare, buat target origin khusus DNS only, tanpa mengubah subdomain lain yang masih menuju hosting berbeda. `SUPPLIER_OUTBOUND_IP` tetap untuk whitelist pemasok; jangan menyamakannya dengan alamat origin tanpa memeriksa jaringan server.

Jalankan `php artisan config:cache` setelah konfigurasi. PHP memerlukan ekstensi cURL dan resolusi DNS yang berfungsi. Jangan menonaktifkan validasi TLS untuk meloloskan domain.

## Sertifikat otomatis dengan Caddy

Adapter izin disediakan lewat `GET /api/domains/tls-ask/{key}?domain=<hostname>`; key salah/kosong ditolak. Izin hanya untuk hostname platform yang dikonfigurasi, subdomain toko web terdaftar, atau custom domain yang sudah lulus DNS dan belum dipurge. Endpoint tidak melakukan request DNS/HTTP agar keputusan handshake cepat. Proof HTTPS pada langkah owner tetap diperlukan sebelum storefront custom diaktifkan.

Contoh `deploy/Caddyfile.example` melayani Laravel melalui PHP-FPM dan menggunakan On-Demand TLS. Ini contoh pengganti web server origin, bukan konfigurasi yang langsung ditambahkan di samping Nginx pada port yang sama.

1. Pada VPS, siapkan target DNS serta origin publik. Port 80/443 perlu dapat dijangkau; periksa CAA/ACME bila penerbitan sertifikat ditolak.
2. Pasang Caddy dari sumber resmi. Set environment service Caddy yang disebutkan di contoh: domain platform, email ACME, root public Laravel, socket PHP-FPM dan key privat yang sama dengan Laravel. `.env` Laravel tidak otomatis menjadi environment service Caddy.
3. Periksa root, izin berkas dan socket, lalu jalankan `caddy validate --config /etc/caddy/Caddyfile`. Pastikan PHP-FPM punya beberapa worker agar probe HTTPS dan permission check dapat dilayani saat request verifikasi masih berjalan.
4. Gunakan endpoint ask loopback `127.0.0.1:8181` pada contoh. Jangan membuka port ini ke publik, mencetak key ke log publik, atau mengizinkan semua hostname. Muat ulang service setelah konfigurasi valid.
5. Uji platform, subdomain toko, domain pending, dan domain tenant setelah TXT/CNAME benar. Handshake pertama dapat menunggu penerbitan sertifikat; jika pemeriksaan aplikasi kehabisan waktu, coba kembali setelah beberapa saat. Persetujuan ask bukan bukti sertifikat sudah terbit.

Caddy mendokumentasikan penerbitan saat TLS handshake dan pembatasan ask pada [On-Demand TLS](https://caddyserver.com/docs/json/apps/tls/automation/on_demand/) serta [global options](https://caddyserver.com/docs/caddyfile/options#on-demand-tls). File contoh belum dijalankan di VPS pengguna atau divalidasi dengan binary Caddy pada lingkungan lokal ini.

Nginx/proxy lain tetap dapat dipakai jika administrator menyediakan virtual host serta sertifikat valid bagi domain tersebut. Setelah itu tombol verifikasi memeriksa HTTPS yang sudah disediakan. Integrasi Cloudflare for SaaS API belum ditambahkan; fitur ini tidak membutuhkan API key Cloudflare tenant dan tidak mengubah DNS tenant secara otomatis.

## Validasi

Tes terisolasi mencakup kepemilikan toko/role, satu domain per toko, domain tidak valid/platform/duplikat, klaim kedaluwarsa, TXT salah, arah IP salah/privat, TLS gagal atau proof salah, pin IP/no redirect, aktivasi, pelepasan, izin TLS untuk host terdaftar dan balasan usang saat penggantian domain. Tidak ada klaim keberhasilan DNS/TLS produksi dari tes atau screenshot.
