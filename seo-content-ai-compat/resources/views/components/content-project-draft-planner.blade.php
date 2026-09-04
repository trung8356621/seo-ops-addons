@props([
    'showProjectActions' => true,
])

@php
    /** @var \Livewire\Component $this */
    $card = $this->seoAuditPlannerCardState ?? [];
    $canWrite = (bool) ($card['can_write'] ?? false);
    $primaryLanguageLabel = $card['primary_language_label'] ?? null;
    $filterOptions = $this->suggestionFilterOptions ?? ['post_types' => [], 'taxonomies' => [], 'terms' => []];
    $issueOptions = \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::auditFilterDefinitions();
    $scoreMax = $this->suggestionScoreMax ?? null;
    $scorePreset = ($scoreMax === null || $scoreMax === '') ? 'all' : (string) (int) $scoreMax;
        $chips = $this->improveRecentFilterChips ?? [];
    if (! is_array($chips) || $chips === []) {
        $chips = [
            (string) __('seo-content-ai::filament.projects.planner_chip_default'),
        ];
    }
    $visibleChips = array_slice($chips, 0, 4);
    $extraChipCount = max(0, count($chips) - count($visibleChips));
    $splitUi = method_exists($this, 'draftSplitUiState') ? $this->draftSplitUiState() : [
        'count' => 0,
        'reviewed_count' => 0,
        'selected' => 0,
        'start_month_label' => now()->format('m/Y'),
        'included_writers' => [],
        'excluded_writers' => [],
        'can_create' => false,
        'max' => 30,
    ];
    $draftItemCount = (int) ($splitUi['reviewed_count'] ?? $splitUi['count'] ?? 0);
    $splitSelectedCount = (int) ($splitUi['selected'] ?? 0);
    $splitStartMonthLabel = (string) ($splitUi['start_month_label'] ?? now()->format('m/Y'));
    $splitIncludedWriters = is_array($splitUi['included_writers'] ?? null) ? $splitUi['included_writers'] : [];
    $splitExcludedWriters = is_array($splitUi['excluded_writers'] ?? null) ? $splitUi['excluded_writers'] : [];
    $splitCanCreate = (bool) ($splitUi['can_create'] ?? false);
    $splitMax = (int) ($splitUi['max'] ?? 30);
    $splitHasWriters = $splitIncludedWriters !== [] || $splitExcludedWriters !== [];
    $splitDomainRows = is_array($splitUi['domain_rows'] ?? null) ? $splitUi['domain_rows'] : [];
    $splitDomainCount = (int) ($splitUi['domain_count'] ?? count($splitDomainRows));
    $splitDomainWarning = is_array($splitUi['domain_warning'] ?? null) ? $splitUi['domain_warning'] : null;
    $splitWriterCount = (int) ($splitUi['writer_count'] ?? count($splitIncludedWriters));
    $splitTargetMonthLabel = (string) ($splitUi['target_month_label'] ?? $splitStartMonthLabel);
    $ideaPayload = $this->ideaCandidatesPayload ?? [];
    $ideaPaginator = $ideaPayload['paginator'] ?? null;
    $ideaTotal = $ideaPaginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
        ? (int) $ideaPaginator->total()
        : count(is_array($ideaPayload['rows'] ?? null) ? $ideaPayload['rows'] : []);
@endphp

<div
    class="cp-plan-slide__planner space-y-3"
    wire:key="cp-draft-content-planner"
    x-data="{ filtersOpen: true, createTab: 'ideas', plannerLayout: 'balanced' }"
