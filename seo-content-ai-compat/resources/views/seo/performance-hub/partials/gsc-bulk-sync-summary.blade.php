@php
    $summary = $result['summary'] ?? [];
    $rows = $result['rows'] ?? [];
@endphp

<section class="performance-hub-panel performance-hub-bulk-summary">
    <div class="performance-hub-panel__head">
        <h2>{{ __('seo-content-ai::filament.performance_hub.bulk_sync_summary') }}</h2>
    </div>

    <div class="performance-hub-bulk-summary__stats">
        @foreach ([
            'total_domains' => __('seo-content-ai::filament.performance_hub.bulk_total_domains'),
            'newly_matched' => __('seo-content-ai::filament.performance_hub.bulk_newly_matched'),
            'already_mapped' => __('seo-content-ai::filament.performance_hub.bulk_already_mapped'),
            'synced' => __('seo-content-ai::filament.performance_hub.bulk_synced'),
            'empty_success' => __('seo-content-ai::filament.performance_hub.bulk_empty_success'),
            'failed' => __('seo-content-ai::filament.performance_hub.bulk_failed'),
            'unmatched' => __('seo-content-ai::filament.performance_hub.bulk_unmatched'),
            'ambiguous' => __('seo-content-ai::filament.performance_hub.bulk_ambiguous'),
            'invalid' => __('seo-content-ai::filament.performance_hub.bulk_invalid'),
            'skipped_unmapped' => __('seo-content-ai::filament.performance_hub.bulk_skipped_unmapped'),
        ] as $key => $label)
            @if (isset($summary[$key]))
                <div class="performance-hub-bulk-summary__stat">
                    <span>{{ $label }}</span>
                    <strong>{{ (int) $summary[$key] }}</strong>
                </div>
            @endif
        @endforeach
    </div>

    @if ($rows !== [])
        <div class="performance-hub-table-wrap">
            <table class="performance-hub-table">
                <thead>
                    <tr>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_domain') }}</th>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_gsc_property') }}</th>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_mapping_status') }}</th>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_sync_status') }}</th>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_updated') }}</th>
                        <th>{{ __('seo-content-ai::filament.performance_hub.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="bulk-sync-row-{{ (int) ($row['site_id'] ?? 0) }}">
                            <td>{{ $row['domain'] ?? '' }}</td>
                            <td>{{ $row['property_url'] ?? '—' }}</td>
                            <td>{{ __('seo-content-ai::filament.performance_hub.mapping_status_'.($row['mapping_status'] ?? 'unknown')) }}</td>
                            <td>{{ __('seo-content-ai::filament.performance_hub.sync_status_'.($row['sync_status'] ?? 'unknown')) }}</td>
                            <td>{{ $row['last_synced_at'] ?? '—' }}</td>
                            <td class="performance-hub-actions">
                                @if (($row['sync_status'] ?? '') === 'failed')
                                    <button type="button" wire:click="retryGscSyncForSite({{ (int) ($row['site_id'] ?? 0) }})" wire:loading.attr="disabled" wire:target="retryGscSyncForSite" class="performance-hub-link-btn">
                                        {{ __('seo-content-ai::filament.performance_hub.retry_sync') }}
                                    </button>
                                @endif
                                @if (! empty($row['error']))
                                    <span class="performance-hub-connection-card__meta--warn">{{ $row['error'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
