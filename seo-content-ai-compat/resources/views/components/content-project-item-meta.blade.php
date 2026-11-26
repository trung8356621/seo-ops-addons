@props([
    'row' => [],
])

@php
    $primary = (string) ($row['primary_label'] ?? '—');
    $tid = (int) ($row['task_id'] ?? 0);
    $articleId = $row['article_id'] ?? null;
    $type = (string) ($row['type_label'] ?? '');
    $postTypeRaw = $row['post_type'] ?? null;
    $postTypeDisplay = is_string($postTypeRaw) && trim($postTypeRaw) !== ''
        ? trim($postTypeRaw)
        : '—';
    // Title → WordPress/source permalink only (internal editor stays on Actions icon).
    $publicUrl = trim((string) ($row['article_public_url'] ?? ''));
    $wpUrl = $publicUrl !== '' && filter_var($publicUrl, FILTER_VALIDATE_URL) ? $publicUrl : null;
    $message = $row['message'] ?? null;
    $isNeedsReview = ! empty($row['is_recently_completed']);
    $reviewNote = trim((string) ($row['latest_review_note'] ?? ''));
    $reviewerName = trim((string) ($row['latest_reviewer_name'] ?? ''));
    $reviewAt = trim((string) ($row['latest_review_at_relative'] ?? ''));
    $hideReviewerName = ! empty($row['hide_review_reviewer_name']);
    $showReviewer = ! $hideReviewerName && $reviewerName !== '';
    // Expand only when the note would be clipped (≈ 2 lines / ~120 chars).
    $noteNeedsExpand = $reviewNote !== '' && mb_strlen($reviewNote) > 120;
@endphp

<div {{ $attributes->class(['min-w-0']) }}>
    <div class="line-clamp-2 text-sm font-semibold leading-snug text-gray-950 dark:text-white">
        @if ($wpUrl)
            <a
                href="{{ $wpUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                @click="typeof claimNeedsReviewArticle === 'function' && claimNeedsReviewArticle({{ $tid }}, {{ $isNeedsReview ? 'true' : 'false' }})"
                class="inline-flex max-w-full items-start gap-1 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded"
            >
                <span class="min-w-0">{{ $primary }}</span>
                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="mt-0.5 h-3 w-3 shrink-0 text-gray-400" />
            </a>
        @else
            {{ $primary }}
        @endif
    </div>
    @if (! $wpUrl && empty($row['article_edit_url']))
        <div class="mt-0.5 text-[11px] font-medium text-gray-500 dark:text-gray-400">
            {{ $row['article_empty_label'] ?? __('seo-content-ai::filament.projects.item_article_unlinked') }}
        </div>
    @endif
    <div class="mt-0.5 text-[11px] leading-snug text-gray-500 dark:text-gray-400">
        #{{ $tid }}
        @if ($articleId) · article {{ $articleId }} @endif
        @if ($type !== '') · {{ $type }} @endif
        · post_type: {{ $postTypeDisplay }}
    </div>
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

    @if ((! empty($row['show_reporting_chip']) && ! empty($row['reporting_badge'])) || $reviewNote !== '')
        <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] leading-snug">
            @if (! empty($row['show_reporting_chip']) && ! empty($row['reporting_badge']))
                @php $rb = $row['reporting_badge']; @endphp
                <span
                    class="{{ $rb['classes'] ?? '' }}"
                    title="{{ ($rb['key'] ?? '') === 'needs_review'
                        ? __('seo-content-ai::filament.projects.reporting_needs_review_tooltip')
                        : __('seo-content-ai::filament.projects.reporting_in_review_tooltip') }}"
                    aria-label="{{ ($rb['key'] ?? '') === 'needs_review'
                        ? __('seo-content-ai::filament.projects.reporting_needs_review_tooltip')
                        : __('seo-content-ai::filament.projects.reporting_in_review_tooltip') }}"
                >{{ $rb['label'] ?? '' }}</span>
            @endif
            @if ($reviewAt !== '')
                <span class="text-gray-400 dark:text-gray-500">· {{ $reviewAt }}</span>
            @endif
            @if ($showReviewer)
                <span class="text-gray-400 dark:text-gray-500">· {{ $reviewerName }}</span>
            @endif
        </div>
    @endif

    @if ($reviewNote !== '')
        @if ($noteNeedsExpand)
            <details class="mt-0.5 group">
                <summary
                    class="cursor-pointer list-none text-[11px] leading-snug text-gray-600 dark:text-gray-300 [&::-webkit-details-marker]:hidden"
                    title="{{ $reviewNote }}"
                >
                    <span class="line-clamp-2">↳ “{{ $reviewNote }}”</span>
                    <span class="mt-0.5 hidden text-[10px] font-medium text-primary-600 group-open:inline dark:text-primary-400">
                        {{ __('seo-content-ai::filament.projects.ops_review_note_collapse') }}
                    </span>
                    <span class="mt-0.5 inline text-[10px] font-medium text-primary-600 group-open:hidden dark:text-primary-400">
                        {{ __('seo-content-ai::filament.projects.ops_review_note_expand') }}
                    </span>
                </summary>
                <div class="mt-1 whitespace-pre-wrap break-words text-[11px] leading-snug text-gray-700 dark:text-gray-200">
                    {{ $reviewNote }}
                </div>
            </details>
        @else
            <div class="mt-0.5 text-[11px] leading-snug text-gray-600 dark:text-gray-300">
                ↳ “{{ $reviewNote }}”
            </div>
        @endif
    @endif
</div>
