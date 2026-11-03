@extends('mail.layout')
@section('content')
<p style="margin:0 0 20px">Gunakan kode berikut untuk melanjutkan verifikasi akun Anda.</p>
<div style="padding:18px 12px;border-radius:12px;background:{{ $brand['primary'] }};color:{{ $brand['on_primary'] }};font-size:32px;font-weight:700;letter-spacing:8px;text-align:center">{{ $code }}</div>
<p style="margin:20px 0 0;color:{{ $brand['muted'] }}">Berlaku <strong>5 menit</strong>. Jika Anda tidak meminta kode ini, abaikan email ini.</p>
@endsection
