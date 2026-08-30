@php
    $formatMetric = static function (?int $value, string $status): string {
        if ($status === 'not_configured') {
            return '—';
        }
        if ($status === 'not_supported') {
            return __('seo-content-ai::filament.performance_hub.metric_not_supported_short');
        }
        if ($status === 'pending' || $status === 'running') {
            return __('seo-content-ai::filament.performance_hub.status_'.$status);
        }
        if ($value !== null) {
            return number_format($value);
        }
        if ($status === 'not_found') {
            return __('seo-content-ai::filament.performance_hub.status_not_found');
        }
        if ($status === 'failed') {
            return __('seo-content-ai::filament.performance_hub.status_failed');
        }

        return '—';
    };
@endphp

<section class="performance-hub-panel">
    <div class="performance-hub-panel__head performance-hub-panel__head--toolbar">
        <h2>{{ __('seo-content-ai::filament.performance_hub.tab_rankings') }}</h2>
        <form wire:submit="applyKeywordSearch" class="contents">
            <input
                type="search"
                wire:model="keywordSearchInput"
                placeholder="{{ __('seo-content-ai::filament.performance_hub.filter_keyword') }}"
                class="performance-hub-input"
                autocomplete="off"
            />
        </form>
    </div>

    <div class="performance-hub-table-wrap performance-hub-table-wrap--rankings">
        <table class="performance-hub-table performance-hub-table--rankings">
            <thead>
                <tr>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_keyword') }}</th>
                    <th class="is-num">{{ __('seo-content-ai::filament.performance_hub.col_position') }}</th>
                    <th class="is-num">{{ __('seo-content-ai::filament.performance_hub.col_change') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_url') }}</th>
                    <th class="is-num" title="{{ __('seo-content-ai::filament.performance_hub.volume_tooltip') }}">{{ __('seo-content-ai::filament.performance_hub.col_volume') }}</th>
                    <th class="is-num" title="{{ __('seo-content-ai::filament.performance_hub.allintitle_tooltip') }}">{{ __('seo-content-ai::filament.performance_hub.col_allintitle') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_status') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_updated') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="ranking-{{ $row['keyword_id'] ?? md5((string) ($row['keyword'] ?? '')) }}">
                        <td>{{ $row['keyword'] ?? '' }}</td>
                        <td class="is-num">
                            @if (($row['position'] ?? null) !== null)
                                {{ number_format((float) $row['position'], 1) }}
                            @elseif (($row['status'] ?? '') === 'not_found')
                                <span class="performance-hub-muted">{{ __('seo-content-ai::filament.performance_hub.status_not_found') }}</span>
                            @elseif (($row['status'] ?? '') === 'pending')
                                <span class="performance-hub-muted">{{ __('seo-content-ai::filament.performance_hub.status_pending') }}</span>
                            @else
                                —
                            @endif
                        </td>
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
                        <td class="is-num" title="{{ __('seo-content-ai::filament.performance_hub.volume_tooltip') }}">
                            {{ $formatMetric(isset($row['volume']) && is_numeric($row['volume']) ? (int) $row['volume'] : null, (string) ($row['volume_status'] ?? 'pending')) }}
                        </td>
                        <td class="is-num" title="{{ __('seo-content-ai::filament.performance_hub.allintitle_tooltip') }}">
                            {{ $formatMetric(isset($row['allintitle']) && is_numeric($row['allintitle']) ? (int) $row['allintitle'] : null, (string) ($row['allintitle_status'] ?? 'pending')) }}
                        </td>
                        <td>
                            @if (! empty($row['error']))
                                <span class="performance-hub-status-badge is-error" title="{{ $row['error'] }}">{{ __('seo-content-ai::filament.performance_hub.status_failed') }}</span>
                            @else
                                <span class="performance-hub-status-badge">{{ __('seo-content-ai::filament.performance_hub.status_'.($row['status'] ?? 'pending')) }}</span>
                            @endif
                        </td>
                        <td>{{ $row['updated_at'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="performance-hub-table-empty">
                            {{ __('seo-content-ai::filament.performance_hub.rankings_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
