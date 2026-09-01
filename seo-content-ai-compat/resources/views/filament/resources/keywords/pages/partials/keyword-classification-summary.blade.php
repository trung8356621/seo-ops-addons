@php
    $summary = $this->getClassificationSummary();
    $progress = $this->getKeywordIntelligenceProgress();
@endphp

<div
    class="keyword-classification-summary rounded-xl border border-gray-200 bg-white px-4 py-3 text-[13px] dark:border-gray-700 dark:bg-gray-900"
    @if ($progress['running'] ?? false) wire:poll.3s @endif
>
    @if (! ($summary['table_ready'] ?? false))
        <p class="font-medium text-warning-700 dark:text-warning-400">
            {{ __('seo-content-ai::filament.keyword.classification_table_missing') }}
        </p>
    @else
        @if ($progress)
            <p class="mb-2 font-semibold text-primary-700 dark:text-primary-300">
                {{ $progress['label'] }}
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $progress['counts'] }}</span>
            </p>
        @endif
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-gray-800 dark:text-gray-200">
            <span class="text-danger-700 dark:text-danger-400">{{ __('seo-content-ai::filament.keyword.op_tag_error') }}: <strong>{{ number_format((int) ($summary['error'] ?? 0)) }}</strong></span>
            <span class="text-danger-700 dark:text-danger-400">{{ __('seo-content-ai::filament.keyword.op_tag_seo_excluded') }}: <strong>{{ number_format((int) ($summary['seo_excluded'] ?? 0)) }}</strong></span>
        </div>
    @endif
</div>
