# Web app Hansbot

Tempat panel admin platform, panel owner toko, dan backend bisnis Hansbot. Implementasi framework belum dimulai.

Backend bertanggung jawab atas autentikasi, otorisasi toko, paket/langganan, katalog dan harga jual, stok premium, pelanggan, pesanan, pembayaran, serta integrasi pemasok.

Frontend hanya berkomunikasi melalui backend yang memeriksa sesi dan akses toko. Secret pemasok dan credential WhatsApp tidak dikirim ke browser. QR/pairing code hanya ditampilkan kepada pengguna yang berwenang pada toko tersebut.
