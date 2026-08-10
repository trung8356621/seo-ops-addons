@props([
    'scoring',
    'segments' => [],
    'donutGradient' => '',
    'emptyMessage' => null,
])

@if(($scoring['scored'] ?? 0) === 0)
    <p class="text-sm text-amber-700 dark:text-amber-300">
        {{ $emptyMessage ?? __('Chưa có bài được chấm. Cần Focus Keyword trên WordPress.') }}
    </p>
@else
    <div class="seo-score-donut">
        <div
            class="seo-score-donut__chart"
            style="{{ $donutGradient !== '' ? 'background: ' . $donutGradient : 'background: rgb(var(--gray-200))' }}"
            role="img"
            aria-label="{{ __('Biểu đồ phân bố điểm SEO') }}"
        >
            <div class="seo-score-donut__hole">
                <strong>{{ $scoring['avg_score'] }}</strong>
                <span>{{ __('seo-content-ai::filament.dashboard.score_avg_label') }}</span>
            </div>
        </div>

        @if(count($segments) > 0)
            <ul class="seo-score-legend">
                @foreach($segments as $seg)
                    @if(($seg['count'] ?? 0) > 0)
                        <li wire:key="score-seg-{{ $seg['key'] ?? $loop->index }}">
                            <span class="seo-score-legend__dot" style="background: {{ $seg['color'] }}"></span>
                            <span class="seo-score-legend__label">{{ $seg['label'] }}:</span>
                            @if(filled($seg['filter_url'] ?? null))
                                <a href="{{ $seg['filter_url'] }}" class="seo-score-legend__link">
                                    {{ $seg['count'] }} {{ __('bài') }}
                                </a>
                            @else
                                <span class="font-semibold text-gray-800 dark:text-gray-200">
                                    {{ $seg['count'] }} {{ __('bài') }}
                                </span>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

        <div class="seo-score-stats">
            <p><span class="font-semibold">{{ __('Đã chấm') }}:</span> {{ $scoring['scored'] }}</p>
            <p><span class="font-semibold">{{ __('Thấp nhất') }}:</span> {{ $scoring['min_score'] }}</p>
            <p><span class="font-semibold">{{ __('Cao nhất') }}:</span> {{ $scoring['max_score'] }}</p>
        </div>
    </div>
@endif
