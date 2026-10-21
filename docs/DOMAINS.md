# Rancangan domain web toko

Status source **21 Oktober 2026**: host resolver tersedia untuk slug di domain/alias platform serta custom hostname active+verified. Model/tabel StoreDomain dan pembacaan domain pada panel admin tersedia; belum ada endpoint panel owner untuk klaim/kelola custom domain maupun otomatisasi ownership/DNS/TLS/provider. Keadaan DNS/VPS/kepemilikan domain nyata tidak diperiksa pada sinkronisasi ini. Domain awal yang direncanakan hansdigi.biz.id, final digitaltoko.com; konfigurasi transisi di DEPLOYMENT.md.

## Routing yang tersedia

- Domain platform awal hansdigi.biz.id; final digitaltoko.com.
- Panel foundation /admin, /owner, /staff/<uuid>/login pada domain platform sama.
- Domain bawaan <slug>.<PLATFORM_DOMAIN>, contoh tokonya.hansdigi.biz.id lalu tokonya.digitaltoko.com.
- Domain custom opsional: `shop.tokonya.example` atau `www.tokonya.example`.

Satu aplikasi storefront melayani banyak hostname. Backend memetakan hostname terdaftar ke `store_id`, lalu memeriksa status toko dan entitlement web. Deploy aplikasi per toko tidak diperlukan.

Ketentuan pengguna: satu subdomain per toko ditentukan saat pembelian, tetap pada fitur awal; rename berbayar mungkin fase berikut. Koneksi domain gratis maksimal satu domain custom per toko, dengan panduan DNS. Rancangan www/apex bila didukung alias domain utama sama. Setelah purge slug dapat dipakai toko lain. Reservasi checkout/binding mencegah dua tenant menguasai hostname sama. Slug juga disimpan untuk toko Bot sebagai identitas toko, tetapi tidak menerbitkan storefront tanpa hak web.

## Subdomain bawaan

Gunakan wildcard DNS ke ingress platform dan konfigurasi TLS yang mencakup subdomain satu tingkat. Wildcard DNS dan wildcard certificate merupakan konfigurasi berbeda; keduanya perlu disiapkan. Subdomain dibuat secara logis dalam database sehingga tidak perlu record DNS manual per toko.

Slug toko unik, hostname dinormalisasi, dan nama sistem seperti panel, api, admin, www, serta status dicadangkan. Host tidak terdaftar ditolak, meskipun wildcard DNS membuatnya dapat mencapai server. Cookie panel bersifat host-only; tidak dibagikan kepada seluruh subdomain toko.

## Custom domain

Usulan layanan: Cloudflare for SaaS untuk registrasi custom hostname, routing ke fallback origin platform, validasi hostname, dan pengelolaan sertifikat TLS. Pemilihan penyedia final dan biaya belum ditetapkan.

Owner membeli/memiliki domain di registrar pilihannya. Untuk onboarding awal, arahkan hostname seperti `shop.tokonya.example` atau `www.tokonya.example` dengan CNAME ke target platform. Panel menampilkan record verifikasi TXT bila diperlukan dan record routing yang harus dipasang.

Domain utama tanpa prefix (apex) tidak diperlakukan identik dengan subdomain. Dukungan bergantung pada kemampuan DNS provider dan fasilitas apex proxying penyedia hosting/CDN. Tahap awal prioritaskan subdomain/www; dukungan apex direncanakan setelah jalur yang didukung dan biayanya diverifikasi. Redirect dari apex ke www harus disediakan tersendiri dengan HTTPS yang valid.

Cloudflare for SaaS tidak membeli domain pelanggan atau mengurus tagihan registrarnya. Masa aktif domain, masa aktif paket Web App, dan status sertifikat TLS adalah lifecycle berbeda.

## Target onboarding domain otomatis (belum lengkap)

1. Owner memasukkan hostname; backend memeriksa entitlement custom domain, normalisasi, dan keunikan global.
2. Backend mendaftarkan custom hostname menggunakan credential platform yang tetap berada di server.
3. Panel menampilkan record ownership dan routing yang dikembalikan penyedia.
4. Worker memeriksa status validasi hostname, routing, dan sertifikat secara terpisah.
5. Domain aktif untuk storefront hanya setelah kepemilikan, routing, sertifikat, dan hak toko valid.

Simpan `store_domains` dengan `store_id`, hostname unik, jenis domain, ID penyedia, status verifikasi/DNS/TLS, waktu verifikasi, dan penanda domain utama. Gunakan status onboarding seperti PENDING_VERIFICATION, PENDING_DNS, PENDING_TLS, ACTIVE, ERROR, dan DISABLED tanpa mencampurkannya dengan status langganan toko.

Hostname bukan bukti otorisasi owner. Perubahan domain memakai sesi panel dan akses toko. Header forwarded host hanya dipercaya dari proxy terkonfigurasi. Link, redirect, cache, dan event tenant harus mengikuti mapping domain yang diverifikasi; jangan membangun URL callback pembayaran dari Host request yang tidak dipercaya.

Saat domain dilepas atau dipindah toko, hapus/nonaktifkan binding di penyedia dan aplikasi secara terkoordinasi. Klaim ulang wajib verifikasi kepemilikan baru; jangan memindahkan domain hanya berdasarkan teks hostname. Jangan mengubah record email/MX pelanggan untuk onboarding storefront.

## Batas implementasi lifecycle

Pemblokiran akses sesuai langganan tersedia di backend. Renewal memperbarui masa aktif. Tidak ada job purge toko penuh atau pelepasan provider domain otomatis; aturan berikut adalah kebijakan target, bukan tindakan yang sudah dijalankan scheduler.

## Kebijakan expiry dan operasional

Web bertransaksi selama grace tujuh hari, lalu publik diblokir. Hari ke-30 memeriksa eligibility purge: saldo pelanggan/transaksi/refund belum selesai menahan penghapusan dan domain mapping. Panel renewal/penyelesaian tetap tersedia. Renewal sebelum purge memulihkan routing setelah readiness check. Slug hanya dilepas setelah purge benar-benar selesai, bukan ketika tanggal ke-30 tercapai dalam DELETION_HOLD. Saat purge binding penyedia/aplikasi dilepas; akun/saldo/riwayat owner Hanshop dan domain tenant di registrar tetap ada. Klaim ulang custom domain membutuhkan verifikasi.

Subdomain bawaan menjadi alamat cadangan ketika custom domain belum siap. Bila dua hostname aktif untuk toko yang sama, tentukan domain utama dan canonical URL; redirect hanya setelah domain utama benar-benar aktif. Pembelian/perpanjangan domain atas nama penyewa dapat menjadi layanan tambahan pada tahap berikutnya, dengan kepemilikan dan biaya dijelaskan.

## Referensi teknis

- Cloudflare for SaaS: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/
- Fallback origin dan CNAME target: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/getting-started/
- Validasi hostname: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/domain-support/hostname-validation/
- Apex proxying: https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/apex-proxying/
