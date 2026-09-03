@php
    /** @var \Livewire\Component $this */
    $payload = $this->auditNotesPayload ?? [];
    $canWrite = (bool) ($payload['can_write'] ?? false);
    $siteId = (int) ($payload['site_id'] ?? 0);
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
    $defaultTarget = \Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT;
    $maxDna = \Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer::MAX_DNA_PER_NOTE;
    $maxTarget = \Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT;
@endphp

<div
    class="cp-audit-notes cp-ai-topic-workspace"
    data-audit-notes="1"
    data-ai-topic-workspace="1"
    wire:key="cp-audit-notes-{{ $siteId }}"
    wire:init="loadAuditNoteSuggestions"
    x-data="cpAuditNotesRoot(@js($selectedItems), {{ (int) $siteId }}, {{ (int) $defaultTarget }}, {{ (int) $maxDna }}, {{ (int) $maxTarget }})"
    @cp-audit-notes-selected.window="mergeServerSelected(($event.detail && $event.detail.items) ? $event.detail.items : [])"
>
    {{-- LEFT: available Topics --}}
    <section
        class="cp-ai-topic-column cp-ai-topic-column--available"
        data-ai-topic-column="available"
        aria-label="{{ __('seo-content-ai::filament.projects.audit_notes_heading') }}"
    >
        <div class="cp-ai-topic-column__head">
            <h4 class="cp-audit-notes__title">
                {{ __('seo-content-ai::filament.projects.audit_notes_heading') }}
                <span class="cp-audit-notes__count">{{ $total }}</span>
            </h4>
            <p class="cp-audit-notes__help">{{ __('seo-content-ai::filament.projects.audit_notes_help') }}</p>

            <div class="cp-audit-notes__toolbar">
                <form wire:submit="applyAuditNoteSearch" class="contents">
                    <input
                        type="search"
                        wire:model="auditNoteSearchInput"
                        class="cp-audit-notes__search"
                        placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_search_placeholder') }}"
                        autocomplete="off"
                        @disabled(! $canWrite)
                    >
                </form>
                <x-select wire:model.live="auditNoteFilter" wrapClass="cp-ops-select" :disabled="! $canWrite">
                    <option value="all">{{ __('seo-content-ai::filament.projects.audit_notes_filter_all') }}</option>
                    <option value="mcp_low">{{ __('seo-content-ai::filament.projects.audit_notes_filter_mcp_low') }}</option>
                    <option value="no_focus">{{ __('seo-content-ai::filament.projects.audit_notes_filter_no_focus') }}</option>
                    <option value="has_focus">{{ __('seo-content-ai::filament.projects.audit_notes_filter_has_focus') }}</option>
                </x-select>
            </div>
        </div>

        <div class="cp-ai-topic-column__body">
            <div
                class="cp-audit-notes__list-wrap"
                wire:loading.class="is-loading"
                wire:target="loadAuditNoteSuggestions,applyAuditNoteSearch,clearAuditNoteSearch,updatedAuditNoteFilter,gotoAuditNotesPage,auditNoteSearch,auditNoteFilter"
            >
                <div
                    class="cp-audit-notes__skeleton"
                    wire:loading.delay.200ms
                    wire:target="loadAuditNoteSuggestions,applyAuditNoteSearch,clearAuditNoteSearch,updatedAuditNoteFilter,gotoAuditNotesPage,auditNoteSearch,auditNoteFilter"
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
                    wire:target="loadAuditNoteSuggestions,applyAuditNoteSearch,clearAuditNoteSearch,updatedAuditNoteFilter,gotoAuditNotesPage,auditNoteSearch,auditNoteFilter"
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
                                $toggleTarget = "toggleAuditNoteCluster('".addslashes($ref)."')";
                            @endphp
                            <li
                                class="cp-audit-notes__row"
                                wire:key="audit-note-suggest-{{ $ref }}"
                                x-show="!isSelected(@js($ref))"
                            >
                                <label class="cp-audit-notes__check">
                                    <input
                                        type="checkbox"
                                        wire:click.prevent="toggleAuditNoteCluster('{{ addslashes($ref) }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $toggleTarget }}"
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
                                    wire:target="{{ $toggleTarget }}"
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
        </div>
    </section>

    {{-- RIGHT: selected Topics --}}
    <section
        class="cp-ai-topic-column cp-ai-topic-column--selected"
        data-ai-topic-column="selected"
        data-audit-notes-selected="1"
        aria-label="{{ __('seo-content-ai::filament.projects.audit_notes_selected_heading') }}"
    >
        <div class="cp-ai-topic-column__head">
            <div class="cp-audit-notes__selected-head">
                <div class="cp-audit-notes__selected-title-wrap">
                    <h5 class="cp-audit-notes__selected-title">{{ __('seo-content-ai::filament.projects.audit_notes_selected_heading') }}</h5>
                    <span
                        class="cp-audit-notes__ideas-total"
                        data-planner-ideas-total="1"
                        x-text="'{{ __('seo-content-ai::filament.projects.planner_ideas_total') }} ' + stickyTotal()"
                    >
                        {{ __('seo-content-ai::filament.projects.planner_ideas_total') }} 0
                    </span>
                </div>
                <button
                    type="button"
                    class="cp-audit-notes__add-topic"
                    x-show="!manualOpen"
                    x-cloak
                    @click="openManualSeed()"
                    @disabled(! $canWrite)
                >
                    + {{ __('seo-content-ai::filament.projects.audit_notes_add_topic') }}
                </button>
            </div>
        </div>

        <div class="cp-ai-topic-column__body">
            <form
                x-show="manualOpen"
                x-cloak
                class="cp-audit-notes__manual-form"
                data-audit-notes-manual="1"
                data-planning-seed-form="1"
                @submit.prevent="addManualSeed()"
            >
                <label class="cp-audit-notes__field-label cp-audit-notes__manual-seed-label">
                    {{ __('seo-content-ai::filament.projects.audit_notes_manual_seed_label') }}
                    <input
                        type="text"
                        x-ref="manualSeedInput"
                        x-model="manualSeedText"
                        class="cp-audit-notes__dna-input"
                        maxlength="300"
                        placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_manual_seed_placeholder') }}"
                        autocomplete="off"
                        @disabled(! $canWrite)
                    >
                </label>
                <label class="cp-audit-notes__field-label">
                    {{ __('seo-content-ai::filament.projects.audit_notes_target_dna') }}
                    <input
                        type="number"
                        min="1"
                        max="{{ $maxTarget }}"
                        x-model="manualSeedTarget"
                        class="cp-audit-notes__dna-weight-input"
                        @disabled(! $canWrite)
                    >
                </label>
                <button
                    type="submit"
                    class="fi-btn fi-btn-color-primary fi-size-sm"
                    @disabled(! $canWrite)
                >
                    {{ __('seo-content-ai::filament.projects.audit_notes_add_topic_confirm') }}
                </button>
                <button
                    type="button"
                    class="cp-audit-notes__manual-cancel"
                    @click="manualOpen = false"
                    title="{{ __('seo-content-ai::filament.projects.planner_close') }}"
                    aria-label="{{ __('seo-content-ai::filament.projects.planner_close') }}"
                >×</button>
            </form>

            <p
                class="cp-audit-notes__alloc-warn"
                x-show="allocationWarning"
                x-cloak
                x-text="allocationWarning"
            ></p>

            <template x-for="item in topicList()" :key="item.cluster_ref">
                <div class="cp-audit-notes__item" :data-cluster-ref="item.cluster_ref">
                    <div class="cp-audit-notes__item-head">
                        <div class="min-w-0 flex-1">
                            <div class="cp-audit-notes__name" x-text="item.cluster_name_snapshot"></div>
                            <div class="cp-audit-notes__meta">
                                <template x-if="isManualSeed(item)">
                                    <span class="cp-audit-notes__pill">{{ __('seo-content-ai::filament.projects.audit_notes_manual_badge') }}</span>
                                </template>
                                <template x-if="!isManualSeed(item)">
                                    <span class="cp-audit-notes__pill" x-text="'MCP ' + Number(item.mcp_share_snapshot || 0).toFixed(1) + '%'"></span>
                                </template>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="cp-audit-notes__remove"
                            @click="removeTopic(item.cluster_ref)"
                            title="{{ __('seo-content-ai::filament.projects.audit_notes_remove_topic') }}"
                        >×</button>
                    </div>

                    <div class="cp-audit-notes__target-row">
                        <div class="cp-audit-notes__target-controls">
                            <label class="cp-audit-notes__field-label">
                                {{ __('seo-content-ai::filament.projects.audit_notes_target_dna') }}
                                <input
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="cp-audit-notes__dna-weight-input"
                                    :value="item.target_dna_count"
                                    @change="setTargetDnaCount(item.cluster_ref, $event.target.value)"
                                >
                            </label>
                            <span
                                class="cp-audit-notes__target-mode"
                                :class="{ 'is-manual': item.target_mode === 'manual' || isManualSeed(item) }"
                                x-text="(item.target_mode === 'manual' || isManualSeed(item)) ? @js(__('seo-content-ai::filament.projects.audit_notes_target_mode_manual')) : @js(__('seo-content-ai::filament.projects.audit_notes_target_mode_auto'))"
                            ></span>
                            <button
                                type="button"
                                class="cp-audit-notes__target-reset"
                                x-show="item.target_mode === 'manual' && !isManualSeed(item)"
                                x-cloak
                                @click="resetTargetAuto(item.cluster_ref)"
                                title="{{ __('seo-content-ai::filament.projects.audit_notes_target_reset_auto') }}"
                            >↻ {{ __('seo-content-ai::filament.projects.audit_notes_target_reset_auto') }}</button>
                        </div>
                        <p
                            class="cp-audit-notes__slot-summary"
                            x-text="slotSummary(item)"
                        ></p>
                    </div>

                    <ul class="cp-audit-notes__dna">
                        <template x-for="visual in visualDnaRows(item)" :key="item.cluster_ref + '-v-' + visual.phrase + '-' + visual.slotIndex">
                            <li class="cp-audit-notes__dna-row">
                                <span class="cp-audit-notes__dna-phrase" x-text="visual.phrase"></span>
                                <label
                                    class="cp-audit-notes__dna-placement"
                                    :title="visual.placement === 'after'
                                        ? @js(__('seo-content-ai::filament.projects.audit_notes_dna_placement_after_tip'))
                                        : @js(__('seo-content-ai::filament.projects.audit_notes_dna_placement_before_tip'))"
                                >
                                    <input
                                        type="checkbox"
                                        class="cp-audit-notes__dna-placement-check"
                                        :checked="visual.placement === 'after'"
                                        @change="setDnaPlacement(item.cluster_ref, visual.phrase, $event.target.checked)"
                                    >
                                    <span>{{ __('seo-content-ai::filament.projects.audit_notes_dna_placement_after') }}</span>
                                </label>
                                <button
                                    type="button"
                                    class="cp-audit-notes__dna-dup"
                                    @click="duplicateDna(item.cluster_ref, visual.phrase)"
                                    title="{{ __('seo-content-ai::filament.projects.audit_notes_duplicate_dna') }}"
                                >⧉</button>
                                <button
                                    type="button"
                                    class="cp-audit-notes__dna-remove"
                                    @click="removeDnaSlot(item.cluster_ref, visual.phrase)"
                                    title="{{ __('seo-content-ai::filament.projects.audit_notes_remove_dna') }}"
                                >×</button>
                            </li>
                        </template>
                    </ul>

                    <form class="cp-audit-notes__dna-form" @submit.prevent="addDna(item.cluster_ref)">
                        <input
                            type="text"
                            class="cp-audit-notes__dna-input"
                            x-model="draftFor(item.cluster_ref).phrase"
                            placeholder="{{ __('seo-content-ai::filament.projects.audit_notes_dna_phrase') }}"
                            autocomplete="off"
                            @keydown.enter.prevent="addDna(item.cluster_ref)"
                        >
                        <button type="submit" class="fi-btn fi-btn-color-primary fi-size-sm">
                            {{ __('seo-content-ai::filament.projects.audit_notes_add_dna_confirm') }}
                        </button>
                    </form>
                </div>
            </template>

            <p class="cp-audit-notes__selected-empty" x-show="topicList().length === 0" x-cloak>
                {{ __('seo-content-ai::filament.projects.audit_notes_selected_empty') }}
            </p>
        </div>
    </section>
