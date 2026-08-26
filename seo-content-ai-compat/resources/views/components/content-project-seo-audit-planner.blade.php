@props([
    'showDraftSelector' => false,
    'showFullPlannerLink' => false,
])

@php
    /** @var \Livewire\Component $this */
    $payload = $this->suggestionsPayload;
    $rows = $payload['rows'] ?? [];
    $paginator = $payload['paginator'] ?? null;
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $hasProject = (bool) ($payload['has_project'] ?? false);
    $isDraft = (bool) ($payload['is_draft'] ?? false);
    $issueOptions = $payload['issue_options'] ?? [];
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $selectedIds = array_map('intval', $this->selectedSuggestionArticleIds ?? []);
    $selectedLookup = array_fill_keys($selectedIds, true);
    $scoreMax = $this->suggestionScoreMax;
    $scorePreset = ($scoreMax === null || $scoreMax === '') ? 'all' : (string) (int) $scoreMax;
    $stateFilter = (string) ($this->suggestionStateFilter ?? 'available');
    $primaryConfigured = (bool) ($payload['primary_configured'] ?? false);
    $primaryLanguageLabel = $payload['primary_language_label'] ?? null;
    $domainEditUrl = $payload['domain_edit_url'] ?? null;
    $draftOptions = $showDraftSelector ? ($this->draftProjectOptions ?? []) : [];
    $siteOptions = $showDraftSelector ? ($this->siteFilterOptions ?? []) : [];
    $filterOptions = $this->suggestionFilterOptions ?? ['post_types' => [], 'taxonomies' => [], 'terms' => []];
@endphp

<div
    class="space-y-4"
    wire:key="cp-seo-audit-planner"
    x-data="{ filtersOpen: false }"
