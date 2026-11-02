# DigitalToko — Paket produksi video promosi 60 detik

Format: **vertikal 9:16**, untuk Reels, TikTok, Shorts, dan Status WhatsApp. Delapan gambar dibuat menggunakan **built-in imagegen**. Ini ilustrasi promosi dengan alur sesuai fitur platform, bukan screenshot atau rekaman UI sebenarnya.

## File yang dipakai
- `STORYBOARD.html`: urutan visual, waktu, narasi, dan tautan prompt.
- `STORYBOARD.jpg`: lembar ringkasan delapan adegan.
- `frames/01.png` sampai `08.png`: gambar awal tiap klip. Unggah gambar satu per satu, bukan lembar storyboard.
- `prompts/01.txt` sampai `08.txt`: prompt lengkap siap salin, masing-masing sudah menyertakan arahan visual, musik, dan dubbing.
- `NARASI.txt`: naskah dubbing Indonesia.
- `NARASI.srt`: subtitle narasi, opsional untuk editor.
- `MUSIK.txt`: brief satu musik latar menyambung.
- `IMAGE-PROMPTS.txt`: prompt pembuatan gambar, untuk revisi.
- `PROMPT-FLOW-SEMUA-ADEGAN.txt`: seluruh prompt adegan dalam satu buku; salin satu adegan per generasi.
- `PROMPT-MASTER.txt`: identitas visual dan audio bersama.

## Urutan dan durasi
| Adegan | Waktu akhir | Durasi | Isi |
|---|---|---|---|
| 01 | 0–6 detik | 6 detik | Pembuka |
| 02 | 6–14 detik | 8 detik | Daftar & pilih paket |
| 03 | 14–22 detik | 8 detik | Hubungkan pemasok |
| 04 | 22–30 detik | 8 detik | Custom tampilan toko |
| 05 | 30–38 detik | 8 detik | Panduan bergambar |
| 06 | 38–46 detik | 8 detik | Pelanggan memesan |
| 07 | 46–52 detik | 6 detik | Operasional lebih mudah |
| 08 | 52–60 detik | 8 detik | Ajakan mulai |
**Total tepat 60 detik.** Durasi narasi masih harus diperiksa pada hasil audio nyata; angka di atas adalah rancangan timeline, bukan hasil render video.

## Cara memakai di Google Flow
1. Buat proyek baru di Flow. Pilih Video, rasio **9:16**, lalu **Frames / Frames to Video**.
2. Pilih model yang mendukung gambar awal dan audio, misalnya Veo 3.1 sesuai opsi akun. Periksa durasi yang tersedia.
3. Unggah `frames/01.png` sebagai **start frame**. Pilih 6 detik dan salin seluruh isi `prompts/01.txt`.
4. Generate, lalu periksa logo, keterbacaan judul, dubbing Indonesia, dan musik. Jangan lanjut memakai hasil dengan kalimat terpotong.
5. Ulangi untuk 02–08. Durasi: **6, 8, 8, 8, 8, 8, 6, 8 detik**. Model Flow tertentu mendukung 4/6/8 detik pada Frames to Video. Jika opsi akun hanya 8 detik, generate 8 detik untuk adegan 01/07 lalu trim 2 detik dari bagian diam, jangan memotong narasi.
6. Gunakan gambar awal yang sesuai pada setiap adegan; gambar 01 hanya acuan gaya, bukan gambar untuk semua klip.
7. Susun berurutan dalam timeline editor Flow bila tersedia, atau editor video. Pakai hard match cut atau transisi di dalam klip; jangan menambah jeda di antara klip.
8. Pastikan total akhir **00:01:00**. Transisi silang yang menumpang klip akan mengurangi total durasi, sehingga sesuaikan trim.
9. Ekspor 1080 × 1920 jika tersedia; jika sumber 720p, upscale sesuai opsi layanan. Pakai frame rate yang sama pada semua klip.

Alur Frames dan unggah gambar awal didukung dokumentasi resmi [Google Flow](https://support.google.com/flow/answer/16353334?hl=en). Dukungan panjang klip berbeda antar-model; lihat [tabel fitur resmi](https://support.google.com/flow/answer/16352836?hl=en).

## Dubbing Indonesia
- Satu narator perempuan dewasa, alto hangat, ramah, profesional; logat Indonesia netral.
- Sebut nama brand **“Digital Toko”**, bukan mengeja huruf.
- Baca **persis** naskah pada setiap prompt. Jangan menambahkan terjemahan Inggris atau dialog.
- Tidak perlu tokoh berbicara di layar; dubbing berfungsi sebagai voice-over.
- Prompt sudah meminta audio, tetapi **teks prompt tidak dapat menjamin suara yang identik atau pelafalan sempurna**. Dengarkan setiap hasil.
- Jika tone narator berubah antar-klip, gunakan voice reference yang sama jika tersedia pada model/akun. Alternatif paling konsisten: rekam/generate seluruh `NARASI.txt` dengan satu suara, lalu pasang sebagai satu track pada timeline dan mute dubbing klip. Fitur voice reference bergantung model dan wilayah; bukan asumsi tersedia di semua akun.

## Musik yang menyambung
Prompt setiap adegan meminta musik orisinal electronic-pop **120 BPM**, D major, synth pluck cerah, bass hangat, drum ringan, tanpa lirik. Gunakan satu track 60 detik sesuai `MUSIK.txt` untuk final, karena musik hasil delapan generasi terpisah dapat berubah warna dan putus pada sambungan.

Dubbing harus lebih terdengar daripada musik. Duck musik saat narator berbicara. Efek klik/whoosh cukup tipis. Jangan memakai lagu komersial atau meniru artis tertentu. Hindari musik yang dimulai ulang pada tiap adegan.

## Pemeriksaan sebelum dipublikasikan
- Logo dan ejaan DigitalToko tidak berubah.
- Tidak ada harga, masa trial, domain, garansi sukses, atau klaim keuntungan yang belum ditetapkan.
- Katalog menampilkan produk digital, bukan penjualan perangkat/barang fisik.
- Adegan pesanan memperlihatkan proses, bukan menjanjikan semua supplier selalu langsung berhasil.
- QRIS custom adalah modul berlisensi; jika nanti ditambahkan ke VO/overlay, sebutkan ketentuan ini. Jangan menyiratkan semua modul tambahan gratis.
- Alur pendaftaran/verifikasi → paket/bayar → persiapan → aktivasi mendahului operasional pelanggan. Gambar bersifat ringkasan, bukan petunjuk klik literal.
- Gambar UI AI bisa berbeda dari produk nyata. Untuk iklan demo yang menuntut UI presisi, ganti area layar dengan rekaman toko demo asli.
- Subtitle opsional tidak menutup tombol atau logo. Periksa pelafalan Hanshop, Digiflazz, OtomaX pada dubbing.
- Dengarkan dengan speaker HP. Pastikan kalimat lengkap dan suara konsisten.
- Paket ini berisi **gambar, storyboard, dan prompt**, belum MP4/video/audio yang dirender di Flow.

## Asal aset
Gambar memakai logo platform existing sebagai referensi. Prompt asli tersedia di IMAGE-PROMPTS.txt. Dua revisi menghilangkan ilustrasi barang fisik dan ikon pengiriman agar konteks tetap produk digital. Tidak mengganti asset aplikasi yang sedang dipakai.