</div>

@once
<script>
    (function () {
        const STORAGE_PREFIX = 'seoOps:content-planner:audit-notes:v2:site:';
        const STORAGE_VERSION = 5;
        const DEFAULT_PLACEMENT = 'after';
        const normalizePlacement = (raw) => {
            const v = String(raw || '').trim().toLowerCase();
            return (v === 'before' || v === 'after') ? v : DEFAULT_PLACEMENT;
        };
        const WARN_TOO_MANY = @js(__('seo-content-ai::filament.projects.audit_notes_too_many_topics', ['topics' => ':topics', 'quantity' => ':quantity']));
        const MAX_SEED_LEN = 300;

        const displayPhrase = (phrase) => String(phrase || '').trim().replace(/\s+/gu, ' ');
        const normalizeKey = (phrase) => displayPhrase(phrase).toLowerCase();
        const newManualSeedRef = () => {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return 'manual:' + window.crypto.randomUUID().replace(/-/g, '');
            }
            return 'manual:' + String(Date.now()) + Math.random().toString(16).slice(2);
        };
        const isManualSeedItem = (item) => {
            const type = String(item?.source_type || '').toLowerCase();
            if (type === 'manual_seed') return true;
            return String(item?.cluster_ref || '').startsWith('manual:');
        };

        const register = () => {
            if (window.__cpAuditNotesAlpineRegistered || !window.Alpine) {
                return;
            }
            window.__cpAuditNotesAlpineRegistered = true;

            Alpine.store('cpAuditNotes', {
                topics: {},
                drafts: {},
                siteId: 0,
                defaultTarget: 5,
                maxDna: 50,
                maxTarget: 100,
                allocationWarning: '',
                revision: 0,
                hydratedSites: {},
                saveTimer: null,

                bump() { this.revision++; },
                storageKey(siteId) { return STORAGE_PREFIX + String(siteId || 0); },
                topicList() { return Object.values(this.topics); },
                stickyTotal() {
                    return this.topicList().reduce((sum, item) => sum + (parseInt(item?.target_dna_count, 10) || 0), 0);
                },
                draftFor(ref) {
                    if (!this.drafts[ref]) this.drafts[ref] = { phrase: '' };
                    return this.drafts[ref];
                },
                normalizeTargetMode(raw) {
                    return String(raw || '').toLowerCase() === 'manual' ? 'manual' : 'auto';
                },
                normalizeItem(raw) {
                    const ref = String(raw?.cluster_ref || '').trim();
                    const dnaIn = Array.isArray(raw?.dna) ? raw.dna : [];
                    const byPhrase = {};
                    dnaIn.forEach((row) => {
                        if (typeof row === 'string') {
                            const phrase = displayPhrase(row);
                            if (!phrase) return;
                            const key = normalizeKey(phrase);
                            if (!byPhrase[key]) {
                                byPhrase[key] = { phrase, slots: 1, source: 'manual', placement: DEFAULT_PLACEMENT };
                            } else {
                                byPhrase[key].slots = Math.min(this.maxTarget, byPhrase[key].slots + 1);
                            }
                            return;
                        }
                        const phrase = displayPhrase(row?.phrase || row?.value || '');
                        if (!phrase) return;
                        const key = normalizeKey(phrase);
                        let slots = 1;
                        if (Object.prototype.hasOwnProperty.call(row || {}, 'slots')) {
                            slots = Math.max(1, parseInt(row.slots, 10) || 1);
                        }
                        const placement = normalizePlacement(row?.placement);
                        if (!byPhrase[key]) {
                            byPhrase[key] = { phrase, slots, source: row?.source === 'cluster' ? 'cluster' : 'manual', placement };
                        } else {
                            byPhrase[key].slots = Math.min(this.maxTarget, byPhrase[key].slots + slots);
                            byPhrase[key].placement = placement;
                            if (row?.source === 'cluster') byPhrase[key].source = 'cluster';
                        }
                    });
                    const dna = Object.values(byPhrase)
                        .sort((a, b) => (b.slots - a.slots) || String(a.phrase).localeCompare(String(b.phrase)))
                        .slice(0, this.maxDna);
                    const specified = dna.reduce((sum, row) => sum + row.slots, 0);
                    let target = parseInt(raw?.target_dna_count ?? raw?.topic_weight, 10);
                    if (!Number.isFinite(target) || target < 1 || target > this.maxTarget) target = this.defaultTarget;
                    if (specified > target) target = Math.min(this.maxTarget, specified);
                    const sourceType = (String(raw?.source_type || '').toLowerCase() === 'manual_seed' || ref.startsWith('manual:'))
                        ? 'manual_seed'
                        : 'cluster';
                    let seedText = null;
                    let mcp = Number(raw?.mcp_share_snapshot || 0);
                    let targetMode = this.normalizeTargetMode(raw?.target_mode);
                    let name = String(raw?.cluster_name_snapshot || ref);
                    if (sourceType === 'manual_seed') {
                        seedText = displayPhrase(raw?.seed_text || name).slice(0, MAX_SEED_LEN);
                        if (!seedText) return null;
                        name = seedText;
                        mcp = null;
                        targetMode = 'manual';
                    }
                    return {
                        source_type: sourceType,
                        cluster_ref: ref,
                        cluster_name_snapshot: name,
                        seed_text: seedText,
                        mcp_share_snapshot: mcp,
                        target_dna_count: target,
                        target_mode: targetMode,
                        dna,
                    };
                },
                isManualSeed(item) { return isManualSeedItem(item); },
                addManualSeed(seedText, targetRaw) {
                    let seed = displayPhrase(seedText).slice(0, MAX_SEED_LEN);
                    if (!seed) return false;
                    let target = parseInt(targetRaw, 10);
                    if (!Number.isFinite(target) || target < 1 || target > this.maxTarget) target = this.defaultTarget;
                    const ref = newManualSeedRef();
                    const item = this.normalizeItem({
                        source_type: 'manual_seed',
                        cluster_ref: ref,
                        cluster_name_snapshot: seed,
                        seed_text: seed,
                        mcp_share_snapshot: null,
                        target_dna_count: target,
                        target_mode: 'manual',
                        dna: [],
                    });
                    if (!item) return false;
                    this.topics = { ...this.topics, [ref]: item };
                    this.drafts = { ...this.drafts, [ref]: { phrase: '' } };
                    this.rebalanceAutoTargets();
                    return ref;
                },
                specifiedCount(item) {
                    return (item?.dna || []).reduce((sum, row) => sum + (row.slots || 0), 0);
                },
                missingCount(item) {
                    return Math.max(0, (item?.target_dna_count || 0) - this.specifiedCount(item));
                },
                visualDnaRows(item) {
                    const rows = [];
                    (item?.dna || []).forEach((row) => {
                        const slots = Math.max(1, row.slots || 1);
                        const placement = normalizePlacement(row.placement);
                        for (let i = 0; i < slots; i++) {
                            rows.push({ phrase: row.phrase, slotIndex: i, placement });
                        }
                    });
                    return rows;
                },
                setDnaPlacement(ref, phrase, afterChecked) {
                    const topic = this.topics[ref];
                    if (!topic) return;
                    const key = normalizeKey(phrase);
                    const placement = afterChecked ? 'after' : 'before';
                    const dna = (topic.dna || []).map((row) => {
                        if (normalizeKey(row.phrase) !== key) return row;
                        return { ...row, placement };
                    });
                    this.topics = { ...this.topics, [ref]: { ...topic, dna } };
                    this.persistSoon();
                    this.bump();
                },
                rebalanceAutoTargets() {
                    const list = this.topicList();
                    // Sticky pool = SUM(current targets). No separate quantity control.
                    const qty = Math.max(list.length, this.stickyTotal() || list.length);
                    if (list.length > qty) {
                        this.allocationWarning = WARN_TOO_MANY
                            .replace(':topics', String(list.length))
                            .replace(':quantity', String(qty));
                        this.bump();
                        this.persistSoon();
                        return;
                    }
                    this.allocationWarning = '';
                    let manualReserved = 0;
                    const autoRefs = [];
                    const next = { ...this.topics };
                    list.forEach((item) => {
                        const mode = this.normalizeTargetMode(item.target_mode);
                        // Planning Seeds always reserve their target — never MCP-auto.
                        if (isManualSeedItem(item) || mode === 'manual') {
                            const floor = this.specifiedCount(item);
                            let target = Math.max(item.target_dna_count || 1, floor, 1);
                            target = Math.min(this.maxTarget, target);
                            next[item.cluster_ref] = {
                                ...item,
                                source_type: isManualSeedItem(item) ? 'manual_seed' : (item.source_type || 'cluster'),
                                target_mode: 'manual',
                                target_dna_count: target,
                            };
                            manualReserved += target;
                        } else {
                            autoRefs.push(item.cluster_ref);
                        }
                    });
                    if (autoRefs.length === 0) {
                        this.topics = next;
                        this.bump();
                        this.persistSoon();
                        return;
                    }
                    let available = Math.max(0, qty - manualReserved);
                    const weights = autoRefs.map((ref) => {
                        const share = Number(next[ref]?.mcp_share_snapshot || 0);
                        return share > 0 ? share : 0;
                    });
                    const weightSum = weights.reduce((s, w) => s + w, 0);
                    const n = autoRefs.length;
                    let assigned = Array(n).fill(0);
                    if (available <= 0) {
                        assigned = autoRefs.map((ref) => Math.max(this.specifiedCount(next[ref]), 0));
                    } else if (weightSum <= 0) {
                        for (let k = 0; k < available; k++) assigned[k % n]++;
                    } else {
                        const rows = [];
                        let floorSum = 0;
                        for (let i = 0; i < n; i++) {
                            const ideal = available * (weights[i] / weightSum);
                            const floor = Math.floor(ideal);
                            floorSum += floor;
                            rows.push({ index: i, floor, frac: ideal - floor, ref: autoRefs[i] });
                        }
                        const remainder = available - floorSum;
                        rows.sort((a, b) => (b.frac - a.frac) || String(a.ref).localeCompare(String(b.ref)) || (a.index - b.index));
                        assigned = Array(n).fill(0);
                        rows.forEach((row, rank) => {
                            assigned[row.index] = row.floor + (rank < remainder ? 1 : 0);
                        });
                        if (available >= n) {
                            for (let z = 0; z < n; z++) {
                                if (assigned[z] >= 1) continue;
                                let donor = -1, donorVal = -1;
                                for (let i = 0; i < n; i++) {
                                    if (assigned[i] > 1 && assigned[i] > donorVal) { donor = i; donorVal = assigned[i]; }
                                }
                                if (donor < 0) break;
                                assigned[donor]--;
                                assigned[z] = 1;
                            }
                        }
                    }
                    autoRefs.forEach((ref, i) => {
                        const floor = this.specifiedCount(next[ref]);
                        let target = Math.max(assigned[i] || 0, floor);
                        if (target < 1 && available >= n) target = 1;
                        target = Math.min(this.maxTarget, Math.max(target, floor));
                        if (target < 1) target = Math.max(1, floor);
                        next[ref] = { ...next[ref], source_type: 'cluster', target_mode: 'auto', target_dna_count: target };
                    });
                    this.topics = next;
                    this.bump();
                    this.persistSoon();
                },
                mergeServerSelected(items, { preferLocal = true } = {}) {
                    const list = Array.isArray(items) ? items : [];
                    const serverRefs = new Set();
                    const nextTopics = { ...this.topics };
                    const nextDrafts = { ...this.drafts };
                    list.forEach((raw) => {
                        const item = this.normalizeItem(raw);
                        if (!item || !item.cluster_ref) return;
                        serverRefs.add(item.cluster_ref);
                        if (!nextTopics[item.cluster_ref]) {
                            nextTopics[item.cluster_ref] = item;
                            if (!nextDrafts[item.cluster_ref]) nextDrafts[item.cluster_ref] = { phrase: '' };
                        } else if (preferLocal) {
                            nextTopics[item.cluster_ref] = {
                                ...nextTopics[item.cluster_ref],
                                cluster_name_snapshot: item.cluster_name_snapshot,
                                mcp_share_snapshot: item.mcp_share_snapshot,
                                source_type: item.source_type,
                                seed_text: item.seed_text,
                            };
                        } else {
                            nextTopics[item.cluster_ref] = item;
                        }
                    });
                    if (!preferLocal) {
                        Object.keys(nextTopics).forEach((ref) => {
                            if (!serverRefs.has(ref)) { delete nextTopics[ref]; delete nextDrafts[ref]; }
                        });
                    } else if (list.length > 0 && serverRefs.size > 0) {
                        Object.keys(nextTopics).forEach((ref) => {
                            if (!serverRefs.has(ref)) { delete nextTopics[ref]; delete nextDrafts[ref]; }
                        });
                    }
                    this.topics = nextTopics;
                    this.drafts = nextDrafts;
                    this.rebalanceAutoTargets();
                },
                setTargetDnaCount(ref, value) {
                    if (!this.topics[ref]) return;
                    let target = parseInt(value, 10);
                    if (!Number.isFinite(target) || target < 1) target = this.defaultTarget;
                    target = Math.min(this.maxTarget, target);
                    const specified = this.specifiedCount(this.topics[ref]);
                    if (specified > target) target = specified;
                    const current = this.topics[ref];
                    this.topics = {
                        ...this.topics,
                        [ref]: {
                            ...current,
                            target_dna_count: target,
                            target_mode: 'manual',
                            source_type: isManualSeedItem(current) ? 'manual_seed' : (current.source_type || 'cluster'),
                        },
                    };
                    this.rebalanceAutoTargets();
                },
                resetTargetAuto(ref) {
                    if (!this.topics[ref] || isManualSeedItem(this.topics[ref])) return;
                    this.topics = { ...this.topics, [ref]: { ...this.topics[ref], target_mode: 'auto' } };
                    this.rebalanceAutoTargets();
                },
                addDna(ref) {
                    const topic = this.topics[ref];
                    const draft = this.draftFor(ref);
                    if (!topic) return false;
                    const phrase = displayPhrase(draft.phrase);
                    if (phrase === '') return false;
                    const key = normalizeKey(phrase);
                    const dna = Array.isArray(topic.dna) ? topic.dna.map((r) => ({ ...r })) : [];
                    let found = false;
                    for (let i = 0; i < dna.length; i++) {
                        if (normalizeKey(dna[i].phrase) === key) {
                            dna[i].slots = Math.min(this.maxTarget, (dna[i].slots || 1) + 1);
                            dna[i].phrase = phrase;
                            dna[i].placement = DEFAULT_PLACEMENT;
                            found = true;
                            break;
                        }
                    }
                    if (!found) {
                        if (dna.length >= this.maxDna) return false;
                        dna.push({ phrase, slots: 1, source: 'manual', placement: DEFAULT_PLACEMENT });
                    }
                    dna.sort((a, b) => (b.slots - a.slots) || String(a.phrase).localeCompare(String(b.phrase)));
                    const next = { ...topic, dna };
                    const specified = dna.reduce((s, r) => s + r.slots, 0);
                    if (specified > next.target_dna_count) next.target_dna_count = Math.min(this.maxTarget, specified);
                    this.topics = { ...this.topics, [ref]: next };
                    this.drafts = { ...this.drafts, [ref]: { phrase: '' } };
                    this.rebalanceAutoTargets();
                    return true;
                },
                duplicateDna(ref, phrase) {
                    const draft = this.draftFor(ref);
                    const prev = draft.phrase;
                    draft.phrase = phrase;
                    this.addDna(ref);
                    draft.phrase = prev;
                },
                removeDnaSlot(ref, phrase) {
                    const topic = this.topics[ref];
                    if (!topic) return;
                    const key = normalizeKey(phrase);
                    const dna = [];
                    (topic.dna || []).forEach((row) => {
                        if (normalizeKey(row.phrase) !== key) { dna.push(row); return; }
                        const slots = (row.slots || 1) - 1;
                        if (slots >= 1) dna.push({ ...row, slots, placement: normalizePlacement(row.placement) });
                    });
                    this.topics = { ...this.topics, [ref]: { ...topic, dna } };
                    this.rebalanceAutoTargets();
                },
                removeTopicLocal(ref) {
                    const next = { ...this.topics };
                    const drafts = { ...this.drafts };
                    delete next[ref];
                    delete drafts[ref];
                    this.topics = next;
                    this.drafts = drafts;
                    this.rebalanceAutoTargets();
                },
                snapshot() {
                    return this.topicList().map((item) => ({
                        source_type: isManualSeedItem(item) ? 'manual_seed' : 'cluster',
                        cluster_ref: item.cluster_ref,
                        cluster_name_snapshot: item.cluster_name_snapshot,
                        seed_text: isManualSeedItem(item) ? (item.seed_text || item.cluster_name_snapshot) : null,
                        mcp_share_snapshot: isManualSeedItem(item) ? null : item.mcp_share_snapshot,
                        target_dna_count: item.target_dna_count,
                        target_mode: (isManualSeedItem(item) || item.target_mode === 'manual') ? 'manual' : 'auto',
                        dna: Array.isArray(item.dna) ? item.dna.map((row) => ({
                            phrase: row.phrase,
                            slots: row.slots,
                            source: row.source,
                            placement: normalizePlacement(row.placement),
                        })) : [],
                    }));
                },
                persistSoon() {
                    if (this.saveTimer) clearTimeout(this.saveTimer);
                    this.saveTimer = setTimeout(() => this.persistNow(), 80);
                },
                persistNow() {
                    if (!this.siteId || this.siteId <= 0) return;
                    try {
                        localStorage.setItem(this.storageKey(this.siteId), JSON.stringify({
                            version: STORAGE_VERSION,
                            site_id: this.siteId,
                            updated_at: new Date().toISOString(),
                            items: this.snapshot(),
                        }));
                    } catch (e) {}
                },
                loadFromStorage(siteId) {
                    if (!siteId || siteId <= 0) { this.topics = {}; this.drafts = {}; this.bump(); return []; }
                    try {
                        const raw = localStorage.getItem(this.storageKey(siteId));
                        if (!raw) { this.topics = {}; this.drafts = {}; this.bump(); return []; }
                        const parsed = JSON.parse(raw);
                        const ver = Number(parsed?.version || 0);
                        if (!parsed || (ver !== 2 && ver !== 3 && ver !== 4 && ver !== 5) || Number(parsed.site_id) !== Number(siteId)) {
                            this.topics = {}; this.drafts = {}; this.bump(); return [];
                        }
                        const items = Array.isArray(parsed.items) ? parsed.items : [];
                        this.mergeServerSelected(items, { preferLocal: false });
                        return this.snapshot();
                    } catch (e) {
                        this.topics = {}; this.drafts = {}; this.bump(); return [];
                    }
                },
                switchSite(siteId) {
                    const next = Number(siteId) || 0;
                    if (this.siteId > 0 && this.siteId !== next) this.persistNow();
                    this.siteId = next;
                    if (this.hydratedSites[next]) {
                        this.rebalanceAutoTargets();
                        return this.snapshot();
                    }
                    const restored = this.loadFromStorage(next);
                    this.hydratedSites[next] = true;
                    return restored;
                },
                hasPlanForSite(siteId) {
                    const id = Number(siteId) || 0;
                    if (id <= 0) return false;
                    if (Number(this.siteId) === id && this.topicList().length > 0) return true;
                    try {
                        const raw = localStorage.getItem(this.storageKey(id));
                        if (!raw) return false;
                        const parsed = JSON.parse(raw);
                        return Array.isArray(parsed?.items) && parsed.items.length > 0;
                    } catch (e) {
                        return false;
                    }
                },
                peekItemsForSite(siteId) {
                    const id = Number(siteId) || 0;
                    if (id <= 0) return [];
                    if (Number(this.siteId) === id) return this.snapshot();
                    try {
                        const raw = localStorage.getItem(this.storageKey(id));
                        if (!raw) return [];
                        const parsed = JSON.parse(raw);
                        return Array.isArray(parsed?.items) ? parsed.items : [];
                    } catch (e) {
                        return [];
                    }
                },
                writeItemsForSite(siteId, items) {
                    const id = Number(siteId) || 0;
                    if (id <= 0) return;
                    const list = Array.isArray(items) ? items : [];
                    const normalized = {};
                    const drafts = {};
                    list.forEach((raw) => {
                        const item = this.normalizeItem(raw);
                        if (!item) return;
                        normalized[item.cluster_ref] = item;
                        drafts[item.cluster_ref] = { phrase: '' };
                    });
                    try {
                        localStorage.setItem(this.storageKey(id), JSON.stringify({
                            version: STORAGE_VERSION,
                            site_id: id,
                            updated_at: new Date().toISOString(),
                            items: Object.values(normalized).map((item) => ({
                                source_type: isManualSeedItem(item) ? 'manual_seed' : 'cluster',
                                cluster_ref: item.cluster_ref,
                                cluster_name_snapshot: item.cluster_name_snapshot,
                                seed_text: isManualSeedItem(item) ? (item.seed_text || item.cluster_name_snapshot) : null,
                                mcp_share_snapshot: isManualSeedItem(item) ? null : item.mcp_share_snapshot,
                                target_dna_count: item.target_dna_count,
                                target_mode: (isManualSeedItem(item) || item.target_mode === 'manual') ? 'manual' : 'auto',
                                dna: Array.isArray(item.dna) ? item.dna.map((row) => ({
                                    phrase: row.phrase,
                                    slots: row.slots,
                                    source: row.source,
                                    placement: normalizePlacement(row.placement),
                                })) : [],
                            })),
                        }));
                    } catch (e) {}
                    // Do not swap the active source planner — only hydrate if user is already on dest.
                    if (Number(this.siteId) === id) {
                        this.topics = normalized;
                        this.drafts = drafts;
                        this.rebalanceAutoTargets();
                    } else {
                        delete this.hydratedSites[id];
                    }
                },
            });

            Alpine.data('cpAuditNotesRoot', (serverItems, siteId, defaultTarget, maxDna, maxTarget) => ({
                manualOpen: false,
                manualSeedText: '',
                manualSeedTarget: String(defaultTarget || 5),
                store: null,
                siteId: Number(siteId) || 0,
                get allocationWarning() {
                    void this.store?.revision;
                    return this.store?.allocationWarning || '';
                },
                init() {
                    this.store = Alpine.store('cpAuditNotes');
                    this.store.defaultTarget = Number(defaultTarget) || 5;
                    this.store.maxDna = Number(maxDna) || 50;
                    this.store.maxTarget = Number(maxTarget) || 100;
                    this.manualSeedTarget = String(this.store.defaultTarget);
                    const restored = this.store.switchSite(this.siteId);
                    if (restored.length > 0 && this.$wire && typeof this.$wire.applyAuditNoteItems === 'function') {
                        this.$wire.applyAuditNoteItems(restored);
                    }
                    const serverList = Array.isArray(serverItems) ? serverItems : [];
                    if (serverList.length > 0) this.store.mergeServerSelected(serverList, { preferLocal: true });
                    else this.store.rebalanceAutoTargets();
                },
                stickyTotal() {
                    void this.store?.revision;
                    return this.store ? this.store.stickyTotal() : 0;
                },
                isSelected(ref) { void this.store.revision; return !!this.store.topics[ref]; },
                topicList() { void this.store.revision; return this.store.topicList(); },
                draftFor(ref) { void this.store.revision; return this.store.draftFor(ref); },
                isManualSeed(item) { return this.store.isManualSeed(item); },
                specifiedCount(item) { return this.store.specifiedCount(item); },
                missingCount(item) { return this.store.missingCount(item); },
                slotSummary(item) {
                    const specified = this.specifiedCount(item);
                    const target = item.target_dna_count;
                    const missing = this.missingCount(item);
                    return @js(__('seo-content-ai::filament.projects.audit_notes_slot_summary'))
                        .replace(':specified', String(specified))
                        .replace(':target', String(target))
                        .replace(':missing', String(missing));
                },
                visualDnaRows(item) { return this.store.visualDnaRows(item); },
                mergeServerSelected(items) { this.store.mergeServerSelected(items, { preferLocal: true }); },
                setTargetDnaCount(ref, value) { this.store.setTargetDnaCount(ref, value); },
                resetTargetAuto(ref) { this.store.resetTargetAuto(ref); },
                openManualSeed() {
                    this.manualOpen = true;
                    this.manualSeedText = '';
                    this.manualSeedTarget = String(this.store?.defaultTarget || 5);
                    this.$nextTick(() => this.$refs.manualSeedInput?.focus());
                },
                addManualSeed() {
                    const ref = this.store.addManualSeed(this.manualSeedText, this.manualSeedTarget);
                    if (!ref) return;
                    this.manualSeedText = '';
                    this.manualSeedTarget = String(this.store.defaultTarget);
                    this.manualOpen = false;
                    if (this.$wire && typeof this.$wire.applyAuditNoteItems === 'function') {
                        this.$wire.applyAuditNoteItems(this.store.snapshot());
                    }
                    this.$nextTick(() => {
                        const el = this.$root.querySelector('[data-cluster-ref="' + CSS.escape(ref) + '"] .cp-audit-notes__dna-input');
                        el?.focus();
                    });
                },
                addDna(ref) {
                    const ok = this.store.addDna(ref);
                    if (ok) {
                        this.$nextTick(() => {
                            const input = this.$root.querySelector('[data-cluster-ref="' + CSS.escape(ref) + '"] .cp-audit-notes__dna-input');
                            input?.focus();
                        });
                    }
                },
                duplicateDna(ref, phrase) { this.store.duplicateDna(ref, phrase); },
                removeDnaSlot(ref, phrase) { this.store.removeDnaSlot(ref, phrase); },
                removeTopic(ref) {
                    this.store.removeTopicLocal(ref);
                    if (this.$wire && typeof this.$wire.removeAuditNoteItem === 'function') this.$wire.removeAuditNoteItem(ref);
                },
                snapshot() { return this.store.snapshot(); },
            }));
        };

        document.addEventListener('alpine:init', register);
        if (window.Alpine) register();
    })();
</script>
@endonce
