{{-- The tab's and a home screen's icons, every one drawn from the owner's logo (2026-10-09) by scripts/make-brand-icons.php:
     the SVG for every browser that takes one, favicon.ico (16, 32 and 48 px) for the rest — its sizes="32x32" keeps Chrome
     on the SVG — the 180 px picture iOS asks for, and the manifest that names the 192 and 512 px ones. --}}
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="{{ route('site.manifest') }}">
