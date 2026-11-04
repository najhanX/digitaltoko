# Pemasangan production DigitalToko di VPS

Disusun 4 November 2026 dari source saat ini. Contoh berikut menjadi panduan pemasangan; catatan penyiapan VPS nyata pada 4 November 2026 tersedia di akhir dokumen. Penyiapan internal tidak sama dengan aktivasi domain publik. Contoh memakai Ubuntu/Debian, user `hans`, domain `digitaltoko.com`, dan checkout `/home/hans/digitaltoko`. Sesuaikan path bila berbeda. Jangan menjalankan perintah Linux berikut di laptop Windows.

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

Salin checkout yang sudah dicommit ke `/home/hans/digitaltoko`. Jangan menyertakan `node_modules`, `vendor`, `public/hot`, database lab, simulator, arsip ZIP root atau arsip sesi lama. Sesi bot hanya dipindah dengan prosedur terkontrol, setelah manager asal berhenti. Jangan clone sesi yang masih hidup.

Buat database **terpisah** dari database API Hanshop lewat MySQL sebagai administrator:

```sql
CREATE DATABASE digitaltoko CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'digitaltoko'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD_ACAK';
GRANT ALL PRIVILEGES ON digitaltoko.* TO 'digitaltoko'@'127.0.0.1';
```

Gunakan nama/password nyata di server. Hak ini hanya mencakup database DigitalToko, bukan `*.*`. Pemulihan ke database terpisah membutuhkan database/user tujuan yang dipersiapkan operator; jangan memberi hak global hanya agar pratinjau restore berjalan.

```bash
cd /home/hans/digitaltoko/apps/web
cp .env.vps.example .env
chmod 600 .env
```

Isi `.env` melalui editor server. Jangan menimpa `.env` existing pada redeploy.

| Pengaturan | Nilai yang harus diperiksa |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL`, `PLATFORM_DOMAIN` | `https://digitaltoko.com`, `digitaltoko.com` |
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
cd /home/hans/digitaltoko/apps/web
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

cd /home/hans/digitaltoko/apps/bot
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
cd /home/hans/digitaltoko
mkdir -p apps/bot/sessions /home/hans/backups/digitaltoko
chmod 700 apps/bot/sessions /home/hans/backups/digitaltoko
chmod -R u+rwX apps/web/storage apps/web/bootstrap/cache
# Hanya hak traversal untuk parent, bukan membaca semua isi home.
sudo setfacl -m u:www-data:--x /home/hans /home/hans/hanshop /home/hans/digitaltoko /home/hans/digitaltoko/apps /home/hans/digitaltoko/apps/web
sudo setfacl -R -m u:www-data:r-X apps/web/public
sudo setfacl -R -d -m u:www-data:r-X apps/web/public
sudo php-fpm8.5 -t
sudo systemctl reload php8.5-fpm
```

Source checkout harus dimiliki `hans`. Jangan `chmod -R 777` atau memberi `www-data` akses `.env`, sesi bot dan backup. Nginx mengakses file publik; media privat tetap melalui Laravel/FPM. Ulangi ACL public bila metode deploy mengganti direktori sehingga ACL hilang. Jika ada storage public symlink berisi file publik, beri hak baca/traversal khusus `storage/app/public` dan parentnya; jangan seluruh `storage/app/private`.

## 6. DNS dan HTTPS

Pada pengelola DNS aktif, siapkan A `@` dan `*` ke IP origin DigitalToko; opsional `shops` sebagai target domain pribadi. Record eksplisit existing seperti `mail`, `api` atau subdomain hosting lain **tetap dipertahankan**. Wildcard DNS tidak menggantikan record tersebut. Jika root masih untuk website lain, tentukan perpindahan root terlebih dahulu; jangan menimpa diam-diam. DNS production saat ini memakai nameserver Jagoanhosting dan dikelola melalui Zone Editor cPanel, bukan Cloudflare.

Jika belum tersedia API DNS, terbitkan sertifikat wildcard dengan verifikasi TXT manual:

```bash
sudo certbot certonly --manual --preferred-challenges dns \
  --cert-name digitaltoko.com -d digitaltoko.com -d '*.digitaltoko.com'
