<section class="performance-hub-panel">
    <p class="performance-hub-panel-hint">{{ __('seo-content-ai::filament.performance_hub.serp_changes_hint') }}</p>
    <div class="performance-hub-table-wrap">
        <table class="performance-hub-table">
            <thead>
                <tr>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_keyword') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_change_type') }}</th>
                    <th class="is-num">{{ __('seo-content-ai::filament.performance_hub.col_position') }}</th>
                    <th class="is-num">{{ __('seo-content-ai::filament.performance_hub.col_change') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_url') }}</th>
                    <th class="is-num">{{ __('seo-content-ai::filament.performance_hub.col_volume') }}</th>
                    <th class="is-num">{{ __('seo-content-ai::filament.performance_hub.col_allintitle') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_updated') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="serp-change-{{ md5((string) ($row['keyword'] ?? '')) }}-{{ $row['change_type'] ?? '' }}">
                        <td>{{ $row['keyword'] ?? '' }}</td>
                        <td>
                            <span class="performance-hub-change-type performance-hub-change-type--{{ $row['change_type'] ?? 'unchanged' }}">
                                {{ __('seo-content-ai::filament.performance_hub.serp_change_'.($row['change_type'] ?? 'unchanged')) }}
                            </span>
                        </td>
                        <td class="is-num">{{ ($row['position'] ?? null) !== null ? number_format((float) $row['position'], 1) : '—' }}</td>
                        <td class="is-num">
                            @if (($row['change'] ?? null) !== null)
                                <span @class([
                                    'performance-hub-change',
                                    'is-up' => ($row['change'] ?? 0) > 0,
                                    'is-down' => ($row['change'] ?? 0) < 0,
                                ])>
                                    {{ ($row['change'] ?? 0) > 0 ? '+' : '' }}{{ (int) $row['change'] }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="max-w-xs truncate">
                            @if (! empty($row['url']))
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer" class="text-emerald-600 hover:underline">{{ $row['url'] }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="is-num">{{ ($row['volume'] ?? null) !== null ? number_format((int) $row['volume']) : '—' }}</td>
                        <td class="is-num">{{ ($row['allintitle'] ?? null) !== null ? number_format((int) $row['allintitle']) : '—' }}</td>
                        <td>{{ $row['updated_at'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="performance-hub-table-empty">
                            {{ __('seo-content-ai::filament.performance_hub.serp_changes_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
