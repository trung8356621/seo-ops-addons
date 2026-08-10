<div class="flex items-center gap-3 mr-4">
    @if ($isAdminViewer ?? false)
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
            {{ __('seo-content-ai::filament.global_bar.admin_view_only') }}
        </div>
    @endif
    @if ($showDomainPicker ?? true)
        <div class="flex items-center gap-1.5">
            <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.domain') }}</span>
            <x-select wire:model.live="globalSiteId" size="sm">
                <option value="">{{ __('seo-content-ai::filament.global_bar.all_domains') }}</option>
                @foreach($sites as $site)
                    <option value="{{ $site->id }}">{{ $site->name ?? $site->domain }}</option>
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