```

Ikuti nilai TXT yang ditampilkan Certbot pada `_acme-challenge.digitaltoko.com`. Jika ada dua challenge, simpan dua record bernama sama dengan nilai berbeda sampai keduanya tervalidasi. Jangan memakai ulang nilai dari percobaan sebelumnya. Metode manual ini **belum memperbarui sertifikat otomatis**: operator perlu menjalankan penerbitan ulang sebelum kedaluwarsa dan memperbarui TXT. Timer Certbot saja tidak dapat menggantikan pengisian DNS. Otomatisasi memerlukan API DNS dan plugin/hook yang sesuai penyedia.

Alternatif bila DNS memakai Cloudflare: sertifikat root + wildcard dengan Certbot DNS Cloudflare (Ubuntu/Debian dengan paket tersedia):

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
  --cert-name digitaltoko.com -d digitaltoko.com -d '*.digitaltoko.com'
```

Setelah sertifikat ada, salin `deploy/nginx.production.conf.example` ke `/etc/nginx/sites-available/digitaltoko`, periksa root/socket/cert, lalu buat symlink jika belum ada:

```bash
sudo ln -s /etc/nginx/sites-available/digitaltoko /etc/nginx/sites-enabled/digitaltoko
sudo nginx -t
sudo systemctl reload nginx
# Untuk sertifikat dengan plugin DNS otomatis, uji juga:
sudo certbot renew --dry-run --cert-name digitaltoko.com
```

Pastikan pembaruan sertifikat memuat ulang Nginx: pasang deploy hook Certbot berisi `systemctl reload nginx` pada `/etc/letsencrypt/renewal-hooks/deploy/`, dan periksa timer `systemctl list-timers '*certbot*'`. Jangan hapus virtual host existing. Nama exact pada Nginx didahulukan dari wildcard, tetapi konflik nama exact yang sama harus diselesaikan. Jangan mengaktifkan site ini sebelum file sertifikatnya tersedia.

Domain pribadi tenant memerlukan **virtual host + sertifikat tersendiri**; wildcard platform tidak melindungi domain orang lain. Untuk Nginx, operator membuat vhost dengan document root/socket yang sama dan sertifikat hostname tenant, kemudian owner memverifikasi melalui panel. Otomatisasi TLS tersedia sebagai alternatif Caddy pada [panduan domain](CUSTOM_DOMAINS.md); Caddy menggantikan origin Nginx, bukan dijalankan di port yang sama. Contoh Nginx ini belum membuat domain pribadi otomatis.

## 7. Jalankan DigitalToko pada PM2 existing

Jalankan sebagai `hans`. `DIGITALTOKO_PHP_BINARY` adalah environment saat membaca ecosystem, bukan isi `.env` Laravel.

```bash
cd /home/hans/digitaltoko
export DIGITALTOKO_PHP_BINARY=/usr/bin/php8.5
pm2 start deploy/ecosystem.production.config.cjs --only digitaltoko-queue,digitaltoko-scheduler,digitaltoko-bot-manager,digitaltoko-backup-queue
pm2 status
pm2 logs digitaltoko-bot-manager --lines 30
pm2 save
```

Keempat proses baru saja dimulai. Tiga proses Hanshop existing tidak direstart. File gabungan juga memuat ketiganya untuk pengelolaan berikutnya, tetapi **jangan menjalankan profil development/root ecosystem atau profil VPS lain bersamaan**. Jika memindahkan executable/cwd sebuah proses existing, periksa definisi PM2 dan daftar ulang hanya proses tersebut pada maintenance terencana; restart dapat mempertahankan jalur lama.

Jika autostart user `hans` belum tersedia, jalankan `pm2 startup`, ikuti persis perintah sudo yang dicetak PM2 lalu `pm2 save`. Jika startup sudah ada, periksa `systemctl status pm2-hans` tanpa membuat daemon kedua. NVM membutuhkan PATH Node yang benar pada systemd; setelah upgrade Node, perbarui startup sesuai petunjuk PM2. User FPM dan PM2 harus menggunakan instance PM2 yang sama bila live restore diaktifkan.

