@php
    $pagination = $pagination ?? [];
    $totalFiltered = (int) ($totalFiltered ?? 0);
    $totalSource = (int) ($totalSource ?? 0);
    $activeBucket = $activeBucket ?? '';
@endphp

<section class="performance-hub-panel">
    @if (! $hasData)
        <div class="performance-hub-empty-state">
            <p>{{ __('seo-content-ai::filament.performance_hub.gsc_empty') }}</p>
        </div>
    @else
        <div class="performance-hub-queries-toolbar">
            <div class="performance-hub-toolbar__field performance-hub-toolbar__field--grow">
                <label for="gsc-query-search">{{ __('seo-content-ai::filament.performance_hub.filter_gsc_query') }}</label>
                <input
                    id="gsc-query-search"
                    type="search"
                    wire:model.live.debounce.400ms="gscQuerySearch"
                    placeholder="{{ __('seo-content-ai::filament.performance_hub.filter_gsc_query_placeholder') }}"
                    class="performance-hub-input"
                />
            </div>

            @if ($activeBucket !== '')
                <div class="performance-hub-active-filters">
                    <span class="performance-hub-filter-chip">
                        {{ __('seo-content-ai::filament.performance_hub.filter_position_bucket', ['bucket' => str_replace('-', '–', $activeBucket)]) }}
                        <button type="button" wire:click="clearPositionBucket" class="performance-hub-filter-chip__clear" aria-label="{{ __('seo-content-ai::filament.performance_hub.clear_filters') }}">×</button>
                    </span>
                    <button type="button" wire:click="clearPositionBucket" class="performance-hub-link-btn">{{ __('seo-content-ai::filament.performance_hub.clear_filters') }}</button>
                </div>
            @endif
        </div>

        <div class="performance-hub-table-wrap">
            <table class="performance-hub-table">
                <thead>
                    <tr>
                        <th><button type="button" wire:click="sortGscQueries('query')" class="performance-hub-sort-btn">{{ __('seo-content-ai::filament.performance_hub.col_query') }}</button></th>
                        <th><button type="button" wire:click="sortGscQueries('clicks')" class="performance-hub-sort-btn">{{ __('seo-content-ai::filament.performance_hub.col_clicks') }}</button></th>
                        <th><button type="button" wire:click="sortGscQueries('impressions')" class="performance-hub-sort-btn">{{ __('seo-content-ai::filament.performance_hub.col_impressions') }}</button></th>
                        <th><button type="button" wire:click="sortGscQueries('ctr')" class="performance-hub-sort-btn">{{ __('seo-content-ai::filament.performance_hub.col_ctr') }}</button></th>
                        <th><button type="button" wire:click="sortGscQueries('position')" class="performance-hub-sort-btn">{{ __('seo-content-ai::filament.performance_hub.col_position') }}</button></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($queries as $row)
                        <tr wire:key="gsc-query-{{ md5((string) ($row['query'] ?? '')) }}-{{ (int) ($pagination['current_page'] ?? 1) }}">
                            <td>{{ $row['query'] ?? '' }}</td>
                            <td>{{ number_format((int) ($row['clicks'] ?? 0)) }}</td>
                            <td>{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                            <td>{{ number_format((float) ($row['ctr'] ?? 0), 2) }}%</td>
                            <td>{{ ($row['position'] ?? null) !== null ? number_format((float) $row['position'], 1) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="performance-hub-table-empty">{{ __('seo-content-ai::filament.performance_hub.queries_empty_filtered') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('seo-content-ai::seo.performance-hub.partials.gsc-queries-pagination', [
            'pagination' => $pagination,
            'totalFiltered' => $totalFiltered,
            'totalSource' => $totalSource,
        ])
    @endif
</section>
