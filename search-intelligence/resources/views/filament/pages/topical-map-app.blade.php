@php
    use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapVite;
    $config = $this->bootstrapConfig();
@endphp

{{-- Inter once for standalone Topical Map (canvas + chrome). System stack remains fallback. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<div class="topical-map-app-mount" data-topical-map-shell="1" wire:key="topical-map-app-shell">
    <div
        wire:ignore
        id="topical-map-app-root"
        class="topical-map-app-shell"
        data-props='@json($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)'
    ></div>

    <style>
        .fi-sidebar,
        .fi-sidebar-close-overlay,
        .fi-breadcrumbs,
        aside.fi-main-sidebar,
        .fi-layout > .fi-sidebar,
        .fi-topbar,
        .fi-header,
        .fi-page-header {
            display: none !important;
        }
        html, body {
            height: 100%;
            overflow: hidden !important;
            margin: 0;
        }
        .fi-layout,
        .fi-main,
        .fi-main-ctn,
        .fi-body,
        .fi-page,
        .fi-page > section,
        .fi-simple-page {
            margin: 0 !important;
            padding: 0 !important;
            max-width: none !important;
            width: 100% !important;
            height: 100dvh !important;
            min-height: 100dvh !important;
            overflow: hidden !important;
        }
        .topical-map-app-mount,
        .topical-map-app-shell {
            width: 100%;
            height: 100dvh;
            min-height: 0;
            overflow: hidden;
        }
    </style>

    {!! app(TopicalMapVite::class)->tags() !!}
</div>
