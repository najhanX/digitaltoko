<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $title }} · {{ $brand['name'] }}</title></head>
<body style="margin:0;padding:0;background:{{ $brand['background'] }};color:{{ $brand['text'] }};font-family:Arial,Helvetica,sans-serif;line-height:1.65">
<div style="display:none;max-height:0;overflow:hidden">{{ $title }} dari {{ $brand['name'] }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:{{ $brand['background'] }}"><tr><td align="center" style="padding:32px 12px">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;table-layout:fixed;word-wrap:break-word;background:{{ $brand['surface'] }};border:1px solid {{ $brand['border'] }};border-radius:18px;overflow:hidden">
<tr><td style="height:6px;background:{{ $brand['primary'] }}"></td></tr>
<tr><td style="padding:28px 28px 20px;border-bottom:1px solid {{ $brand['border'] }}">
@if($brand['logo_path'] || $brand['logo'])<img src="{{ $brand['logo_path'] ? $message->embed($brand['logo_path']) : $brand['logo'] }}" alt="{{ $brand['name'] }}" style="display:block;max-width:190px;max-height:60px;height:auto;width:auto;border:0;margin-bottom:12px">@endif
@if($brand['store'] || ! $brand['logo'])
<strong style="font-size:18px;color:{{ $brand['text'] }}">{{ $brand['name'] }}</strong>
@endif</td></tr>
<tr><td style="padding:28px"><h1 style="font-size:24px;line-height:1.35;margin:0 0 16px;color:{{ $brand['text'] }}">{{ $title }}</h1>@yield('content')</td></tr>
<tr><td style="padding:20px 28px;border-top:1px solid {{ $brand['border'] }};color:{{ $brand['muted'] }};font-size:13px">Email otomatis dari {{ $brand['name'] }}. Jangan bagikan kode OTP atau data akun Anda kepada siapa pun.<br>@if($brand['store'])Powered by DigitalToko
@endif</td></tr>
</table></td></tr></table></body></html>
