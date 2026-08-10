@php
    $hasData = (bool) ($has_data ?? false);
    $scoring = is_array($scoring ?? null) ? $scoring : [];
    $segments = is_array($segments ?? null) ? $segments : [];
    $donutGradient = (string) ($donut_gradient ?? '');
    $overviewCss = base_path('addons/content/resources/css/domain-overview.css');
@endphp

<x-filament-widgets::widget>
    @if(is_readable($overviewCss))
        <style>{!! file_get_contents($overviewCss) !!}</style>
    @endif

    <x-filament::section
        :heading="__('seo-content-ai::filament.dashboard.score_chart_heading')"
        :description="__('seo-content-ai::filament.dashboard.score_chart_description', ['count' => $scoring['scored'] ?? 0])"
        icon="heroicon-o-chart-pie"
    >
        @if(! $hasData)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.dashboard.score_chart_empty') }}
            </p>
        @else
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.seo-score-donut-block', [
                'scoring' => $scoring,
                'segments' => $segments,
                'donutGradient' => $donutGradient,
                'emptyMessage' => __('seo-content-ai::filament.dashboard.score_chart_empty'),
            ])
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