Satu manager membuat banyak worker tenant; batas RAM manager bukan batas seluruh anaknya. Awali kapasitas `MANAGER_MAX_WORKERS=2` pada VPS 2 GB ini, ukur RAM bot Hanshop + worker tenant sebelum menambah kapasitas.

## 8. Backup, download dan layanan pendukung

Generate BACKUP_KEY terpisah: `/usr/bin/php8.5 -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`. Simpan offline bersama APP_KEY dengan akses terbatas; jangan commit. Isi `BACKUP_KEY`, aktifkan `BACKUP_ENABLED=true`, cache konfigurasi ulang, lalu `/usr/bin/php8.5 artisan digitaltoko:backup` dari `apps/web`. Periksa hasil melalui admin dan salin ke penyimpanan di luar VPS; direktori replica di disk yang sama bukan perlindungan jika disk rusak.

Antrean backup sudah berjalan terpisah. `BACKUP_LIVE_RESTORE_ENABLED` tetap false sampai uji restore database terpisah dan prosedur resume lulus di VPS. Untuk mengaktifkannya, isi `BACKUP_PM2_CLI` dengan path absolut `$(npm root -g)/pm2/bin/pm2` dan `BACKUP_NODE_BINARY` sesuai `command -v node`, pastikan user/runtime mampu mengakses daemon PM2 yang sama. Ikuti bagian backup/pemulihan pada [DEPLOYMENT.md](DEPLOYMENT.md); jangan menyalakan live restore hanya untuk menghilangkan pesan error.

Berkas APK/tools HansDev bukan bagian build frontend. Salin release yang sudah ditinjau ke lokasi private yang dipakai konfigurasi `HANSDEV_FORWARDER_*_PATH`, atau default aplikasi. Uji unduhan tiap OS; jangan sertakan credential/user data di ZIP.

## 9. Uji sebelum menerima pelanggan

1. `curl -I https://digitaltoko.com/up` harus 200. Landing, `/admin/login`, `/owner` dan `/paket` tersedia dengan aset tanpa Vite/HMR.
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


## Pelaksanaan prasyarat pada VPS — 4 November 2026

Lokasi clone aktual `/home/hans/digitaltoko`, branch main commit `68a3234`. Konfigurasi dan source contoh menggunakan lokasi ini. Tiga layanan Hanshop existing tidak direstart/dipindahkan selama penyiapan.

Selesai:

- Snapshot konfigurasi Nginx/PHP/APT dan daftar PM2 awal disimpan privat sebelum perubahan. PHP CLI/FPM **8.5.12** dipasang berdampingan; binary default PHP lama serta pool situs existing tidak dipindahkan.
- Composer **2.11.1** dipasang untuk user hans di `/home/hans/.local/bin/composer.phar` setelah verifikasi checksum installer. Gunakan `/usr/bin/php8.5 /home/hans/.local/bin/composer.phar` pada VPS ini karena Composer sistem masih lama.
- Database dan user MySQL khusus `digitaltoko` dibuat. Environment web/bot mode 600, APP_KEY, token manager/bridge, BACKUP_KEY dan direktori sesi/backup privat disiapkan tanpa memasukkan credential ke Git. Password Redis existing diterapkan secara internal; DigitalToko memakai DB Redis 2/3, tanpa flush data Hanshop.
- Dependensi Composer production, pemeriksaan platform, build web dan bot berhasil. Migrasi Laravel diterapkan pada database DigitalToko baru. Hanya `PackagePlanSeeder` dijalankan: penawaran masih nonaktif, tanpa dummy dan tanpa admin. Akun admin akan dibuat sendiri oleh pengguna; jangan menjalankan ProductionSeeder sebelum ADMIN_* terisi.
- Pool FPM `digitaltoko` dengan tiga child maksimum dan preview Nginx **127.0.0.1:18085** aktif. Pemeriksaan FPM/Nginx lulus, landing dan `/up` menghasilkan HTTP 200 melalui host platform. Domain publik/SSL/DNS belum dialihkan; server preview tidak mendengarkan IP publik.
- Empat proses PM2 DigitalToko terdaftar; proses PHP memakai `/usr/bin/php8.5`, manager 3099 loopback, kapasitas awal dua tenant. Database, Redis/cache, manager, heartbeat queue dan scheduler sudah terbaca sehat. Proses sempat mengulang saat autentikasi Redis belum terisi; konfigurasi diperbaiki dan worker dimulai ulang secara khusus. Pemeriksaan akhir menunjukkan worker PHP stabil lebih dari lima menit tanpa pertambahan restart setelah perbaikan. Tidak me-restart seluruh PM2.
- `pm2 save` dijalankan setelah snapshot daftar sebelumnya; service pm2-hans existing enabled. Belum melakukan uji reboot.
- Backup terenkripsi awal berhasil dibuat dan diverifikasi di `/home/hans/backups/digitaltoko/digitaltoko-20261104-020104285555-wp7zb7t0.zip`. Belum ada salinan offsite atau uji live restore; fitur live restore tetap false.

