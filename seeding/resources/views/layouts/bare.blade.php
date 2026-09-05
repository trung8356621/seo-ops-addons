<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Seeding' }} — seo-ops</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @livewireStyles
    {{ $styles ?? '' }}
</head>
<body style="margin:0;min-height:100vh;background:#f3f4f6;color:#111827;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    {{ $slot }}
    @livewireScripts
    {{ $scripts ?? '' }}
</body>
</html>