>
    @if ($showProjectActions)
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.projects.planner_content_plan') }}
                </h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.projects.planner_content_plan_help') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="openDraftSplitModal"
                    wire:loading.attr="disabled"
                    wire:target="openDraftSplitModal,confirmDraftSplit,activateAllDraftItems"
                    @disabled(! $canWrite || $draftItemCount <= 0)
                    @class(['fi-btn fi-btn-color-gray fi-size-sm', 'opacity-50 pointer-events-none' => ! $canWrite || $draftItemCount <= 0])
                    data-draft-action="split"
                >
                    {{ __('seo-content-ai::filament.projects.draft_split') }}
                </button>
                <button
                    type="button"
                    wire:click="activateAllDraftItems"
                    wire:loading.attr="disabled"
                    wire:target="activateAllDraftItems,confirmDraftSplit"
                    @disabled(! $canWrite || $draftItemCount <= 0)
                    @class(['fi-btn fi-btn-color-primary fi-size-sm', 'opacity-50 pointer-events-none' => ! $canWrite || $draftItemCount <= 0])
                    data-draft-action="activate-all"
                >
                    <span wire:loading.remove wire:target="activateAllDraftItems">{{ __('seo-content-ai::filament.projects.draft_activate_all') }}</span>
                    <span wire:loading wire:target="activateAllDraftItems" class="inline-flex items-center gap-1">
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    </span>
                </button>
            </div>
        </div>
    @else
        {{-- Keep contract hooks for split/activate without showing duplicate primary actions on unified page --}}
        <div class="hidden" aria-hidden="true">
            <button type="button" wire:click="openDraftSplitModal" data-draft-action="split"></button>
            <button type="button" wire:click="activateAllDraftItems" data-draft-action="activate-all"></button>
        </div>
    @endif

    @if ($this->suggestionsLastResult !== '')
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-white/10 dark:bg-gray-900/40 dark:text-gray-300">
            {{ $this->suggestionsLastResult }}
        </div>
    @endif

    <div
        class="cp-plan-slide__grid cp-plan-grid"
        :class="{ 'is-ai-focused': plannerLayout === 'ai-focused' }"
        data-planner-grid="1"
        :data-planner-layout="plannerLayout"
    >
        {{-- Improve existing — full card only in balanced 50/50 mode --}}
        <div class="cp-plan-card cp-plan-card--improve cp-plan-card--with-sticky-cta" data-planner-card="improve">
            <div class="cp-plan-card__head">
                <span class="cp-plan-card__icon cp-plan-card__icon--improve" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 7-7"/><path d="M14 8h7v7"/></svg>
                </span>
                <div>
                    <h3 class="cp-plan-card__title">
                        {{ __('seo-content-ai::filament.projects.planner_improve_heading') }}
                    </h3>
                    <p class="cp-plan-card__help">
                        {{ __('seo-content-ai::filament.projects.planner_improve_help') }}
                    </p>
                </div>
            </div>

            <div class="cp-plan-card__scroll" data-plan-scroll="improve">
            <div class="cp-plan-meta">
                <div class="cp-plan-chips">
                    <span class="cp-plan-chips__label">{{ __('seo-content-ai::filament.projects.content_planning_recent_filters') }}</span>
                    @foreach ($visibleChips as $chip)
                        <span class="cp-plan-chip">{{ $chip }}</span>
                    @endforeach
                    @if ($extraChipCount > 0)
                        <span class="cp-plan-chip">+{{ $extraChipCount }}</span>
                    @endif
                </div>

                <button
                    type="button"
                    class="cp-plan-filters-toggle"
                    @click="filtersOpen = !filtersOpen"
                    data-planner-filters-toggle="improve"
                >
                    {{ __('seo-content-ai::filament.projects.planner_filters') }}
                    <svg class="h-3.5 w-3.5 transition" :class="filtersOpen && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>
                </button>

                <div x-show="filtersOpen" x-cloak class="cp-plan-filters-panel" data-planner-filters="improve">
                    <div class="cp-plan-filters-grid">
                        <div
                            class="cp-plan-filters-grid__field"
                            data-seo-score-segment="1"
                            x-data="{ selected: @js($scorePreset === 'all' ? 'all' : $scorePreset) }"
                        >
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_filter_score_max') }}</label>
                            <div class="cp-plan-segment">
                                @foreach (['all' => __('seo-content-ai::filament.projects.seo_audit_score_all'), '80' => '<80', '60' => '<60', '40' => '<40'] as $presetKey => $presetLabel)
                                    <button
                                        type="button"
                                        @click="selected = '{{ $presetKey }}'; $wire.setSuggestionScorePreset('{{ $presetKey }}')"
                                        :class="selected === '{{ $presetKey }}' ? 'cp-plan-segment__btn is-active' : 'cp-plan-segment__btn'"
                                    >
                                        {{ $presetLabel }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="cp-plan-filters-grid__field">
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_filter_issue') }}</label>
                            <x-select wire:model.live="suggestionIssueKey" wrapClass="cp-ops-select">
                                <option value="">{{ __('seo-content-ai::filament.projects.suggestions_filter_issue_all') }}</option>
                                @foreach ($issueOptions as $def)
                                    <option value="{{ $def['key'] ?? '' }}">{{ $def['label'] ?? ($def['key'] ?? '') }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div class="cp-plan-filters-grid__field">
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_post_type') }}</label>
                            <x-select wire:model.live="suggestionPostTypeMode" wrapClass="cp-ops-select">
                                <option value="all_except_page">{{ __('seo-content-ai::filament.projects.planner_post_type_except_page') }}</option>
                                <option value="all">{{ __('seo-content-ai::filament.article_list.all_post_types') }}</option>
                                <option value="specific">{{ __('seo-content-ai::filament.projects.planner_post_type_specific') }}</option>
                            </x-select>
                            @if (($this->suggestionPostTypeMode ?? '') === 'specific')
                                <div class="mt-2">
                                    <x-select wire:model.live="suggestionPostType" wrapClass="cp-ops-select">
                                        @foreach (($filterOptions['post_types'] ?? []) as $slug => $label)
                                            <option value="{{ $slug }}">{{ $label }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            @endif
                        </div>

                        <div class="cp-plan-filters-grid__field">
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_taxonomy') }}</label>
                            <x-select wire:model.live="suggestionTaxonomy" wrapClass="cp-ops-select">
                                <option value="">{{ __('seo-content-ai::filament.article_list.all_taxonomies') }}</option>
                                @foreach (($filterOptions['taxonomies'] ?? []) as $slug => $label)
                                    <option value="{{ $slug }}">{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div class="cp-plan-filters-grid__field">
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_term_all') }}</label>
                            <x-select wire:model.live="suggestionTermId" wrapClass="cp-ops-select" :disabled="($this->suggestionTaxonomy ?? '') === ''">
                                <option value="">{{ __('seo-content-ai::filament.projects.planner_term_all') }}</option>
                                @foreach (($filterOptions['terms'] ?? []) as $term)
                                    <option value="{{ $term['id'] }}">{{ $term['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div class="cp-plan-filters-grid__field">
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_filter_language') }}</label>
                            <x-select wire:model.live="suggestionLanguageScope" wrapClass="cp-ops-select">
                                <option value="primary">
                                    @if (is_string($primaryLanguageLabel) && $primaryLanguageLabel !== '')
                                        {{ __('seo-content-ai::filament.projects.suggestions_filter_language_primary', ['label' => $primaryLanguageLabel]) }}
                                    @else
                                        {{ __('seo-content-ai::filament.projects.suggestions_filter_language_primary_fallback') }}
                                    @endif
                                </option>
                                <option value="all">{{ __('seo-content-ai::filament.projects.suggestions_filter_language_all') }}</option>
                            </x-select>
                        </div>

                        <div class="cp-plan-filters-grid__field">
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_col_state') }}</label>
                            <x-select wire:model.live="suggestionStateFilter" wrapClass="cp-ops-select">
                                <option value="available">{{ __('seo-content-ai::filament.projects.suggestions_state_available') }}</option>
                            </x-select>
                        </div>

                        <div class="cp-plan-filters-grid__actions">
                            <button type="button" class="fi-btn fi-btn-color-gray fi-size-sm" wire:click="saveSeoAuditFilters">
                                {{ __('seo-content-ai::filament.projects.planner_save_filters') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <div class="cp-plan-sticky-cta cp-plan-sticky-cta--improve">
                <div class="cp-plan-action-row">
                    <div class="cp-plan-qty">
                        <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_quantity') }}</label>
                        <x-select wire:model.live="fillLimit" wrapClass="cp-ops-select" :disabled="! $canWrite">
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </x-select>
                    </div>
                    <button
                        type="button"
                        wire:click="fillSuggestions"
                        wire:loading.attr="disabled"
                        wire:target="fillSuggestions"
                        @disabled(! $canWrite)
                        @class(['cp-plan-btn cp-plan-btn--improve', 'is-disabled' => ! $canWrite])
                    >
                        <svg wire:loading.remove wire:target="fillSuggestions" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.2 3.6L17 8l-3.8 1.4L12 13l-1.2-3.6L7 8l3.8-1.4L12 3z"/><path d="M19 14l.6 1.8L21.5 16.5l-1.9.7L19 19l-.6-1.8L16.5 16.5l1.9-.7L19 14z"/></svg>
                        <span wire:loading.remove wire:target="fillSuggestions">{{ __('seo-content-ai::filament.projects.planner_fill_from_seo_audit') }}</span>
                        <span wire:loading wire:target="fillSuggestions" class="inline-flex items-center gap-1">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Create / ideas (blue outer panel; tab panels stacked for stable height) --}}
        <div class="cp-plan-card cp-plan-card--create" data-planner-card="create">
            <div class="cp-plan-create-tabs" role="tablist" aria-label="{{ __('seo-content-ai::filament.projects.planner_create_heading') }}">
                <button
                    type="button"
                    role="tab"
                    class="cp-plan-create-tab"
                    :class="createTab === 'ideas' && 'is-active'"
                    :aria-selected="createTab === 'ideas'"
                    @click="createTab = 'ideas'; plannerLayout = 'balanced'"
                    data-create-tab="ideas"
                >
                    {{ __('seo-content-ai::filament.projects.idea_candidate_tab_available') }}
                    <span class="cp-plan-create-tab__badge" data-idea-total-badge="1">{{ $ideaTotal }}</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    class="cp-plan-create-tab"
                    :class="createTab === 'ai' && 'is-active'"
                    :aria-selected="createTab === 'ai'"
                    @click="createTab = 'ai'; plannerLayout = 'ai-focused'"
                    data-create-tab="ai"
                >
                    {{ __('seo-content-ai::filament.projects.idea_candidate_tab_ai') }}
                </button>
                <button
                    type="button"
                    role="tab"
                    class="cp-plan-create-tab"
                    :class="createTab === 'site-planning' && 'is-active'"
                    :aria-selected="createTab === 'site-planning'"
                    @click="createTab = 'site-planning'; plannerLayout = 'balanced'"
                    data-create-tab="site-planning"
                >
                    {{ __('seo-content-ai::filament.projects.site_planning_tab') }}
                </button>
                @php
                    $aiHistoryTabUrl = method_exists($this, 'draftAiHistoryUrl')
                        ? $this->draftAiHistoryUrl()
                        : (method_exists($this, 'newContentDraftAiHistoryUrl') ? $this->newContentDraftAiHistoryUrl() : '#');
                @endphp
                @if ($aiHistoryTabUrl !== '#')
                    <a
                        href="{{ $aiHistoryTabUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        role="tab"
                        class="cp-plan-create-tab cp-plan-create-tab--external"
                        data-create-tab="ai-history"
                        data-new-content-ai-history="1"
                        title="{{ __('seo-content-ai::filament.projects.draft_ai_history_nav') }}"
                    >
                        {{ __('seo-content-ai::filament.projects.draft_ai_history_nav') }}
                        <svg class="h-3.5 w-3.5 shrink-0 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/></svg>
                    </a>
                @endif
            </div>

            <div class="cp-plan-tab-panels">
                <div
                    class="cp-plan-tab-panel"
                    data-create-panel="ideas"
                    :class="createTab === 'ideas' ? 'is-active' : 'is-inactive'"
                    :aria-hidden="createTab !== 'ideas'"
                >
                    <x-seo-content-ai::content-project-idea-candidate-picker :embedded="true" />
                </div>
                <div
                    class="cp-plan-tab-panel"
                    data-create-panel="ai"
                    :class="createTab === 'ai' ? 'is-active' : 'is-inactive'"
                    :aria-hidden="createTab !== 'ai'"
                >
                    <x-seo-content-ai::content-project-new-content-card :embedded="true" />
                </div>
                <div
                    class="cp-plan-tab-panel"
                    data-create-panel="site-planning"
                    :class="createTab === 'site-planning' ? 'is-active' : 'is-inactive'"
                    :aria-hidden="createTab !== 'site-planning'"
                >
                    <x-seo-content-ai::content-project-site-planning />
                </div>
            </div>
        </div>
    </div>

    {{-- Split modal: teleport to body (escape Filament/page stacking); reuse cp-ops-dialog layer --}}
    @if ($this->draftSplitModalOpen ?? false)
        <template x-teleport="body">
            <div
                class="cp-ops-dialog-overlay"
                wire:key="cp-draft-split-modal"
                data-split-modal="1"
                wire:click="closeDraftSplitModal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cp-draft-split-title"
            >
                <div
                    class="cp-ops-dialog cp-ops-dialog--split"
                    wire:click.stop
                    data-split-panel="1"
                >
                    <div class="cp-ops-dialog__header border-b border-gray-100 dark:border-white/10">
                        <h3 id="cp-draft-split-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.projects.draft_split_modal_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" data-split-eligible="1">
                            {{ __('seo-content-ai::filament.projects.draft_split_eligible', ['count' => $draftItemCount]) }}
                        </p>
                    </div>

                    <div class="cp-ops-dialog__scroll">
                        <div class="mb-4">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200" for="draft-split-target-month">
                                {{ __('seo-content-ai::filament.projects.draft_split_target_month') }}
                            </label>
                            <x-select
                                id="draft-split-target-month"
                                wire:model.live="draftSplitTargetMonth"
                                size="inline"
                                class="min-w-[10rem] text-sm"
                                data-split-field="target_month"
                            >
                                @foreach ($this->getDraftSplitTargetMonthOptions() as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div class="cp-draft-split-layout">
                            <fieldset class="cp-draft-split-pane space-y-2" data-split-pane="items">
                                <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.projects.draft_split_items') }}
                                </legend>
                                <p class="text-xs text-gray-500 dark:text-gray-400" data-split-assign-count="1">
                                    {{ __('seo-content-ai::filament.projects.draft_split_will_assign', ['count' => $splitSelectedCount]) }}
                                </p>

                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 px-3 py-3 dark:border-white/10">
                                    <input type="radio" wire:model.live="draftSplitMode" value="first_n" class="mt-1" data-split-mode="first_n">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ __('seo-content-ai::filament.projects.draft_split_first_n', ['count' => max(1, (int) ($this->draftSplitQuantity ?? 30))]) }}
                                        </span>
                                        <span class="mt-2 flex items-center gap-2">
                                            <input
                                                type="number"
                                                min="1"
                                                max="{{ max(1, $draftItemCount) }}"
                                                wire:model.live.debounce.300ms="draftSplitQuantity"
                                                class="w-20 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                                                data-split-field="quantity"
                                                @disabled(($this->draftSplitMode ?? '') !== 'first_n')
                                            >
                                            <span class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_first_n_unit') }}</span>
                                        </span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 px-3 py-3 dark:border-white/10">
                                    <input type="radio" wire:model.live="draftSplitMode" value="all" class="mt-1" data-split-mode="all">
                                    <span>
                                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ __('seo-content-ai::filament.projects.draft_split_all', ['count' => $draftItemCount]) }}
                                        </span>
                                        <span class="mt-1 block text-xs text-gray-500">
                                            {{ __('seo-content-ai::filament.projects.draft_split_all_count', ['count' => $draftItemCount]) }}
                                        </span>
                                    </span>
                                </label>
                            </fieldset>

                            <fieldset class="cp-draft-split-pane space-y-2" data-split-pane="writers">
                                <legend class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.projects.draft_split_writers_heading', ['month' => $splitStartMonthLabel]) }}
                                </legend>

                                <div
                                    wire:loading.flex
                                    wire:target="draftSplitQuantity,draftSplitMode,draftSplitTargetMonth"
                                    class="cp-draft-split-writers min-h-[12rem] flex-col justify-center gap-1.5"
                                    data-split-writers-loading="1"
                                    aria-live="polite"
                                >
                                    <div class="h-8 w-full animate-pulse rounded-lg bg-gray-200 dark:bg-white/10"></div>
                                    <div class="h-8 w-full animate-pulse rounded-lg bg-gray-200 dark:bg-white/10"></div>
                                    <div class="h-8 w-11/12 animate-pulse rounded-lg bg-gray-200 dark:bg-white/10"></div>
                                    <span class="sr-only">{{ __('seo-content-ai::filament.projects.draft_split_preview_loading') }}</span>
                                </div>

                                {{-- Alpine local include/exclude — no Livewire remorph on X / Thêm lại (scroll-safe). --}}
                                <div
                                    wire:loading.remove
                                    wire:target="draftSplitQuantity,draftSplitMode,draftSplitTargetMonth"
                                    wire:ignore
                                    wire:key="cp-split-writers-{{ $this->draftSplitTargetMonth }}-{{ $this->draftSplitMode }}-{{ (int) $this->draftSplitQuantity }}"
                                    class="cp-draft-split-writers min-h-[12rem]"
                                    data-split-writers="1"
                                    x-data="cpDraftSplitWriters(@js([
                                        'writers' => $splitUi['writers'] ?? [],
                                        'includedIds' => array_values(array_map('intval', $this->draftSplitIncludedUserIds ?? [])),
                                        'labels' => [
                                            'existing' => (string) __('seo-content-ai::filament.projects.draft_split_existing'),
                                            'capacity' => (string) __('seo-content-ai::filament.projects.draft_split_capacity_label'),
                                            'remaining' => (string) __('seo-content-ai::filament.projects.draft_split_remaining'),
                                            'new' => (string) __('seo-content-ai::filament.projects.draft_split_new'),
                                            'projects' => (string) __('seo-content-ai::filament.projects.draft_split_projects_hint'),
                                            'result' => (string) __('seo-content-ai::filament.projects.draft_split_result_capacity'),
                                            'capacityZero' => (string) __('seo-content-ai::filament.projects.draft_split_capacity_zero'),
                                            'capacityFull' => (string) __('seo-content-ai::filament.projects.draft_split_capacity_exhausted'),
                                            'exclude' => (string) __('seo-content-ai::filament.projects.draft_split_exclude'),
                                            'addBack' => (string) __('seo-content-ai::filament.projects.draft_split_add_back'),
                                            'excludedHeading' => (string) __('seo-content-ai::filament.projects.draft_split_excluded_heading'),
                                            'noStaff' => (string) __('seo-content-ai::filament.projects.draft_split_no_staff'),
                                            'noWriters' => (string) __('seo-content-ai::filament.projects.draft_split_no_writers'),
                                        ],
                                    ]))"
                                >
                                    <template x-for="writer in includedWriters()" :key="'in-' + writer.id">
                                        <div
                                            class="cp-draft-split-writer-row"
                                            :class="{ 'is-capacity-limited': writer.capacity_zero || writer.capacity_full }"
                                            :data-split-writer="writer.id"
                                            data-split-writer-included="1"
                                        >
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="writer.name"></p>
                                                <div class="cp-draft-split-writer-metrics mt-1">
                                                    <span class="cp-draft-split-metric cp-draft-split-metric--existing" x-text="fmt(labels.existing, writer.current)"></span>
                                                    <span class="cp-draft-split-metric" x-text="fmt(labels.capacity, writer.capacity)"></span>
                                                    <template x-if="writer.capacity_zero">
                                                        <span class="cp-draft-split-metric text-amber-700 dark:text-amber-300" x-text="labels.capacityZero"></span>
                                                    </template>
                                                    <template x-if="!writer.capacity_zero && writer.capacity_full">
                                                        <span class="cp-draft-split-metric text-amber-700 dark:text-amber-300" x-text="labels.capacityFull"></span>
                                                    </template>
                                                    <template x-if="!writer.capacity_zero && !writer.capacity_full">
                                                        <span class="cp-draft-split-metric" x-text="fmt(labels.remaining, Math.max(0, writer.remaining))"></span>
                                                    </template>
                                                    <template x-if="!writer.capacity_zero && !writer.capacity_full">
                                                        <span class="cp-draft-split-metric cp-draft-split-metric--new">
                                                            <span x-text="fmt(labels.new, writer.new_allocation)"></span>
                                                            <span class="cp-draft-split-metric--projects" x-show="writer.project_count > 1" x-text="' · ' + fmt(labels.projects, writer.project_count)"></span>
                                                        </span>
                                                    </template>
                                                    <template x-if="!writer.capacity_zero && !writer.capacity_full">
                                                        <span class="cp-draft-split-metric cp-draft-split-metric--result" x-text="fmtResult(writer)"></span>
                                                    </template>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                class="cp-draft-split-exclude"
                                                @click="excludeWriter(writer.id)"
                                                :title="labels.exclude"
                                                :aria-label="labels.exclude"
                                                :data-split-exclude="writer.id"
                                            >
                                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    </template>

                                    <template x-if="writers.length === 0">
                                        <p class="text-xs text-gray-400" x-text="labels.noStaff"></p>
                                    </template>
                                    <template x-if="writers.length > 0 && includedWriters().length === 0 && excludedWriters().length === 0">
                                        <p class="text-xs text-amber-700 dark:text-amber-200" data-split-no-included="1" x-text="labels.noWriters"></p>
                                    </template>
                                    <template x-if="writers.length > 0 && includedWriters().length === 0 && excludedWriters().length > 0">
                                        <p class="text-xs text-amber-700 dark:text-amber-200" data-split-no-included="1" x-text="labels.noWriters"></p>
                                    </template>

                                    <template x-if="excludedWriters().length > 0">
                                        <div class="cp-draft-split-excluded" data-split-excluded="1">
                                            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400" x-text="labels.excludedHeading"></p>
                                            <template x-for="writer in excludedWriters()" :key="'ex-' + writer.id">
                                                <div
                                                    class="cp-draft-split-writer-row is-excluded"
                                                    :data-split-writer="writer.id"
                                                    data-split-writer-excluded="1"
                                                >
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-medium text-gray-500 dark:text-gray-400" x-text="writer.name"></p>
                                                        <div class="cp-draft-split-writer-metrics mt-1">
                                                            <span class="cp-draft-split-metric cp-draft-split-metric--existing" x-text="fmt(labels.existing, writer.current)"></span>
                                                            <span class="cp-draft-split-metric" x-text="fmt(labels.capacity, writer.capacity)"></span>
                                                            <template x-if="writer.capacity_zero">
                                                                <span class="cp-draft-split-metric text-amber-700 dark:text-amber-300" x-text="labels.capacityZero"></span>
                                                            </template>
                                                        </div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        class="cp-draft-split-add-back"
                                                        @click="includeWriter(writer.id)"
                                                        :data-split-add-back="writer.id"
                                                        :disabled="writer.capacity_zero"
                                                        x-text="labels.addBack"
                                                    ></button>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </fieldset>
                        </div>

                        @if ($splitSelectedCount > 0)
                            <div
                                class="mt-4 rounded-lg border border-gray-200 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/5"
                                wire:loading.class="opacity-50"
                                wire:target="draftSplitQuantity,draftSplitMode,draftSplitTargetMonth"
                                data-split-domain-summary="1"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.draft_split_summary_heading') }}
                                </p>
                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.projects.draft_split_summary_line', [
                                        'month' => $splitTargetMonthLabel,
                                        'selected' => $splitSelectedCount,
                                        'domains' => $splitDomainCount,
                                        'writers' => $splitWriterCount,
                                    ]) }}
                                </p>
                                @if ($splitDomainRows !== [])
                                    <p class="mt-3 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        {{ __('seo-content-ai::filament.projects.draft_split_domain_heading') }}
                                    </p>
                                    <ul class="mt-1.5 space-y-1">
                                        @foreach ($splitDomainRows as $domainRow)
                                            <li class="flex items-baseline justify-between gap-3 text-sm text-gray-800 dark:text-gray-100">
                                                <span class="min-w-0 truncate">{{ $domainRow['domain'] ?? '' }}</span>
                                                <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">
                                                    {{ (int) ($domainRow['count'] ?? 0) }}
                                                    <span class="text-xs text-gray-400">({{ number_format((float) ($domainRow['percent'] ?? 0), 1) }}%)</span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($splitDomainWarning)
                                    <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" data-split-domain-warning="1" role="status">
                                        <p class="font-semibold">
                                            {{ __('seo-content-ai::filament.projects.draft_split_domain_warning_title') }}
                                        </p>
                                        <p class="mt-1">
                                            {{ __('seo-content-ai::filament.projects.draft_split_domain_warning_body', [
                                                'domain' => (string) ($splitDomainWarning['domain'] ?? ''),
                                                'count' => (int) ($splitDomainWarning['count'] ?? 0),
                                                'total' => (int) ($splitDomainWarning['total'] ?? 0),
                                                'percent' => number_format((float) ($splitDomainWarning['percent'] ?? 0), 1),
                                                'per_day' => number_format((float) ($splitDomainWarning['articles_per_day'] ?? 0), 1),
                                                'month' => $splitTargetMonthLabel,
                                            ]) }}
                                        </p>
                                        <p class="mt-1 text-xs opacity-90">
                                            {{ __('seo-content-ai::filament.projects.draft_split_domain_warning_hint') }}
                                        </p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="cp-ops-dialog__footer flex items-center justify-end gap-2">
                        <button type="button" class="fi-btn fi-btn-color-gray fi-size-sm" wire:click="closeDraftSplitModal" data-split-cancel="1">
                            {{ __('seo-content-ai::filament.projects.planner_close') }}
                        </button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-color-primary fi-size-sm"
                            wire:click="confirmDraftSplit"
                            wire:loading.attr="disabled"
                            wire:target="confirmDraftSplit"
                            data-split-submit="1"
                            @disabled(! $splitCanCreate)
                        >
                            <span wire:loading.remove wire:target="confirmDraftSplit">{{ __('seo-content-ai::filament.projects.draft_split_create') }}</span>
                            <span wire:loading wire:target="confirmDraftSplit" class="inline-flex items-center gap-1">
                                <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif

