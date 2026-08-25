@props([
    'showProjectActions' => true,
    'advancedUrl' => null,
])

@php
    /** @var \Livewire\Component $this */
    $card = $this->seoAuditPlannerCardState ?? [];
    $canWrite = (bool) ($card['can_write'] ?? false);
    $primaryLanguageLabel = $card['primary_language_label'] ?? null;
    $history = $this->seoAuditFilterHistory ?? [];
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
    $resolvedAdvancedUrl = is_string($advancedUrl) && $advancedUrl !== ''
        ? $advancedUrl
        : \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl(array_filter([
            'project' => (int) ($this->project?->getKey() ?? 0) ?: null,
            'advanced' => 1,
        ]));
    $splitUi = method_exists($this, 'draftSplitUiState') ? $this->draftSplitUiState() : ['count' => 0, 'selected' => 0, 'month_options' => []];
    $draftItemCount = (int) ($splitUi['count'] ?? 0);
    $selectedCount = (int) ($splitUi['selected'] ?? 0);
    $monthOptions = is_array($splitUi['month_options'] ?? null) ? $splitUi['month_options'] : [];
@endphp

<div
    class="space-y-4"
    wire:key="cp-draft-content-planner"
    x-data="{ filtersOpen: false, historyOpen: false }"
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

    <div class="cp-plan-grid">
        {{-- Improve existing --}}
        <div class="cp-plan-card cp-plan-card--improve" data-planner-card="improve">
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

            <div class="cp-plan-meta">
                <div class="cp-plan-chips">
                    <span class="cp-plan-chips__label">{{ __('seo-content-ai::filament.projects.content_planning_recent_filters') }}</span>
                    @foreach ($visibleChips as $chip)
                        <span class="cp-plan-chip">{{ $chip }}</span>
                    @endforeach
                    @if ($extraChipCount > 0)
                        <span class="cp-plan-chip">+{{ $extraChipCount }}</span>
                    @endif
                    <button type="button" class="cp-plan-link cp-plan-link--improve" @click="historyOpen = true">
                        {{ __('seo-content-ai::filament.projects.planner_history') }}
                    </button>
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

                <div x-show="filtersOpen" x-cloak class="cp-plan-filters-panel space-y-3" data-planner-filters="improve">
                    <div>
                        <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_filter_score_max') }}</label>
                        <div class="inline-flex items-center gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5 dark:border-white/10 dark:bg-gray-900">
                            @foreach (['all' => __('seo-content-ai::filament.projects.seo_audit_score_all'), '80' => '<80', '60' => '<60', '40' => '<40'] as $presetKey => $presetLabel)
                                <button type="button" wire:click="setSuggestionScorePreset('{{ $presetKey }}')" @class(['rounded-md px-2 py-1 text-xs font-medium', 'bg-emerald-600 text-white' => $scorePreset === $presetKey, 'text-gray-600' => $scorePreset !== $presetKey])>
                                    {{ $presetLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_filter_issue') }}</label>
                        <x-select wire:model.live="suggestionIssueKey" wrapClass="cp-ops-select">
                            <option value="">{{ __('seo-content-ai::filament.projects.suggestions_filter_issue_all') }}</option>
                            @foreach ($issueOptions as $def)
                                <option value="{{ $def['key'] ?? '' }}">{{ $def['label'] ?? ($def['key'] ?? '') }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div>
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

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_taxonomy') }}</label>
                            <x-select wire:model.live="suggestionTaxonomy" wrapClass="cp-ops-select">
                                <option value="">{{ __('seo-content-ai::filament.article_list.all_taxonomies') }}</option>
                                @foreach (($filterOptions['taxonomies'] ?? []) as $slug => $label)
                                    <option value="{{ $slug }}">{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_term_all') }}</label>
                            <x-select wire:model.live="suggestionTermId" wrapClass="cp-ops-select" :disabled="($this->suggestionTaxonomy ?? '') === ''">
                                <option value="">{{ __('seo-content-ai::filament.projects.planner_term_all') }}</option>
                                @foreach (($filterOptions['terms'] ?? []) as $term)
                                    <option value="{{ $term['id'] }}">{{ $term['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
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
                        <div>
                            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.suggestions_col_state') }}</label>
                            <x-select wire:model.live="suggestionStateFilter" wrapClass="cp-ops-select">
                                <option value="available">{{ __('seo-content-ai::filament.projects.suggestions_state_available') }}</option>
                            </x-select>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" class="fi-btn fi-btn-color-gray fi-size-sm" wire:click="saveSeoAuditFilters">
                            {{ __('seo-content-ai::filament.projects.planner_save_filters') }}
                        </button>
                    </div>
                </div>
            </div>

            <a href="{{ $resolvedAdvancedUrl }}" class="cp-plan-advanced" data-advanced-seo-audit="1">
                {{ __('seo-content-ai::filament.projects.content_planning_open_advanced') }}
            </a>
        </div>

        <x-seo-content-ai::content-project-new-content-card />
    </div>

    {{-- Split modal --}}
    @if ($this->draftSplitModalOpen ?? false)
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 p-4" wire:key="cp-draft-split-modal" data-split-modal="1">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" wire:click.stop>
                <h3 class="text-base font-semibold">{{ __('seo-content-ai::filament.projects.draft_split_modal_title') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_modal_help') }}</p>

                <div class="mt-4 space-y-3">
                    <p class="text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_items') }}</p>

                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <input type="radio" wire:model.live="draftSplitMode" value="first_n" class="mt-1" data-split-mode="first_n">
                        <span>
                            <span class="block text-sm font-medium">{{ __('seo-content-ai::filament.projects.draft_split_first_n') }}</span>
                            <span class="mt-2 block">
                                <input
                                    type="number"
                                    min="1"
                                    max="{{ max(1, $draftItemCount) }}"
                                    wire:model="draftSplitQuantity"
                                    class="w-24 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                                    @disabled(($this->draftSplitMode ?? '') !== 'first_n')
                                >
                            </span>
                        </span>
                    </label>

                    @if ($selectedCount > 0)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <input type="radio" wire:model.live="draftSplitMode" value="selected" class="mt-1" data-split-mode="selected">
                            <span>
                                <span class="block text-sm font-medium">{{ __('seo-content-ai::filament.projects.draft_split_selected') }}</span>
                                <span class="mt-1 block text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_selected_count', ['count' => $selectedCount]) }}</span>
                            </span>
                        </label>
                    @endif

                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <input type="radio" wire:model.live="draftSplitMode" value="all" class="mt-1" data-split-mode="all">
                        <span>
                            <span class="block text-sm font-medium">{{ __('seo-content-ai::filament.projects.draft_split_all') }}</span>
                            <span class="mt-1 block text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_all_count', ['count' => $draftItemCount]) }}</span>
                        </span>
                    </label>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_month') }}</label>
                        <x-select wire:model="draftSplitMonth" wrapClass="cp-ops-select" data-split-field="month">
                            @foreach ($monthOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.projects.draft_split_project_name') }}</label>
                        <input
                            type="text"
                            wire:model="draftSplitName"
                            class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            data-split-field="name"
                        >
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="fi-btn fi-btn-color-gray fi-size-sm" wire:click="closeDraftSplitModal">
                        {{ __('seo-content-ai::filament.projects.planner_close') }}
                    </button>
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-primary fi-size-sm"
                        wire:click="confirmDraftSplit"
                        wire:loading.attr="disabled"
                        wire:target="confirmDraftSplit"
                        data-split-submit="1"
                    >
                        <span wire:loading.remove wire:target="confirmDraftSplit">{{ __('seo-content-ai::filament.projects.draft_split_create') }}</span>
                        <span wire:loading wire:target="confirmDraftSplit" class="inline-flex items-center gap-1">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- History drawer --}}
    <div x-show="historyOpen" x-cloak class="fixed inset-0 z-[70] flex justify-end bg-black/40" @keydown.escape.window="historyOpen = false">
        <div class="h-full w-full max-w-md overflow-y-auto bg-white p-5 shadow-xl dark:bg-gray-900" @click.outside="historyOpen = false">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-base font-semibold">{{ __('seo-content-ai::filament.projects.planner_seo_audit_history') }}</h3>
                <button type="button" class="text-sm text-gray-500" @click="historyOpen = false">×</button>
            </div>
            <div class="space-y-3">
                @forelse ($history as $item)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <p class="text-xs text-gray-500">{{ $item['created_at'] }}</p>
                        <p class="mt-1 text-sm font-medium">
                            {{ __('seo-content-ai::filament.projects.planner_history_counts', ['requested' => $item['requested'], 'added' => $item['added']]) }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $item['label'] }}</p>
                        <div class="mt-2 flex gap-2">
                            <button type="button" class="fi-btn fi-btn-color-gray fi-size-sm" wire:click="loadSeoAuditFilterHistory({{ (int) $item['id'] }})" @click="historyOpen = false">
                                {{ __('seo-content-ai::filament.projects.planner_load_filters') }}
                            </button>
                            <button type="button" class="fi-btn fi-btn-color-primary fi-size-sm" wire:click="runSeoAuditFilterHistory({{ (int) $item['id'] }})" @click="historyOpen = false" @disabled(! $canWrite)>
                                {{ __('seo-content-ai::filament.projects.planner_run_again') }}
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.planner_history_empty') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
