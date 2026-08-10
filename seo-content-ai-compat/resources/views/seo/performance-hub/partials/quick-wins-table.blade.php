<section class="performance-hub-panel">
    <p class="performance-hub-panel-hint">{{ __('seo-content-ai::filament.performance_hub.quick_wins_hint') }}</p>
    <div class="performance-hub-table-wrap">
        <table class="performance-hub-table">
            <thead>
                <tr>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_query') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_impressions') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_position') }}</th>
                    <th>{{ __('seo-content-ai::filament.performance_hub.col_actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="quick-win-{{ md5((string) ($row['query'] ?? '')) }}">
                        <td>{{ $row['query'] ?? '' }}</td>
                        <td>{{ number_format((int) ($row['impressions'] ?? 0)) }}</td>
                        <td>{{ number_format((float) ($row['position'] ?? 0), 1) }}</td>
                        <td class="performance-hub-actions">
                            <button type="button" wire:click="pushQuickWinToEditor(@js($row['query'] ?? ''), 'suggest')" wire:loading.attr="disabled" wire:target="pushQuickWinToEditor" class="performance-hub-link-btn">
                                {{ __('seo-content-ai::filament.performance_hub.push_suggest') }}
                            </button>
                            <button type="button" wire:click="pushQuickWinToEditor(@js($row['query'] ?? ''), 'normal')" wire:loading.attr="disabled" wire:target="pushQuickWinToEditor" class="performance-hub-link-btn">
                                {{ __('seo-content-ai::filament.performance_hub.push_focus') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="performance-hub-table-empty">
                            {{ __('seo-content-ai::filament.performance_hub.quick_wins_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