</div>

@once
    <script>
        window.cpDraftSplitWriters = window.cpDraftSplitWriters || function (boot) {
            const cfg = boot && typeof boot === 'object' ? boot : {};
            const writers = (Array.isArray(cfg.writers) ? cfg.writers : []).map((row) => {
                const capacity = Math.max(0, Number(row.capacity || 0));
                const current = Number(row.current || 0);
                const remaining = Number(row.remaining != null ? row.remaining : (capacity - current));

                return {
                    id: Number(row.id || 0),
                    name: String(row.name || ''),
                    current,
                    capacity,
                    remaining,
                    new_allocation: Number(row.new_allocation || 0),
                    resulting: Number(row.resulting != null ? row.resulting : (current + Number(row.new_allocation || 0))),
                    project_count: Number(row.project_count || 0),
                    capacity_zero: !!row.capacity_zero || capacity === 0,
                    capacity_full: !!row.capacity_full || (capacity > 0 && remaining <= 0),
                };
            }).filter((row) => row.id > 0);

            return {
                writers,
                includedIds: (Array.isArray(cfg.includedIds) ? cfg.includedIds : [])
                    .map((id) => Number(id))
                    .filter((id) => id > 0),
                labels: cfg.labels || {},
                syncTimer: null,

                includedWriters() {
                    const set = new Set(this.includedIds);

                    return this.writers.filter((w) => set.has(w.id));
                },
                excludedWriters() {
                    const set = new Set(this.includedIds);

                    return this.writers.filter((w) => ! set.has(w.id));
                },
                fmt(template, count) {
                    return String(template || ':count').replace(':count', String(count));
                },
                fmtResult(writer) {
                    return String(this.labels.result || 'Result: :result / :capacity')
                        .replace(':result', String(writer.resulting))
                        .replace(':capacity', String(writer.capacity));
                },
                excludeWriter(id) {
                    const n = Number(id);
                    if (n <= 0) {
                        return;
                    }
                    this.includedIds = this.includedIds.filter((x) => x !== n);
                    this.scheduleSync();
                },
                includeWriter(id) {
                    const n = Number(id);
                    if (n <= 0) {
                        return;
                    }
                    const hit = this.writers.find((w) => w.id === n);
                    if (! hit || hit.capacity_zero) {
                        return;
                    }
                    if (! this.includedIds.includes(n)) {
                        this.includedIds = this.writers
                            .map((w) => w.id)
                            .filter((wid) => wid === n || this.includedIds.includes(wid));
                    }
                    this.scheduleSync();
                },
                scheduleSync() {
                    if (this.syncTimer) {
                        clearTimeout(this.syncTimer);
                    }
                    this.syncTimer = setTimeout(() => this.syncToWire(), 0);
                },
                syncToWire() {
                    if (! this.$wire || typeof this.$wire.set !== 'function') {
                        return;
                    }
                    const scroller = this.$el && this.$el.closest
                        ? this.$el.closest('.cp-ops-dialog__scroll')
                        : null;
                    const top = scroller ? scroller.scrollTop : 0;
                    const restore = () => {
                        if (scroller) {
                            scroller.scrollTop = top;
                        }
                    };
                    const result = this.$wire.set('draftSplitIncludedUserIds', this.includedIds.slice());
                    if (result && typeof result.then === 'function') {
                        result.then(restore).catch(restore);
                    } else {
                        this.$nextTick(restore);
                        setTimeout(restore, 0);
                        setTimeout(restore, 50);
                    }
                },
            };
        };
    </script>
@endonce
