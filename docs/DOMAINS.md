# Domain web toko DigitalToko

Status source lokal: **2 November 2026**. Resolver host, klaim domain owner, verifikasi TXT/arah DNS/HTTPS, dan pelepasan binding sudah tersedia. Konfigurasi DNS, penerbitan sertifikat publik dan kesiapan VPS nyata tetap harus diuji pada lingkungan deployment. Domain awal yang direncanakan hansdigi.biz.id; domain final yang diinginkan digitaltoko.com. Dokumen ini tidak memeriksa kepemilikan atau DNS live.

## Satu aplikasi, beberapa hostname

Panel admin/owner/staff berjalan pada host platform. Storefront memakai `<slug>.<PLATFORM_DOMAIN>` atau satu hostname pribadi aktif dan terverifikasi. APP_URL, PLATFORM_DOMAIN, alias serta mapping StoreDomain menentukan routing, bukan salinan source/frontend per toko. Toko Bot tetap mempunyai slug sebagai identitas tanpa hak storefront transaksi Web.

Subdomain bawaan dipilih ketika membuat toko dan tidak dapat diganti melalui fitur awal. Domain pribadi opsional dan gratis, maksimal satu per toko Web/Full. Owner membeli/memiliki domain pada registrar; DigitalToko tidak membeli atau memperpanjang domain tersebut. Slug dapat dipakai lagi setelah toko benar-benar dipurge, bukan sekadar kedaluwarsa.

## Subdomain bawaan

Sediakan wildcard DNS ke ingress platform dan TLS yang mencakup subdomain satu tingkat; DNS wildcard dan wildcard certificate adalah konfigurasi berbeda. Slug dibuat secara logis dalam database, sehingga tidak perlu record DNS manual untuk setiap toko. Record spesifik milik layanan lain harus tetap diperiksa saat menyiapkan DNS.

Hostname dinormalisasi dan unik, nama sistem dicadangkan, dan host yang tidak terdaftar ditolak. Cookie panel/customer bersifat host-only. Domain bukan bukti izin owner; guard, store_id dan permission tetap diperiksa. Header forwarded host hanya dipercaya dari proxy yang dikonfigurasi.

## Domain pribadi yang sudah diimplementasikan

Owner terverifikasi membuka **Tampilan & pengaturan → Pengaturan toko → Domain pribadi**. Staff tidak mendapat aksi klaim/verifikasi/lepas.

1. Masukkan hostname tanpa URL, port atau path. IP, wildcard, domain lokal serta domain platform ditolak.
2. Pasang TXT `_digitaltoko-domain.<hostname>` dengan kode kepemilikan dari popup.
3. Pasang CNAME ke target platform, atau metode apex yang didukung DNS provider. Gunakan record/nilai aktual dari panel. `www` dan apex berbeda; tidak dibuat alias/redirect otomatis.
4. Klik Verifikasi & aktifkan. `pending_verification` berarti TXT/arah belum sesuai; `pending_tls` berarti DNS cocok tetapi HTTPS/proof origin belum sesuai. Hanya `active` dan verified yang membuka storefront pada hostname itu.
5. Mengganti/melepas domain mengubah binding; domain registrar dan toko tidak dihapus. Pembeli masuk kembali pada hostname baru. Subdomain bawaan tetap tersedia.

Klaim belum terverifikasi kedaluwarsa setelah tujuh hari dan dapat diambil pada klaim berikutnya. Pengambilan domain terverifikasi milik toko lain tetap ditolak. Probe HTTPS mem-pin IP publik yang dipercaya, memvalidasi sertifikat serta menolak redirect; token/binding harus terkini. Endpoint proof tidak membuka data pelanggan atau storefront pending.

Route owner: POST `/owner/stores/{store}/domain`, POST `/owner/stores/{store}/domain/verify`, dan DELETE `/owner/stores/{store}/domain`. Izin On-Demand TLS Caddy dan proof origin terpisah dari halaman toko.

## Deployment dan batas operasional

Konfigurasi `CUSTOM_DOMAIN_CNAME_TARGET`, `CUSTOM_DOMAIN_ORIGIN_IPS`, dan `CUSTOM_DOMAIN_TLS_ASK_KEY`, contoh Caddy/Nginx, batas verifikasi dan langkah DNS rinci ada di [CUSTOM_DOMAINS.md](CUSTOM_DOMAINS.md). APP_URL/PLATFORM_DOMAIN dan transisi domain platform ada di [DEPLOYMENT.md](DEPLOYMENT.md).

Adapter izin Caddy tersedia; integrasi provisioning Cloudflare for SaaS API bukan fitur aktif. DNS/TLS tidak dianggap siap hanya karena hostname tersimpan. Dukungan apex bergantung DNS/provider; registrar, TTL, sertifikat dan langganan toko memiliki lifecycle berbeda. Pelepasan DNS/sertifikat provider eksternal saat purge dan backup/restore masih tanggung jawab operasi yang perlu ditetapkan.

## Kedaluwarsa dan penghapusan toko

Grace tujuh hari masih bertransaksi; setelahnya operasional publik diblokir. Pada expiry+30, saldo/transaksi/refund atau kewajiban lain menahan penghapusan beserta mapping. Panel renewal/penyelesaian tetap tersedia. Renewal sebelum purge dapat memulihkan routing setelah pemeriksaan kesiapan; tidak mengembalikan toko yang sudah terhapus. Akun/saldo/riwayat owner Hanshop dan kepemilikan domain registrar tetap dipertahankan. Klaim ulang domain memerlukan verifikasi baru.
