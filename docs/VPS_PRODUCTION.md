# Pemasangan production DigitalToko di VPS

Disusun 4 November 2026 dari source saat ini. Ini berkas persiapan; belum dipasang atau diuji pada VPS pengguna. Contoh memakai Ubuntu/Debian, user `hans`, domain `hansdigi.biz.id`, dan checkout `/home/hans/hanshop/digitaltoko`. Sesuaikan path bila berbeda. Jangan menjalankan perintah Linux berikut di laptop Windows.

## 1. Susunan layanan

| Layanan | Pengelola | Lokasi / port |
| --- | --- | --- |
| API Hanshop existing | PM2 `api-hanshop` | `/home/hans/hanshop/api_hanshop`, 4000 |
| Bot WA Hanshop existing | PM2 `bot-wa` | `/home/hans/hanshop/bot_wa_hanshop_v2`, 3000 |
| Bot Telegram existing | PM2 `bot-tele` | `/home/hans/hanshop/bot_tele_hanshop`, 3100 |
| Web DigitalToko | Nginx + pool PHP-FPM | `apps/web/public`, HTTPS 443 |
| Antrean transaksi | PM2 `digitaltoko-queue` | database queue `default`, timeout 180 detik |
| Scheduler | PM2 `digitaltoko-scheduler` | satu `schedule:work` |
| Bot tenant | PM2 `digitaltoko-bot-manager` | loopback 3099, worker dibuat manager |
| Antrean backup/restore | PM2 `digitaltoko-backup-queue` | database queue `backups`, timeout 3600 detik |
| MySQL, Redis, PHP-FPM, Nginx | systemd | Redis/MySQL lokal; jangan dibuka publik |

Profil gabungan: `deploy/ecosystem.production.config.cjs`. Nama/path tiga aplikasi existing mengikuti konfigurasi Anda. Profil tidak membangun atau mengubah source Hanshop. Jalankan sebagai user PM2 existing (`hans` pada contoh), bukan `sudo pm2`. Satu scheduler PM2 dipertahankan agar prosedur restore mengenal prosesnya; **jangan menambahkan cron Laravel bersamaan**. `artisan serve`, Vite dev dan simulator tidak dipakai di production.

## 2. Periksa VPS dan simpan checkpoint

Masuk sebagai `hans`; periksa tanpa membagikan output yang mengandung rahasia:

```bash
whoami
pm2 status
node --version
npm --version
/usr/bin/php8.5 --version
/usr/bin/php8.5 "$(command -v composer)" --version
nginx -v
sudo ss -lntp
sudo nginx -t
```

Gunakan Node 22.12+ dan **PHP 8.5.12 atau patch stabil 8.5.x lebih baru** sesuai lock dependencies (disarankan versi patch terbaru yang didukung). Periksa versi PHP CLI dan FPM, jangan hanya salah satunya. Siapkan MySQL 8, Redis, Git, Composer, Nginx, PHP-FPM, klien `mysql`/`mysqldump`, ekstensi PDO MySQL, mbstring, XML/DOM, cURL, zip (AES-256), GD, bcmath, intl dan PCNTL untuk CLI. `/usr/bin/php8.5 "$(command -v composer)" check-platform-reqs --no-dev` menjadi pemeriksaan akhir kebutuhan sebenarnya. Jika paket PHP 8.5 tidak tersedia di repo OS, siapkan repository PHP tepercaya atau runtime yang sesuai; jangan memaksakan PHP 8.3 melalui `--ignore-platform-reqs`.

### Wajib patch PHP2Shell sebelum dibuka publik

Kedua advisory resmi PHP, [GHSA-w8hw-w9fx-j79w](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) dan [GHSA-6wmf-4rv5-p3rp](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp), mencantumkan perbaikan pada 8.4.27 dan 8.5.12 (cabang lain: 8.3.36/8.2.35). Profil ini menetapkan cabang **8.5 dengan patch minimal 8.5.12** untuk CLI, FPM, queue dan scheduler. Pada pemeriksaan 4 November 2026, changelog resmi menampilkan 8.5.12 sebagai rilis stabil terbaru cabang 8.5. Gunakan rilis stabil, bukan alpha/beta/RC. Gunakan patch terbaru yang tersedia dari repository tepercaya, jangan mengunci selamanya pada rilis minimum.

Periksa kandidat paket dan binary yang benar:

```bash
apt-cache policy php8.5-cli php8.5-fpm php8.5-common
/usr/bin/php8.5 -v
/usr/sbin/php-fpm8.5 -v
```

