<div
    class="flex items-center gap-1"
    wire:key="api-conn-priority-{{ $getRecord()->getKey() }}"
>
    @php
        $record = $getRecord();
        $isAi = $record instanceof \App\Models\ApiConnection
            && \Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders::isAi((string) $record->provider);
        $stored = $isAi
            ? app(\Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService::class)
                ->storedConnectionPriority($record)
            : null;
        $display = $stored !== null ? (string) $stored : '';
    @endphp

    @if ($isAi)
        <input
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            value="{{ $display }}"
            placeholder="—"
            title="{{ __('seo-content-ai::filament.api_connections.priority_help') }}"
            aria-label="{{ __('seo-content-ai::filament.api_connections.col_priority') }}"
            class="fi-input block w-16 rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 disabled:opacity-50 disabled:cursor-not-allowed dark:border-white/10 dark:bg-white/5 dark:text-white"
            wire:change="updateConnectionPriority('{{ $record->getKey() }}', $event.target.value)"
            wire:loading.attr="disabled"
            wire:target="updateConnectionPriority('{{ $record->getKey() }}')"
            onkeydown="if (event.key === 'Enter') { event.preventDefault(); this.blur(); }"
        />
    @else
        <span class="text-sm text-gray-400">—</span>
    @endif
</div>
