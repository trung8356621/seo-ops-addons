<div
    class="flex items-center gap-3 mr-4"
    data-seo-domain-context-bar
    data-domain-key="{{ $domainKey }}"
>
    @if ($showDomainPicker ?? true)
        <div
            class="flex items-center gap-1.5"
            wire:loading.class="opacity-60"
            wire:target="domainKey"
        >
            <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.domain') }}</span>
            <x-select
                wire:model.live="domainKey"
                size="sm"
                x-on:change="window.SeoDomainContext && window.SeoDomainContext.select($event.target.value)"
            >
                @if (! ($hideAllDomainsOption ?? false))
                    <option value="all">{{ __('seo-content-ai::filament.global_bar.all_domains') }}</option>
                @endif
                @foreach($sites as $site)
                    <option value="{{ $domainKeys[$site->id] ?? $site->domain }}">{{ $site->name ?? $site->domain }}</option>
                @endforeach
            </x-select>
        </div>
    @endif

    @if($showContentProjectPicker ?? false)
        <div class="flex items-center gap-1.5">
            <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.content_project') }}</span>
            <x-select wire:model.live="globalContentProjectId" size="sm" wrapClass="x-select-wrap--narrow">
                <option value="">{{ __('seo-content-ai::filament.global_bar.no_content_project') }}</option>
                @foreach($contentProjectOptions as $projectId => $label)
                    <option value="{{ $projectId }}">{{ $label }}</option>
                @endforeach
            </x-select>
        </div>
    @endif

    <div class="flex items-center gap-1.5">
        <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.view_as') }}</span>
        <x-select wire:model.live="simulatedRole" size="sm">
            @foreach($roleOptions as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </x-select>
    </div>
</div>