Jangan lanjut membuka layanan jika versi upstream cabang 8.5 di bawah 8.5.12, kecuali vendor distribusi secara resmi membuktikan kedua patch telah di-backport pada build tersebut. Simpan nomor paket dan rujukan advisory vendor. Berhasil menjalankan Composer saja tidak membuktikan patch keamanan sudah diterapkan.

Setelah upgrade pada server existing, drain pekerjaan lalu restart service FPM dan proses PHP jangka panjang agar tidak memakai binary lama. `sudo systemctl restart php8.5-fpm` berdampak pada semua pool versi itu, jadi jadwalkan jika dipakai aplikasi lain. Queue dapat dihentikan graceful melalui `/usr/bin/php8.5 artisan queue:restart`, kemudian periksa worker baru dan scheduler PM2; hentikan/daftar ulang proses bila interpreter menunjuk PHP lama. Restart bot Node tidak menggantikan restart PHP-FPM. Periksa konfigurasi socket Nginx dan executable pada `pm2 describe digitaltoko-queue` serta `pm2 describe digitaltoko-scheduler`.

Nginx + FPM, WAF, batas upload, atau maintenance aplikasi bukan pengganti patch runtime. Batas minimum ini khusus perbaikan PHP2Shell yang diperiksa pada 4 November 2026, bukan jaminan bebas semua celah keamanan.

Jalankan Composer melalui binary PHP 8.5 secara eksplisit agar tidak mengikuti PHP default lama. Contoh mengasumsikan `command -v composer` menunjuk skrip PHP/PHAR Composer asli; bila berupa wrapper shell, gunakan path PHAR aslinya. Tidak perlu mengganti PHP default aplikasi lain.

Contoh setelah repository PHP sesuai tersedia (lewati layanan yang sudah terpasang):

```bash
sudo apt-get update
sudo apt-get install nginx redis-server mysql-client git unzip acl php8.5-cli php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-gd php8.5-bcmath php8.5-intl
```

MySQL server, Node, Composer dan PM2 existing tetap dipakai jika memenuhi versi. Jika MySQL belum ada, pasang/konfigurasi MySQL server dahulu. Atur firewall VPS/cloud agar HTTP/HTTPS 80/443 masuk, SSH tetap tersedia pada port Anda, dan MySQL/Redis/manager tidak terbuka publik. Jangan mengaktifkan aturan firewall yang memutus SSH sesi ini. Periksa binding proses existing sebelum membatasi port API yang mungkin sedang digunakan partner.

Backup database Hanshop, konfigurasi Nginx dan ecosystem existing sebelum perubahan. Catat proses aktif. Jangan menghentikan ketiga aplikasi existing untuk memasang DigitalToko. Jika Nginx/Apache/Caddy sudah mendengarkan port 80/443, gunakan web server existing atau rencanakan perpindahannya; dua server tidak boleh memakai port yang sama.

## 3. Salin source dan siapkan database

Salin checkout yang sudah dicommit ke `/home/hans/hanshop/digitaltoko`. Jangan menyertakan `node_modules`, `vendor`, `public/hot`, database lab, simulator, arsip ZIP root atau arsip sesi lama. Sesi bot hanya dipindah dengan prosedur terkontrol, setelah manager asal berhenti. Jangan clone sesi yang masih hidup.

Buat database **terpisah** dari database API Hanshop lewat MySQL sebagai administrator:

```sql
CREATE DATABASE digitaltoko CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'digitaltoko'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD_ACAK';
GRANT ALL PRIVILEGES ON digitaltoko.* TO 'digitaltoko'@'127.0.0.1';
```

Gunakan nama/password nyata di server. Hak ini hanya mencakup database DigitalToko, bukan `*.*`. Pemulihan ke database terpisah membutuhkan database/user tujuan yang dipersiapkan operator; jangan memberi hak global hanya agar pratinjau restore berjalan.

```bash
cd /home/hans/hanshop/digitaltoko/apps/web
cp .env.vps.example .env
chmod 600 .env
```

Isi `.env` melalui editor server. Jangan menimpa `.env` existing pada redeploy.

