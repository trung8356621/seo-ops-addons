@php
    use Omnichannel\Addons\Seeding\Support\SeedingVite;
    $props = $this->workspaceProps();
@endphp

{{-- Shared Filament topbar owns branding / user / language switch. No Seeding-specific app chrome. --}}
<div class="seeding-workspace-mount" data-seeding-shell="workspace" wire:key="seeding-workspace-shell">
    <div
        wire:ignore
        id="seeding-workspace-root"
        data-props='@json($props, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)'
        class="seeding-workspace-shell"
        data-layout="feed"
    ></div>

    <style>
        /* Panel has navigation(false) but Filament may still reserve sidebar gutter — hide sidebar only. */
        .fi-sidebar,
        .fi-sidebar-close-overlay,
        .fi-breadcrumbs,
        aside.fi-main-sidebar,
        .fi-layout > .fi-sidebar {
            display: none !important;
        }
        /* Do NOT hide .fi-topbar — canonical LanguageSwitch + user menu live there. */
        .fi-layout,
        .fi-main,
        .fi-main-ctn,
        .fi-body,
        .fi-page,
        .fi-page > section,
        .fi-simple-page {
            margin-left: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            max-width: none !important;
            width: 100% !important;
        }
        .seeding-workspace-shell {
            width: 100%;
            min-height: calc(100vh - 4.5rem);
        }
    </style>

    {!! app(SeedingVite::class)->tags() !!}
</div>
