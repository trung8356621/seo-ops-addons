@php
    /** @var \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectDraftAiHistory $this */
    $payload = $this->draftAiCallsPayload();
@endphp

<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />
    @vite(['addons/content-projects/resources/css/project-run-step.css'])

    <x-seo-content-ai::prompt-ai-calls-panel
        :groups="$payload['groups']"
        :context-eyebrow="$payload['context']['eyebrow']"
        :context-title="$payload['context']['title']"
        :context-description="$payload['context']['description']"
        :empty-message="__('seo-content-ai::filament.projects.draft_ai_calls_empty')"
        :total="$payload['total']"
        :has-more="$payload['has_more']"
        :page="$payload['page']"
        filter-type-property="draftAiCallFilterType"
        filter-status-property="draftAiCallFilterStatus"
        clear-filters-method="clearDraftAiCallFilters"
        load-more-method="loadMoreDraftAiCalls"
        load-detail-method="loadDraftRawAiCallDetail"
    />
</x-filament-panels::page>
