<?php

namespace App\Support;

class StaffPermissions
{
    public static function labels(): array
    {
        return ['dashboard.view' => 'Lihat ringkasan toko', 'products.view' => 'Lihat produk',
            'products.manage' => 'Kelola produk & parsing', 'pricing.manage' => 'Kelola harga & markup',
            'stock.view' => 'Lihat jumlah stok', 'stock.manage' => 'Kelola stok ready',
            'stock.credentials' => 'Lihat credential stok', 'suppliers.balance' => 'Lihat saldo Digiflazz toko', 'orders.view' => 'Lihat & cek transaksi',
            'orders.resend' => 'Kirim ulang hasil ke penerima asli', 'guests.resolve' => 'Tindak lanjut pesanan guest',
            'customers.view' => 'Lihat pelanggan', 'customers.contacts' => 'Lihat kontak pelanggan',
            'customers.manage' => 'Kelola profil pelanggan', 'payments.view' => 'Lihat pembayaran',
            'payments.policy' => 'Atur fee dan kebijakan kode unik', 'wallet.view' => 'Lihat ledger saldo pelanggan',
            'wallet.settlement' => 'Catat pencairan saat toko berhenti', 'web.manage' => 'Kelola template & branding',
            'checkout.manage' => 'Atur guest / wajib login', 'bot.view' => 'Lihat koneksi WhatsApp',
            'bot.manage' => 'Pairing / reconnect WhatsApp', 'subscription.view' => 'Lihat masa aktif',
            'subscription.renew' => 'Buat invoice perpanjangan'];
    }
}
