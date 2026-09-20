@php
    /** @var array{seo: array{calls: int, tokens: int}, seeding: array{calls: int, tokens: int}, total_tokens: int, total_calls: int} $tokenSummary */
    $tokenSummary = $tokenSummary ?? $this->tokenSummary();
    $totalTokens = max(0, (int) ($tokenSummary['total_tokens'] ?? 0));
    $seoTokens = max(0, (int) ($tokenSummary['seo']['tokens'] ?? 0));
    $seedingTokens = max(0, (int) ($tokenSummary['seeding']['tokens'] ?? 0));
    $seoShare = $totalTokens > 0 ? round(($seoTokens / $totalTokens) * 100, 1) : 0.0;
    $seedingShare = $totalTokens > 0 ? round(($seedingTokens / $totalTokens) * 100, 1) : 0.0;
    $cards = [
        [
            'label' => 'Tổng tokens',
            'value' => number_format($totalTokens),
            'meta' => number_format((int) $tokenSummary['total_calls']).' calls',
            'hint' => 'Trong khoảng đã chọn',
            'tone' => 'blue',
            'icon' => 'M5 7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7Zm4 1h6M9 12h6M9 16h4',
        ],
        [
            'label' => 'SEO Action tokens',
            'value' => number_format($seoTokens),
            'meta' => number_format((int) $tokenSummary['seo']['calls']).' calls',
            'hint' => number_format($seoShare, 1).'% tổng tokens',
            'tone' => 'green',
            'icon' => 'M5 12h14M12 5l7 7-7 7',
        ],
        [
            'label' => 'Seeding tokens',
            'value' => number_format($seedingTokens),
            'meta' => number_format((int) $tokenSummary['seeding']['calls']).' calls',
            'hint' => number_format($seedingShare, 1).'% tổng tokens',
            'tone' => 'violet',
            'icon' => 'M7 7h10M7 12h10M7 17h6',
        ],
        [
            'label' => 'Tổng lượt gọi',
            'value' => number_format((int) $tokenSummary['total_calls']),
            'meta' => 'AI requests',
            'hint' => 'Không hiển thị cost ước tính',
            'tone' => 'orange',
            'icon' => 'M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        ],
    ];
@endphp

<div class="ops-kpi-grid grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as $card)
        @php
            $toneClasses = [
                'blue' => ['box' => 'bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300', 'text' => 'text-blue-600 dark:text-blue-300', 'line' => '#2563eb'],
                'green' => ['box' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300', 'text' => 'text-emerald-600 dark:text-emerald-300', 'line' => '#10b981'],
                'violet' => ['box' => 'bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-300', 'text' => 'text-violet-600 dark:text-violet-300', 'line' => '#8b5cf6'],
                'orange' => ['box' => 'bg-orange-50 text-orange-600 dark:bg-orange-950/40 dark:text-orange-300', 'text' => 'text-orange-600 dark:text-orange-300', 'line' => '#f97316'],
            ][$card['tone']];
        @endphp
        <article class="overflow-hidden rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $toneClasses['box'] }}">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $card['icon'] }}" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                        <div class="mt-1 text-2xl font-bold leading-8 tracking-tight text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                            <span class="font-semibold {{ $toneClasses['text'] }}">{{ $card['meta'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $card['hint'] }}</span>
                        </div>
                    </div>
                </div>
                <svg class="mt-8 hidden h-10 w-24 shrink-0 opacity-80 sm:block" viewBox="0 0 96 40" fill="none" aria-hidden="true">
                    <path d="M2 31C12 31 14 23 23 24C31 25 31 18 40 18C49 18 48 11 57 13C67 15 67 7 76 8C85 9 86 4 94 3" stroke="{{ $toneClasses['line'] }}" stroke-width="3" stroke-linecap="round" />
                    <path d="M2 31C12 31 14 23 23 24C31 25 31 18 40 18C49 18 48 11 57 13C67 15 67 7 76 8C85 9 86 4 94 3V40H2V31Z" fill="{{ $toneClasses['line'] }}" opacity="0.08" />
                </svg>
            </div>
        </article>
    @endforeach
</div>