### Audit dan migrasi database API Hanshop

Database aktual adalah MariaDB **10.4.32**, schema `hansdigi_hanshop`; jangan menggantinya dengan instalasi MySQL baru di atas service existing. Semua tabel yang diaudit memakai InnoDB. Snapshot SQL lengkap sebelum perubahan tersimpan dengan permission privat di `/home/hans/deploy-checkpoints/digitaltoko-20261104/`, termasuk `api-hanshop-before.sql` dan `api-hanshop-before-live.sql`. SQL snapshot ini berisi data nyata: jangan upload ke GitHub atau document root.

Sembilan script berikut terlebih dahulu diuji pada salinan database, kemudian diterapkan pada database aktif:

1. `20260830_complete_saldo_ledger.sql`
2. `20260830_payment_reconciliation_worker.sql`
3. `20261013_digitaltoko_billing.sql`
4. `20261013_digitaltoko_identity.sql`
5. `20261020_manual_result_supplier_callback.sql`
6. `20261020_negative_balance_support.sql`
7. `20261026_digitaltoko_payment_runtime_columns.sql`
8. `20261026_digitaltoko_rental_refunds.sql`
9. `20261026_digitaltoko_supplier_deposits.sql`

Verifikasi membandingkan checksum kolom existing sebelum/sesudah, bukan SELECT * yang ikut berubah karena penambahan kolom. **98 akun, 1.165 transaksi dan 503 pembayaran** tetap sama; data akun termasuk saldo, transaksi dan pembayaran tidak berubah. Ledger audit berjumlah 844 setelah backfill yang ditandai sesuai script; tidak ada kredit/debit saldo akun untuk backfill. Tidak ada transaksi/payment pending pada saat penerapan. Ketiga database salinan uji migrasi sudah dihapus setelah verifikasi; snapshot dan bukti checksum tetap disimpan privat. Bukti privat: `clone-verification.json`, `production-verification.json`, log per script dan `production-migrations.log`.

API yang aktif masih commit **1433973**. Source lokal terbaru **d3d1393** diarsipkan tanpa `.env.backup`/credential, lalu disiapkan di `/home/hans/hanshop/api_hanshop_releases/d3d1393` dengan lockfile lokal dan build berhasil. Preflight production release terpisah terhadap konfigurasi/skema VPS berstatus **READY**. Ini bukan bukti release itu sudah melayani HTTP. Token bridge telah disiapkan pada env API, tetapi proses existing belum direstart. Karena API aktif belum memiliki health/ready dan bridge baru, panel DigitalToko masih dapat menampilkan integrasi API Hanshop belum siap. Fetch GitHub API pada VPS belum berhasil dengan autentikasi tersimpan; jangan menggunakan token yang sudah terpapar di chat.

### Sebelum aktivasi publik