| Pengaturan | Nilai yang harus diperiksa |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL`, `PLATFORM_DOMAIN` | `https://hansdigi.biz.id`, `hansdigi.biz.id` |
| `DB_*` | database DigitalToko baru, host `127.0.0.1` |
| `CACHE_STORE`, `SUPPLIER_CACHE_STORE` | `redis`; DB Redis 2/3 contoh harus kosong/khusus aplikasi ini |
| `QUEUE_CONNECTION` | `database`, `DB_QUEUE_RETRY_AFTER=240` (> timeout 180) |
| `SESSION_DOMAIN` | `null`, cookie host-only, secure di HTTPS |
| `HANSHOP_API_URL` | origin API Hanshop existing yang dapat dijangkau server; loopback 4000 jika satu VPS |
| `HANSHOP_IDENTITY_TOKEN` | sama dengan `DIGITALTOKO_IDENTITY_TOKEN` pada API Hanshop |
| `BOT_MANAGER_TOKEN` | acak minimal 32 karakter, sama di web dan bot DigitalToko |
| `SUPPLIER_OUTBOUND_IP` | IP publik keluar VPS, untuk informasi whitelist tenant |
| `CUSTOM_DOMAIN_ORIGIN_IPS` | IP publik masuk VPS; hapus AAAA DNS bila IPv6 belum dikonfigurasi |
| `MAIL_*` | akun SMTP nyata; contoh `smtps`/465 perlu sesuai penyedia |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | admin production, password 12–72 karakter |

Generate masing-masing token secara terpisah dengan `openssl rand -hex 32`; jangan memakai token bot Hanshop untuk bot manager. Atur Redis loopback; jika membutuhkan password, isi `REDIS_PASSWORD`. Hindari `FLUSHALL` pada Redis bersama.

Untuk Cloudflare awal gunakan **DNS only** agar routing dan HTTPS origin bisa diuji langsung. Jika nanti proxy diaktifkan, gunakan SSL Full (strict), isi `TRUSTED_PROXIES` dengan CIDR Cloudflare resmi terkini dan pastikan IP klien terdeteksi benar; jangan isi `*`. Jangan membuat cache rule untuk panel, login, checkout atau callback.

## 4. Dependensi, build dan data dasar

```bash
cd /home/hans/hanshop/digitaltoko/apps/web
/usr/bin/php8.5 "$(command -v composer)" install --no-dev --prefer-dist --optimize-autoloader
/usr/bin/php8.5 "$(command -v composer)" check-platform-reqs --no-dev
/usr/bin/php8.5 artisan key:generate --force
npm ci --include=dev
npm run build
# Hanya hapus penanda Vite development ini, bukan direktori public.
rm -f public/hot
/usr/bin/php8.5 artisan migrate --force
/usr/bin/php8.5 artisan db:seed --class=ProductionSeeder --force
/usr/bin/php8.5 artisan storage:link
/usr/bin/php8.5 artisan config:cache
/usr/bin/php8.5 artisan route:cache
/usr/bin/php8.5 artisan view:cache

cd /home/hans/hanshop/digitaltoko/apps/bot
cp .env.vps.example .env
chmod 600 .env
# Edit .env: isi token yang sama, WEB_BASE_URL dan SESSION_ROOT yang benar.
npm ci --include=dev
npm run build
```

`key:generate` **hanya instalasi kosong pertama**. Simpan APP_KEY; pada pemindahan data existing gunakan APP_KEY asal agar credential/enkripsi tetap terbaca. Jangan gunakan `migrate:fresh`. Seeder production tidak menambah toko/customer dummy. Paket baru masih nonaktif; atur harga/durasi lalu aktifkan dari admin. Modul QRIS juga perlu ditinjau harganya sebelum dijual. Seeder bukan perintah update rutin karena konfigurasi admin akan diterapkan kembali dari env.

Pastikan source API Hanshop yang menyediakan bridge `/internal/digitaltoko` sudah terdeploy. Periksa dan terapkan SQL additive yang belum ada dari `api_hanshop/scripts/migrations/`, sesuai README API dan urutan migrasinya; jangan menganggap database production sama dengan laptop atau menjalankan ulang semua SQL tanpa pemeriksaan. Deploy integrasi kedua sisi sebelum menguji OTP/saldo/refund.

## 5. PHP-FPM dan izin file

Salin `deploy/php-fpm.production.conf.example` ke `/etc/php/8.5/fpm/pool.d/digitaltoko.conf`, sesuaikan versi PHP/user. Pool khusus berjalan sebagai `hans`; ini menyamakan kepemilikan private media, sesi dan akun PM2 untuk pemulihan. Nginx tetap `www-data`, melalui socket terbatas. Tidak perlu mengubah pool aplikasi lain.

