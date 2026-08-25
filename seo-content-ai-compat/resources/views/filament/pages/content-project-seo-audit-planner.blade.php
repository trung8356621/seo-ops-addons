@php
    /** @var \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner $this */
    $hasDraft = $this->project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject
        && $this->project->isDraftPlanning();
    $draftOptions = $this->draftProjectOptions ?? [];
    $siteOptions = $this->siteFilterOptions ?? [];
    $draftItems = $hasDraft ? ($this->draftPlanningItems ?? []) : [];
    $advancedUrl = \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl(array_filter([
        'project' => (int) ($this->projectId ?? 0) ?: null,
        'site' => (int) ($this->filterSiteId ?? 0) ?: null,
        'advanced' => 1,
    ]));
    $planningUrl = \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl(array_filter([
        'project' => (int) ($this->projectId ?? 0) ?: null,
        'site' => (int) ($this->filterSiteId ?? 0) ?: null,
    ]));
@endphp

<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />

    @if ($this->advanced)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.projects.seo_audit_advanced_help') }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.projects.seo_audit_advanced_hint') }}
                </p>
            </div>
            <a href="{{ $planningUrl }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                {{ __('seo-content-ai::filament.projects.content_planning_back') }}
            </a>
        </div>

        <x-seo-content-ai::content-project-seo-audit-planner :show-draft-selector="true" />
    @else
        <div class="cp-plan" wire:key="cp-content-planning">
            <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-300" data-content-planning-subtitle="1">
                {{ __('seo-content-ai::filament.projects.content_planning_subtitle') }}
            </p>

            <div class="cp-plan-context" data-content-planning-context="1">
                <div class="cp-plan-context__field">
                    <label class="cp-plan-context__label">
                        {{ __('seo-content-ai::filament.projects.seo_audit_site_label') }}
                    </label>
                    <x-select wire:model.live="filterSiteId" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.seo_audit_site_label') }}">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_site_all') }}</option>
                        @foreach ($siteOptions as $id => $domain)
                            <option value="{{ $id }}">{{ $domain }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="cp-plan-context__field cp-plan-context__field--draft">
                    <label class="cp-plan-context__label">
                        {{ __('seo-content-ai::filament.projects.content_planning_draft_label') }}
                    </label>
                    <x-select wire:model.live="projectId" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.content_planning_draft_label') }}">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_draft_selector_placeholder') }}</option>
                        @foreach ($draftOptions as $opt)
                            <option value="{{ $opt['id'] }}">
                                {{ $opt['name'] }}@if (($opt['domain'] ?? '') !== '') — {{ $opt['domain'] }}@endif
                            </option>
                        @endforeach
                    </x-select>
                </div>
                @if ($hasDraft)
                    <button
                        type="button"
                        wire:click="openPublishFromPlanner"
                        wire:loading.attr="disabled"
                        wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit"
                        class="cp-plan-btn cp-plan-btn--publish"
                        data-content-planning-action="publish"
                    >
                        <svg wire:loading.remove wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 00-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 012-3.95A12.88 12.88 0 0122 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 01-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                        <span wire:loading.remove wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit">
                            {{ __('seo-content-ai::filament.projects.content_planning_publish') }}
                        </span>
                        <span wire:loading wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit" class="inline-flex items-center gap-1">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </span>
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="createDraftForPlanner"
                        wire:loading.attr="disabled"
                        wire:target="createDraftForPlanner"
                        class="cp-plan-btn cp-plan-btn--publish"
                        data-content-planning-action="create-draft"
                    >
                        <span wire:loading.remove wire:target="createDraftForPlanner">
                            {{ __('seo-content-ai::filament.projects.seo_audit_create_draft') }}
                        </span>
                        <span wire:loading wire:target="createDraftForPlanner" class="inline-flex items-center gap-1">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </span>
                    </button>
                @endif
                @if (! $hasDraft)
                    <p class="basis-full text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.projects.content_planning_create_draft_first') }}
                    </p>
                @endif
            </div>

            <x-seo-content-ai::content-project-draft-planner
                :show-project-actions="false"
                :advanced-url="$advancedUrl"
            />

            <x-seo-content-ai::content-project-draft-items
                :items="$draftItems"
                :has-draft="$hasDraft"
            />
        </div>
    @endif
</x-filament-panels::page>