- Buat admin sendiri, tinjau harga paket/modul dan aktifkan penawaran yang benar. Perintah interaktif tanpa menaruh password pada argumen: `/usr/bin/php8.5 artisan digitaltoko:admin email-anda@example.com` dari apps/web, atau isi ADMIN_* lalu jalankan AdminSeeder.
- Tinjau dan aktifkan release API Hanshop terbaru dengan rencana rollback; jangan memindahkan proses ke folder staging tanpa memperbarui cwd/script dan verifikasi ulang kompatibilitas bot/panel existing.
- Konfigurasi SMTP asli. `MAIL_MAILER=log` sengaja dipakai selama persiapan, sehingga email/OTP production belum dikirim. Tidak ada transaksi supplier atau email uji yang dikirim selama penyiapan.
- Tinjau audit dependensi runtime sebelum publik: bot memiliki temuan body-parser (high) dan qs (moderate) pada lockfile saat ini. Hasil audit privat disimpan di checkpoint; dependensi belum diubah otomatis. Release API staging mempunyai delapan temuan runtime (satu critical pada proxy-addr, dua high dan lima moderate); jangan mengaktifkan release staging sebelum temuan yang relevan ditinjau/diperbaiki. Audit ini bukan pengukuran versi node_modules API lama yang sedang aktif.
- Selesaikan DNS root/wildcard, sertifikat dan virtual host publik. Draft vhost sesuai lokasi aktual berada di checkpoint, belum diaktifkan pada port publik. Siapkan release APK/tools HansDev di lokasi download jika belum tersedia.
- Upgrade kapasitas dan lakukan pemeriksaan OS/MariaDB/PHP situs lama secara terencana. Pemasangan PHP 8.5 DigitalToko tidak sekaligus mempatch PHP 8.1 milik situs existing. Jangan menyatakan seluruh VPS aman hanya dari versi PHP DigitalToko.
- Uji login, integrasi, callback publik dan backup/restore setelah aktivasi. Semua pekerjaan ini masih berbeda dari keberhasilan build, preflight, atau pemeriksaan localhost.


## Pembaruan layanan Hanshop — 4 November 2026

Bagian ini menggantikan status API belum aktif pada checkpoint penyiapan awal di atas.

- **API Hanshop aktif sekarang d3d1393** di `/home/hans/hanshop/api_hanshop`, fast-forward dari 1433973 melalui bundle Git lokal. Tidak memakai token GitHub yang terpapar. Environment/credential production dipertahankan. Backup SQL baru, source, hasil build lama, node_modules lama dan daftar PM2 disimpan privat di `/home/hans/deploy-checkpoints/hanshop-update-20261104/`. Pending transaksi dan pembayaran nol sebelum aktivasi. Tidak ada migrasi baru dalam langkah aktivasi ini; sembilan migrasi prasyarat sudah diterapkan pada penyiapan sebelumnya.
- Kandidat API mendapat pembaruan dependensi yang masih diizinkan range manifest, tanpa `--force` atau upgrade mayor. Setelahnya audit runtime menunjukkan **0 high / 0 critical**, dengan **6 moderate** yang masih perlu ditinjau. Lockfile hasil patch disimpan juga pada checkout API aktif agar npm ci tidak membaca lockfile lama. Lockfile API masih di-ignore oleh repo; jangan menggantinya dengan lockfile laptop lama pada deploy berikutnya tanpa audit.
- Pengujian API: **216 lulus, 5 tes integrasi opsional dilewati**, menggunakan environment fixture berport tidak aktif dan tanpa credential production. Build berhasil. Preflight sebelumnya READY; setelah aktivasi `/health/ready` berhasil lewat loopback, HTTPS `api2.hansdigi.biz.id` dan proxy `/api` panel admin. Bridge identity DigitalToko diuji dengan ID dummy yang tidak ada: HTTP 200/SUCCESS tanpa pendaftaran, OTP atau transaksi supplier. Health integrasi API pada DigitalToko berubah menjadi ok.
- **Panel admin** memakai snapshot working tree lokal terbaru di atas commit e853b00, termasuk perubahan yang belum dicommit. SHA-256 arsip source: `333d82917c2ef4e679c0361d958be410b103ee94761bd244f5d1bf3048fd20b3`. Build, **7 tes**, dan lint lulus di laptop dan VPS. Asset baru aktif pada `/var/www/hanshop/admin/dist`; aset hash lama dipertahankan agar tab yang masih terbuka tidak kehilangan chunk. Source admin VPS disinkronkan tanpa menyentuh env dan override package.json/package-lock.json milik server.
- Catatan tooling panel admin: override ESLint 10 pada checkout server tidak kompatibel dengan plugin hooks existing. Build tervalidasi memakai manifest/lockfile lokal yang saling cocok (ESLint 9) di `/home/hans/hanshop/admin_frontend_releases/20261104`. Override server disimpan/dipertahankan, bukan dibuang diam-diam. Gunakan release tervalidasi tersebut untuk rebuild sampai override tooling ditinjau.
- **Bot WA** sudah sama pada commit 678c3ed. Seluruh JS hasil kompilasi source saat ini dibandingkan dengan dist aktif: tidak ada perbedaan. Endpoint health menunjukkan WhatsApp CONNECTED dan Redis READY. Sesi dan proses tidak diganti.
- **Bot Telegram** sudah sama pada commit 0a7890f; tidak ada perubahan source baru. **Panel catatan** sudah sama pada commit bf3fef5; konfigurasi `.env.production` khusus VPS dipertahankan. Tidak me-restart layanan hanya karena namanya termasuk Hanshop.
- **Cetak struk** berbeda dari layanan existing yang berhasil diperbarui: Nginx memiliki hostname `cetakstruk.hansdigi.biz.id`, tetapi folder `/var/www/hanshop/cetakstruk/dist` tidak ada sehingga menghasilkan HTTP 500. Belum ada instalasi/source server untuk dibandingkan. Aplikasi lokal juga masih mempunyai integrasi kirim PDF menuju `/kirim-struk` yang tidak tersedia pada bot terbaru. Tidak mempublikasikan aplikasi yang integrasinya belum selesai dalam pembaruan ini; perlu pekerjaan tersendiri.
- `pm2 save` dijalankan. Tidak melakukan pembelian, pembayaran atau pengiriman pesan uji nyata. Pengujian bisnis live, rollback drill, perbaikan moderate dependency dan penuntasan cetak struk tetap terpisah dari keberhasilan update source ini.

