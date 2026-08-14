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
    $assignLabel = AssignToContentProjectContract::label();
    $icon = AssignToContentProjectContract::ICON;
    $color = AssignToContentProjectContract::COLOR;
    // Build JS once; Blade {{ $mergedClick }} HTML-escapes a single time.
    // Do NOT e()/htmlspecialchars here — e()+{{ }} becomes &amp;quot; and Alpine throws
    // Uncaught SyntaxError: Unexpected token '&' when compiling x-on:click.
    $dispatchClick = 'window.dispatchEvent(new CustomEvent('
        .json_encode(AssignToContentProjectContract::OPEN_EVENT)
        .',{detail:'
        .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        .'}))';
    // Caller may pass x-on:click / @click (e.g. close menu). Merge into ONE handler —
    // duplicate Alpine attributes make browsers keep the first and drop dispatchEvent.
    $extraClick = trim((string) ($attributes->get('x-on:click') ?? $attributes->get('@click') ?? ''));
    $mergedClick = trim($extraClick !== '' ? $extraClick.'; '.$dispatchClick : $dispatchClick);
    $safeAttributes = $attributes->except(['x-on:click', '@click', 'x-on:click.prevent', '@click.prevent']);
@endphp

@if ($variant === 'menu')
    <button
        type="button"
        {{ $safeAttributes->class(['seo-editor-menu-item'])->merge([]) }}
        role="menuitem"
        data-seo-page-action="assign-content-project"
        data-assign-content-project-trigger
        aria-label="{{ $assignLabel }}"
        title="{{ $assignLabel }}"
        x-on:click="{{ $mergedClick }}"
    >
        <x-filament::icon icon="{{ $icon }}" class="h-5 w-5" />
        <span>{{ $assignLabel }}</span>
    </button>
@elseif ($variant === 'bulk')
    <button
        type="button"
        {{ $safeAttributes->class(['flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5']) }}
        data-assign-content-project-trigger
        aria-label="{{ $assignLabel }}"
        title="{{ $assignLabel }}"
        x-on:click="{{ $mergedClick }}"
    >
        <x-filament::icon icon="{{ $icon }}" class="h-4 w-4 shrink-0 text-warning-600" />
        {{ $assignLabel }}
    </button>
@else
    {{-- Mirror articles-optimal Edit/Skip: string tooltip/label props (avoid Alpine $label). --}}
    <x-filament::icon-button
        type="button"
        icon="{{ $icon }}"
        size="sm"
        color="{{ $color }}"
        tooltip="{{ $assignLabel }}"
        label="{{ $assignLabel }}"
        data-assign-content-project-trigger
        x-on:click="{{ $mergedClick }}"
        {{ $safeAttributes }}
    />
@endif
