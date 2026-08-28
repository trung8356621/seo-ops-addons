@php
    /** @var \Livewire\Component $this */
    $payload = $this->auditNotesPayload ?? [];
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $total = (int) ($payload['total'] ?? 0);
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $selectedItems = is_array($payload['selected_items'] ?? null) ? $payload['selected_items'] : [];
    $selectedRefs = is_array($payload['selected_refs'] ?? null) ? $payload['selected_refs'] : [];
    $selectedMap = array_fill_keys($selectedRefs, true);
    $visibleRows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => ! isset($selectedMap[(string) ($row['cluster_ref'] ?? '')]),
    ));
    $ready = (bool) ($payload['ready'] ?? false);
    $loading = (bool) ($payload['loading'] ?? false);
    $hasPages = (bool) ($payload['has_pages'] ?? false);
    $currentPage = (int) ($payload['current_page'] ?? 1);
    $lastPage = (int) ($payload['last_page'] ?? 1);
@endphp

<div
    class="cp-audit-notes"
    data-audit-notes="1"
    wire:key="cp-audit-notes"
    wire:init="loadAuditNoteSuggestions"
    x-data="{ dnaOpen: @js($selectedRefs[0] ?? null), manualOpen: false }"
>
    <div class="cp-audit-notes__head">
        <h4 class="cp-audit-notes__title">
            {{ __('seo-content-ai::filament.projects.audit_notes_heading') }}
            <span class="cp-audit-notes__count">{{ $total }}</span>
        </h4>
        <p class="cp-audit-notes__help">{{ __('seo-content-ai::filament.projects.audit_notes_help') }}</p>
    </div>

    <div class="cp-audit-notes__toolbar">
        <input
            type="search"
            wire:model.live.debounce.450ms="auditNoteSearch"
            class="cp-audit-notes__search"
            placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_search_placeholder') }}"
            @disabled(! $canWrite)
        >
        <x-select wire:model.live="auditNoteFilter" wrapClass="cp-ops-select" :disabled="! $canWrite">
            <option value="all">{{ __('seo-content-ai::filament.projects.audit_notes_filter_all') }}</option>
            <option value="mcp_low">{{ __('seo-content-ai::filament.projects.audit_notes_filter_mcp_low') }}</option>
            <option value="no_focus">{{ __('seo-content-ai::filament.projects.audit_notes_filter_no_focus') }}</option>
            <option value="has_focus">{{ __('seo-content-ai::filament.projects.audit_notes_filter_has_focus') }}</option>
        </x-select>
    </div>

    <div
        class="cp-audit-notes__list-wrap"
        wire:loading.class="is-loading"
        wire:target="loadAuditNoteSuggestions,updatedAuditNoteSearch,updatedAuditNoteFilter,gotoAuditNotesPage,auditNoteSearch,auditNoteFilter"
    >
        <div
            class="cp-audit-notes__skeleton"
            wire:loading.delay.200ms
            wire:target="loadAuditNoteSuggestions,updatedAuditNoteSearch,updatedAuditNoteFilter,gotoAuditNotesPage,auditNoteSearch,auditNoteFilter"
        >
            @for ($i = 0; $i < 5; $i++)
                <div class="cp-audit-notes__skeleton-row animate-pulse">
                    <div class="cp-audit-notes__skeleton-check"></div>
                    <div class="cp-audit-notes__skeleton-body">
                        <div class="cp-audit-notes__skeleton-line cp-audit-notes__skeleton-line--title"></div>
                        <div class="cp-audit-notes__skeleton-line cp-audit-notes__skeleton-line--meta"></div>
                    </div>
                </div>
            @endfor
        </div>

        <ul
            class="cp-audit-notes__list"
            data-audit-notes-suggestions="1"
            wire:loading.remove.delay.200ms
            wire:target="loadAuditNoteSuggestions,updatedAuditNoteSearch,updatedAuditNoteFilter,gotoAuditNotesPage,auditNoteSearch,auditNoteFilter"
        >
            @if (! $ready && ! $loading)
                <li class="cp-audit-notes__empty">{{ __('seo-content-ai::filament.projects.audit_notes_loading') }}</li>
            @else
                @forelse ($visibleRows as $row)
                    @php
                        $ref = (string) ($row['cluster_ref'] ?? '');
                        $share = (float) ($row['mcp_share'] ?? 0);
                        $dnaCount = (int) ($row['dna_count'] ?? 0);
                        $articles = (int) ($row['article_count'] ?? 0);
                    @endphp
                    <li class="cp-audit-notes__row" wire:key="audit-note-suggest-{{ $ref }}">
                        <label class="cp-audit-notes__check">
                            <input
                                type="checkbox"
                                wire:click.prevent="toggleAuditNoteCluster('{{ addslashes($ref) }}')"
                                wire:loading.attr="disabled"
                                wire:target="toggleAuditNoteCluster"
                                @disabled(! $canWrite || $ref === '')
                            >
                            <span class="cp-audit-notes__row-body">
                                <span class="cp-audit-notes__name">{{ $row['cluster_name'] ?? $ref }}</span>
                                <span class="cp-audit-notes__meta">
                                    <span class="cp-audit-notes__pill">MCP {{ number_format($share, 1) }}%</span>
                                    <span class="cp-audit-notes__pill">{{ __('seo-content-ai::filament.projects.audit_notes_dna_count', ['count' => $dnaCount]) }}</span>
                                    @if ($articles > 0)
                                        <span class="cp-audit-notes__pill">{{ __('seo-content-ai::filament.projects.audit_notes_focus_articles', ['count' => $articles]) }}</span>
                                    @endif
                                </span>
                            </span>
                        </label>
                        <div
                            class="cp-audit-notes__row-loading"
                            wire:loading.flex
                            wire:target="toggleAuditNoteCluster"
                        >
                            <svg class="h-3.5 w-3.5 animate-spin text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </div>
                    </li>
                @empty
                    <li class="cp-audit-notes__empty">
                        @if ($selectedItems !== [])
                            {{ __('seo-content-ai::filament.projects.audit_notes_all_selected') }}
                        @else
                            {{ __('seo-content-ai::filament.projects.audit_notes_empty') }}
                        @endif
                    </li>
                @endforelse
            @endif
        </ul>
    </div>

    @if ($hasPages)
        <div class="cp-audit-notes__pager">
            <button
                type="button"
                class="cp-audit-notes__page-btn"
                wire:click="gotoAuditNotesPage({{ max(1, $currentPage - 1) }})"
                wire:loading.attr="disabled"
                @disabled($currentPage <= 1)
            >‹</button>
            <span class="cp-audit-notes__page-label">{{ $currentPage }} / {{ $lastPage }}</span>
            <button
                type="button"
                class="cp-audit-notes__page-btn"
                wire:click="gotoAuditNotesPage({{ min($lastPage, $currentPage + 1) }})"
                wire:loading.attr="disabled"
                @disabled($currentPage >= $lastPage)
            >›</button>
        </div>
    @endif

    <div class="cp-audit-notes__selected" data-audit-notes-selected="1">
        <div class="cp-audit-notes__selected-head">
            <h5 class="cp-audit-notes__selected-title">{{ __('seo-content-ai::filament.projects.audit_notes_selected_heading') }}</h5>
            <button
                type="button"
                class="cp-audit-notes__add-topic"
                x-show="!manualOpen"
                x-cloak
                @click="manualOpen = true"
                @disabled(! $canWrite)
            >
                + {{ __('seo-content-ai::filament.projects.audit_notes_add_topic') }}
            </button>
        </div>

        <div x-show="manualOpen" x-cloak class="cp-audit-notes__manual-form" data-audit-notes-manual="1">
            <input
                type="text"
                wire:model="auditNoteManualTopic"
                class="cp-audit-notes__dna-input"
                placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_manual_topic_placeholder') }}"
                @disabled(! $canWrite)
            >
            <button
                type="button"
                class="fi-btn fi-btn-color-primary fi-size-sm"
                wire:click="addManualAuditNoteTopic"
                wire:loading.attr="disabled"
                wire:target="addManualAuditNoteTopic"
                @disabled(! $canWrite)
            >
                <span wire:loading.remove wire:target="addManualAuditNoteTopic">
                    {{ __('seo-content-ai::filament.projects.audit_notes_add_topic_confirm') }}
                </span>
                <span wire:loading wire:target="addManualAuditNoteTopic" class="inline-flex items-center gap-1">
                    <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                </span>
            </button>
            <button type="button" class="cp-audit-notes__manual-cancel" @click="manualOpen = false">
                {{ __('seo-content-ai::filament.projects.planner_close') }}
            </button>
        </div>

        @forelse ($selectedItems as $item)
            @php
                $ref = (string) ($item['cluster_ref'] ?? '');
                $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
                $share = (float) ($item['mcp_share_snapshot'] ?? 0);
                $isManual = str_starts_with($ref, 'manual:');
            @endphp
            <div
                class="cp-audit-notes__item"
                wire:key="audit-note-item-{{ $ref }}"
                wire:loading.class="opacity-60"
                wire:target="removeAuditNoteItem('{{ addslashes($ref) }}'),addAuditNoteDna('{{ addslashes($ref) }}'),removeAuditNoteDna"
            >
                <div class="cp-audit-notes__item-head">
                    <div>
                        <div class="cp-audit-notes__name">{{ $item['cluster_name_snapshot'] ?? $ref }}</div>
                        <div class="cp-audit-notes__meta">
                            @if ($isManual)
                                <span class="cp-audit-notes__pill">{{ __('seo-content-ai::filament.projects.audit_notes_manual_badge') }}</span>
                            @else
                                <span class="cp-audit-notes__pill">MCP {{ number_format($share, 1) }}%</span>
                            @endif
                            <span class="cp-audit-notes__pill">{{ __('seo-content-ai::filament.projects.audit_notes_dna_count', ['count' => count($dna)]) }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="cp-audit-notes__remove"
                        wire:click="removeAuditNoteItem('{{ addslashes($ref) }}')"
                        wire:loading.attr="disabled"
                        wire:target="removeAuditNoteItem('{{ addslashes($ref) }}')"
                        title="{{ __('seo-content-ai::filament.projects.audit_notes_remove_topic') }}"
                    >
                        ×
                    </button>
                </div>

                <ul class="cp-audit-notes__dna">
                    @foreach ($dna as $dnaRow)
                        <li class="cp-audit-notes__dna-row">
                            <span class="cp-audit-notes__dna-weight">[{{ (int) ($dnaRow['weight'] ?? 1) }}]</span>
                            <span class="cp-audit-notes__dna-phrase">{{ $dnaRow['phrase'] ?? '' }}</span>
                            <button
                                type="button"
                                class="cp-audit-notes__dna-remove"
                                wire:click="removeAuditNoteDna('{{ addslashes($ref) }}', '{{ addslashes((string) ($dnaRow['phrase'] ?? '')) }}')"
                                wire:loading.attr="disabled"
                                title="{{ __('seo-content-ai::filament.projects.audit_notes_remove_dna') }}"
                            >×</button>
                        </li>
                    @endforeach
                </ul>

                <div x-show="dnaOpen === @js($ref)" x-cloak class="cp-audit-notes__dna-form">
                    <input
                        type="text"
                        wire:model="auditNoteDnaPhrase"
                        class="cp-audit-notes__dna-input"
                        placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_dna_phrase') }}"
                    >
                    <input
                        type="number"
                        min="1"
                        wire:model="auditNoteDnaWeight"
                        class="cp-audit-notes__dna-weight-input"
                        placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_dna_weight') }}"
                    >
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-primary fi-size-sm"
                        wire:click="addAuditNoteDna('{{ addslashes($ref) }}')"
                        wire:loading.attr="disabled"
                        wire:target="addAuditNoteDna('{{ addslashes($ref) }}')"
                    >
                        <span wire:loading.remove wire:target="addAuditNoteDna('{{ addslashes($ref) }}')">
                            {{ __('seo-content-ai::filament.projects.audit_notes_add_dna_confirm') }}
                        </span>
                        <span wire:loading wire:target="addAuditNoteDna('{{ addslashes($ref) }}')" class="inline-flex items-center gap-1">
                            <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </span>
                    </button>
                </div>
                <button
                    type="button"
                    class="cp-audit-notes__add-dna"
                    x-show="dnaOpen !== @js($ref)"
                    x-cloak
                    @click="dnaOpen = @js($ref)"
                >
                    + {{ __('seo-content-ai::filament.projects.audit_notes_add_dna') }}
                </button>
            </div>
        @empty
            <p class="cp-audit-notes__selected-empty">{{ __('seo-content-ai::filament.projects.audit_notes_selected_empty') }}</p>
        @endforelse
    </div>
</div>
