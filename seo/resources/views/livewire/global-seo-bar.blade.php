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

    <x-filament::dropdown
        placement="bottom-end"
        width="sm"
        teleport
    >
        <x-slot name="trigger">
            <button
                type="button"
                class="fi-icon-btn flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 outline-none transition duration-75 hover:bg-gray-400/10 hover:text-gray-700 focus-visible:bg-gray-400/10 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200 dark:focus-visible:bg-white/5"
                title="{{ __('seo-content-ai::filament.global_bar.settings_gear') }}"
                aria-label="{{ __('seo-content-ai::filament.global_bar.settings_gear') }}"
            >
                <x-filament::icon
                    icon="heroicon-m-cog-6-tooth"
                    class="h-5 w-5"
                />
            </button>
        </x-slot>

        <div class="w-[320px] space-y-3 p-3">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.global_bar.options_heading') }}
            </p>

            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.global_bar.view_as') }}
                </label>
                <x-select wire:model.live="simulatedRole" size="sm" class="w-full">
                    @foreach($roleOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>

            <div class="border-t border-gray-200 pt-3 dark:border-gray-700">
                <p class="mb-2 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.global_bar.ai_generation_heading') }}
                </p>
                <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input
                        type="checkbox"
                        class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        wire:model.live="writingSplitEnabled"
                    >
                    <span>
                        <span class="font-medium">{{ __('seo-content-ai::filament.global_bar.writing_split_label') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.global_bar.writing_split_help') }}</span>
                    </span>
                </label>
            </div>
        </div>
    </x-filament::dropdown>
</div>