>
    <x-seo-content-ai::content-project-ops-styles />

    @if ($showDraftSelector)
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[12rem]">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.projects.seo_audit_site_label') }}
                    </label>
                    <x-select wire:model.live="filterSiteId" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.seo_audit_site_label') }}">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_site_all') }}</option>
                        @foreach ($siteOptions as $id => $domain)
                            <option value="{{ $id }}">{{ $domain }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="min-w-[16rem] flex-1">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.projects.seo_audit_draft_selector_label') }}
                    </label>
                    <x-select wire:model.live="projectId" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.seo_audit_draft_selector_label') }}">
                        <option value="">{{ __('seo-content-ai::filament.projects.seo_audit_draft_selector_placeholder') }}</option>
                        @foreach ($draftOptions as $opt)
                            <option value="{{ $opt['id'] }}">
                                {{ $opt['name'] }}@if (($opt['domain'] ?? '') !== '') — {{ $opt['domain'] }}@endif
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <button
                    type="button"
                    wire:click="createDraftForPlanner"
                    wire:loading.attr="disabled"
                    class="fi-btn fi-btn-color-primary fi-size-sm inline-flex items-center gap-1"
                >
                    {{ __('seo-content-ai::filament.projects.seo_audit_create_draft') }}
                </button>
            </div>
            @if (! $hasProject)
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.projects.seo_audit_draft_empty') }}
                </p>
            @endif
        </div>
    @endif

    @if ($showFullPlannerLink && $hasProject)
        <div class="flex justify-end">
            <a
                href="{{ \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl(['project' => (int) ($this->project?->getKey() ?? 0)]) }}"
                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ __('seo-content-ai::filament.projects.seo_audit_open_full_planner') }}
            </a>
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                {{ __('seo-content-ai::filament.projects.seo_audit_advanced_heading') }}
            </h2>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.projects.suggestions_existing_help') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-white/10 dark:bg-gray-900">
                <x-select
                    wire:model.live="fillLimit"
                    wrapClass="cp-ops-select"
                    aria-label="{{ __('seo-content-ai::filament.projects.suggestions_fill_limit') }}"
                    :disabled="! $canWrite"
                >
                    <option value="10">10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="all">{{ __('seo-content-ai::filament.projects.suggestions_fill_all') }}</option>
                </x-select>
                <button
                    type="button"
                    wire:click="fillSuggestions"
                    wire:loading.attr="disabled"
                    wire:target="fillSuggestions"
                    @disabled(! $canWrite)
                    @class([
                        'fi-btn fi-btn-color-primary fi-size-sm inline-flex items-center gap-1',
                        'opacity-50 pointer-events-none' => ! $canWrite,
                    ])
                >
                    <span wire:loading.remove wire:target="fillSuggestions">{{ __('seo-content-ai::filament.projects.suggestions_fill') }}</span>
                    <span wire:loading wire:target="fillSuggestions" class="inline-flex items-center gap-1">
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    </span>
                </button>
            </div>
        </div>
    </div>

    @if ($hasProject && ! $isDraft)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
            <p class="font-medium">{{ __('seo-content-ai::filament.projects.suggestions_readable_banner') }}</p>
            <p class="mt-1 text-amber-800/90 dark:text-amber-100/80">{{ __('seo-content-ai::filament.projects.suggestions_draft_required_body') }}</p>
        </div>
    @endif

    @if ($this->suggestionsLastResult !== '')
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-white/10 dark:bg-gray-900/40 dark:text-gray-300">
            {{ $this->suggestionsLastResult }}
        </div>
    @endif

    @if ($hasProject)
        <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600 dark:text-gray-300">
            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">
                <span class="font-medium tabular-nums">{{ (int) ($summary['matched'] ?? 0) }}</span>
                {{ __('seo-content-ai::filament.projects.seo_audit_summary_matched') }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 dark:bg-gray-800">
                <span class="font-medium tabular-nums">{{ (int) ($summary['selected'] ?? 0) }}</span>
                {{ __('seo-content-ai::filament.projects.seo_audit_summary_selected') }}
            </span>
            @if ($isDraft)
                <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-1 font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-400/30">
                    {{ __('seo-content-ai::filament.projects.suggestions_draft_badge') }}
                </span>
            @endif
        </div>
    @endif

    {{-- Row 1: search · score · language · issue · plan · state --}}
    <div class="cp-ops-toolbar space-y-2" data-seo-audit-filter-rows="2">
        <div class="cp-ops-toolbar__row flex flex-wrap items-center gap-2" data-seo-audit-filter-row="1">
            <input
                type="search"
                wire:model.live.debounce.300ms="suggestionSearch"
                placeholder="{{ __('seo-content-ai::filament.projects.suggestions_filter_search') }}"
                class="fi-input cp-ops-toolbar__search"
                aria-label="{{ __('seo-content-ai::filament.projects.suggestions_filter_search') }}"
                data-filter="search"
            />

            <div class="inline-flex items-center gap-0.5 rounded-lg border border-gray-200 bg-white p-0.5 dark:border-white/10 dark:bg-gray-900" role="group" data-filter="score">
                @foreach (['all' => __('seo-content-ai::filament.projects.seo_audit_score_all'), '80' => '<80', '60' => '<60', '40' => '<40'] as $presetKey => $presetLabel)
                    <button
                        type="button"
                        wire:click="setSuggestionScorePreset('{{ $presetKey }}')"
                        @class([
                            'rounded-md px-2 py-1 text-xs font-medium transition',
                            'bg-primary-600 text-white' => $scorePreset === $presetKey,
                            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $scorePreset !== $presetKey,
                        ])
                    >
                        {{ $presetLabel }}
                    </button>
                @endforeach
            </div>

            <x-select wire:model.live="suggestionLanguageScope" wrapClass="cp-ops-select" data-filter="language" aria-label="{{ __('seo-content-ai::filament.projects.suggestions_filter_language') }}">
                <option value="primary">
                    @if (is_string($primaryLanguageLabel) && $primaryLanguageLabel !== '')
                        {{ __('seo-content-ai::filament.projects.suggestions_filter_language_primary', ['label' => $primaryLanguageLabel]) }}
                    @else
                        {{ __('seo-content-ai::filament.projects.suggestions_filter_language_primary_fallback') }}
                    @endif
                </option>
                <option value="all">{{ __('seo-content-ai::filament.projects.suggestions_filter_language_all') }}</option>
            </x-select>

            <x-select wire:model.live="suggestionIssueKey" wrapClass="cp-ops-select" data-filter="issue" aria-label="{{ __('seo-content-ai::filament.projects.suggestions_filter_issue') }}">
                <option value="">{{ __('seo-content-ai::filament.projects.suggestions_filter_issue_all') }}</option>
                @foreach ($issueOptions as $opt)
                    <option value="{{ $opt['key'] }}">{{ $opt['label'] }}</option>
                @endforeach
            </x-select>

            <x-select wire:model.live="suggestedAction" wrapClass="cp-ops-select" data-filter="action" aria-label="{{ __('seo-content-ai::filament.projects.suggestions_filter_action') }}">
                <option value="">{{ __('seo-content-ai::filament.projects.suggestions_filter_action_all') }}</option>
                <option value="rewrite">Rewrite</option>
                <option value="improve">Improve</option>
            </x-select>

            <x-select wire:model.live="suggestionStateFilter" wrapClass="cp-ops-select" data-filter="state" aria-label="{{ __('seo-content-ai::filament.projects.seo_audit_state_filter') }}">
                <option value="available">{{ __('seo-content-ai::filament.projects.suggestions_state_available') }}</option>
                <option value="dismissed">{{ __('seo-content-ai::filament.projects.suggestions_state_dismissed') }}</option>
                <option value="planned">{{ __('seo-content-ai::filament.projects.suggestions_state_planned') }}</option>
            </x-select>
        </div>

        {{-- Row 2: post type · taxonomy · term --}}
        <div class="cp-ops-toolbar__row flex flex-wrap items-center gap-2" data-seo-audit-filter-row="2">
            <x-select wire:model.live="suggestionPostTypeMode" wrapClass="cp-ops-select" data-filter="post_type" aria-label="{{ __('seo-content-ai::filament.article_list.post_type') }}">
                <option value="all_except_page">{{ __('seo-content-ai::filament.projects.planner_post_type_except_page') }}</option>
                <option value="all">{{ __('seo-content-ai::filament.article_list.all_post_types') }}</option>
                <option value="specific">{{ __('seo-content-ai::filament.projects.planner_post_type_specific') }}</option>
            </x-select>
            @if (($this->suggestionPostTypeMode ?? '') === 'specific')
                <x-select wire:model.live="suggestionPostType" wrapClass="cp-ops-select" aria-label="Post type value">
                    <option value="">{{ __('seo-content-ai::filament.article_list.all_post_types') }}</option>
                    @foreach (($filterOptions['post_types'] ?? []) as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            @endif

            <x-select wire:model.live="suggestionTaxonomy" wrapClass="cp-ops-select" data-filter="taxonomy" aria-label="{{ __('seo-content-ai::filament.article_list.taxonomy') }}">
                <option value="">{{ __('seo-content-ai::filament.article_list.all_taxonomies') }}</option>
                @foreach (($filterOptions['taxonomies'] ?? []) as $slug => $label)
                    <option value="{{ $slug }}">{{ $label }}</option>
                @endforeach
            </x-select>

            <x-select wire:model.live="suggestionTermId" wrapClass="cp-ops-select" data-filter="term" aria-label="Term" :disabled="($this->suggestionTaxonomy ?? '') === ''">
                <option value="">{{ __('seo-content-ai::filament.projects.planner_term_all') }}</option>
                @foreach (($filterOptions['terms'] ?? []) as $term)
                    <option value="{{ $term['id'] }}">{{ $term['label'] }}</option>
                @endforeach
            </x-select>

            <button type="button" wire:click="selectAllVisibleSuggestions" class="cp-ops-toolbar__link">
                {{ __('seo-content-ai::filament.projects.suggestions_select_visible') }}
            </button>
            @if (count($selectedIds) > 0)
                <button type="button" wire:click="clearSuggestionSelection" class="cp-ops-toolbar__link">
                    {{ __('seo-content-ai::filament.projects.suggestions_clear_selection') }}
                </button>
            @endif
        </div>
    </div>

    @if (count($selectedIds) > 0)
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-gray-900/40">
            <span class="text-xs text-gray-500">{{ count($selectedIds) }} selected</span>
            <button type="button" wire:click="addSelectedSuggestions" wire:loading.attr="disabled" @disabled(! $canWrite) @class(['fi-btn fi-btn-color-success fi-size-sm', 'opacity-50 pointer-events-none' => ! $canWrite])>
                {{ __('seo-content-ai::filament.projects.suggestions_add_to_draft') }}
            </button>
            <button type="button" wire:click="dismissSelectedSuggestions" wire:loading.attr="disabled" @disabled(! $canWrite) @class(['fi-btn fi-btn-color-gray fi-size-sm', 'opacity-50 pointer-events-none' => ! $canWrite])>
                {{ __('seo-content-ai::filament.projects.suggestions_dismiss_selected') }}
            </button>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
                    <tr>
                        <th class="w-10 px-3 py-2"></th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_article') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_seo') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_issues') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_plan') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_check_index') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_state') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.suggestions_col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($rows as $row)
                        @php
                            $articleId = (int) ($row['article_id'] ?? 0);
                            $state = (string) ($row['state'] ?? '');
                            $addDisabled = (bool) ($row['add_disabled'] ?? false);
                            $isDismissed = $state === 'dismissed';
                            $checked = isset($selectedLookup[$articleId]);
                            $actionValue = (string) ($this->suggestionActionByArticle[$articleId] ?? $row['suggested_action'] ?? 'improve');
                            $issues = is_array($row['issues'] ?? null) ? $row['issues'] : [];
                            $score = $row['seo_score'] ?? null;
                            $checkUrl = (string) ($row['check_index_url'] ?? '');
                            $editUrl = (string) ($row['edit_url'] ?? '');
                            $publicUrl = (string) ($row['public_url'] ?? $row['permalink'] ?? $row['url'] ?? '');
                            $titleHref = $publicUrl !== '' ? $publicUrl : $editUrl;
                            $title = (string) ($row['title'] ?? ('#'.$articleId));
                        @endphp
                        <tr wire:key="suggestion-row-{{ $articleId }}" @class(['bg-gray-50/60 dark:bg-gray-800/30' => $isDismissed])>
                            <td class="px-3 py-2 align-top">
                                @unless ($addDisabled)
                                    <input type="checkbox" @checked($checked) wire:click="toggleSuggestionSelection({{ $articleId }})" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                                @endunless
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="min-w-0">
                                    @if ($titleHref !== '')
                                        <a href="{{ $titleHref }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400" target="_blank" rel="noopener" data-title-link="{{ $publicUrl !== '' ? 'public' : 'editor' }}">
                                            {{ $title }}
                                        </a>
                                    @else
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $title }}</span>
                                    @endif
                                    @if ($editUrl !== '' && $publicUrl !== '')
                                        <a href="{{ $editUrl }}" target="_blank" rel="noopener" class="mt-0.5 block text-[11px] text-gray-500 hover:underline">
                                            {{ __('seo-content-ai::filament.projects.item_action_open_article') }}
                                        </a>
                                    @endif
                                    @if (! empty($row['recommendation_summary']))
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['recommendation_summary'] }}</p>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 align-top tabular-nums">{{ $score === null ? '—' : $score }}</td>
                            <td class="px-3 py-2 align-top">
                                @if ($issues === [])
                                    <span class="text-xs text-gray-400">—</span>
                                @else
                                    <div class="flex max-w-xs flex-wrap gap-1">
                                        @foreach (array_slice($issues, 0, 4) as $issue)
                                            <span class="inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $issue['label'] ?? $issue['key'] ?? '' }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if ($isDismissed)
                                    <button type="button" wire:click="restoreSuggestion({{ $articleId }})" @disabled(! $canWrite) @class(['fi-btn fi-btn-color-gray fi-size-sm', 'opacity-50 pointer-events-none' => ! $canWrite])>
                                        {{ __('seo-content-ai::filament.projects.suggestions_restore') }}
                                    </button>
                                @else
                                    <select class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" wire:change="setSuggestionAction({{ $articleId }}, $event.target.value)" @disabled($addDisabled || ! $canWrite)>
                                        <option value="rewrite" @selected($actionValue === 'rewrite')>Rewrite</option>
                                        <option value="improve" @selected($actionValue === 'improve')>Improve</option>
                                    </select>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if ($checkUrl !== '')
                                    <a href="{{ $checkUrl }}" target="_blank" rel="noopener" class="text-xs text-primary-600 hover:underline dark:text-primary-400">{{ __('seo-content-ai::filament.projects.suggestions_check_index') }}</a>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                @php
                                    $stateLabel = match ($state) {
                                        'available' => __('seo-content-ai::filament.projects.suggestions_state_available'),
                                        'already_planned' => __('seo-content-ai::filament.projects.suggestions_state_planned'),
                                        'planned_other_project' => __('seo-content-ai::filament.projects.suggestions_state_planned_other', ['name' => (string) ($row['planned_project_name'] ?? '')]),
                                        'dismissed' => __('seo-content-ai::filament.projects.suggestions_state_dismissed'),
                                        default => $state,
                                    };
                                @endphp
                                <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $stateLabel }}</span>
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="flex flex-col gap-1 text-xs">
                                    @if (! $addDisabled && $canWrite)
                                        <button type="button" class="text-left text-primary-600 hover:underline" wire:click="addOneSuggestion({{ $articleId }})">
                                            {{ __('seo-content-ai::filament.projects.suggestions_add_to_draft') }}
                                        </button>
                                    @endif
                                    @if ($canWrite && ! $isDismissed)
                                        <button type="button" class="text-left text-gray-600 hover:underline dark:text-gray-300" wire:click="dismissSuggestion({{ $articleId }})">
                                            {{ __('seo-content-ai::filament.projects.suggestions_dismiss_one') }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        class="text-left text-danger-600 hover:underline dark:text-danger-400"
                                        wire:click="skipSuggestionFromSeoAudit({{ $articleId }})"
                                        wire:confirm="{{ __('seo-content-ai::filament.projects.seo_audit_skip_confirm') }}"
                                    >
                                        {{ __('seo-content-ai::filament.article_list.skip_seo_audit') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                @if (! $hasProject)
                                    {{ __('seo-content-ai::filament.projects.seo_audit_draft_empty') }}
                                @else
                                    {{ __('seo-content-ai::filament.projects.suggestions_empty') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($paginator && count($rows) > 0)
        <div class="mt-2">{{ $paginator->links() }}</div>
    @endif
</div>
