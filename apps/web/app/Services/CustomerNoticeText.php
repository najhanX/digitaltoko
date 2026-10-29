<?php

namespace App\Services;

use App\Models\CustomerOrder;

class CustomerNoticeText
{
    private const FOOTER = '_Powered by DigitalToko_';

    private function line(?string $value, int $limit = 180): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f*_~`]+/u', ' ', $value ?? '')), 0, $limit);
    }

    private function money(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }

    public function order(CustomerOrder $order, string $status, string $url): string
    {
        $topup = $order->purpose === 'topup';
        $cancelled = (bool) ($order->snapshot['cancelled_by_customer'] ?? false) || filled($order->snapshot['cancelled_by_owner'] ?? null);
        [$icon, $title, $next] = match ($status) {
            'awaiting_payment' => ['💳', 'Menunggu pembayaran', 'Selesaikan pembayaran melalui tautan berikut.'],
            'paid' => ['✅', 'Pembayaran diterima', $topup ? 'Pembayaran topup sudah tercatat.' : 'Pesanan Anda sedang diproses.'],
            'pending' => ['⏳', 'Pesanan masih diproses', 'Tidak perlu memesan ulang. Cek perkembangannya melalui tautan berikut.'],
            'success' => ['✅', $topup ? 'Saldo berhasil ditambahkan' : 'Pesanan berhasil', $topup ? 'Saldo siap digunakan untuk berbelanja.' : 'Terima kasih sudah berbelanja. Simpan bukti pesanan Anda.'],
            'failed' => ['❌', 'Pesanan gagal', $order->customer_id ? 'Periksa rincian pesanan melalui tautan berikut.' : 'Hubungi toko untuk penyelesaian pesanan atau refund manual.'],
            'review' => ['🔎', 'Pesanan sedang diperiksa', 'Toko sedang memeriksa pesanan Anda. Cek status melalui tautan berikut.'],
            'expired' => $cancelled ? ['❌', 'Pesanan dibatalkan', 'Jangan membayar atau menggunakan QRIS pesanan ini lagi. Anda dapat membuat pesanan baru melalui menu toko.'] : ['⌛', 'Pembayaran kedaluwarsa', 'Waktu pembayaran berakhir. Jangan gunakan QRIS ini lagi. Periksa status pesanan sebelum membuat pesanan baru.'],
            default => ['ℹ️', 'Pembaruan pesanan', 'Lihat informasi terbaru melalui tautan berikut.'],
        };
        $lines = ['*'.mb_strtoupper($this->line($order->store->name)).'*', $icon.' *'.$title.'*'];
        if ($order->is_sandbox) {
            $lines[] = '_Mode pengujian · bukan transaksi nyata_';
        }
        array_push($lines, '', '*'.$this->line($topup ? 'Topup saldo' : ($order->snapshot['name'] ?? 'Produk digital')).'*', 'Pesanan: `'.$this->line($order->number).'`');
        if (! $topup && ($order->snapshot['kind'] ?? null) !== 'premium' && filled($order->destination)) {
            $lines[] = 'Tujuan: '.$this->line($order->destination);
        }
        if ($order->supplierDescription()) {
            $lines[] = 'Keterangan: '.$this->line($order->supplierDescription(), 500);
        }
        $billing = ($order->snapshot['kind'] ?? '') === 'postpaid' ? app(PostpaidBilling::class)->publicBreakdown($order->snapshot['bill_breakdown'] ?? []) : null;
        if ($billing) {
            $lines[] = 'Tagihan pokok: '.$this->money($billing['bill']);
            $lines[] = 'Admin layanan: '.$this->money($billing['service_admin']);
            $lines[] = 'Admin toko: '.$this->money($billing['store_admin']);
        }
        $lines[] = 'Total pembayaran: *'.$this->money($order->total).'*';
        if ($topup && $status === 'success') {
            $lines[] = 'Saldo masuk: *'.$this->money($order->credit_amount).'*';
        }
        if ($order->refunded_at) {
            $lines[] = 'Dikembalikan ke saldo toko: *'.$this->money($order->refund_amount).'*';
        }
        $time = match ($status) {
            'paid' => $order->paid_at,
            'success', 'failed', 'expired' => $order->completed_at,
            default => $order->created_at,
        };
        if ($time) {
            $lines[] = ($status === 'paid' ? 'Dibayar' : (in_array($status, ['success', 'failed', 'expired'], true) ? 'Selesai' : 'Dibuat')).': '.$time->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
        }
        if ($status === 'awaiting_payment' && $order->expires_at) {
            $lines[] = 'Batas bayar: '.$order->expires_at->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
        }
        array_push($lines, '', $next, $url, '', self::FOOTER);

        return implode("\n", $lines);
    }

    /** Keep account credentials/SN intact and place them before the message footer. */
    public function withResult(string $message, string $result): string
    {
        $hasFooter = str_ends_with($message, self::FOOTER);
        $body = $hasFooter ? rtrim(substr($message, 0, -strlen(self::FOOTER))) : rtrim($message);

        return $body."\n\n*Hasil pesanan*\n".$result.($hasFooter ? "\n\n".self::FOOTER : '');
    }
}
