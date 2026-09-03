@props([
    'embedded' => false,
])

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
    $canClonePlan = method_exists($this, 'canShowPlannerPlanClone') && $this->canShowPlannerPlanClone();
    $cloneSourceDomain = method_exists($this, 'getPlannerPlanCloneSourceDomainProperty')
        ? (string) $this->plannerPlanCloneSourceDomain
        : '';
    $cloneDestOptions = method_exists($this, 'getPlannerPlanCloneDestinationOptionsProperty')
        ? (array) $this->plannerPlanCloneDestinationOptions
        : [];
    $cloneResult = method_exists($this, 'plannerPlanCloneResult') || property_exists($this, 'plannerPlanCloneResult')
        ? ($this->plannerPlanCloneResult ?? null)
        : null;
@endphp

<div
    @class([
        'cp-plan-card cp-plan-card--create' => ! $embedded,
        'cp-plan-create-body cp-plan-create-body--sticky-cta' => $embedded,
        'cp-plan-create-body--sticky-cta' => ! $embedded,
    ])
    wire:key="cp-new-content-card"
    data-planner-card="new-content"
    x-data="cpPlannerPlanClone(@js([
        'destOptions' => $cloneDestOptions,
        'sourceDomain' => $cloneSourceDomain,
        'canWrite' => $canWrite && $canClonePlan,
    ]))"
    @planner-plan-cloned.window="onCloneResult($event.detail?.result || $event.detail || null)"
    @if ($isGenerating)
        wire:poll.3s="refreshNewContentRun"
    @endif
