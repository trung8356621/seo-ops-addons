@props([
    'embedded' => false,
])

@php
    /** @var \Livewire\Component $this */
    $payload = $this->ideaCandidatesPayload ?? [];
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $hasProject = (bool) ($payload['has_project'] ?? false);
    $isDraft = (bool) ($payload['is_draft'] ?? false);
    $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $paginator = $payload['paginator'] ?? null;
    $selectedCount = (int) ($payload['selected_count'] ?? 0);
    $selectedIds = array_fill_keys(array_map('intval', $this->selectedIdeaKeywordIds ?? []), true);
    $actionsEnabled = $canWrite && $selectedCount > 0;
@endphp

<section
    @class([
        'cp-idea-picker',
        'rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900' => ! $embedded,
        'cp-idea-picker--embedded' => $embedded,
    ])
    wire:key="cp-idea-candidate-picker"
    data-idea-candidate-picker="1"
>
    @if (! $embedded)
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.projects.idea_candidate_heading') }}
                </h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.projects.idea_candidate_help') }}
                </p>
            </div>
            @if ($selectedCount > 0)
                <button
                    type="button"
                    class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                    wire:click="clearIdeaCandidateSelection"
                >
                    {{ __('seo-content-ai::filament.projects.idea_candidate_clear') }}
                </button>
            @endif
        </div>
    @elseif ($selectedCount > 0)
        <div class="mb-2 flex justify-end">
            <button
                type="button"
                class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
                wire:click="clearIdeaCandidateSelection"
            >
                {{ __('seo-content-ai::filament.projects.idea_candidate_clear') }}
            </button>
        </div>
    @endif

    <div @class(['mt-3' => ! $embedded, 'cp-idea-picker__toolbar flex flex-wrap items-end gap-2'])>
        <div class="min-w-[10rem]">
            <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">
                {{ __('seo-content-ai::filament.projects.idea_candidate_source') }}
            </label>
            <x-select wire:model.live="ideaCandidateSource" wrapClass="cp-ops-select" aria-label="{{ __('seo-content-ai::filament.projects.idea_candidate_source') }}">
                @foreach ($sources as $source)
                    <option value="{{ $source['key'] ?? '' }}">{{ $source['label'] ?? '' }}</option>
                @endforeach
            </x-select>
        </div>
        <div class="min-w-[14rem] flex-1">
            <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">
                {{ __('seo-content-ai::filament.projects.idea_candidate_search') }}
            </label>
            <input
                type="search"
                wire:model.live.debounce.300ms="ideaCandidateSearch"
                placeholder="{{ __('seo-content-ai::filament.projects.idea_candidate_search_placeholder') }}"
                class="fi-input w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                aria-label="{{ __('seo-content-ai::filament.projects.idea_candidate_search') }}"
            />
        </div>
    </div>

    <div class="cp-plan-card__scroll cp-idea-picker__scroll" data-plan-scroll="ideas">
    @if (! $hasProject || ! $isDraft)
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.content_planning_create_draft_first') }}
        </p>
    @elseif ($rows === [])
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-idea-candidate-empty="1">
            {{ __('seo-content-ai::filament.projects.idea_candidate_empty') }}
        </p>
    @else
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/5" data-idea-candidate-list="1">
            @foreach ($rows as $row)
                @php
                    $kid = (int) ($row['keyword_id'] ?? 0);
                    $checked = isset($selectedIds[$kid]);
                @endphp
                <li class="cp-idea-row group flex items-start gap-2 py-2" wire:key="idea-kw-{{ $kid }}">
                    <input
                        type="checkbox"
                        class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        @checked($checked)
                        wire:click="toggleIdeaCandidate({{ $kid }})"
                        @disabled(! $canWrite)
                        aria-label="{{ $row['phrase'] ?? '' }}"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['phrase'] ?? '' }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $row['source_label'] ?? '' }}</p>
                        @if (! empty($row['source_article_title']))
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.idea_candidate_from_article', ['title' => $row['source_article_title']]) }}
                            </p>
                        @endif
                        @if (! empty($row['vocabulary_group_label']))
                            <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ $row['vocabulary_group_label'] }}</p>
                        @endif
                    </div>
                    @if ($canWrite && ($row['source'] ?? '') === 'vocabulary_suggest')
                        <button
                            type="button"
                            class="cp-idea-row__delete"
                            wire:click="dismissIdeaCandidate({{ $kid }})"
                            wire:confirm="{{ __('seo-content-ai::filament.projects.idea_candidate_delete_confirm') }}"
                            wire:loading.attr="disabled"
                            wire:target="dismissIdeaCandidate({{ $kid }})"
                            title="{{ __('seo-content-ai::filament.projects.idea_candidate_delete_tooltip') }}"
                            aria-label="{{ __('seo-content-ai::filament.projects.idea_candidate_delete_tooltip') }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($paginator instanceof \Illuminate\Contracts\Pagination\Paginator && $paginator->hasPages())
            <div class="mt-2">
                {{ $paginator->links() }}
            </div>
        @endif
    @endif
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
        <p class="text-xs text-gray-600 dark:text-gray-300" data-idea-selected-count="1">
            {{ __('seo-content-ai::filament.projects.idea_candidate_selected_count', ['count' => $selectedCount]) }}
        </p>
        <div class="flex flex-wrap gap-2" data-idea-action-bar="1">
            <button
                type="button"
                wire:click="addIdeaCandidatesAsCreate"
                wire:loading.attr="disabled"
                wire:target="addIdeaCandidatesAsCreate"
                @disabled(! $actionsEnabled)
                @class(['cp-plan-btn cp-plan-btn--create', 'is-disabled' => ! $actionsEnabled])
                data-idea-action="create"
            >
                <span wire:loading.remove wire:target="addIdeaCandidatesAsCreate">
                    {{ __('seo-content-ai::filament.projects.idea_candidate_action_create') }}
                </span>
                <span wire:loading wire:target="addIdeaCandidatesAsCreate" class="inline-flex items-center gap-1">
                    <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                </span>
            </button>
        </div>
    </div>

    @if (($this->ideaCandidatesLastResult ?? '') !== '')
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300" data-idea-last-result="1">
            {{ $this->ideaCandidatesLastResult }}
        </p>
    @endif
</section>
