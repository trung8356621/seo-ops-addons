@props([
    'items' => [],
    'hasDraft' => false,
    'counts' => ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0],
    'reviewFilter' => 'all',
    'typeFilter' => 'all',
    'selectedIds' => [],
    'hideSectionTitle' => false,
])

@php
    /** @var list<array<string, mixed>> $items */
    $allCount = (int) ($counts['all'] ?? 0);
    $unreviewedCount = (int) ($counts['unreviewed'] ?? 0);
    $reviewedCount = (int) ($counts['reviewed'] ?? 0);
    $selectedIds = array_values(array_map('intval', is_array($selectedIds) ? $selectedIds : []));
    $rows = array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'keyword' => (string) ($row['keyword'] ?? ''),
            'planning_reviewed' => ! empty($row['planning_reviewed']),
            'type' => (string) ($row['type'] ?? ''),
            'icon_kind' => (string) ($row['icon_kind'] ?? 'manual'),
            'seo_score_label' => (string) ($row['seo_score_label'] ?? '—'),
            'plan_label' => (string) ($row['plan_label'] ?? '—'),
            'post_type' => (string) ($row['post_type'] ?? ''),
            'post_type_label' => (string) ($row['post_type_label'] ?? '—'),
            'added_label' => (string) ($row['added_label'] ?? '—'),
            'added_at' => (string) ($row['added_at'] ?? ''),
            'title_href' => (string) ($row['title_href'] ?? ''),
            'title_external' => ! empty($row['title_external']),
            'article_public_url' => (string) ($row['article_public_url'] ?? ''),
            'article_edit_url' => (string) ($row['article_edit_url'] ?? ''),
            'check_index_url' => (string) ($row['check_index_url'] ?? ''),
            'can_check_index' => ! empty($row['can_check_index']),
            'can_open_public' => ! empty($row['can_open_public']),
            'can_edit_article' => ! empty($row['can_edit_article']),
            'can_skip_seo_audit' => ! empty($row['can_skip_seo_audit']),
            'visible' => true,
        ];
    }, $items);

    $boot = [
        'tab' => (string) $reviewFilter,
        'type' => (string) $typeFilter,
        'counts' => [
            'all' => $allCount,
            'unreviewed' => $unreviewedCount,
            'reviewed' => $reviewedCount,
        ],
        'rows' => $rows,
        'selected' => $selectedIds,
        'descriptionHint' => (string) __('seo-content-ai::filament.projects.planning_description_hint'),
        'confirmSkip' => (string) __('seo-content-ai::filament.projects.seo_audit_skip_confirm'),
        'confirmArchive' => (string) __('seo-content-ai::filament.projects.item_action_remove_from_draft_confirm'),
        'labelReviewed' => (string) __('seo-content-ai::filament.projects.planning_reviewed'),
        'labelUnreviewed' => (string) __('seo-content-ai::filament.projects.planning_unreviewed'),
        'markReviewed' => (string) __('seo-content-ai::filament.projects.planning_mark_reviewed'),
        'markUnreviewed' => (string) __('seo-content-ai::filament.projects.planning_mark_unreviewed'),
        'labelEditArticle' => (string) __('seo-content-ai::filament.projects.item_action_edit_article'),
        'labelOpenPublic' => (string) __('seo-content-ai::filament.projects.item_action_open_public'),
        'labelCheckIndex' => (string) __('seo-content-ai::filament.projects.suggestions_check_index'),
        'labelSkipSeoAudit' => (string) __('seo-content-ai::filament.projects.item_action_skip_seo_audit'),
        'labelRemove' => (string) __('seo-content-ai::filament.projects.item_action_remove_from_draft'),
        'seoPrefix' => 'SEO',
    ];
@endphp