## Audit environment domain DigitalToko — 4 November 2026

- Environment pengguna dicadangkan privat sebelum koreksi di `/home/hans/deploy-checkpoints/digitaltoko-public-20261104/`. APP_KEY, credential database, token integrasi dan BACKUP_KEY dipertahankan. Tidak menyimpan nilai rahasia dalam dokumentasi ini.
- Domain platform dan APP_URL memakai `digitaltoko.com`. WEB_BASE_URL bot disamakan. MAIL_MAILER yang sebelumnya `log` diperbaiki menjadi `smtp`; alamat pengirim disamakan dengan akun SMTP pengguna dan EHLO memakai domain platform. Koneksi SMTPS port 465 beserta autentikasi berhasil, **belum mengirim email uji atau membuktikan penerimaan inbox**.
- Autentikasi database dari environment aktual serta kesamaan token web/API/manager diverifikasi. Cache konfigurasi dibangun ulang dan worker diberi sinyal restart; scheduler, manager, database, Redis, queue probe dan integrasi Hanshop terbaca sehat. Layanan Hanshop existing tetap online.
- APK HansDev dan ZIP tools Windows/macOS/Linux tersedia terpisah pada storage release privat. Landing, login, paket, panduan, dan keempat URL unduhan menghasilkan HTTP 200 melalui preview Nginx internal dengan Host `digitaltoko.com`; permintaan `.env` ditolak 403.
- Backup terenkripsi terbaru berhasil dibuat dan diverifikasi: `/home/hans/backups/digitaltoko/digitaltoko-20261104-051640263348-rpncik6w.zip`. Live restore tetap dinonaktifkan.
- DNS root dan wildcard menuju VPS; mail/webmail tetap menuju shared hosting. Sertifikat Let's Encrypt untuk `digitaltoko.com` dan `*.digitaltoko.com` berhasil diterbitkan setelah dua TXT terbaru diverifikasi, berlaku sampai **2 Februari 2027**. Percobaan pertama kedaluwarsa saat menunggu DNS dan diganti dengan challenge baru; jangan memakai ulang nilai TXT dari percobaan sebelumnya.
- Vhost `/etc/nginx/sites-available/digitaltoko` diaktifkan setelah `nginx -t` lulus. HTTP dialihkan ke HTTPS; `www` dialihkan ke root. Pemeriksaan eksternal menghasilkan HTTP 200 untuk `/`, `/up`, `/login`, `/admin/login`, `/paket`, `/panduan`, keempat unduhan dan seluruh aset build yang dirujuk landing. Sertifikat diverifikasi tanpa bypass. Host wildcard yang belum terdaftar mendapat 404 dengan sertifikat valid, sedangkan `.env` ditolak 403. Landing/login/paket/panduan juga dibuka melalui browser dan tampil dengan CSS.
- **Renewal wildcard masih manual**, karena DNS Jagoanhosting belum dihubungkan melalui API. Hook tunggu khusus penerbitan pertama sudah dilepas dari konfigurasi renewal sertifikat ini agar timer tidak menggantung menunggu TXT. Operator perlu menjalankan perintah Certbot manual pada bagian DNS sebelum kedaluwarsa, memperbarui TXT yang diminta, lalu menguji HTTPS. Deploy hook memeriksa dan memuat ulang Nginx ketika sertifikat berhasil diperbarui. Timer Certbot existing tidak membuktikan wildcard ini bisa diperpanjang otomatis.
- Akun admin belum dibuat, mengikuti keputusan pengguna untuk membuatnya sendiri. Penawaran paket juga perlu ditinjau/diaktifkan admin sebelum penjualan.

