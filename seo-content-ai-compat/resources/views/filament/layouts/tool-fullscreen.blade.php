<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $title ?? 'Chỉnh sửa ảnh' }}</title>
    @filamentStyles
    @viteReactRefresh
    {{ $head ?? '' }}
</head>
<body class="magic-eraser-standalone-body h-full overflow-hidden bg-slate-950 antialiased">
    {{ $slot }}
    @filamentScripts
    {{ $scripts ?? '' }}
</body>
</html>