```bash
sudo apt-get install acl
cd /home/hans/hanshop/digitaltoko
mkdir -p apps/bot/sessions /home/hans/backups/digitaltoko
chmod 700 apps/bot/sessions /home/hans/backups/digitaltoko
chmod -R u+rwX apps/web/storage apps/web/bootstrap/cache
# Hanya hak traversal untuk parent, bukan membaca semua isi home.
sudo setfacl -m u:www-data:--x /home/hans /home/hans/hanshop /home/hans/hanshop/digitaltoko /home/hans/hanshop/digitaltoko/apps /home/hans/hanshop/digitaltoko/apps/web
sudo setfacl -R -m u:www-data:r-X apps/web/public
sudo setfacl -R -d -m u:www-data:r-X apps/web/public
sudo php-fpm8.5 -t
sudo systemctl reload php8.5-fpm
```

Source checkout harus dimiliki `hans`. Jangan `chmod -R 777` atau memberi `www-data` akses `.env`, sesi bot dan backup. Nginx mengakses file publik; media privat tetap melalui Laravel/FPM. Ulangi ACL public bila metode deploy mengganti direktori sehingga ACL hilang. Jika ada storage public symlink berisi file publik, beri hak baca/traversal khusus `storage/app/public` dan parentnya; jangan seluruh `storage/app/private`.

## 6. DNS dan HTTPS

Di Cloudflare siapkan A `@` dan `*` ke IP origin DigitalToko; opsional `shops` sebagai target domain pribadi. Record eksplisit existing seperti `mail`, `api` atau subdomain hosting lain **tetap dipertahankan**. Wildcard DNS tidak menggantikan record tersebut. Jika root masih untuk website lain, tentukan perpindahan root terlebih dahulu; jangan menimpa diam-diam.

Contoh sertifikat root + wildcard dengan Certbot DNS Cloudflare (Ubuntu/Debian dengan paket tersedia):

```bash
sudo apt-get install certbot python3-certbot-dns-cloudflare
sudo install -d -m 700 /root/.secrets/certbot
sudoedit /root/.secrets/certbot/cloudflare.ini
```

Isi file: `dns_cloudflare_api_token = TOKEN_CLOUDFLARE_ANDA`. Token hanya perlu Zone DNS Edit untuk zone domain terkait, bukan Global API Key.

```bash
sudo chmod 600 /root/.secrets/certbot/cloudflare.ini
sudo certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /root/.secrets/certbot/cloudflare.ini \
  --cert-name hansdigi.biz.id -d hansdigi.biz.id -d '*.hansdigi.biz.id'
```

Setelah sertifikat ada, salin `deploy/nginx.production.conf.example` ke `/etc/nginx/sites-available/digitaltoko`, periksa root/socket/cert, lalu buat symlink jika belum ada:

```bash
sudo ln -s /etc/nginx/sites-available/digitaltoko /etc/nginx/sites-enabled/digitaltoko
sudo nginx -t
sudo systemctl reload nginx
sudo certbot renew --dry-run
```

Pastikan pembaruan sertifikat memuat ulang Nginx: pasang deploy hook Certbot berisi `systemctl reload nginx` pada `/etc/letsencrypt/renewal-hooks/deploy/`, dan periksa timer `systemctl list-timers '*certbot*'`. Jangan hapus virtual host existing. Nama exact pada Nginx didahulukan dari wildcard, tetapi konflik nama exact yang sama harus diselesaikan. Jangan mengaktifkan site ini sebelum file sertifikatnya tersedia.

Domain pribadi tenant memerlukan **virtual host + sertifikat tersendiri**; wildcard platform tidak melindungi domain orang lain. Untuk Nginx, operator membuat vhost dengan document root/socket yang sama dan sertifikat hostname tenant, kemudian owner memverifikasi melalui panel. Otomatisasi TLS tersedia sebagai alternatif Caddy pada [panduan domain](CUSTOM_DOMAINS.md); Caddy menggantikan origin Nginx, bukan dijalankan di port yang sama. Contoh Nginx ini belum membuat domain pribadi otomatis.

## 7. Jalankan DigitalToko pada PM2 existing

Jalankan sebagai `hans`. `DIGITALTOKO_PHP_BINARY` adalah environment saat membaca ecosystem, bukan isi `.env` Laravel.

```bash
cd /home/hans/hanshop/digitaltoko
export DIGITALTOKO_PHP_BINARY=/usr/bin/php8.5
pm2 start deploy/ecosystem.production.config.cjs --only digitaltoko-queue,digitaltoko-scheduler,digitaltoko-bot-manager,digitaltoko-backup-queue
pm2 status
pm2 logs digitaltoko-bot-manager --lines 30
pm2 save
```