>
    <div class="cp-plan-create-scroll">
        @if (! $embedded)
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
        @else
            <p class="cp-plan-card__help">
                {{ __('seo-content-ai::filament.projects.planner_create_help') }}
            </p>
        @endif

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

        <div class="cp-plan-ai-meta">
            <div class="cp-plan-type cp-plan-type--compact" data-planner-content-type="1">
                <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_content_type') }}</label>
                @if (! $supportsProduct)
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-gray-950" data-content-type-readonly="post">
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

            @if (is_array($planningPreview))
                <div class="cp-plan-chips cp-plan-chips--compact" data-planning-intelligence="1">
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

            @if ($canWrite && $canClonePlan)
                <button
                    type="button"
                    class="cp-plan-link cp-plan-link--create cp-plan-clone-trigger"
                    data-planner-clone-open="1"
                    x-show="canClone"
                    x-cloak
                    @click.prevent="openCloneModal()"
                >
                    {{ __('seo-content-ai::filament.projects.planner_clone_button') }}
                </button>
            @endif
        </div>

        @if ($isGenerating)
            @php
                $progressAdded = (int) ($payload['progress_added'] ?? 0);
                $progressRequested = (int) ($payload['progress_requested'] ?? 0);
                $progressPhase = (string) ($payload['progress_phase'] ?? 'running');
                $progressUserMessage = (string) ($payload['progress_user_message'] ?? '');
            @endphp
            <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100" data-new-content-progress="1" data-progress-phase="{{ $progressPhase }}">
                @if ($progressUserMessage !== '')
                    {{ $progressUserMessage }}
                @else
                    {{ __('seo-content-ai::filament.projects.planner_generating_ideas') }}
                    @if ($progressRequested > 0)
                        <span class="ml-1 font-medium tabular-nums">{{ $progressAdded }} / {{ $progressRequested }}</span>
                    @endif
                @endif
            </p>
        @endif

        <div class="cp-plan-notes" data-planner-notes="new-content">
            <x-seo-content-ai::content-project-audit-notes />
        </div>

        @if ($lastResult !== '' && ! $isGenerating)
            <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200" data-new-content-last-result="1">
                {{ $lastResult }}
            </p>
        @endif

        @php
            $canFillRemaining = (bool) ($payload['can_fill_remaining'] ?? false);
            $partialRemaining = (int) ($payload['partial_remaining'] ?? 0);
        @endphp
        @if ($canFillRemaining && ! $isGenerating)
            <div class="mt-2 flex flex-wrap items-center gap-2" data-new-content-fill-remaining="1">
                <button
                    type="button"
                    class="cp-plan-btn cp-plan-btn--create"
                    wire:click="fillRemainingNewContentSuggestions"
                    wire:loading.attr="disabled"
                    wire:target="fillRemainingNewContentSuggestions"
                >
                    <span wire:loading.class="opacity-50 pointer-events-none" wire:target="fillRemainingNewContentSuggestions" class="inline-flex items-center gap-2">
                        <svg wire:loading wire:target="fillRemainingNewContentSuggestions" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <span>{{ __('seo-content-ai::filament.projects.planner_retry_remaining', ['remaining' => $partialRemaining]) }}</span>
                    </span>
                </button>
            </div>
        @endif
    </div>

    <div class="cp-plan-sticky-cta" data-planner-sticky-cta="1">
        @if ($embedded)
            {{-- AI focus: bottom 30/70 actions (not header / not card-width split) --}}
            <div
                class="cp-plan-sticky-cta__split"
                x-show="plannerLayout === 'ai-focused'"
                x-cloak
                data-planner-ai-focus-actions="1"
            >
                <button
                    type="button"
                    class="cp-plan-btn cp-plan-btn--improve"
                    @click="plannerLayout = 'balanced'"
                    data-planner-return-balanced="1"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 17l6-6 4 4 7-7"/><path d="M14 8h7v7"/></svg>
                    <span>{{ __('seo-content-ai::filament.projects.planner_improve_heading') }}</span>
                </button>
                <button
                    type="button"
                    x-data
                    @click.prevent="
                        const store = Alpine.store('cpAuditNotes');
                        const items = store && typeof store.snapshot === 'function' ? store.snapshot() : null;
                        if (items !== null) {
                            $wire.generateNewContentSuggestions(items);
                        } else {
                            $wire.generateNewContentSuggestions();
                        }
                    "
                    wire:loading.attr="disabled"
                    wire:target="generateNewContentSuggestions"
                    @disabled(! $generateEnabled)
                    @class(['cp-plan-btn cp-plan-btn--create', 'is-disabled' => ! $generateEnabled])
                    data-planner-generate="new-content"
                >
                    <span
                        wire:loading.class="opacity-50 pointer-events-none"
                        wire:target="generateNewContentSuggestions"
                        class="inline-flex items-center justify-center gap-2"
                    >
                        <svg wire:loading.remove wire:target="generateNewContentSuggestions" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.2 3.6L17 8l-3.8 1.4L12 13l-1.2-3.6L7 8l3.8-1.4L12 3z"/><path d="M19 14l.6 1.8L21.5 16.5l-1.9.7L19 19l-.6-1.8L16.5 16.5l1.9-.7L19 14z"/></svg>
                        <svg wire:loading wire:target="generateNewContentSuggestions" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <span wire:loading.remove wire:target="generateNewContentSuggestions">
                            @if ($isGenerating)
                                {{ __('seo-content-ai::filament.projects.planner_generating_ideas') }}
                            @else
                                {{ __('seo-content-ai::filament.projects.idea_candidate_tab_ai') }}
                            @endif
                        </span>
                        <span wire:loading wire:target="generateNewContentSuggestions">
                            {{ __('seo-content-ai::filament.projects.planner_generating_ideas') }}
                        </span>
                    </span>
                </button>
            </div>
        @endif

        <button
            type="button"
            @if ($embedded)
                x-show="plannerLayout !== 'ai-focused'"
                x-cloak
            @endif
            x-data
            @click.prevent="
                const store = Alpine.store('cpAuditNotes');
                const items = store && typeof store.snapshot === 'function' ? store.snapshot() : null;
                if (items !== null) {
                    $wire.generateNewContentSuggestions(items);
                } else {
                    $wire.generateNewContentSuggestions();
                }
            "
            wire:loading.attr="disabled"
            wire:target="generateNewContentSuggestions"
            @disabled(! $generateEnabled)
            @class(['cp-plan-btn cp-plan-btn--create', 'is-disabled' => ! $generateEnabled])
            data-planner-generate="new-content"
            @if ($embedded)
                data-planner-generate-balanced="1"
            @endif
        >
            <span
                wire:loading.class="opacity-50 pointer-events-none"
                wire:target="generateNewContentSuggestions"
                class="inline-flex items-center gap-2"
            >
                <svg wire:loading.remove wire:target="generateNewContentSuggestions" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.2 3.6L17 8l-3.8 1.4L12 13l-1.2-3.6L7 8l3.8-1.4L12 3z"/><path d="M19 14l.6 1.8L21.5 16.5l-1.9.7L19 19l-.6-1.8L16.5 16.5l1.9-.7L19 14z"/></svg>
                <svg wire:loading wire:target="generateNewContentSuggestions" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                <span wire:loading.remove wire:target="generateNewContentSuggestions">
                    @if ($isGenerating)
                        {{ __('seo-content-ai::filament.projects.planner_generating_ideas') }}
                    @else
                        {{ __('seo-content-ai::filament.projects.planner_generate_with_ai') }}
                    @endif
                </span>
                <span wire:loading wire:target="generateNewContentSuggestions">
                    {{ __('seo-content-ai::filament.projects.planner_generating_ideas') }}
                </span>
            </span>
        </button>
    </div>

    <template x-teleport="body">
        <div
            class="cp-ops-dialog-overlay"
            x-show="open"
            x-cloak
            x-transition.opacity
            @keydown.escape.window="if (open) closeCloneModal()"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cp-planner-clone-title"
            data-planner-clone-modal="1"
            @click.self="closeCloneModal()"
        >
            <div class="cp-ops-dialog cp-ops-dialog--clone" @click.stop wire:ignore.self>
                <div class="cp-ops-dialog__header border-b border-gray-100 dark:border-white/10">
                    <h3 id="cp-planner-clone-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('seo-content-ai::filament.projects.planner_clone_modal_title') }}
                    </h3>
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200" @click="closeCloneModal()">
                        {{ __('seo-content-ai::filament.projects.planner_close') }}
                    </button>
                </div>

                <div class="cp-ops-dialog__scroll space-y-4 p-4">
                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ __('seo-content-ai::filament.projects.planner_clone_source_label') }}
                        </p>
                        <p class="text-sm text-gray-900 dark:text-gray-100" x-text="sourceDomain || '—'" data-planner-clone-source="1"></p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200" for="cp-planner-clone-search">
                            {{ __('seo-content-ai::filament.projects.planner_clone_dest_label') }}
                        </label>
                        <input
                            id="cp-planner-clone-search"
                            type="search"
                            class="mb-2 w-full rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-sm dark:border-white/10 dark:bg-gray-950"
                            placeholder="{{ __('seo-content-ai::filament.projects.planner_clone_dest_search') }}"
                            x-model="search"
                            data-planner-clone-search="1"
                        >
                        <div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-white/10" data-planner-clone-dest-list="1">
                            <template x-for="row in filteredDestinations()" :key="row.id">
                                <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                    <input type="checkbox" class="rounded border-gray-300" :value="row.id" x-model="selectedIds">
                                    <span x-text="row.domain"></span>
                                </label>
                            </template>
                            <p class="px-2 py-1 text-xs text-gray-500" x-show="filteredDestinations().length === 0">
                                {{ __('seo-content-ai::filament.projects.planner_clone_dest_empty') }}
                            </p>
                        </div>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.planner_clone_mode_label') }}
                        </legend>
                        <label class="flex cursor-pointer items-start gap-2 text-sm">
                            <input type="radio" class="mt-0.5" value="skip_existing" x-model="mode">
                            <span>{{ __('seo-content-ai::filament.projects.planner_clone_mode_skip') }}</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 text-sm">
                            <input type="radio" class="mt-0.5" value="merge_missing" x-model="mode">
                            <span>{{ __('seo-content-ai::filament.projects.planner_clone_mode_merge') }}</span>
                        </label>
                    </fieldset>

                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200" data-planner-clone-summary="1">
                        <p x-text="planSummaryText()"></p>
                        <p class="mt-1 text-amber-800 dark:text-amber-200">
                            {{ __('seo-content-ai::filament.projects.planner_clone_config_only_notice') }}
                        </p>
                    </div>
                </div>

                <div class="cp-ops-dialog__footer flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 p-3 dark:border-white/10">
                    <button type="button" class="rounded-md px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5" @click="closeCloneModal()">
                        {{ __('seo-content-ai::filament.projects.planner_close') }}
                    </button>
                    <button
                        type="button"
                        class="cp-plan-btn cp-plan-btn--create"
                        style="width: auto; min-width: 8rem;"
                        :disabled="selectedIds.length === 0 || busy"
                        wire:loading.attr="disabled"
                        wire:target="clonePlannerPlan"
                        @click.prevent="submitClone()"
                        data-planner-clone-submit="1"
                    >
                        <span wire:loading.class="opacity-50 pointer-events-none" wire:target="clonePlannerPlan" class="inline-flex items-center gap-2">
                            <svg wire:loading wire:target="clonePlannerPlan" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            <span>{{ __('seo-content-ai::filament.projects.planner_clone_submit') }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

@once
<script>
    (function () {
        const register = () => {
            if (window.__cpPlannerPlanCloneRegistered || !window.Alpine) return;
            window.__cpPlannerPlanCloneRegistered = true;
            Alpine.data('cpPlannerPlanClone', (cfg) => ({
                open: false,
                busy: false,
                search: '',
                mode: 'skip_existing',
                selectedIds: [],
                sourceDomain: String(cfg?.sourceDomain || ''),
                destOptions: cfg?.destOptions || {},
                canWrite: !!cfg?.canWrite,
                get canClone() {
                    if (!this.canWrite) return false;
                    const store = window.Alpine?.store?.('cpAuditNotes');
                    if (!store) return false;
                    const n = typeof store.topicList === 'function' ? store.topicList().length : 0;
                    return n > 0 && Number(store.siteId || 0) > 0;
                },
                openCloneModal() {
                    this.selectedIds = [];
                    this.search = '';
                    this.mode = 'skip_existing';
                    this.busy = false;
                    this.open = true;
                },
                closeCloneModal() {
                    this.open = false;
                    this.busy = false;
                },
                filteredDestinations() {
                    const q = String(this.search || '').trim().toLowerCase();
                    return Object.entries(this.destOptions || {})
                        .map(([id, domain]) => ({ id: String(id), domain: String(domain) }))
                        .filter((row) => !q || row.domain.toLowerCase().includes(q));
                },
                planSummaryText() {
                    const store = window.Alpine?.store?.('cpAuditNotes');
                    const items = store && typeof store.snapshot === 'function' ? store.snapshot() : [];
                    const topicCount = items.length;
                    let dnaCount = 0;
                    let target = 0;
                    items.forEach((item) => {
                        (item.dna || []).forEach(() => { dnaCount += 1; });
                        target += parseInt(item.target_dna_count, 10) || 0;
                    });
                    return @js(__('seo-content-ai::filament.projects.planner_clone_summary'))
                        .replace(':topics', String(topicCount))
                        .replace(':dna', String(dnaCount))
                        .replace(':total', String(target));
                },
                submitClone() {
                    const store = window.Alpine?.store?.('cpAuditNotes');
                    const items = store && typeof store.snapshot === 'function' ? store.snapshot() : [];
                    if (!items.length || !this.selectedIds.length) return;
                    const hasPlan = {};
                    const itemsBySite = {};
                    this.selectedIds.forEach((id) => {
                        const sid = Number(id);
                        if (sid <= 0) return;
                        hasPlan[sid] = !!(store && typeof store.hasPlanForSite === 'function' && store.hasPlanForSite(sid));
                        itemsBySite[sid] = store && typeof store.peekItemsForSite === 'function'
                            ? store.peekItemsForSite(sid)
                            : [];
                    });
                    this.busy = true;
                    this.$wire.clonePlannerPlan(items, this.selectedIds.map(Number), this.mode, hasPlan, itemsBySite)
                        .finally(() => { this.busy = false; });
                },
                onCloneResult(result) {
                    if (!result || typeof result !== 'object') return;
                    const store = window.Alpine?.store?.('cpAuditNotes');
                    if (store && typeof store.writeItemsForSite === 'function') {
                        (result.destinations || []).forEach((row) => {
                            if (row.status !== 'copied') return;
                            store.writeItemsForSite(row.site_id, row.note_items || []);
                        });
                    }
                    // Success → đóng modal; toast Filament đã báo summary.
                    this.closeCloneModal();
                },
            }));
        };
        document.addEventListener('alpine:init', register);
        if (window.Alpine) register();
    })();
</script>
@endonce