### Perbaikan pembelian modul QRIS selama penyiapan — 4 November 2026

Pemeriksaan data production menemukan toko sudah memiliki invoice sewa awal `paid`, tetapi statusnya masih `provisioning` dan masa aktif belum berjalan. Pembelian modul sebelumnya mensyaratkan `acceptsOrders()`, sehingga voucher ditolak sebelum validasi promo. Pembelian kini diizinkan saat toko aktif/masa tenggang atau sedang disiapkan dengan invoice sewa awal lunas; toko ditangguhkan, belum membayar atau sedang dalam peninjauan/refund penyiapan tetap ditolak. Pembelian modul tidak mengaktifkan toko atau memulai masa sewa. Pemeriksaan memakai baris toko terbaru yang dikunci; pesan kesalahan lisensi, penjualan modul dan kelayakan sewa dipisahkan. Tidak ada perubahan schema atau migrasi. `VoucherTest` dan `CustomerCommerceTest`: **84 tes, 819 assertion lulus**, mencakup voucher gratis, diskon sebagian, penangguhan dan refund penyiapan. Pembelian/voucher nyata tidak dijalankan otomatis atas nama owner.

### Maintenance tetap membuka katalog — 4 November 2026

Middleware storefront kini membolehkan penelusuran katalog, kategori, pencarian dan informasi toko pada ketiga template. Checkout/quick order dan aktivitas baru yang sebelumnya dibatasi tetap dijeda; callback, pembayaran serta status pesanan existing tetap diproses. Endpoint katalog bot dan respons menu/list juga dibuka selama maintenance, dengan pemberitahuan saat memilih produk untuk dipesan atau membuat topup. Tombol status pesanan existing tetap diizinkan. Halaman pemberitahuan memiliki tautan kembali ke katalog. Pengujian lokal: **35 tes Laravel / 548 assertion** (maintenance, logo email, pendaftaran dan navigasi storefront), serta **77 tes bot** lulus. Tidak ada migrasi, perubahan harga/stok, pengaktifan maintenance nyata atau transaksi uji production.

Pembaruan popup: informasi maintenance dibagikan melalui Inertia hanya pada host toko, dengan tema/logo publik serta waktu server. Popup dapat ditutup, muncul kembali pada full refresh dan tidak berulang pada navigasi Inertia yang sama. Halaman GET checkout tetap bisa dilihat; POST pemesanan tetap ditahan. Tombol checkout menjadi `Sedang maintenance` dengan countdown di bawahnya. Build/TypeScript berhasil, **15 tes Laravel / 324 assertion** dan **40 tes frontend** lulus; sesudah penyesuaian terakhir, **8 tes maintenance / 146 assertion** dijalankan kembali dan lulus. Browser production WuzWuz Topup memverifikasi tutup, refresh, navigasi kategori, tombol disabled serta countdown pada desktop dan viewport ponsel 390 × 844. Tidak membuat transaksi atau mengubah tenggat maintenance. Backup kode/aset sebelumnya tersimpan privat pada `/home/hans/deploy-checkpoints/maintenance-popup-20261104/before.tar.gz`; aset hash lama dipertahankan saat deployment agar tab existing tetap dapat memuat chunk.
