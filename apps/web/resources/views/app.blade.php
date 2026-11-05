<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    @php
        $store = request()->attributes->get('store');
        $favicon = $store?->branding['design']['favicon'] ?? '/favicon.ico';
        $background = $store?->branding['design']['colors']['background'] ?? null;
        $viewportBackground = $store
            ? (is_string($background) && preg_match('/^#[0-9a-f]{6}$/i', $background)
                ? $background
                : match ($store->template) {
                    'nebula', 'promo' => '#0c101c',
                    'atlas', 'category' => '#f5f7fa',
                    default => '#f8fafc',
                })
            : null;
    @endphp
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $viewportBackground ?? '#f3f5fc' }}" @if($viewportBackground) data-store-background="{{ $viewportBackground }}" @endif>
    <script>try{var t=localStorage.getItem('digitaltoko.panel.theme');document.documentElement.dataset.panelTheme=t==='dark'||t==='light'?t:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');}catch(e){document.documentElement.dataset.panelTheme='light';}var r=document.documentElement,m=document.querySelector('meta[name="theme-color"]'),b=m.dataset.storeBackground||(r.dataset.panelTheme==='dark'?'#0c1323':'#f3f5fc'),c=[1,3,5].map(function(i){var v=parseInt(b.slice(i,i+2),16)/255;return v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4);});r.style.backgroundColor=b;r.style.colorScheme=c[0]*.2126+c[1]*.7152+c[2]*.0722<.179?'dark':'light';m.content=b;</script>
    <link rel="icon" href="{{ $favicon }}">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    <x-inertia::head />
</head>
<body><x-inertia::app /></body>
</html>
