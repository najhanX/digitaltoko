<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $state === 'preview' ? 'Pratinjau hanya baca' : 'Status layanan' }} · DigitalToko</title>
<style>
:root{color-scheme:light dark}*{box-sizing:border-box}body{margin:0;min-height:100svh;display:grid;place-items:center;background:#f3f5fc;color:#172440;font:16px/1.65 system-ui,sans-serif}main{width:min(620px,calc(100% - 32px));margin:28px auto;padding:32px;border:1px solid #dfe4ef;border-radius:20px;background:#fff;box-shadow:0 16px 60px #1724400c}.brand{font-weight:700;color:#4f46e5;letter-spacing:.06em;font-size:14px}h1{font-size:clamp(23px,5vw,30px);line-height:1.3;margin:16px 0}p,li{color:#52617c}ol{padding-left:22px}li{padding:5px 0}strong{color:inherit}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}a{padding:11px 17px;border-radius:10px;text-decoration:none;font-weight:600;border:1px solid #d6d4f8;color:#3730a3;background:#efedff}a.primary{color:#fff;background:#4f46e5;border-color:#4f46e5}.note{font-size:14px;margin-bottom:0}@media(prefers-color-scheme:dark){body{background:#0e1526;color:#eef2ff}main{background:#182137;border-color:#303d56}p,li{color:#b8c5df}.brand{color:#a7a2ff}a{background:#292d53;color:#dedbff;border-color:#454573}}
</style>
</head>
<body><main><span class="brand">DIGITALTOKO</span>
@if($state === 'review')
<h1>Hasil pemulihan menunggu tinjauan admin</h1>
<p>Data sudah dipulihkan. Login owner, OTP, transaksi, dan perubahan data ditahan sementara sampai admin membuka kembali layanan.</p>
<ol><li>Masuk menggunakan <strong>akun admin pada backup yang dipulihkan</strong>.</li><li>Buka <strong>Backup & pemulihan</strong>, lalu periksa pesanan, pembayaran, dan saldo terhadap kondisi terbaru di pemasok/gateway.</li><li>Pilih <strong>Buka kembali layanan</strong> dan konfirmasi bahwa peninjauan sudah selesai.</li></ol>
<p class="note">Jika Anda pelanggan atau owner toko, hubungi admin platform. Pengiriman OTP belum dijalankan saat layanan ditahan.</p>
<div class="actions"><a class="primary" href="{{ $platformUrl }}/admin/login">Masuk sebagai admin</a><a href="{{ $platformUrl }}/admin/backups">Backup & pemulihan</a><a href="/recovery-review">Periksa kembali</a></div>
@elseif($state === 'preview')
<h1>Pratinjau pemulihan hanya baca</h1><p>Ini salinan untuk memeriksa hasil backup. OTP, transaksi, dan perubahan data tidak dijalankan di pratinjau.</p><div class="actions"><a href="/admin/backups">Lihat hasil pemulihan</a></div>
@elseif($state === 'processing')
<h1>Pemulihan sedang berlangsung</h1><p>Mohon tunggu hingga pemulihan selesai. Jangan mengulangi tindakan atau membuat transaksi baru.</p><div class="actions"><a href="/recovery-review">Periksa kembali</a></div>
@else
<h1>Layanan sudah dapat digunakan</h1><p>Tidak ada pemulihan yang menunggu tinjauan. Kembali ke aplikasi untuk melanjutkan.</p><div class="actions"><a class="primary" href="/">Kembali ke aplikasi</a></div>
@endif
</main></body></html>
