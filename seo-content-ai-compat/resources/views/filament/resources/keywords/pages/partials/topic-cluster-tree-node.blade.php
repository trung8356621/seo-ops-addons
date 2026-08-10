@php
    /** @var array<string, mixed> $node */
    $volume = $node['volume'] ?? null;
    $difficulty = $node['difficulty'] ?? null;
    $links = (int) ($node['active_links_count'] ?? 0);
@endphp

<div class="rounded-lg px-2 py-2 transition hover:bg-white dark:hover:bg-white/5">
    <p @class([
        'truncate text-sm font-semibold text-gray-900 dark:text-white',
        'text-xs font-medium' => ! empty($isChild),
    ])>
        {{ $node['phrase'] ?? '—' }}
    </p>

    <div class="mt-2 flex flex-wrap gap-1.5">
        <span class="topic-cluster-meta-tag">
            Vol {{ is_numeric($volume) ? number_format((int) $volume) : '—' }}
        </span>
        <span class="topic-cluster-meta-tag">
            KD {{ is_numeric($difficulty) ? (int) $difficulty : '—' }}
        </span>
        <span class="topic-cluster-meta-tag topic-cluster-meta-tag--links">
            {{ $links }} {{ __('seo-content-ai::filament.keyword.cluster_links_short') }}
        </span>
    </div>
</div>
