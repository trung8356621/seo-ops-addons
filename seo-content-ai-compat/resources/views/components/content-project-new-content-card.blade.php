@php
    /** @var \Livewire\Component $this */
    $payload = $this->newContentPlannerPayload ?? [];
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $isGenerating = (bool) ($payload['is_generating'] ?? false);
    $primaryConfigured = (bool) ($payload['primary_configured'] ?? false);
    $primaryLanguageLabel = $payload['primary_language_label'] ?? null;
    $history = $this->newContentHistory ?? [];
    $viewResults = $this->newContentViewResults;
    $lastResult = (string) ($payload['last_result'] ?? $this->newContentLastResult ?? '');
    $planningPreview = $this->newContentPlanningPreview ?? null;

    $recentChips = [];
    if (trim((string) ($this->newContentFocus ?? '')) !== '') {
        $recentChips[] = (string) __('seo-content-ai::filament.projects.content_planning_chip_topic', ['topic' => $this->newContentFocus]);
    }
    if (is_array($planningPreview)) {
        $recentChips[] = (string) __('seo-content-ai::filament.projects.content_planning_chip_intelligence');
        $kw = (int) ($planningPreview['principal_keywords_count'] ?? 0);
        $clusters = (int) ($planningPreview['cluster_count'] ?? 0);
        if ($kw > 0 || $clusters > 0) {
            $recentChips[] = (string) __('seo-content-ai::filament.projects.content_planning_chip_kw_clusters', [
                'keywords' => $kw,
                'clusters' => $clusters,
            ]);
        }
    }
    if ($recentChips === []) {
        $recentChips[] = (string) __('seo-content-ai::filament.projects.planner_direction_automatic');
    }
    $visibleChips = array_slice($recentChips, 0, 3);
    $extraChipCount = max(0, count($recentChips) - count($visibleChips));
@endphp

