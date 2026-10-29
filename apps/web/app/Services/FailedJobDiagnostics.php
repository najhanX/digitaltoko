<?php

namespace App\Services;

class FailedJobDiagnostics
{
    /** @return array{process: string, reason: string, impact: string, next_steps: array<int, string>} */
    public function describe(string $payload, string $exception): array
    {
        // Decode metadata only; never unserialize queued commands or expose raw exceptions.
        $data = json_decode($payload, true, 32);
        $job = is_array($data) && is_string($data['displayName'] ?? null) ? $data['displayName'] : '';
        [$process, $impact, $steps] = match ($job) {
            'App\\Jobs\\RefreshSupplierWallet' => ['Pembaruan saldo pemasok', 'Pembaruan informasi saldo pemasok gagal. Catatan ini tidak menyatakan transaksi pelanggan gagal.', [
                'Owner: pilih toko terkait, buka Produk & harga → Saldo & deposit pemasok, lalu tekan Perbarui saldo pada pemasok yang bermasalah.',
                'Jika saldo terbaru berhasil terbaca, gunakan hasil baru tersebut. Tidak perlu mengulang semua job pembaruan saldo lama.',
            ]],
            'App\\Jobs\\SyncOtomaxCatalog', 'App\\Jobs\\ImportOtomaxProducts' => ['Pembaruan katalog OtomaX', 'Pembaruan katalog belum selesai; harga dan ketersediaan terbaru perlu diperiksa.', [
                'Owner/staff: buka Sumber produk → Kelola supplier OtomaX, lalu periksa koneksi dan daftar harga URL pemasok.',
                'Periksa hasil sinkronisasi terakhir sebelum menjalankan sinkronisasi baru dari menu pemasok.',
            ]],
            'App\\Jobs\\SendCustomerNotice' => ['Pengiriman notifikasi pelanggan', 'Pengiriman pemberitahuan gagal. Ini tidak otomatis berarti pesanan atau pembayaran gagal.', [
                'Owner/staff: buka rincian transaksi untuk melihat status pesanan dan pembayaran sebenarnya.',
                'Periksa koneksi WhatsApp toko atau pengaturan Notifikasi & email sesuai kanal yang digunakan.',
            ]],
            'App\\Jobs\\ProcessCustomerOrder', 'App\\Jobs\\ProcessDigiflazzCallback' => ['Pemrosesan transaksi pelanggan', 'Proses pesanan atau pembaruan dari pemasok belum selesai. Periksa status sebenarnya sebelum mengambil tindakan.', [
                'Owner/staff: buka Transaksi pelanggan, cari pesanan terkait waktu kegagalan, lalu periksa pembayaran dan percobaan sumber.',
                'Bandingkan status dan nomor referensi dengan pemasok. Jangan membuat pesanan baru atau mengulang job sebelum memastikan tidak terjadi pemrosesan ganda.',
            ]],
            'App\\Jobs\\CheckSystemQueue' => ['Pemeriksaan kesehatan antrean', 'Job ini hanya menguji queue; tidak memproses transaksi atau saldo pelanggan.', [
                'Administrator: periksa indikator Queue worker dan koneksi penyimpanan antrean pada halaman ini.',
                'Setelah worker pulih, gunakan Uji ulang antrean jika tombolnya tersedia.',
            ]],
            default => ['Proses latar belakang', 'Jenis proses belum dikenali. Status transaksi tidak dapat disimpulkan dari catatan ini.', [
                'Administrator: cocokkan nomor job, antrean, dan waktu gagal dengan log layanan queue.',
                'Identifikasi proses yang gagal sebelum mencoba ulang; pastikan proses sebelumnya belum menghasilkan perubahan saldo atau pesanan.',
            ]],
        };

        $line = strtok($exception, "\r\n") ?: '';
        $reason = 'Penyebab belum dapat dikenali dengan aman. Administrator perlu mencocokkan nomor job dan waktu gagal dengan log queue.';
        $check = null;
        if (str_starts_with($line, 'App\\Services\\SupplierFailure: ')) {
            $supplierMessage = substr($line, strlen('App\\Services\\SupplierFailure: '));
            foreach ([
                'Endpoint pemasok Hanshop belum tersedia.' => ['Endpoint pemasok Hanshop belum tersedia saat proses dijalankan.', 'Administrator: pastikan API Hanshop memakai source terbaru dan HANSHOP_API_URL mengarah ke server API yang benar. Setelah diperbaiki, owner dapat memperbarui saldo Hanshop kembali.'],
                'Pembacaan akun Hanshop ditolak.' => ['Hanshop menolak pembacaan akun saat proses dijalankan.', 'Owner: periksa akun Hanshop yang tertaut dan masa aktif toko. Administrator perlu memeriksa respons penolakan pada API Hanshop jika pembaruan saldo masih gagal.'],
                'Token integrasi Hanshop ditolak.' => ['Token integrasi web dengan API Hanshop ditolak.', 'Administrator: samakan HANSHOP_IDENTITY_TOKEN pada web dengan DIGITALTOKO_IDENTITY_TOKEN pada API Hanshop, lalu muat ulang konfigurasi dan proses terkait.'],
                'Koneksi Hanshop belum dikonfigurasi.' => ['Koneksi API Hanshop belum dikonfigurasi.', 'Administrator: lengkapi HANSHOP_API_URL dan token integrasi pada konfigurasi server.'],
                'API Hanshop tidak dapat dihubungi.' => ['Koneksi ke API Hanshop gagal.', 'Administrator: periksa indikator API Hanshop, proses API di PM2, alamat API, port dan koneksi jaringan server.'],
                'Hanshop sedang membatasi permintaan.' => ['API Hanshop sedang membatasi permintaan.', 'Tunggu sebelum memperbarui saldo kembali. Administrator perlu memeriksa pembatasan permintaan dan jadwal pembaruan agar tidak berulang terlalu sering.'],
                'Layanan API Hanshop sedang bermasalah.' => ['Layanan API Hanshop mengalami kesalahan.', 'Administrator: periksa indikator API Hanshop serta log API pada waktu kegagalan. Pastikan dependensi database dan Redis tersedia.'],
                'Respons pemasok Hanshop tidak valid.' => ['Format respons API Hanshop tidak sesuai.', 'Administrator: periksa kecocokan versi web dan API Hanshop, lalu owner dapat mencoba pembaruan saldo baru.'],
            ] as $prefix => [$summary, $instruction]) {
                if (str_starts_with($supplierMessage, $prefix)) {
                    $reason = $summary;
                    $check = $instruction;
                    break;
                }
            }
        } else {
            foreach ([
                'Illuminate\\Queue\\TimeoutExceededException: ' => ['Proses melewati batas waktu yang diizinkan.', 'Administrator: periksa beban worker, koneksi layanan tujuan dan pengaturan timeout/retry_after. Periksa hasil proses sebelumnya sebelum mencoba ulang.'],
                'Illuminate\\Queue\\MaxAttemptsExceededException: ' => ['Batas percobaan proses sudah habis.', 'Administrator: periksa kegagalan sebelumnya pada log queue dan perbaiki penyebabnya sebelum mencoba ulang.'],
                'Illuminate\\Database\\QueryException: ' => ['Proses gagal mengakses atau memperbarui database.', 'Administrator: periksa koneksi database dan migrasi yang belum diterapkan. Detail SQL diperiksa pada log server.'],
                'Illuminate\\Http\\Client\\ConnectionException: ' => ['Layanan tujuan tidak dapat dihubungi atau melewati batas waktu koneksi.', 'Administrator: periksa alamat layanan, proses yang berjalan, jaringan dan whitelist IP pemasok.'],
            ] as $prefix => [$summary, $instruction]) {
                if (str_starts_with($line, $prefix)) {
                    $reason = $summary;
                    $check = $instruction;
                    break;
                }
            }
        }
        if ($check !== null) {
            array_unshift($steps, $check);
        }

        return ['process' => $process, 'reason' => $reason, 'impact' => $impact, 'next_steps' => $steps];
    }
}
