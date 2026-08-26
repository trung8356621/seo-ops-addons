@php
    /** @var \Livewire\Component $this */
    $payload = $this->newContentPlannerPayload ?? [];
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $quantityEnabled = (bool) ($payload['quantity_enabled'] ?? $canWrite);
    $generateEnabled = (bool) ($payload['generate_enabled'] ?? $canWrite);
    $isGenerating = (bool) ($payload['is_generating'] ?? false);
    $primaryConfigured = (bool) ($payload['primary_configured'] ?? false);
    $primaryLanguageLabel = $payload['primary_language_label'] ?? null;
    $blockReasons = is_array($payload['block_reasons'] ?? null) ? $payload['block_reasons'] : [];
    $lastResult = (string) ($payload['last_result'] ?? $this->newContentLastResult ?? '');
    $planningPreview = $this->newContentPlanningPreview ?? null;
    $contentTypeOptions = is_array($payload['content_type_options'] ?? null)
        ? $payload['content_type_options']
        : ['post' => (string) __('seo-content-ai::filament.projects.planner_content_type_post')];
    $supportsProduct = (bool) ($payload['supports_product'] ?? false);
    $aiHistoryUrl = method_exists($this, 'newContentDraftAiHistoryUrl') ? $this->newContentDraftAiHistoryUrl() : '#';
@endphp

<div
    class="cp-plan-card cp-plan-card--create"
    wire:key="cp-new-content-card"
    data-planner-card="new-content"
    @if ($isGenerating)
        wire:poll.3s="refreshNewContentRun"
    @endif
>
    <div class="cp-plan-card__head">
        <span class="cp-plan-card__icon cp-plan-card__icon--create" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z"/><path d="M19 14l.75 2.25L22 17l-2.25.75L19 20l-.75-2.25L16 17l2.25-.75L19 14z"/></svg>
        </span>
        <div>
            <h3 class="cp-plan-card__title">
                {{ __('seo-content-ai::filament.projects.planner_create_heading') }}
            </h3>
            <p class="cp-plan-card__help">
                {{ __('seo-content-ai::filament.projects.planner_create_help') }}
            </p>
        </div>
    </div>

    @if (is_string($primaryLanguageLabel) && $primaryLanguageLabel !== '' && $primaryConfigured)
        <p class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.planner_primary_language_label', ['label' => $primaryLanguageLabel]) }}</p>
    @endif

    @if ($blockReasons !== [] && ! $generateEnabled)
        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" data-new-content-readiness="blocked">
            @foreach ($blockReasons as $reason)
                <p>⚠ {{ $reason }}</p>
            @endforeach
        </div>
    @endif

    <div class="cp-plan-action-row">
        <div class="cp-plan-qty">
            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_quantity') }}</label>
            <x-select wire:model.live="newContentQuantity" wrapClass="cp-ops-select" :disabled="! $quantityEnabled">
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
            </x-select>
        </div>
        <div class="cp-plan-type" data-planner-content-type="1">
            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_content_type') }}</label>
            @if (! $supportsProduct)
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-950" data-content-type-readonly="post">
                    {{ $contentTypeOptions['post'] ?? __('seo-content-ai::filament.projects.planner_content_type_post') }}
                </div>
            @else
                <x-select wire:model="newContentPostType" wrapClass="cp-ops-select" :disabled="! $quantityEnabled">
                    @foreach ($contentTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            @endif
        </div>
    </div>

    <button
        type="button"
        wire:click="generateNewContentSuggestions"
        wire:loading.attr="disabled"
        wire:target="generateNewContentSuggestions"
        @disabled(! $generateEnabled)
        @class(['cp-plan-btn cp-plan-btn--create', 'is-disabled' => ! $generateEnabled])
        data-planner-generate="new-content"
    >
        <svg wire:loading.remove wire:target="generateNewContentSuggestions" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.2 3.6L17 8l-3.8 1.4L12 13l-1.2-3.6L7 8l3.8-1.4L12 3z"/><path d="M19 14l.6 1.8L21.5 16.5l-1.9.7L19 19l-.6-1.8L16.5 16.5l1.9-.7L19 14z"/></svg>
        <span wire:loading.remove wire:target="generateNewContentSuggestions">
            @if ($isGenerating)
                {{ __('seo-content-ai::filament.projects.planner_generating') }}
            @else
                {{ __('seo-content-ai::filament.projects.planner_generate_with_ai') }}
            @endif
        </span>
        <span wire:loading wire:target="generateNewContentSuggestions" class="inline-flex items-center gap-1">
            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
        </span>
    </button>

    <div class="cp-plan-meta">
        @if (is_array($planningPreview))
            <div class="cp-plan-chips" data-planning-intelligence="1">
                <span class="cp-plan-chips__label">{{ __('seo-content-ai::filament.projects.planner_planning_context') }}</span>
                <span class="cp-plan-chip">
                    {{ __('seo-content-ai::filament.projects.content_planning_chip_kw_clusters', [
                        'keywords' => (int) ($planningPreview['principal_keywords_count'] ?? 0),
                        'clusters' => (int) ($planningPreview['cluster_count'] ?? 0),
                    ]) }}
                </span>
                @if (($planningPreview['mcp_period'] ?? null) !== null && (string) $planningPreview['mcp_period'] !== '')
                    <span class="cp-plan-chip">MCP {{ $planningPreview['mcp_period'] }}</span>
                @endif
            </div>
        @endif

        <div class="cp-plan-notes" data-planner-notes="new-content">
            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_notes') }}</label>
            <textarea
                wire:model="newContentNotes"
                rows="3"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                placeholder="{{ __('seo-content-ai::filament.projects.planner_notes_placeholder') }}"
                @disabled(! $quantityEnabled)
            ></textarea>
        </div>

        @if ($aiHistoryUrl !== '#')
            <a
                href="{{ $aiHistoryUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="cp-plan-link cp-plan-link--create"
                data-new-content-ai-history="1"
            >
                {{ __('seo-content-ai::filament.projects.draft_ai_history_link') }}
            </a>
        @endif
    </div>

    @if ($lastResult !== '')
        <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200" data-new-content-last-result="1">
            {{ $lastResult }}
        </p>
    @endif
</div>
