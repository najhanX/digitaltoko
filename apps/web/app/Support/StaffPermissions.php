<?php

namespace App\Support;

class StaffPermissions
{
    public static function labels(): array
    {
        return ['vouchers.view' => 'Lihat voucher diskon', 'vouchers.manage' => 'Buat, ubah & hapus voucher diskon', 'dashboard.view' => 'Lihat ringkasan toko', 'products.view' => 'Lihat produk',
            'products.manage' => 'Kelola layanan, kategori, produk & parsing', 'pricing.manage' => 'Kelola harga & markup',
            'stock.view' => 'Lihat jumlah stok', 'stock.manage' => 'Kelola stok ready',
            'stock.credentials' => 'Lihat credential stok', 'suppliers.manage' => 'Kelola koneksi, URL harga & callback OtomaX', 'suppliers.responses' => 'Kelola kelompok jawaban OtomaX', 'suppliers.import' => 'Sinkronisasi & impor katalog OtomaX', 'suppliers.deposit' => 'Buat deposit pemasok (Hanshop khusus owner)', 'suppliers.balance' => 'Lihat saldo pemasok toko', 'orders.view' => 'Lihat & cek transaksi',
            'orders.manage' => 'Kirim ulang pemasok & putuskan sukses/gagal manual', 'orders.resend' => 'Kirim ulang hasil ke penerima asli', 'guests.resolve' => 'Tindak lanjut pesanan guest',
            'customers.view' => 'Lihat pelanggan', 'customers.contacts' => 'Lihat kontak pelanggan',
            'customers.manage' => 'Kelola profil pelanggan', 'payments.view' => 'Lihat pembayaran',
            'payments.parsers' => 'Kelola penangkap notifikasi QRIS', 'payments.policy' => 'Atur fee dan kebijakan kode unik', 'payments.confirm' => 'Konfirmasi penerimaan pembayaran QRIS manual', 'wallet.view' => 'Lihat ledger saldo pelanggan',
            'wallet.settlement' => 'Catat pencairan saat toko berhenti', 'web.manage' => 'Kelola template & branding',
            'articles.view' => 'Lihat artikel toko', 'articles.manage' => 'Tulis, terbitkan & hapus artikel',
            'checkout.manage' => 'Atur guest / wajib login', 'bot.view' => 'Lihat koneksi WhatsApp',
            'bot.manage' => 'Pairing / reconnect WhatsApp', 'notifications.view' => 'Lihat pengaturan notifikasi & email',
            'notifications.manage' => 'Kelola pengirim email & kirim email uji', 'subscription.view' => 'Lihat masa aktif',
            'subscription.renew' => 'Buat invoice perpanjangan'];
    }
}
