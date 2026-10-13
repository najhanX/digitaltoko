# Web toko Hansbot

Storefront sekarang berada dalam aplikasi Laravel yang sama dengan panel: `apps/web/resources/js/pages/Storefront/Home.tsx`. Folder ini hanya catatan, bukan aplikasi frontend atau deployment tersendiri. Template awal Minimal, Promo, dan Kategori tersedia sebagai fondasi Inertia React; katalog/checkout belum diimplementasikan.

Toko memakai katalog, harga, stok, pelanggan, dan transaksi dari backend Hansbot yang sama dengan bot WhatsApp. Branding dan domain ditentukan oleh tenant yang dipetakan dari hostname terdaftar. Hostname tidak dikenal tidak boleh membuka toko default.

Kanal web, kanal WhatsApp, sumber produk, dan hak custom domain dimodelkan sebagai entitlement terpisah. Web toko bukan panel owner/admin.
