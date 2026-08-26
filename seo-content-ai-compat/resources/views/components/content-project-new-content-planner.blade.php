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
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.seo_audit_site_label') }}</label>
                    <x-select wire:model.live="filterSiteId" wrapClass="cp-ops-select">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_site_all') }}</option>
                        @foreach ($siteOptions as $id => $domain)
                            <option value="{{ $id }}">{{ $domain }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="min-w-[16rem] flex-1">
                    <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.seo_audit_draft_selector_label') }}</label>
                    <x-select wire:model.live="projectId" wrapClass="cp-ops-select">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_draft_selector_placeholder') }}</option>
                        @foreach ($draftOptions as $opt)
                            <option value="{{ $opt['id'] }}">
                                {{ $opt['name'] }}@if (($opt['domain'] ?? '') !== '') — {{ $opt['domain'] }}@endif
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <button type="button" wire:click="createDraftForPlanner" wire:loading.attr="disabled" class="fi-btn fi-btn-color-primary fi-size-sm">
                    {{ __('seo-content-ai::filament.projects.seo_audit_create_draft') }}
                </button>
            </div>
            @if (! $hasProject)
                <p class="mt-3 text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.seo_audit_draft_empty') }}</p>
            @endif
        </div>
    @endif

    <x-seo-content-ai::content-project-new-content-card />
</div>
