@props([
    'mode' => 'article',
    'source' => '',
    'articleIds' => [],
    'keywordIds' => [],
    'siteIds' => [],
    'mapId' => null,
    'anchorPhrase' => null,
    'defaults' => [],
    'options' => [],
    'variant' => 'icon', // icon | menu | bulk
])

@php
    use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;

    $payload = AssignToContentProjectContract::normalizePayload([
        'mode' => $mode,
        'source' => $source,
        'article_ids' => $articleIds,
        'keyword_ids' => $keywordIds,
        'site_ids' => $siteIds,
        'map_id' => $mapId,
        'anchor_phrase' => $anchorPhrase,
        'defaults' => $defaults,
        'options' => $options,
    ]);
    $label = AssignToContentProjectContract::label();
    $openScript = AssignToContentProjectContract::openScript($payload);
@endphp

@php
    // Caller may pass x-on:click / @click (e.g. close menu). Merge into ONE handler —
    // duplicate Alpine attributes make browsers keep the first and drop dispatchEvent.
    $extraClick = trim((string) ($attributes->get('x-on:click') ?? $attributes->get('@click') ?? ''));
    $mergedClick = trim($extraClick !== '' ? $extraClick.'; '.$openScript : $openScript);
    $safeAttributes = $attributes->except(['x-on:click', '@click', 'x-on:click.prevent', '@click.prevent']);
@endphp

@if ($variant === 'menu')
    <button
        type="button"
        {{ $safeAttributes->class(['seo-editor-menu-item'])->merge([]) }}
        role="menuitem"
        data-seo-page-action="assign-content-project"
        data-assign-content-project-trigger
        aria-label="{{ $label }}"
        title="{{ $label }}"
        x-on:click="{{ $mergedClick }}"
    >
        <x-filament::icon :icon="AssignToContentProjectContract::ICON" class="h-5 w-5" />
        <span>{{ $label }}</span>
    </button>
@elseif ($variant === 'bulk')
    <button
        type="button"
        {{ $safeAttributes->class(['flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5']) }}
        data-assign-content-project-trigger
        aria-label="{{ $label }}"
        title="{{ $label }}"
        x-on:click="{{ $mergedClick }}"
    >
        <x-filament::icon :icon="AssignToContentProjectContract::ICON" class="h-4 w-4 shrink-0 text-warning-600" />
        {{ $label }}
    </button>
@else
    <x-filament::icon-button
        type="button"
        :icon="AssignToContentProjectContract::ICON"
        size="sm"
        :color="AssignToContentProjectContract::COLOR"
        :tooltip="$label"
        :label="$label"
        data-assign-content-project-trigger
        x-on:click="{{ $mergedClick }}"
        {{ $safeAttributes }}
    />
@endif
