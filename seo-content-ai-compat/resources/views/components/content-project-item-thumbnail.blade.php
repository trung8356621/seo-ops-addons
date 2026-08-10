@props([
    'row' => [],
    'size' => 'w-12 h-12',
])

@php
    $url = $row['thumbnail_url'] ?? null;
    $hasImage = ! empty($row['has_featured_image']) && is_string($url) && $url !== '';
    $editUrl = $row['article_edit_url'] ?? null;
    $tid = (int) ($row['task_id'] ?? 0);
    $title = (string) ($row['primary_label'] ?? '');
    $noImageLabel = __('seo-content-ai::filament.projects.ops_no_featured_image');
@endphp

<div {{ $attributes->class(['w-12 shrink-0']) }}>
    <div @class([$size, 'overflow-hidden rounded-md border border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-gray-800'])>
        @if ($hasImage)
            @if ($editUrl)
                <a
                    href="{{ $editUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    @click="typeof claimNeedsReviewArticle === 'function' && claimNeedsReviewArticle({{ $tid }}, {{ ! empty($row['is_recently_completed']) ? 'true' : 'false' }})"
                    class="block h-full w-full"
                    title="{{ $title }}"
                >
                    <img src="{{ $url }}" alt="{{ $title }}" loading="lazy" class="h-full w-full object-cover" />
                </a>
            @else
                <img src="{{ $url }}" alt="{{ $title }}" loading="lazy" class="h-full w-full object-cover" />
            @endif
        @else
            <div class="flex h-full w-full items-center justify-center text-gray-400 dark:text-gray-500" title="{{ $noImageLabel }}">
                <x-filament::icon icon="heroicon-o-photo" class="h-4 w-4" />
            </div>
        @endif
    </div>
    {{-- Reserved line: avoid table jump when warning appears --}}
    <div class="mt-0.5 h-3 overflow-hidden text-center text-[8px] leading-3 text-amber-600 dark:text-amber-400" title="{{ $hasImage ? '' : $noImageLabel }}">
        @unless ($hasImage)
            {{ $noImageLabel }}
        @endunless
    </div>
</div>
