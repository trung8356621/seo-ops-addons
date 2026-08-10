<section class="performance-hub-panel">
    @if (($kpis['has_data'] ?? false) !== true)
        <div class="performance-hub-empty-state">
            <p>{{ __('seo-content-ai::filament.performance_hub.gsc_empty') }}</p>
        </div>
    @else
        <div class="performance-hub-kpi-grid performance-hub-kpi-grid--compact">
            <article class="performance-hub-kpi-card">
                <p class="performance-hub-kpi-card__label">{{ __('seo-content-ai::filament.performance_hub.kpi_clicks') }}</p>
                <p class="performance-hub-kpi-card__value">{{ number_format((int) ($kpis['total_clicks'] ?? 0)) }}</p>
            </article>
            <article class="performance-hub-kpi-card">
                <p class="performance-hub-kpi-card__label">{{ __('seo-content-ai::filament.performance_hub.kpi_impressions') }}</p>
                <p class="performance-hub-kpi-card__value">{{ number_format((int) ($kpis['total_impressions'] ?? 0)) }}</p>
            </article>
            <article class="performance-hub-kpi-card">
                <p class="performance-hub-kpi-card__label">{{ __('seo-content-ai::filament.performance_hub.kpi_ctr') }}</p>
                <p class="performance-hub-kpi-card__value">{{ number_format((float) ($kpis['avg_ctr'] ?? 0), 2) }}%</p>
            </article>
            <article class="performance-hub-kpi-card">
                <p class="performance-hub-kpi-card__label">{{ __('seo-content-ai::filament.performance_hub.kpi_position') }}</p>
                <p class="performance-hub-kpi-card__value">
                    @if (($kpis['avg_position'] ?? null) !== null)
                        {{ number_format((float) $kpis['avg_position'], 1) }}
                    @else
                        <span class="performance-hub-empty-value">{{ __('seo-content-ai::filament.performance_hub.empty_no_data') }}</span>
                    @endif
                </p>
            </article>
        </div>

        <div class="performance-hub-table-wrap mt-4">
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
                        <tr wire:key="gsc-query-{{ md5((string) ($row['query'] ?? '')) }}">
                            <td>{{ $row['query'] ?? '' }}</td>
                            <td>{{ number_format((int) ($row['clicks'] ?? 0)) }}</td>
                            <td>{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                            <td>{{ number_format((float) ($row['ctr'] ?? 0), 2) }}%</td>
                            <td>{{ ($row['position'] ?? null) !== null ? number_format((float) $row['position'], 1) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="performance-hub-table-empty">{{ __('seo-content-ai::filament.performance_hub.queries_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</section>