<div
    class="cp-plan-card cp-plan-card--create"
    wire:key="cp-new-content-card"
    data-planner-card="new-content"
    x-data="{ optionsOpen: false, historyOpen: false, resultsOpen: false }"
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

    <div class="cp-plan-action-row">
        <div class="cp-plan-qty">
            <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_quantity') }}</label>
            <x-select wire:model.live="newContentQuantity" wrapClass="cp-ops-select" :disabled="! $canWrite || $isGenerating">
                <option value="10">10</option>
                <option value="20">20</option>
                <option value="50">50</option>
            </x-select>
        </div>
        <button
            type="button"
            wire:click="generateNewContentSuggestions"
            wire:loading.attr="disabled"
            wire:target="generateNewContentSuggestions"
            @disabled(! $canWrite || $isGenerating)
            @class(['cp-plan-btn cp-plan-btn--create', 'is-disabled' => ! $canWrite || $isGenerating])
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
    </div>

    <div class="cp-plan-meta">
        <div class="cp-plan-chips">
            <span class="cp-plan-chips__label">{{ __('seo-content-ai::filament.projects.content_planning_recent_runs') }}</span>
            @foreach ($visibleChips as $chip)
                <span class="cp-plan-chip">{{ $chip }}</span>
            @endforeach
            @if ($extraChipCount > 0)
                <span class="cp-plan-chip">+{{ $extraChipCount }}</span>
            @endif
            <button type="button" class="cp-plan-link cp-plan-link--create" @click="historyOpen = true">
                {{ __('seo-content-ai::filament.projects.planner_history') }}
            </button>
        </div>

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

        <button
            type="button"
            class="cp-plan-filters-toggle"
            @click="optionsOpen = !optionsOpen"
            data-planner-filters-toggle="new-content"
        >
            <span class="sr-only">{{ __('seo-content-ai::filament.projects.planner_options') }}</span>
            {{ __('seo-content-ai::filament.projects.planner_filters') }}
            <svg class="h-3.5 w-3.5 transition" :class="optionsOpen && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>
        </button>

        <div x-show="optionsOpen" x-cloak class="cp-plan-filters-panel space-y-3" data-planner-filters="new-content">
            <div>
                <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_direction') }}</label>
                <x-select wire:model="newContentDirection" wrapClass="cp-ops-select">
                    <option value="automatic">{{ __('seo-content-ai::filament.projects.planner_direction_automatic') }}</option>
                    <option value="seasonal">{{ __('seo-content-ai::filament.projects.planner_direction_seasonal') }}</option>
                    <option value="evergreen">{{ __('seo-content-ai::filament.projects.planner_direction_evergreen') }}</option>
                </x-select>
            </div>
            <div>
                <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_focus') }}</label>
                <input type="text" wire:model="newContentFocus" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950" placeholder="{{ __('seo-content-ai::filament.projects.planner_focus_placeholder') }}" />
            </div>
            <div>
                <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_post_type') }}</label>
                <x-select wire:model="newContentPostType" wrapClass="cp-ops-select">
                    <option value="article">article / post</option>
                    <option value="product">product</option>
                </x-select>
            </div>
            <div>
                <label class="cp-plan-qty__label">{{ __('seo-content-ai::filament.projects.planner_taxonomy') }}</label>
                <input type="text" wire:model="newContentTaxonomy" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950" />
            </div>
            <div class="flex justify-end">
                <button type="button" class="fi-btn fi-btn-color-gray fi-size-sm" wire:click="saveNewContentOptions">
                    {{ __('seo-content-ai::filament.projects.planner_save_options') }}
                </button>
            </div>
        </div>
    </div>

    @if ($lastResult !== '')
        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $lastResult }}</p>
    @endif

    {{-- History drawer --}}
    <div x-show="historyOpen" x-cloak class="fixed inset-0 z-[70] flex justify-end bg-black/40" @keydown.escape.window="historyOpen = false">
        <div class="h-full w-full max-w-md overflow-y-auto bg-white p-5 shadow-xl dark:bg-gray-900" @click.outside="historyOpen = false">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-base font-semibold">{{ __('seo-content-ai::filament.projects.planner_ai_history_title') }}</h3>
                <button type="button" class="text-sm text-gray-500" @click="historyOpen = false">{{ __('seo-content-ai::filament.projects.planner_close') }}</button>
            </div>
            <div class="space-y-3">
                @forelse ($history as $row)
                    <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                        <div class="font-medium">{{ $row['created_at'] ?? '' }}</div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ ($row['requested'] ?? 0) }} requested · {{ ($row['added'] ?? 0) }} added
                            @if (($row['status'] ?? '') !== '')
                                · {{ $row['status'] }}
                            @endif
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ ucfirst((string) ($row['direction'] ?? 'automatic')) }}
                            @if (($row['primary_language'] ?? '') !== '')
                                · {{ strtoupper((string) $row['primary_language']) }}
                            @endif
                            @if (($row['focus'] ?? '') !== '')
                                · Focus: {{ $row['focus'] }}
                            @endif
                        </div>
                        @if (($row['context_summary'] ?? '') !== '')
                            <div class="mt-1 text-xs text-gray-500">{{ $row['context_summary'] }}</div>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            <button type="button" class="text-primary-600 hover:underline" wire:click="loadNewContentHistory({{ (int) $row['id'] }})">{{ __('seo-content-ai::filament.projects.planner_load_options') }}</button>
                            <button type="button" class="text-primary-600 hover:underline" wire:click="runNewContentHistory({{ (int) $row['id'] }})" @click="historyOpen = false">{{ __('seo-content-ai::filament.projects.planner_generate_again') }}</button>
                            <button type="button" class="text-primary-600 hover:underline" wire:click="viewNewContentHistoryResults({{ (int) $row['id'] }})" @click="resultsOpen = true">{{ __('seo-content-ai::filament.projects.planner_view_results') }}</button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.planner_history_empty') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Results --}}
    @if (is_array($viewResults))
        <div x-show="resultsOpen" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="resultsOpen = false">
            <div class="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" @click.outside="resultsOpen = false">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-base font-semibold">{{ __('seo-content-ai::filament.projects.planner_view_results') }}</h3>
                    <button type="button" class="text-sm text-gray-500" @click="resultsOpen = false">{{ __('seo-content-ai::filament.projects.planner_close') }}</button>
                </div>
                <p class="mb-3 text-xs text-gray-500">{{ $viewResults['message'] ?? '' }}</p>
                <div class="space-y-2">
                    @foreach (($viewResults['candidates'] ?? []) as $candidate)
                        <div class="rounded border border-gray-100 px-3 py-2 text-xs dark:border-white/10">
                            <div class="font-medium">{{ $candidate['title'] ?? '' }}</div>
                            <div class="text-gray-500">{{ $candidate['keyword'] ?? '' }} · {{ $candidate['status'] ?? '' }}</div>
                            @if (! empty($candidate['suggestion_reason']))
                                <div class="mt-1 text-gray-500">{{ __('seo-content-ai::filament.projects.planner_why') }}: {{ $candidate['suggestion_reason'] }}</div>
                            @endif
                            @if (($candidate['status'] ?? '') === 'rejected_skipped' && ! empty($candidate['fingerprint']))
                                <button type="button" class="mt-1 text-primary-600 hover:underline" wire:click="restoreNewContentFingerprint('{{ e((string) ($candidate['fingerprint'] ?? '')) }}')">{{ __('seo-content-ai::filament.projects.suggestions_restore') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
