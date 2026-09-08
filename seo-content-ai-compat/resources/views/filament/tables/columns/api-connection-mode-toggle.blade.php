<div
    class="flex items-center gap-2"
    wire:key="api-conn-mode-{{ $getRecord()->getKey() }}"
>
    @php
        $record = $getRecord();
        $isAi = $record instanceof \App\Models\ApiConnection
            && \Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders::isAi((string) $record->provider);
        $support = app(\Omnichannel\Addons\AiPrompt\Services\AiConnectionFreeOnlySupport::class);
        $supportsFreeOnly = $isAi && $support->supports($record);
        $freeOnly = $isAi && (bool) ($record->paid_locked ?? false);
    @endphp

    @if (! $isAi)
        <span class="text-sm text-gray-400">—</span>
    @elseif (! $supportsFreeOnly)
        <span
            class="text-sm text-gray-400"
            title="{{ __('seo-content-ai::filament.api_connections.free_only_unsupported') }}"
        >—</span>
    @else
        <x-filament::badge :color="$freeOnly ? 'warning' : 'gray'" class="font-semibold">
            @if ($freeOnly)
                <span class="inline-flex items-center gap-1">
                    {{ __('seo-content-ai::filament.api_connections.mode_free_only') }}
                    <x-heroicon-s-lock-closed class="h-3.5 w-3.5" />
                </span>
            @else
                {{ __('seo-content-ai::filament.api_connections.mode_full') }}
            @endif
        </x-filament::badge>

        <button
            type="button"
            wire:click="toggleConnectionFreeOnly('{{ $record->getKey() }}')"
            wire:loading.attr="disabled"
            wire:target="toggleConnectionFreeOnly('{{ $record->getKey() }}')"
            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-warning-500 focus:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none {{ $freeOnly ? 'bg-warning-500' : 'bg-gray-200 dark:bg-gray-700' }}"
            role="switch"
            aria-checked="{{ $freeOnly ? 'true' : 'false' }}"
            title="{{ $freeOnly ? __('seo-content-ai::filament.api_connections.toggle_free_only_off') : __('seo-content-ai::filament.api_connections.toggle_free_only_on') }}"
        >
            <span
                class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $freeOnly ? 'translate-x-4' : 'translate-x-0' }}"
            ></span>
        </button>
    @endif
</div>
