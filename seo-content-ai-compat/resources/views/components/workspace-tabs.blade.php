@props([
    'activeKey' => '',
    /** @var list<array{key: string, label: string, url: string}> */
    'items' => [],
])

@php
    $navItems = is_array($items) ? $items : [];
@endphp

<nav
    {{ $attributes->class(['workspace-tabs']) }}
    aria-label="{{ __('seo-content-ai::filament.keyword.workspace_nav_label') }}"
>
    <div class="workspace-tabs__list" role="tablist">
        @foreach ($navItems as $item)
            @php
                $isActive = ($activeKey ?? '') === ($item['key'] ?? '');
            @endphp
            <a
                href="{{ $item['url'] ?? '#' }}"
                role="tab"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                @class([
                    'workspace-tabs__item',
                    'is-active' => $isActive,
                ])
            >
                <span class="workspace-tabs__label">{{ $item['label'] ?? '' }}</span>
                <span @class(['workspace-tabs__underline', 'is-active' => $isActive]) aria-hidden="true"></span>
            </a>
        @endforeach
    </div>
</nav>