@once
    <script>
        window.cpPlanDraftItems = window.cpPlanDraftItems || function (boot) {
            const cfg = boot && typeof boot === 'object' ? boot : {};
            const rows = (Array.isArray(cfg.rows) ? cfg.rows : []).map((row) => ({
                ...row,
                visible: row.visible !== false,
            }));

            return {
                tab: cfg.tab || 'all',
                type: cfg.type || 'all',
                counts: cfg.counts || { all: 0, unreviewed: 0, reviewed: 0 },
                rows,
                selected: Array.isArray(cfg.selected) ? cfg.selected.slice() : [],
                descriptionHint: cfg.descriptionHint || '',
                confirmSkip: cfg.confirmSkip || '',
                confirmArchive: cfg.confirmArchive || '',
                markReviewed: cfg.markReviewed || '',
                markUnreviewed: cfg.markUnreviewed || '',
                labelReviewed: cfg.labelReviewed || '',
                labelUnreviewed: cfg.labelUnreviewed || '',
                labelEditArticle: cfg.labelEditArticle || 'Edit article',
                labelOpenPublic: cfg.labelOpenPublic || 'Open public',
                labelCheckIndex: cfg.labelCheckIndex || 'Check index',
                labelSkipSeoAudit: cfg.labelSkipSeoAudit || 'Skip SEO Audit',
                labelRemove: cfg.labelRemove || 'Remove',
                seoPrefix: cfg.seoPrefix || 'SEO',
                editing: null,
                draft: '',
                blurGuardUntil: 0,
                alpineReady: false,

                init() {
                    this.alpineReady = true;
                    this.$root.querySelectorAll('[data-draft-ssr-row]').forEach((el) => el.remove());
                },

                setTab(next) {
                    this.tab = next;
                    this.selected = [];
                    this.$wire.setDraftReviewFilter(next);
                },

                setType(next) {
                    this.type = next;
                    this.selected = [];
                    this.$wire.setDraftTypeFilter(next);
                },

                toggleSelect(id) {
                    const n = Number(id);
                    const i = this.selected.indexOf(n);
                    if (i >= 0) {
                        this.selected.splice(i, 1);
                    } else {
                        this.selected.push(n);
                    }
                    this.$wire.set('selectedTaskIds', this.selected.slice());
                },

                toggleReview(row) {
                    const was = !!row.planning_reviewed;
                    row.planning_reviewed = !was;
                    if (was) {
                        this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                        this.counts.unreviewed += 1;
                    } else {
                        this.counts.unreviewed = Math.max(0, this.counts.unreviewed - 1);
                        this.counts.reviewed += 1;
                    }
                    if (this.tab === 'unreviewed' && row.planning_reviewed) {
                        row.visible = false;
                    }
                    if (this.tab === 'reviewed' && !row.planning_reviewed) {
                        row.visible = false;
                    }
                    this.$wire.setPlanningReviewed(row.id, row.planning_reviewed).catch(() => {
                        row.planning_reviewed = was;
                        if (was) {
                            this.counts.reviewed += 1;
                            this.counts.unreviewed = Math.max(0, this.counts.unreviewed - 1);
                        } else {
                            this.counts.unreviewed += 1;
                            this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                        }
                        row.visible = true;
                    });
                },

                startEdit(row, field) {
                    this.editing = row.id + ':' + field;
                    this.draft = field === 'title'
                        ? row.title
                        : (field === 'keyword' ? row.keyword : row.description);
                    // Dblclick's mouseup can blur a freshly focused control — ignore blur briefly.
                    this.blurGuardUntil = Date.now() + 350;
                    this.$nextTick(() => {
                        const key = String(row.id) + '-' + field;
                        const el = this.$root.querySelector('[data-inline-edit="' + key + '"]');
                        if (el) {
                            el.focus({ preventScroll: true });
                            if (field !== 'description' && typeof el.select === 'function') {
                                el.select();
                            }
                        }
                    });
                },

                cancelEdit() {
                    this.blurGuardUntil = 0;
                    this.editing = null;
                    this.draft = '';
                },

                onEditBlur(row, field) {
                    if (this.editing !== row.id + ':' + field) {
                        return;
                    }
                    if (Date.now() < this.blurGuardUntil) {
                        this.$nextTick(() => {
                            const key = String(row.id) + '-' + field;
                            const el = this.$root.querySelector('[data-inline-edit="' + key + '"]');
                            if (el && this.editing === row.id + ':' + field) {
                                el.focus({ preventScroll: true });
                            }
                        });

                        return;
                    }
                    this.saveEdit(row, field);
                },

                saveEdit(row, field) {
                    if (this.editing !== row.id + ':' + field) {
                        return;
                    }
                    const value = (this.draft || '').trim();
                    const prev = field === 'title'
                        ? row.title
                        : (field === 'keyword' ? row.keyword : row.description);
                    if (field === 'title' && value !== '') {
                        row.title = value;
                    }
                    if (field === 'keyword') {
                        row.keyword = value;
                    }
                    if (field === 'description') {
                        row.description = value;
                    }
                    this.blurGuardUntil = 0;
                    this.editing = null;
                    this.draft = '';
                    if (value === prev || (field === 'title' && value === '')) {
                        return;
                    }
                    this.$wire.updatePlanningField(row.id, field, value).catch(() => {
                        if (field === 'title') {
                            row.title = prev;
                        }
                        if (field === 'keyword') {
                            row.keyword = prev;
                        }
                        if (field === 'description') {
                            row.description = prev;
                        }
                    });
                },

                removeLocal(rowId) {
                    const idx = this.rows.findIndex((r) => r.id === rowId);
                    if (idx < 0) {
                        return;
                    }
                    const row = this.rows[idx];
                    this.counts.all = Math.max(0, this.counts.all - 1);
                    if (row.planning_reviewed) {
                        this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                    } else {
                        this.counts.unreviewed = Math.max(0, this.counts.unreviewed - 1);
                    }
                    this.selected = this.selected.filter((id) => id !== rowId);
                    this.rows.splice(idx, 1);
                },

                async skipRow(row) {
                    if (!window.confirm(this.confirmSkip)) {
                        return;
                    }
                    try {
                        await this.$wire.skipSeoAuditOne(row.id);
                        this.removeLocal(row.id);
                    } catch (e) {
                        // Halt / notify handled by Livewire; keep row.
                    }
                },

                async archiveRow(row) {
                    if (!window.confirm(this.confirmArchive)) {
                        return;
                    }
                    try {
                        await this.$wire.archiveOne(row.id);
                        this.removeLocal(row.id);
                    } catch (e) {
                        // Halt / notify handled by Livewire; keep row.
                    }
                },
            };
        };
    </script>
