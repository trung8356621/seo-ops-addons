@props([
    'row' => [],
])

@php
    $primary = (string) ($row['primary_label'] ?? '—');
    $keyword = (string) ($row['keyword'] ?? '');
    $title = (string) ($row['title'] ?? '');
    $tid = (int) ($row['task_id'] ?? 0);
    $articleId = $row['article_id'] ?? null;
    $type = (string) ($row['type_label'] ?? '');
    $url = $row['article_edit_url'] ?? null;
    $message = $row['message'] ?? null;
    $showKeyword = $keyword !== '' && $keyword !== '—' && $keyword !== $primary && $keyword !== $title;
    $isNeedsReview = ! empty($row['is_recently_completed']);
@endphp

<div {{ $attributes->class(['min-w-0']) }}>
    <div class="line-clamp-2 text-sm font-semibold leading-snug text-gray-950 dark:text-white">
        @if ($url)
            <a
                href="{{ $url }}"
                target="_blank"
                rel="noopener noreferrer"
                @click="typeof claimNeedsReviewArticle === 'function' && claimNeedsReviewArticle({{ $tid }}, {{ $isNeedsReview ? 'true' : 'false' }})"
                class="hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded"
            >{{ $primary }}</a>
        @else
            {{ $primary }}
        @endif
    </div>
    @if (! $url)
        <div class="mt-0.5 text-[11px] font-medium text-gray-500 dark:text-gray-400">
            {{ $row['article_empty_label'] ?? __('seo-content-ai::filament.projects.item_article_unlinked') }}
        </div>
    @endif
    <div class="mt-0.5 text-[11px] leading-snug text-gray-500 dark:text-gray-400">
        #{{ $tid }}
        @if ($articleId) · article {{ $articleId }} @endif
        @if ($type !== '') · {{ $type }} @endif
    </div>
    @if ($showKeyword)
        <div class="mt-0.5 line-clamp-1 text-[11px] text-gray-400 dark:text-gray-500">{{ $keyword }}</div>
    @endif
    @if (! empty($row['suggestion_reason']))
        <div class="mt-0.5 line-clamp-1 text-[11px] text-gray-400 dark:text-gray-500" title="{{ $row['suggestion_reason'] }}">
            {{ __('seo-content-ai::filament.projects.planner_why') }}: {{ $row['suggestion_reason'] }}
        </div>
    @endif
    @if (! empty($row['project_name']))
        <div class="mt-0.5 line-clamp-1 text-[11px] text-gray-400 dark:text-gray-500">
            @if (! empty($row['project_url']))
                <a href="{{ $row['project_url'] }}" class="hover:underline">{{ $row['project_name'] }}</a>
            @else
                {{ $row['project_name'] }}
            @endif
        </div>
    @endif
    @if (! empty($message))
        <div class="mt-1 line-clamp-1 text-[11px] font-medium text-danger-600 dark:text-danger-400" title="{{ $message }}">
            {{ $message }}
        </div>
    @endif
    @if (! empty($row['generation_blocked']))
        <div class="mt-1">
            <span class="inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-700 ring-1 ring-gray-600/20 dark:bg-gray-500/15 dark:text-gray-300 dark:ring-gray-400/30">
                {{ __('seo-content-ai::filament.projects.badge_generation_skipped') }}
            </span>
        </div>
    @endif
    @if (! empty($row['show_reporting_chip']) && ! empty($row['reporting_badge']))
        @php $rb = $row['reporting_badge']; @endphp
        <div class="mt-1">
            <span
                class="{{ $rb['classes'] ?? '' }}"
                title="{{ ($rb['key'] ?? '') === 'needs_review'
                    ? __('seo-content-ai::filament.projects.reporting_needs_review_tooltip')
                    : __('seo-content-ai::filament.projects.reporting_in_review_tooltip') }}"
                aria-label="{{ ($rb['key'] ?? '') === 'needs_review'
                    ? __('seo-content-ai::filament.projects.reporting_needs_review_tooltip')
                    : __('seo-content-ai::filament.projects.reporting_in_review_tooltip') }}"
            >{{ $rb['label'] ?? '' }}</span>
        </div>
    @endif
    @if (! empty($row['has_unpublished_changes']))
        <div class="mt-1 inline-flex rounded bg-warning-50 px-1.5 py-0.5 text-[10px] font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
            {{ __('seo-content-ai::filament.projects.ops_unpublished_changes') }}
        </div>
    @endif
</div>
