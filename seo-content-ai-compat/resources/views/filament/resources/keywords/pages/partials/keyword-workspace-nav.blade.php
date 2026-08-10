@php
    $siteOptions = $this->getKeywordWorkspaceSiteFilterOptions();
    $showSiteFilter = $this->shouldShowKeywordWorkspaceSiteFilter();
@endphp

<div class="keyword-workspace-tabs-bar">
    <x-seo-content-ai::workspace-tabs
        :active-key="$activeKey ?? ''"
        :items="$navItems ?? []"
        class="keyword-workspace-tabs"
    />

    @if ($showSiteFilter)
        <div class="keyword-workspace-tabs-bar__filter">
            <label for="keyword-workspace-site-filter" class="sr-only">
                {{ __('seo-content-ai::filament.keyword.domain_filter_label') }}
            </label>
            <x-select
                id="keyword-workspace-site-filter"
                wire:model.live="keywordWorkspaceSiteId"
                wire:loading.attr="disabled"
                wire:target="keywordWorkspaceSiteId"
                class="keyword-workspace-domain-select"
            >
                <option value="">{{ __('seo-content-ai::filament.keyword.domain_filter_all') }}</option>
                @foreach ($siteOptions as $siteId => $domainLabel)
                    <option value="{{ (int) $siteId }}">{{ $domainLabel }}</option>
                @endforeach
            </x-select>
        </div>
    @endif
</div>