Keempat proses baru saja dimulai. Tiga proses Hanshop existing tidak direstart. File gabungan juga memuat ketiganya untuk pengelolaan berikutnya, tetapi **jangan menjalankan profil development/root ecosystem atau profil VPS lain bersamaan**. Jika memindahkan executable/cwd sebuah proses existing, periksa definisi PM2 dan daftar ulang hanya proses tersebut pada maintenance terencana; restart dapat mempertahankan jalur lama.

Jika autostart user `hans` belum tersedia, jalankan `pm2 startup`, ikuti persis perintah sudo yang dicetak PM2 lalu `pm2 save`. Jika startup sudah ada, periksa `systemctl status pm2-hans` tanpa membuat daemon kedua. NVM membutuhkan PATH Node yang benar pada systemd; setelah upgrade Node, perbarui startup sesuai petunjuk PM2. User FPM dan PM2 harus menggunakan instance PM2 yang sama bila live restore diaktifkan.

Satu manager membuat banyak worker tenant; batas RAM manager bukan batas seluruh anaknya. Awali kapasitas `MANAGER_MAX_WORKERS=10`, ukur RAM bot Hanshop + worker tenant sebelum menambah kapasitas.

## 8. Backup, download dan layanan pendukung

Generate BACKUP_KEY terpisah: `/usr/bin/php8.5 -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`. Simpan offline bersama APP_KEY dengan akses terbatas; jangan commit. Isi `BACKUP_KEY`, aktifkan `BACKUP_ENABLED=true`, cache konfigurasi ulang, lalu `/usr/bin/php8.5 artisan digitaltoko:backup` dari `apps/web`. Periksa hasil melalui admin dan salin ke penyimpanan di luar VPS; direktori replica di disk yang sama bukan perlindungan jika disk rusak.

Antrean backup sudah berjalan terpisah. `BACKUP_LIVE_RESTORE_ENABLED` tetap false sampai uji restore database terpisah dan prosedur resume lulus di VPS. Untuk mengaktifkannya, isi `BACKUP_PM2_CLI` dengan path absolut `$(npm root -g)/pm2/bin/pm2` dan `BACKUP_NODE_BINARY` sesuai `command -v node`, pastikan user/runtime mampu mengakses daemon PM2 yang sama. Ikuti bagian backup/pemulihan pada [DEPLOYMENT.md](DEPLOYMENT.md); jangan menyalakan live restore hanya untuk menghilangkan pesan error.

Berkas APK/tools HansDev bukan bagian build frontend. Salin release yang sudah ditinjau ke lokasi private yang dipakai konfigurasi `HANSDEV_FORWARDER_*_PATH`, atau default aplikasi. Uji unduhan tiap OS; jangan sertakan credential/user data di ZIP.

## 9. Uji sebelum menerima pelanggan

1. `curl -I https://hansdigi.biz.id/up` harus 200. Landing, `/admin/login`, `/owner` dan `/paket` tersedia dengan aset tanpa Vite/HMR.
2. Login admin, periksa kesehatan sistem: queue, backup queue, scheduler, Redis, manager dan sinkronisasi. Periksa log bila errored; `online` PM2 saja belum membuktikan proses bisnis berjalan.
3. Uji email owner dan customer toko, logo, OTP serta private media. Gunakan akun uji; SMTP auth bukan bukti inbox menerima email.
4. Buat satu toko sandbox, aktifkan paket uji, subdomain, hubungkan WA, sumber, katalog dan pembayaran. Host asing tidak boleh membuka data toko; session customer berbeda antarhost.
5. Salin **URL callback dari panel** ke Midtrans, Digiflazz, OtomaX dan Forwarder. Semua harus menggunakan HTTPS publik terbaru, bukan ngrok/localhost. Whitelist IP keluar VPS pada akun supplier yang sesuai.
6. Uji pending → callback sukses/gagal, refund, callback berulang, failover, pascabayar cek/bayar dan pembatalan. Pastikan tiap callback tercatat/diproses, bukan hanya HTTP 200.
7. Uji maintenance global/toko: transaksi baru diblokir, webhook dan pembaruan pesanan existing tetap berjalan. Jangan memakai `artisan down` untuk maintenance bisnis karena dapat memblokir callback.
8. Uji backup, unduh, restore terpisah, lalu reboot terencana: semua proses dan domain kembali sehat. Baru lakukan transaksi production kecil dengan persetujuan operator; jangan mencampurkan lab dengan saldo/stok nyata.

