<div
    class="flex items-center gap-2"
    wire:key="api-conn-active-{{ $getRecord()->getKey() }}"
>
    @php
        $record = $getRecord();
        $isAi = $record instanceof \App\Models\ApiConnection
            && \Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders::isAi((string) $record->provider);
        $active = $isAi && (string) $record->status === 'active';
        $label = $isAi
            ? ($active
                ? __('seo-content-ai::filament.api_connections.status_active')
                : __('seo-content-ai::filament.api_connections.status_inactive'))
            : \Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::formatStatusLabel((string) $record->getAttribute('status'));
        $color = $isAi
            ? ($active ? 'success' : 'gray')
            : match ((string) $record->getAttribute('status')) {
                'active', 'connected' => 'success',
                'inactive' => 'gray',
                default => 'warning',
            };
    @endphp

    <x-filament::badge :color="$color">
        {{ $label }}
    </x-filament::badge>

    @if ($isAi)
        <button
            type="button"
            wire:click="toggleConnectionActive('{{ $record->getKey() }}')"
            wire:loading.attr="disabled"
            wire:target="toggleConnectionActive('{{ $record->getKey() }}')"
            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none {{ $active ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700' }}"
            role="switch"
            aria-checked="{{ $active ? 'true' : 'false' }}"
            title="{{ $active ? __('seo-content-ai::filament.api_connections.toggle_active_off') : __('seo-content-ai::filament.api_connections.toggle_active_on') }}"
        >
            <span
                class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $active ? 'translate-x-4' : 'translate-x-0' }}"
            ></span>
        </button>
    @endif
</div>
