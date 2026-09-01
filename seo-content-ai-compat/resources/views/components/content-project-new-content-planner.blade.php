@props([
    'showDraftSelector' => false,
])

@php
    /** @var \Livewire\Component $this */
    $payload = $this->newContentPlannerPayload ?? [];
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $hasProject = (bool) ($payload['has_project'] ?? false);
    $isGenerating = (bool) ($payload['is_generating'] ?? false);
    $primaryConfigured = (bool) ($payload['primary_configured'] ?? false);
    $primaryLanguageLabel = $payload['primary_language_label'] ?? null;
    $domainEditUrl = $payload['domain_edit_url'] ?? null;
    $draftOptions = $showDraftSelector ? ($this->draftProjectOptions ?? []) : [];
    $siteOptions = $showDraftSelector ? ($this->siteFilterOptions ?? []) : [];
@endphp

<div
    class="space-y-4"
    wire:key="cp-new-content-planner"
    @if ($isGenerating)
        wire:poll.3s="refreshNewContentRun"
    @endif
>
    @if ($showDraftSelector)
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[12rem]">
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.content_planning_working_site') }}</label>
                    <x-select wire:model.live="filterSiteId" wrapClass="cp-ops-select">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_site_all') }}</option>
                        @foreach ($siteOptions as $id => $domain)
                            <option value="{{ $id }}">{{ $domain }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="min-w-[16rem] flex-1">
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.content_planning_draft_label') }}</label>
                    <div class="text-sm text-gray-700 dark:text-gray-200" data-shared-planning-draft="1">
                        {{ __('seo-content-ai::filament.projects.content_planning_shared_draft_name') }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    <x-seo-content-ai::content-project-new-content-card />
</div>
