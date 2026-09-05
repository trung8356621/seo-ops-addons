@php
    use Omnichannel\Addons\Seeding\Support\SeedingVite;
    $props = $this->workspaceProps();
    $userName = auth()->user()?->name ?? 'User';
@endphp

<div class="seeding-standalone" data-seeding-shell="standalone" wire:key="seeding-standalone-shell">
    <header class="seeding-standalone__chrome">
        <div class="seeding-standalone__brand">
            <img
                src="{{ asset('images/logo.png') }}"
                alt="seo-ops"
                class="seeding-standalone__logo"
                onerror="this.style.display='none'"
            >
            <span class="seeding-standalone__product">seo-ops</span>
            <span class="seeding-standalone__divider">/</span>
            <span class="seeding-standalone__label">Seeding</span>
            <span class="seeding-standalone__local">Local-first</span>
        </div>

        <div class="seeding-standalone__actions">
            <a class="seeding-standalone__link" href="{{ url('/admin') }}">Admin</a>
            <span class="seeding-standalone__user" title="{{ $userName }}">
                {{ strtoupper(mb_substr($userName, 0, 1)) }}
            </span>
        </div>
    </header>

    <div
        wire:ignore
        id="seeding-workspace-root"
        data-props='@json($props, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)'
        class="seeding-workspace-shell"
        data-layout="feed"
    ></div>

    <style>
        .seeding-standalone {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: #f3f4f6;
        }
        .seeding-standalone__chrome {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.55rem 1rem;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            z-index: 20;
        }
        .seeding-standalone__brand {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-width: 0;
        }
        .seeding-standalone__logo { height: 1.75rem; width: auto; }
        .seeding-standalone__product { font-size: 0.8125rem; font-weight: 650; }
        .seeding-standalone__divider { color: #d1d5db; }
        .seeding-standalone__label { font-size: 0.8125rem; font-weight: 650; color: #ea580c; }
        .seeding-standalone__local {
            font-size: 0.625rem;
            font-weight: 650;
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 999px;
            padding: 0.1rem 0.45rem;
        }
        .seeding-standalone__actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .seeding-standalone__link {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-decoration: none;
        }
        .seeding-standalone__link:hover { color: #111827; }
        .seeding-standalone__user {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 999px;
            background: #111827;
            color: #fff;
            font-size: 0.6875rem;
            font-weight: 700;
        }
        .seeding-workspace-shell {
            flex: 1;
            min-height: 0;
            width: 100%;
        }
        .fi-sidebar,
        .fi-topbar,
        .fi-sidebar-close-overlay,
        .fi-breadcrumbs,
        aside.fi-main-sidebar,
        .fi-layout > .fi-sidebar {
            display: none !important;
        }
    </style>

    {!! app(SeedingVite::class)->tags() !!}
</div>