@endonce

<section
    class="cp-plan-draft cp-plan-draft--full"
    data-content-planning-draft-items="1"
    wire:key="cp-draft-items-{{ $reviewFilter }}-{{ $typeFilter }}"
    x-data="cpPlanDraftItems(@js($boot))"
>
    <div class="cp-plan-draft__head" @if ($hideSectionTitle) style="display:none" aria-hidden="true" @endif>
        <h3 class="cp-plan-draft__title">
            {{ __('seo-content-ai::filament.projects.planner_draft_items') }}
        </h3>
        @if ($hasDraft)
            <span class="cp-plan-draft__badge" x-text="counts.all">{{ $allCount }}</span>
        @endif
    </div>

    @if ($hasDraft)
        <div class="cp-plan-draft__tabs" data-draft-review-tabs="1">
            <button type="button" @click="setTab('all')" :class="tab === 'all' ? 'cp-plan-draft__tab is-active' : 'cp-plan-draft__tab'" data-draft-review-tab="all">
                {{ __('seo-content-ai::filament.projects.planning_tab_all') }}
                <span class="cp-plan-draft__tab-count" x-text="counts.all">{{ $allCount }}</span>
            </button>
            <button type="button" @click="setTab('unreviewed')" :class="tab === 'unreviewed' ? 'cp-plan-draft__tab is-active' : 'cp-plan-draft__tab'" data-draft-review-tab="unreviewed">
                {{ __('seo-content-ai::filament.projects.planning_tab_unreviewed') }}
                <span class="cp-plan-draft__tab-count" x-text="counts.unreviewed">{{ $unreviewedCount }}</span>
            </button>
            <button type="button" @click="setTab('reviewed')" :class="tab === 'reviewed' ? 'cp-plan-draft__tab is-active' : 'cp-plan-draft__tab'" data-draft-review-tab="reviewed">
                {{ __('seo-content-ai::filament.projects.planning_tab_reviewed') }}
                <span class="cp-plan-draft__tab-count" x-text="counts.reviewed">{{ $reviewedCount }}</span>
            </button>
        </div>

        <div class="cp-plan-draft__type-filter" data-draft-type-filter="1">
            <span class="cp-plan-chips__label">{{ __('seo-content-ai::filament.projects.planning_type_filter') }}</span>
            @foreach (['all' => __('seo-content-ai::filament.projects.planning_type_all'), 'rewrite' => 'Rewrite', 'improve' => 'Improve', 'create' => 'Create'] as $typeKey => $typeLabel)
                <button
                    type="button"
                    @click="setType('{{ $typeKey }}')"
                    :class="type === '{{ $typeKey }}' ? 'cp-plan-chip is-active' : 'cp-plan-chip'"
                    data-draft-type="{{ $typeKey }}"
                >{{ $typeLabel }}</button>
            @endforeach
        </div>
    @endif

    <div class="cp-plan-draft__body" wire:loading.class="opacity-60" wire:target="setDraftReviewFilter,setDraftTypeFilter,archiveOne,skipSeoAuditOne">
        @if (! $hasDraft)
            <div class="cp-plan-draft__empty text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.projects.content_planning_create_draft_first') }}
            </div>
        @else
            <div
                class="cp-plan-draft__empty"
                data-draft-empty="1"
                x-show="rows.length === 0 || rows.every(r => !r.visible)"
                @if (count($rows) > 0) style="display:none" @endif
            >
                <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.projects.content_planning_draft_empty_title') }}
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('seo-content-ai::filament.projects.content_planning_draft_empty_body') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="cp-plan-btn cp-plan-btn--improve" style="width:auto;flex:0 0 auto;" wire:click="fillSuggestions">
                        {{ __('seo-content-ai::filament.projects.planner_fill_from_seo_audit') }}
                    </button>
                    <button type="button" class="cp-plan-btn cp-plan-btn--create" style="width:auto;flex:0 0 auto;" wire:click="generateNewContentSuggestions">
                        {{ __('seo-content-ai::filament.projects.planner_generate_with_ai') }}
                    </button>
                </div>
            </div>

            <div
                class="cp-plan-draft-table-wrap"
                x-show="rows.some(r => r.visible)"
                @if (count($rows) === 0) style="display:none" @endif
            >
                <table class="cp-plan-draft-table min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500 dark:bg-gray-950/50 dark:text-gray-400">
                        <tr>
                            <th class="w-10 px-4 py-2.5"></th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_article') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.planning_col_keywords') }}</th>
                            <th class="px-3 py-2.5 cp-plan-draft-table__col-post-type">{{ __('seo-content-ai::filament.projects.planning_col_post_type') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_plan') }}</th>
                            <th class="px-3 py-2.5 w-20">{{ __('seo-content-ai::filament.projects.planning_col_review') }}</th>
                            <th class="px-3 py-2.5 cp-plan-draft-table__col-added">{{ __('seo-content-ai::filament.projects.planning_col_added') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $ssrRow)
                            <tr data-draft-ssr-row="{{ (int) ($ssrRow['id'] ?? 0) }}">
                                <td class="px-4 py-3 align-top"></td>
                                <td class="px-3 py-3 align-top">
                                    <div class="cp-plan-article-cell">
                                        <div class="min-w-0 flex-1">
                                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ $ssrRow['title'] }}
                                                <span class="cp-plan-seo-inline"> · SEO {{ $ssrRow['seo_score_label'] }}</span>
                                            </div>
                                            @if (($ssrRow['description'] ?? '') !== '')
                                                <p class="mt-0.5 text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $ssrRow['description'], 160) }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top text-sm text-gray-700 dark:text-gray-200">{{ $ssrRow['keyword'] }}</td>
                                <td class="px-3 py-3 align-top text-xs text-gray-600 cp-plan-draft-table__col-post-type">{{ $ssrRow['post_type_label'] }}</td>
                                <td class="px-3 py-3 align-top">
                                    <span class="cp-plan-badge cp-plan-badge--{{ $ssrRow['type'] === 'create' ? 'create' : ($ssrRow['type'] === 'improve' ? 'improve' : 'rewrite') }}">{{ $ssrRow['plan_label'] }}</span>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500">{{ ! empty($ssrRow['planning_reviewed']) ? ($boot['labelReviewed'] ?? 'Reviewed') : ($boot['labelUnreviewed'] ?? 'Unreviewed') }}</td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500 cp-plan-draft-table__col-added" @if (($ssrRow['added_at'] ?? '') !== '') title="{{ $ssrRow['added_at'] }}" @endif>{{ $ssrRow['added_label'] }}</td>
                            </tr>
                        @endforeach
                        <template x-for="row in rows" :key="row.id">
                            <tr x-show="!alpineReady || row.visible">
                                <td class="px-4 py-3 align-top">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-primary-600"
                                        :checked="selected.includes(row.id)"
                                        @change="toggleSelect(row.id)"
                                    >
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <div class="cp-plan-article-cell">
                                        <span class="cp-plan-article-icon" :class="'cp-plan-article-icon--' + row.icon_kind" aria-hidden="true">
                                            <template x-if="row.icon_kind === 'create'">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.2 3.6L17 8l-3.8 1.4L12 13l-1.2-3.6L7 8l3.8-1.4L12 3z"/></svg>
                                            </template>
                                            <template x-if="row.icon_kind === 'improve'">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 7-7"/><path d="M14 8h7v7"/></svg>
                                            </template>
                                            <template x-if="row.icon_kind === 'manual'">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>
                                            </template>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <template x-if="editing === row.id + ':title'">
                                                <input
                                                    type="text"
                                                    class="cp-plan-inline-input"
                                                    x-model="draft"
                                                    :data-inline-edit="row.id + '-title'"
                                                    @keydown.enter.prevent="saveEdit(row, 'title')"
                                                    @keydown.escape.prevent="cancelEdit()"
                                                    @blur="onEditBlur(row, 'title')"
                                                >
                                            </template>
                                            <template x-if="editing !== row.id + ':title'">
                                                <div class="cp-plan-title-line">
                                                    <template x-if="row.title_href">
                                                        <a :href="row.title_href" :target="row.title_external ? '_blank' : null" :rel="row.title_external ? 'noopener' : null" class="font-medium text-primary-600 hover:underline dark:text-primary-400" @dblclick.prevent="startEdit(row, 'title')" x-text="row.title"></a>
                                                    </template>
                                                    <template x-if="!row.title_href">
                                                        <span class="font-medium text-gray-900 dark:text-gray-100" @dblclick.prevent="startEdit(row, 'title')" x-text="row.title"></span>
                                                    </template>
                                                    <span class="cp-plan-seo-inline" x-text="' · ' + seoPrefix + ' ' + row.seo_score_label"></span>
                                                </div>
                                            </template>

                                            <template x-if="editing === row.id + ':description'">
                                                <textarea
                                                    class="cp-plan-inline-textarea mt-1"
                                                    rows="3"
                                                    x-model="draft"
                                                    :data-inline-edit="row.id + '-description'"
                                                    @keydown.escape.prevent="cancelEdit()"
                                                    @keydown.ctrl.enter.prevent="saveEdit(row, 'description')"
                                                    @blur="onEditBlur(row, 'description')"
                                                ></textarea>
                                            </template>
                                            <template x-if="editing !== row.id + ':description'">
                                                <div
                                                    class="mt-0.5 cursor-text text-xs leading-snug text-gray-500 dark:text-gray-400"
                                                    @dblclick.prevent="startEdit(row, 'description')"
                                                    x-text="row.description || descriptionHint"
                                                ></div>
                                            </template>

                                            <div class="cp-plan-row-actions cp-plan-row-actions--under">
                                                <template x-if="row.can_edit_article && row.article_edit_url">
                                                    <a :href="row.article_edit_url" target="_blank" rel="noopener" class="cp-plan-row-action" x-text="labelEditArticle"></a>
                                                </template>
                                                <template x-if="row.can_open_public && row.article_public_url">
                                                    <a :href="row.article_public_url" target="_blank" rel="noopener" class="cp-plan-row-action" x-text="labelOpenPublic"></a>
                                                </template>
                                                <template x-if="row.can_check_index && row.check_index_url">
                                                    <a :href="row.check_index_url" target="_blank" rel="noopener" class="cp-plan-row-action" x-text="labelCheckIndex"></a>
                                                </template>
                                                <template x-if="row.can_skip_seo_audit">
                                                    <button type="button" class="cp-plan-row-action cp-plan-row-action--warn" @click="skipRow(row)" x-text="labelSkipSeoAudit"></button>
                                                </template>
                                                <button type="button" class="cp-plan-row-action cp-plan-row-action--danger" @click="archiveRow(row)" x-text="labelRemove"></button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-700 dark:text-gray-200">
                                    <template x-if="editing === row.id + ':keyword'">
                                        <input
                                            type="text"
                                            class="cp-plan-inline-input"
                                            x-model="draft"
                                            :data-inline-edit="row.id + '-keyword'"
                                            @keydown.enter.prevent="saveEdit(row, 'keyword')"
                                            @keydown.escape.prevent="cancelEdit()"
                                            @blur="onEditBlur(row, 'keyword')"
                                        >
                                    </template>
                                    <template x-if="editing !== row.id + ':keyword'">
                                        <span class="cursor-text" @dblclick.prevent="startEdit(row, 'keyword')" x-text="row.keyword || '—'"></span>
                                    </template>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-600 dark:text-gray-300 cp-plan-draft-table__col-post-type" x-text="row.post_type_label"></td>
                                <td class="px-3 py-3 align-top">
                                    <span class="cp-plan-badge" :class="{
                                        'cp-plan-badge--rewrite': row.type === 'rewrite',
                                        'cp-plan-badge--improve': row.type === 'improve',
                                        'cp-plan-badge--create': row.type === 'create'
                                    }" x-text="row.plan_label"></span>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <button
                                        type="button"
                                        class="cp-plan-review-toggle"
                                        :class="row.planning_reviewed ? 'is-reviewed' : 'is-unreviewed'"
                                        @click="toggleReview(row)"
                                        :title="row.planning_reviewed ? markUnreviewed : markReviewed"
                                        :aria-label="row.planning_reviewed ? labelReviewed : labelUnreviewed"
                                    >
                                        <span x-text="row.planning_reviewed ? '✓' : '○'"></span>
                                    </button>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500 dark:text-gray-400 cp-plan-draft-table__col-added" :title="row.added_at || null" x-text="row.added_label"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
