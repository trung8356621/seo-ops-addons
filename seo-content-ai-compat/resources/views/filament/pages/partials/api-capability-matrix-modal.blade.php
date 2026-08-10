@php
    use Omnichannel\Addons\Seo\DataTransfer\SeoProviderCapabilityState;
@endphp

<div
    x-data="{ open: false }"
    x-on:open-capability-matrix.window="open = true"
    x-on:close-capability-matrix.window="open = false"
    x-show="open"
    x-cloak
    class="seo-capability-matrix-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="seo-capability-matrix-title"
>
    <div class="seo-capability-matrix-backdrop" @click="open = false"></div>
    <div class="seo-capability-matrix-panel">
        <header class="seo-capability-matrix-header">
            <div>
                <h2 id="seo-capability-matrix-title">{{ __('seo-content-ai::filament.api_connections.capability_matrix_title') }}</h2>
                <p>{{ __('seo-content-ai::filament.api_connections.capability_matrix_subtitle') }}</p>
            </div>
            <button type="button" class="seo-capability-matrix-close" @click="open = false" aria-label="{{ __('seo-content-ai::filament.api_connections.capability_matrix_close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </header>

        <div class="seo-capability-matrix-body">
            <div class="seo-capability-matrix-scroll">
                <table class="seo-capability-matrix-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('seo-content-ai::filament.api_connections.capability_col_provider') }}</th>
                            @foreach ($columns as $column)
                                <th scope="col">{{ $column['label'] }}</th>
                            @endforeach
                            <th scope="col">{{ __('seo-content-ai::filament.api_connections.capability_col_integration') }}</th>
                            <th scope="col">{{ __('seo-content-ai::filament.api_connections.capability_col_best_for') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach ($columns as $column)
                                    @php
                                        /** @var SeoProviderCapabilityState $state */
                                        $state = $row['capabilities'][$column['key']] ?? SeoProviderCapabilityState::unsupported();
                                        $cell = app(\Omnichannel\Addons\SearchIntelligence\Services\SeoProviderCapabilityResolver::class)->matrixCellState($state);
                                    @endphp
                                    <td>
                                        @include('seo-content-ai::filament.pages.partials.api-capability-cell', [
                                            'cell' => $cell,
                                            'provider' => $row['label'],
                                            'capability' => $column['label'],
                                        ])
                                    </td>
                                @endforeach
                                <td>
                                    <span title="{{ $row['integration']['accessible_label'] ?? '' }}" aria-label="{{ $row['integration']['accessible_label'] ?? '' }}">
                                        {{ $row['integration']['label'] ?? '' }}
                                    </span>
                                </td>
                                <td>{{ $row['best_for'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="seo-capability-matrix-legend" aria-label="{{ __('seo-content-ai::filament.api_connections.capability_legend_title') }}">
                @foreach ([
                    'available' => __('seo-content-ai::filament.api_connections.capability_legend_available'),
                    'vendor_only' => __('seo-content-ai::filament.api_connections.capability_legend_vendor_only'),
                    'not_configured' => __('seo-content-ai::filament.api_connections.capability_legend_not_configured'),
                    'unsupported' => __('seo-content-ai::filament.api_connections.capability_legend_unsupported'),
                ] as $legendKey => $legendLabel)
                    @include('seo-content-ai::filament.pages.partials.api-capability-cell', [
                        'cell' => $legendKey,
                        'provider' => '',
                        'capability' => $legendLabel,
                        'legend' => true,
                    ])
                    <span>{{ $legendLabel }}</span>
                @endforeach
            </div>
        </div>
    </div>
</div>