## 10. Update source / ubah environment

Ambil snapshot/backup sebelum migrasi. Pertahankan `.env`, APP_KEY, BACKUP_KEY, media, registry dan sesi bot. Jangan memakai `pm2 restart all` karena ikut mengganggu bot/API Hanshop.

1. Periksa antrean/pekerjaan aktif dan aktifkan maintenance bisnis jika perlu; tunggu backup/restore yang berjalan selesai. Perubahan skema besar membutuhkan rencana maintenance khusus dan pengiriman ulang callback.
2. Perbarui source, `/usr/bin/php8.5 "$(command -v composer)" install --no-dev --prefer-dist --optimize-autoloader`, build web/bot seperti langkah 4. Jangan menjalankan seeder rutin atau key:generate.
3. Dari `apps/web`, jalankan `/usr/bin/php8.5 artisan migrate --force`, `/usr/bin/php8.5 artisan config:cache`, `/usr/bin/php8.5 artisan route:cache`, `/usr/bin/php8.5 artisan view:cache`, lalu `/usr/bin/php8.5 artisan schedule:interrupt`.
4. `/usr/bin/php8.5 artisan queue:restart` untuk meminta worker selesai secara graceful. Tunggu PM2 menjalankan ulang **kedua antrean**; proses backup panjang bisa memerlukan waktu. Jika hanya mengganti `.env` Laravel, worker baru akan membaca hasil config cache yang baru.
5. Restart scheduler dan manager sesuai perubahan, dari root:

```bash
pm2 restart deploy/ecosystem.production.config.cjs --only digitaltoko-scheduler,digitaltoko-bot-manager --update-env
sudo systemctl reload php8.5-fpm
pm2 save
```

Untuk `.env` bot, restart manager diperlukan. Untuk perubahan ecosystem/PHP binary, berikan override yang sama saat memuat konfigurasi dan pastikan executable benar. Gunakan restart fork, bukan reload cluster untuk sesi WA. Jangan memaksa stop backup aktif: kill timeout profil memang panjang agar pekerjaan selesai. Jika config cache diubah, selalu restart worker lama; `--update-env` PM2 sendiri tidak membangun config cache Laravel.

6. Periksa `/up`, inbox/log, kesehatan admin dan satu pesanan. Matikan maintenance bisnis setelah validasi. Jika versi baru gagal, pulihkan source yang kompatibel dan evaluasi migrasi; jangan melakukan rollback database otomatis di tengah transaksi.

## Referensi resmi

- [Laravel deployment](https://laravel.com/framework/docs/deployment), [queue](https://laravel.com/framework/docs/13.x/queues), [scheduler dan submenit](https://laravel.com/framework/docs/13.x/scheduling).
- [PM2 ecosystem](https://doc.pm2.io/en/runtime/guide/ecosystem-file/) dan [startup](https://pm2.keymetrics.io/docs/usage/startup/).
- [Certbot Cloudflare DNS](https://certbot-dns-cloudflare.readthedocs.io/en/stable/), [pemilihan virtual host Nginx](https://nginx.org/en/docs/http/server_names.html).

## Catatan pemeriksaan lokal — 4 November 2026

- Konfigurasi JS lolos `node --check`; pemeriksaan profil memastikan tujuh nama unik, path/port tiga layanan existing, satu scheduler, queue backup terpisah, satu instance fork dan tidak ada server development.
- Sebelas tes konfigurasi/launcher PM2 existing lulus. Kedua contoh environment berhasil dibaca parser Dotenv; `git diff --check` lulus.
- Tidak ada layanan lokal/VPS yang direstart, migrasi/seeder dijalankan, credential nyata ditambahkan, atau callback/transaksi dikirim selama persiapan ini.
- Target runtime diperbarui menjadi PHP 8.5.12+; profil PM2 production memakai `/usr/bin/php8.5` sebagai default tanpa mengubah profil laptop.
- Binary Nginx/PHP-FPM Linux dan sertifikat publik belum tersedia pada lingkungan pemeriksaan Windows. Jalankan `nginx -t`, `php-fpm8.5 -t`, pemeriksaan runtime, renewal, reboot dan alur bisnis pada VPS sebelum menerima pelanggan.
- Profil laptop dan konfigurasi lama tetap tersedia untuk kompatibilitas. Panduan ini memakai profil production gabungan saja; arsip ZIP dan sesi WhatsApp lama tidak termasuk berkas deployment yang disiapkan.
