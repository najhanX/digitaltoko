@extends('mail.layout')
@section('content')
<div style="padding:18px;border:1px solid {{ $brand['border'] }};border-radius:12px;overflow-wrap:anywhere;color:{{ $brand['text'] }};font-size:15px">{!! nl2br(e($messageText)) !!}</div>
<p style="margin:24px 0 0"><a href="{{ $brand['url'] }}/cek-pesanan" style="display:inline-block;padding:12px 20px;border-radius:10px;background:{{ $brand['primary'] }};color:{{ $brand['on_primary'] }};font-weight:700;text-decoration:none">Periksa pesanan</a></p>
@endsection
