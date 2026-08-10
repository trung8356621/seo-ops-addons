@php
    use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
@endphp

<section class="performance-hub-panel performance-hub-comparison">
    <div class="performance-hub-panel__head performance-hub-panel__head--toolbar">
        <div>
            <h2>{{ __('seo-content-ai::filament.performance_hub.comparison_heading') }}</h2>
            <p class="performance-hub-panel__hint">{{ __('seo-content-ai::filament.performance_hub.comparison_hint') }}</p>
        </div>
        <div class="performance-hub-comparison__actions">
            <input
                type="text"
                wire:model.defer="comparisonKeyword"
                placeholder="{{ __('seo-content-ai::filament.performance_hub.comparison_keyword_placeholder') }}"
                class="performance-hub-input"
            />
            <button
                type="button"
                wire:click="runComparisonCheck"
                wire:loading.attr="disabled"
                wire:target="runComparisonCheck"
                class="performance-hub-action-btn performance-hub-action-btn--secondary"
            >
                <span wire:loading.remove wire:target="runComparisonCheck">{{ __('seo-content-ai::filament.performance_hub.run_comparison_check') }}</span>
                <span wire:loading wire:target="runComparisonCheck">{{ __('seo-content-ai::filament.performance_hub.running_comparison_check') }}</span>
            </button>
        </div>
    </div>

    @if ($rows !== [])
        <div class="performance-hub-table-wrap">
            <table class="performance-hub-table">
                <thead>
                    <tr>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_keyword') }}</th>
                        @foreach (SerpProviderKeys::all() as $providerKey)
                            <th>{{ SerpProviderKeys::label($providerKey) }}</th>
                        @endforeach
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_spread') }}</th>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_updated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['keyword'] ?? '' }}</td>
                            @foreach (SerpProviderKeys::all() as $providerKey)
                                @php $cell = $row[$providerKey] ?? []; @endphp
                                <td>
                                    <div>{{ ($cell['position'] ?? null) !== null ? number_format((float) $cell['position'], 1) : '—' }}</div>
                                    @if (! empty($cell['url']))
                                        <div class="text-xs truncate max-w-[12rem] text-gray-500">{{ $cell['url'] }}</div>
                                    @endif
                                    @if (! empty($cell['duration_ms']))
                                        <div class="text-xs text-gray-400">{{ (int) $cell['duration_ms'] }}ms</div>
                                    @endif
                                    @if (! empty($cell['error']))
                                        <div class="text-xs text-red-600">{{ $cell['status'] ?? 'error' }}</div>
                                    @endif
                                </td>
                            @endforeach
                            <td>{{ ($row['position_spread'] ?? null) !== null ? number_format((float) $row['position_spread'], 1) : '—' }}</td>
                            <td>{{ $row['checked_at'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="performance-hub-table-empty">{{ __('seo-content-ai::filament.performance_hub.comparison_empty') }}</p>
    @endif
</section>
