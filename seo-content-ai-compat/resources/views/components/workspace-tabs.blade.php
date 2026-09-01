@props([
    'activeKey' => '',
    /** @var list<array{key: string, label: string, url: string, count?: int|null}> */
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
                $hasCount = array_key_exists('count', $item) && $item['count'] !== null;
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
                <span class="workspace-tabs__label-row">
                    <span class="workspace-tabs__label">{{ $item['label'] ?? '' }}</span>
                    @if ($hasCount)
                        <span class="workspace-tabs__count">{{ number_format((int) $item['count']) }}</span>
                    @endif
                </span>
                <span @class(['workspace-tabs__underline', 'is-active' => $isActive]) aria-hidden="true"></span>
            </a>
        @endforeach
    </div>
</nav>
