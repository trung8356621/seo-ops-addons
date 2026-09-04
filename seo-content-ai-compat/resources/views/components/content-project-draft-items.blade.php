@props([
    'items' => [],
    'hasDraft' => false,
    'counts' => ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0],
    'reviewFilter' => 'all',
    'typeFilter' => 'all',
    'draftDomainFilter' => 'all',
    'selectedIds' => [],
    'hideSectionTitle' => false,
    'refreshNonce' => 0,
    'supportsProduct' => false,
    'siteOptions' => [],
    'showPublishInHeader' => false,
])

@php
    /** @var list<array<string, mixed>> $items */
    $allCount = (int) ($counts['all'] ?? 0);
    $unreviewedCount = (int) ($counts['unreviewed'] ?? 0);
    $reviewedCount = (int) ($counts['reviewed'] ?? 0);
    $selectedIds = array_values(array_map('intval', is_array($selectedIds) ? $selectedIds : []));
    $labelPost = (string) __('seo-content-ai::filament.article_list.post_type_post');
    $labelProduct = (string) __('seo-content-ai::filament.article_list.post_type_product');
    $postTypeOptions = [
        ['value' => 'post', 'label' => $labelPost],
    ];
    if ($supportsProduct) {
        $postTypeOptions[] = ['value' => 'product', 'label' => $labelProduct];
    }
    $siteOptionsList = [];
    foreach ((array) $siteOptions as $id => $domain) {
        $siteOptionsList[] = [
            'value' => (int) $id,
            'label' => (string) $domain,
        ];
    }
    $rows = array_map(static function (array $row) use ($labelPost, $labelProduct): array {
        $postType = (string) ($row['post_type'] ?? '');
        if ($postType === '' || $postType === 'article') {
            $postType = 'post';
        }
        $canEditPostType = ! empty($row['can_edit_post_type']);
        $siteId = isset($row['site_id']) ? (int) $row['site_id'] : 0;
        $domain = trim((string) ($row['domain'] ?? ''));
        if ($domain === '') {
            $domain = '—';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'product_description' => (string) ($row['product_description'] ?? ''),
            'keyword' => (string) ($row['keyword'] ?? ''),
            'site_id' => $siteId > 0 ? $siteId : null,
            'domain' => $domain,
            'planning_reviewed' => ! empty($row['planning_reviewed']),
            'type' => (string) ($row['type'] ?? ''),
            'icon_kind' => (string) ($row['icon_kind'] ?? 'manual'),
            'seo_score_label' => (string) ($row['seo_score_label'] ?? '—'),
            'plan_label' => (string) ($row['plan_label'] ?? '—'),
            'post_type' => $postType,
            'post_type_label' => (string) ($row['post_type_label'] ?? ($postType === 'product' ? $labelProduct : $labelPost)),
            'can_edit_post_type' => $canEditPostType,
            'can_clone_idea' => ! empty($row['can_clone_idea']) || ((string) ($row['type'] ?? '') === 'create'),
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
            'saving_post_type' => false,
            'saving_domain' => false,
            'cloning' => false,
        ];
    }, $items);

    $boot = [
        'tab' => (string) $reviewFilter,
        'type' => (string) $typeFilter,
        'domainFilter' => (string) $draftDomainFilter,
        'counts' => [
            'all' => $allCount,
            'unreviewed' => $unreviewedCount,
            'reviewed' => $reviewedCount,
        ],
        'rows' => $rows,
        'selected' => $selectedIds,
        'siteOptions' => $siteOptionsList,
        'postTypeOptions' => $postTypeOptions,
        'labelPost' => $labelPost,
        'labelProduct' => $labelProduct,
        'descriptionHint' => (string) __('seo-content-ai::filament.projects.planning_description_hint'),
        'productDescriptionLabel' => (string) __('seo-content-ai::filament.projects.planning_product_description_label'),
        'postTypeEditHint' => (string) __('seo-content-ai::filament.projects.planning_post_type_edit_hint'),
        'domainEditHint' => (string) __('seo-content-ai::filament.projects.planning_domain_edit_hint'),
        'confirmSkip' => (string) __('seo-content-ai::filament.projects.seo_audit_skip_confirm'),
        'confirmArchive' => (string) __('seo-content-ai::filament.projects.item_action_remove_from_draft_confirm'),
        'confirmBulkArchive' => (string) __('seo-content-ai::filament.projects.planning_bulk_remove_confirm'),
        'labelReviewed' => (string) __('seo-content-ai::filament.projects.planning_reviewed'),
        'labelUnreviewed' => (string) __('seo-content-ai::filament.projects.planning_unreviewed'),
        'markReviewed' => (string) __('seo-content-ai::filament.projects.planning_mark_reviewed'),
        'markUnreviewed' => (string) __('seo-content-ai::filament.projects.planning_mark_unreviewed'),
        'labelBulkSelected' => (string) __('seo-content-ai::filament.projects.planning_bulk_selected_count'),
        'labelBulkMarkReviewed' => (string) __('seo-content-ai::filament.projects.planning_bulk_mark_reviewed'),
        'labelBulkRemove' => (string) __('seo-content-ai::filament.projects.planning_bulk_remove'),
        'labelEditArticle' => (string) __('seo-content-ai::filament.projects.item_action_edit_article'),
        'labelOpenPublic' => (string) __('seo-content-ai::filament.projects.item_action_open_public'),
        'labelCheckIndex' => (string) __('seo-content-ai::filament.projects.suggestions_check_index'),
        'labelSkipSeoAudit' => (string) __('seo-content-ai::filament.projects.item_action_skip_seo_audit'),
        'labelRemove' => (string) __('seo-content-ai::filament.projects.item_action_remove_from_draft'),
        'labelCloneIdea' => (string) __('seo-content-ai::filament.projects.planning_clone_idea'),
        'domainBlank' => (string) __('seo-content-ai::filament.projects.planning_domain_blank'),
        'domainRequired' => (string) __('seo-content-ai::filament.projects.planning_domain_required_before_review'),
        'seoPrefix' => 'SEO',
    ];
@endphp

@once
    <script>
        window.cpPlanDraftItems = window.cpPlanDraftItems || function (boot) {
            const cfg = boot && typeof boot === 'object' ? boot : {};
            const rows = (Array.isArray(cfg.rows) ? cfg.rows : []).map((row) => ({
                ...row,
                site_id: row.site_id != null && Number(row.site_id) > 0 ? Number(row.site_id) : null,
                domain: String(row.domain ?? cfg.domainBlank ?? '—'),
                visible: row.visible !== false,
            }));

            return {
                tab: cfg.tab || 'all',
                type: cfg.type || 'all',
                domainFilter: cfg.domainFilter || 'all',
                counts: cfg.counts || { all: 0, unreviewed: 0, reviewed: 0 },
                rows,
                selected: Array.isArray(cfg.selected) ? cfg.selected.slice() : [],
                siteOptions: Array.isArray(cfg.siteOptions) ? cfg.siteOptions : [],
                descriptionHint: cfg.descriptionHint || '',
                productDescriptionLabel: cfg.productDescriptionLabel || 'Product description:',
                postTypeEditHint: cfg.postTypeEditHint || 'Double click to change post type',
                domainEditHint: cfg.domainEditHint || 'Double-click to change Domain',
                confirmSkip: cfg.confirmSkip || '',
                confirmArchive: cfg.confirmArchive || '',
                confirmBulkArchive: cfg.confirmBulkArchive || '',
                markReviewed: cfg.markReviewed || '',
                markUnreviewed: cfg.markUnreviewed || '',
                labelReviewed: cfg.labelReviewed || '',
                labelUnreviewed: cfg.labelUnreviewed || '',
                labelBulkSelected: cfg.labelBulkSelected || ':count selected',
                labelBulkMarkReviewed: cfg.labelBulkMarkReviewed || 'Review',
                labelBulkRemove: cfg.labelBulkRemove || 'Delete',
                labelEditArticle: cfg.labelEditArticle || 'Edit article',
                labelOpenPublic: cfg.labelOpenPublic || 'Open public',
                labelCheckIndex: cfg.labelCheckIndex || 'Check index',
                labelSkipSeoAudit: cfg.labelSkipSeoAudit || 'Skip SEO Audit',
                labelRemove: cfg.labelRemove || 'Remove',
                labelCloneIdea: cfg.labelCloneIdea || 'Clone idea',
                domainBlank: cfg.domainBlank || '—',
                domainRequired: cfg.domainRequired || 'Domain is required before review.',
                seoPrefix: cfg.seoPrefix || 'SEO',
                postTypeOptions: Array.isArray(cfg.postTypeOptions) ? cfg.postTypeOptions : [],
                labelPost: cfg.labelPost || 'Post',
                labelProduct: cfg.labelProduct || 'Product',
                editing: null,
                editingPostTypeId: null,
                editingDomainId: null,
                draft: '',
                blurGuardUntil: 0,
                alpineReady: false,
                bulkBusy: false,

                /** Livewire morph-safe root — never assume $root exists. */
                rootEl() {
                    if (this.$el && typeof this.$el.querySelector === 'function') {
                        return this.$el.closest('[data-content-planning-draft-items]') || this.$el;
                    }
                    if (this.$root && typeof this.$root.querySelector === 'function') {
                        return this.$root;
                    }

                    return null;
                },

                qs(selector) {
                    const root = this.rootEl();
                    if (! root || typeof root.querySelector !== 'function') {
                        return null;
                    }

                    return root.querySelector(selector);
                },

                qsa(selector) {
                    const root = this.rootEl();
                    if (! root || typeof root.querySelectorAll !== 'function') {
                        return [];
                    }

                    return Array.from(root.querySelectorAll(selector));
                },

                init() {
                    this.alpineReady = true;
                    this.qsa('[data-draft-ssr-row]').forEach((el) => el.remove());
                    this.applyVisibility();
                    this.$nextTick(() => this.syncDomainFilterSelect());
                },

                syncDomainFilterSelect() {
                    const sel = this.qs('[data-draft-domain-filter="1"] select');
                    if (! sel) {
                        return;
                    }
                    const want = String(this.domainFilter || 'all');
                    if (sel.value !== want) {
                        sel.value = want;
                    }
                },

                domainLabelFor(siteId) {
                    const id = Number(siteId || 0);
                    if (id <= 0) {
                        return this.domainBlank;
                    }
                    const hit = this.siteOptions.find((o) => Number(o.value) === id);

                    return hit && hit.label ? hit.label : ('#' + id);
                },

                applyVisibility() {
                    const domainFilter = this.domainFilter;
                    this.rows.forEach((row) => {
                        let ok = true;
                        if (this.tab === 'unreviewed' && row.planning_reviewed) {
                            ok = false;
                        }
                        if (this.tab === 'reviewed' && ! row.planning_reviewed) {
                            ok = false;
                        }
                        if (this.type !== 'all' && row.type !== this.type) {
                            ok = false;
                        }
                        if (domainFilter !== 'all') {
                            const want = Number(domainFilter);
                            const have = Number(row.site_id || 0);
                            if (want === 0) {
                                ok = ok && have <= 0;
                            } else {
                                ok = ok && have === want;
                            }
                        }
                        row.visible = ok;
                    });
                },

                /** 1-based display index among currently visible rows (not DB id). */
                displayStt(row) {
                    let n = 0;
                    for (let i = 0; i < this.rows.length; i++) {
                        const r = this.rows[i];
                        if (! r.visible) {
                            continue;
                        }
                        n += 1;
                        if (r.id === row.id) {
                            return n;
                        }
                    }

                    return '';
                },

                rowMatchesDomainProjection(row) {
                    const domainFilter = String(this.domainFilter || 'all');
                    if (domainFilter === 'all') {
                        return true;
                    }
                    const want = Number(domainFilter);
                    const have = Number(row.site_id || 0);
                    if (want === 0) {
                        return have <= 0;
                    }

                    return have === want;
                },

                setDomainFilter(next) {
                    const value = String(next || 'all');
                    const prev = String(this.domainFilter || 'all');
                    if (value === prev) {
                        return;
                    }
                    this.domainFilter = value;
                    // Server remounts domain-scoped rows + counts (do not client-filter a global payload).
                    this.$wire.setDraftDomainFilter(value);
                },

                postTypeLabelFor(value) {
                    const key = value === 'product' ? 'product' : 'post';
                    const hit = this.postTypeOptions.find((o) => o.value === key);
                    if (hit && hit.label) {
                        return hit.label;
                    }
                    return key === 'product' ? this.labelProduct : this.labelPost;
                },

                postTypeSelectOptions(row) {
                    const opts = this.postTypeOptions.slice();
                    if (row.post_type === 'product' && !opts.some((o) => o.value === 'product')) {
                        opts.push({ value: 'product', label: this.labelProduct });
                    }
                    return opts;
                },

                async changePostType(row, event) {
                    if (!row.can_edit_post_type || row.saving_post_type) {
                        return;
                    }
                    const next = String(event.target.value || '');
                    const prev = row.post_type === 'product' ? 'product' : 'post';
                    const prevLabel = row.post_type_label;
                    const wasReviewed = !!row.planning_reviewed;
                    if (next === prev) {
                        this.editingPostTypeId = null;

                        return;
                    }
                    row.post_type = next;
                    row.post_type_label = this.postTypeLabelFor(next);
                    row.saving_post_type = true;
                    try {
                        await this.$wire.updateDraftPlanningItem(row.id, 'post_type', next);
                        if (wasReviewed) {
                            row.planning_reviewed = false;
                            this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                            this.counts.unreviewed += 1;
                        }
                        this.applyVisibility();
                    } catch (e) {
                        row.post_type = prev;
                        row.post_type_label = prevLabel;
                        event.target.value = prev;
                    } finally {
                        row.saving_post_type = false;
                        this.editingPostTypeId = null;
                    }
                },

                startPostTypeEdit(row) {
                    if (!row.can_edit_post_type || row.saving_post_type) {
                        return;
                    }
                    this.editingPostTypeId = row.id;
                    this.blurGuardUntil = Date.now() + 350;
                    this.$nextTick(() => {
                        const el = this.qs('[data-post-type-edit="' + String(row.id) + '"]');
                        if (el && typeof el.focus === 'function') {
                            el.focus({ preventScroll: true });
                        }
                    });
                },

                cancelPostTypeEdit() {
                    this.editingPostTypeId = null;
                },

                onPostTypeBlur(row) {
                    if (this.editingPostTypeId !== row.id || row.saving_post_type) {
                        return;
                    }
                    if (Date.now() < this.blurGuardUntil) {
                        this.$nextTick(() => {
                            const el = this.qs('[data-post-type-edit="' + String(row.id) + '"]');
                            if (el && this.editingPostTypeId === row.id && typeof el.focus === 'function') {
                                el.focus({ preventScroll: true });
                            }
                        });

                        return;
                    }
                    this.editingPostTypeId = null;
                },

                startDomainEdit(row) {
                    if (row.saving_domain) {
                        return;
                    }
                    this.editingDomainId = row.id;
                    this.blurGuardUntil = Date.now() + 350;
                    this.$nextTick(() => {
                        const el = this.qs('[data-domain-edit="' + String(row.id) + '"]');
                        if (el && typeof el.focus === 'function') {
                            el.focus({ preventScroll: true });
                        }
                    });
                },

                cancelDomainEdit() {
                    this.editingDomainId = null;
                },

                onDomainBlur(row) {
                    if (this.editingDomainId !== row.id || row.saving_domain) {
                        return;
                    }
                    if (Date.now() < this.blurGuardUntil) {
                        this.$nextTick(() => {
                            const el = this.qs('[data-domain-edit="' + String(row.id) + '"]');
                            if (el && this.editingDomainId === row.id && typeof el.focus === 'function') {
                                el.focus({ preventScroll: true });
                            }
                        });

                        return;
                    }
                    this.editingDomainId = null;
                },

                async changeDomain(row, event) {
                    if (row.saving_domain) {
                        return;
                    }
                    const raw = String(event.target.value || '');
                    const next = raw === '' ? null : Number(raw);
                    const prev = row.site_id ? Number(row.site_id) : null;
                    const prevDomain = row.domain;
                    if ((next || null) === (prev || null)) {
                        this.editingDomainId = null;

                        return;
                    }
                    row.site_id = next && next > 0 ? next : null;
                    row.domain = this.domainLabelFor(row.site_id);
                    row.saving_domain = true;
                    try {
                        await this.$wire.updatePlanningField(row.id, 'site_id', row.site_id ? String(row.site_id) : '0');
                        if (! this.rowMatchesDomainProjection(row)) {
                            this.removeLocal(row.id);
                        } else {
                            this.applyVisibility();
                        }
                    } catch (e) {
                        row.site_id = prev;
                        row.domain = prevDomain;
                        event.target.value = prev ? String(prev) : '';
                    } finally {
                        row.saving_domain = false;
                        this.editingDomainId = null;
                    }
                },

                showProductDescription(row) {
                    return row.post_type === 'product' && String(row.product_description || '').trim() !== '';
                },

                setTab(next) {
                    this.tab = next;
                    this.selected = [];
                    this.$wire.setDraftReviewFilter(next);
                    this.applyVisibility();
                },

                setType(next) {
                    this.type = next;
                    this.selected = [];
                    this.$wire.setDraftTypeFilter(next);
                    this.applyVisibility();
                },

                toggleSelect(id) {
                    const n = Number(id);
                    const i = this.selected.indexOf(n);
                    if (i >= 0) {
                        this.selected.splice(i, 1);
                    } else {
                        this.selected.push(n);
                    }
                    this.syncSelectedToWire();
                },

                visibleIds() {
                    return this.rows.filter((r) => r.visible).map((r) => Number(r.id));
                },

                allVisibleSelected() {
                    const visible = this.visibleIds();

                    return visible.length > 0 && visible.every((id) => this.selected.includes(id));
                },

                someVisibleSelected() {
                    const visible = this.visibleIds();
                    if (visible.length === 0) {
                        return false;
                    }
                    const hit = visible.filter((id) => this.selected.includes(id)).length;

                    return hit > 0 && hit < visible.length;
                },

                toggleSelectAllVisible() {
                    const visible = this.visibleIds();
                    if (visible.length === 0) {
                        return;
                    }
                    if (this.allVisibleSelected()) {
                        const drop = new Set(visible);
                        this.selected = this.selected.filter((id) => ! drop.has(id));
                    } else {
                        this.selected = Array.from(new Set([...this.selected, ...visible]));
                    }
                    this.syncSelectedToWire();
                },

                syncSelectedToWire() {
                    this.$wire.set('selectedTaskIds', this.selected.slice());
                },

                selectedCountLabel() {
                    return String(this.labelBulkSelected || ':count selected')
                        .replace(':count', String(this.selected.length));
                },

                async bulkMarkReviewed() {
                    if (this.bulkBusy || this.selected.length === 0) {
                        return;
                    }
                    const eligible = this.rows.filter((row) =>
                        this.selected.includes(row.id)
                        && ! row.planning_reviewed
                        && row.site_id
                        && Number(row.site_id) > 0
                    );
                    const missingDomain = this.rows.filter((row) =>
                        this.selected.includes(row.id)
                        && ! row.planning_reviewed
                        && (! row.site_id || Number(row.site_id) <= 0)
                    );
                    if (eligible.length === 0) {
                        if (missingDomain.length > 0) {
                            window.alert(this.domainRequired);
                        }

                        return;
                    }

                    const prevById = {};
                    eligible.forEach((row) => {
                        prevById[row.id] = true;
                        row.planning_reviewed = true;
                        this.counts.unreviewed = Math.max(0, this.counts.unreviewed - 1);
                        this.counts.reviewed += 1;
                    });
                    this.applyVisibility();
                    this.bulkBusy = true;
                    try {
                        const result = await this.$wire.markReviewedSelected();
                        const okIds = Array.isArray(result && result.reviewed_ids)
                            ? result.reviewed_ids.map((id) => Number(id))
                            : [];
                        const okSet = new Set(okIds);
                        Object.keys(prevById).forEach((rawId) => {
                            const id = Number(rawId);
                            if (okSet.has(id)) {
                                return;
                            }
                            const row = this.rows.find((r) => r.id === id);
                            if (! row) {
                                return;
                            }
                            row.planning_reviewed = false;
                            this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                            this.counts.unreviewed += 1;
                        });
                        if (result && result.ok) {
                            this.selected = this.selected.filter((id) => ! okSet.has(id));
                            this.syncSelectedToWire();
                        }
                        this.applyVisibility();
                    } catch (e) {
                        Object.keys(prevById).forEach((rawId) => {
                            const id = Number(rawId);
                            const row = this.rows.find((r) => r.id === id);
                            if (! row || ! row.planning_reviewed) {
                                return;
                            }
                            row.planning_reviewed = false;
                            this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                            this.counts.unreviewed += 1;
                        });
                        this.applyVisibility();
                    } finally {
                        this.bulkBusy = false;
                    }
                },

                async bulkArchive() {
                    if (this.bulkBusy || this.selected.length === 0) {
                        return;
                    }
                    const msg = String(this.confirmBulkArchive || this.confirmArchive || '')
                        .replace(':count', String(this.selected.length));
                    if (! window.confirm(msg)) {
                        return;
                    }
                    const ids = this.selected.slice();
                    const snapshots = [];
                    ids.forEach((id) => {
                        const snap = this.removeLocal(id);
                        if (snap) {
                            snapshots.push(snap);
                        }
                    });
                    this.syncSelectedToWire();
                    this.bulkBusy = true;
                    try {
                        const ok = await this.$wire.archiveSelected();
                        if (! ok) {
                            snapshots.reverse().forEach((snap) => this.restoreLocal(snap));
                            this.selected = ids.slice();
                            this.syncSelectedToWire();
                        }
                    } catch (e) {
                        snapshots.reverse().forEach((snap) => this.restoreLocal(snap));
                        this.selected = ids.slice();
                        this.syncSelectedToWire();
                    } finally {
                        this.bulkBusy = false;
                    }
                },

                toggleReview(row) {
                    const was = !!row.planning_reviewed;
                    if (! was && (! row.site_id || Number(row.site_id) <= 0)) {
                        window.alert(this.domainRequired);

                        return;
                    }
                    row.planning_reviewed = !was;
                    if (was) {
                        this.counts.reviewed = Math.max(0, this.counts.reviewed - 1);
                        this.counts.unreviewed += 1;
                    } else {
                        this.counts.unreviewed = Math.max(0, this.counts.unreviewed - 1);
                        this.counts.reviewed += 1;
                    }
                    this.applyVisibility();
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
                        this.applyVisibility();
                    });
                },

                startEdit(row, field) {
                    this.editing = row.id + ':' + field;
                    this.draft = field === 'title'
                        ? row.title
                        : (field === 'keyword' ? row.keyword : row.description);
                    this.blurGuardUntil = Date.now() + 350;
                    this.$nextTick(() => {
                        const key = String(row.id) + '-' + field;
                        const el = this.qs('[data-inline-edit="' + key + '"]');
                        if (el && typeof el.focus === 'function') {
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
                            const el = this.qs('[data-inline-edit="' + key + '"]');
                            if (el && this.editing === row.id + ':' + field && typeof el.focus === 'function') {
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
                    if (field === 'title') {
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
                    if (value === prev) {
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

                normalizeBootRow(raw) {
                    if (! raw || typeof raw !== 'object') {
                        return null;
                    }
                    const postType = (raw.post_type === 'product') ? 'product' : 'post';
                    const siteId = raw.site_id ? Number(raw.site_id) : null;

                    return {
                        id: Number(raw.id || 0),
                        title: String(raw.title || ''),
                        description: String(raw.description || ''),
                        product_description: String(raw.product_description || ''),
                        keyword: String(raw.keyword || ''),
                        site_id: siteId && siteId > 0 ? siteId : null,
                        domain: String(raw.domain || this.domainBlank),
                        planning_reviewed: !!raw.planning_reviewed,
                        type: String(raw.type || 'create'),
                        icon_kind: String(raw.icon_kind || 'create'),
                        seo_score_label: String(raw.seo_score_label || '—'),
                        plan_label: String(raw.plan_label || 'Create'),
                        post_type: postType,
                        post_type_label: String(raw.post_type_label || this.postTypeLabelFor(postType)),
                        can_edit_post_type: !!raw.can_edit_post_type,
                        can_clone_idea: !!raw.can_clone_idea || String(raw.type || '') === 'create',
                        added_label: String(raw.added_label || '—'),
                        added_at: String(raw.added_at || ''),
                        title_href: String(raw.title_href || ''),
                        title_external: !!raw.title_external,
                        article_public_url: String(raw.article_public_url || ''),
                        article_edit_url: String(raw.article_edit_url || ''),
                        check_index_url: String(raw.check_index_url || ''),
                        can_check_index: !!raw.can_check_index,
                        can_open_public: !!raw.can_open_public,
                        can_edit_article: !!raw.can_edit_article,
                        can_skip_seo_audit: !!raw.can_skip_seo_audit,
                        visible: true,
                        saving_post_type: false,
                        saving_domain: false,
                        cloning: false,
                    };
                },

                async cloneIdea(row) {
                    if (! row.can_clone_idea || row.cloning) {
                        return;
                    }
                    row.cloning = true;
                    try {
                        const result = await this.$wire.cloneDraftIdea(row.id);
                        if (result && result.counts) {
                            this.counts = {
                                all: Number(result.counts.all || this.counts.all),
                                unreviewed: Number(result.counts.unreviewed || this.counts.unreviewed),
                                reviewed: Number(result.counts.reviewed || this.counts.reviewed),
                            };
                        }
                        const normalized = this.normalizeBootRow(result && result.row ? result.row : null);
                        if (normalized && normalized.id > 0) {
                            this.rows.unshift(normalized);
                            this.applyVisibility();
                        }
                    } catch (e) {
                        // Livewire Halt / notification
                    } finally {
                        row.cloning = false;
                    }
                },

                removeLocal(rowId) {
                    const idx = this.rows.findIndex((r) => r.id === rowId);
                    if (idx < 0) {
                        return null;
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

                    return { row, index: idx };
                },

                restoreLocal(snapshot) {
                    if (! snapshot || ! snapshot.row) {
                        return;
                    }
                    const row = snapshot.row;
                    const id = Number(row.id || 0);
                    if (id > 0 && this.rows.some((r) => r.id === id)) {
                        return;
                    }
                    const at = Math.min(Math.max(0, Number(snapshot.index || 0)), this.rows.length);
                    this.rows.splice(at, 0, row);
                    this.counts.all += 1;
                    if (row.planning_reviewed) {
                        this.counts.reviewed += 1;
                    } else {
                        this.counts.unreviewed += 1;
                    }
                    this.applyVisibility();
                },

                async skipRow(row) {
                    if (!window.confirm(this.confirmSkip)) {
                        return;
                    }
                    const snapshot = this.removeLocal(row.id);
                    try {
                        const ok = await this.$wire.skipSeoAuditOne(row.id);
                        if (! ok) {
                            this.restoreLocal(snapshot);
                        }
                    } catch (e) {
                        this.restoreLocal(snapshot);
                    }
                },

                async archiveRow(row) {
                    if (!window.confirm(this.confirmArchive)) {
                        return;
                    }
                    // Optimistic: drop from list immediately, then persist.
                    const snapshot = this.removeLocal(row.id);
                    try {
                        const ok = await this.$wire.archiveOne(row.id);
                        if (! ok) {
                            this.restoreLocal(snapshot);
                        }
                    } catch (e) {
                        this.restoreLocal(snapshot);
                    }
                },
            };
        };
    </script>
@endonce

<section
    class="cp-plan-draft cp-plan-draft--full"
    data-content-planning-draft-items="1"
    wire:key="cp-draft-items-{{ $reviewFilter }}-{{ $typeFilter }}-{{ $draftDomainFilter }}-{{ (int) $refreshNonce }}"
    x-data="cpPlanDraftItems(@js($boot))"
>
    <div class="cp-plan-draft-header cp-plan-draft__head" @if ($hideSectionTitle) style="display:none" aria-hidden="true" @endif>
        <div class="cp-plan-draft-header__title">
            <h3 class="cp-plan-draft__title">
                {{ __('seo-content-ai::filament.projects.planner_draft_items') }}<span class="cp-plan-draft__title-count" x-text="' · ' + counts.all"> · {{ $allCount }}</span>
            </h3>
        </div>
        @if ($hasDraft)
            <div class="cp-plan-draft-header__domain" data-draft-domain-filter="1">
                <span class="cp-plan-draft-header__domain-label">{{ __('seo-content-ai::filament.projects.planning_domain_filter') }}</span>
                <select
                    class="cp-plan-inline-select cp-plan-inline-select--domain-filter"
                    :value="domainFilter"
                    @change="setDomainFilter($event.target.value)"
                    wire:loading.attr="disabled"
                    wire:target="setDraftDomainFilter"
                    aria-label="{{ __('seo-content-ai::filament.projects.planning_domain_filter') }}"
                >
                    <option value="all" @selected((string) $draftDomainFilter === 'all')>{{ __('seo-content-ai::filament.projects.planning_domain_filter_all') }}</option>
                    <option value="0" @selected((string) $draftDomainFilter === '0')>{{ __('seo-content-ai::filament.projects.planning_domain_blank') }}</option>
                    @foreach ($siteOptionsList as $siteOpt)
                        <option
                            value="{{ $siteOpt['value'] }}"
                            @selected((string) $draftDomainFilter === (string) $siteOpt['value'])
                        >{{ $siteOpt['label'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($showPublishInHeader && $hasDraft)
            <button
                type="button"
                wire:click="openPublishFromPlanner"
                wire:loading.attr="disabled"
                wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit"
                class="cp-plan-btn cp-plan-btn--publish cp-plan-draft-header__publish"
                data-content-planning-action="publish"
            >
                <svg wire:loading.remove wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 00-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 012-3.95A12.88 12.88 0 0122 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 01-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                <span wire:loading.remove wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit">
                    {{ __('seo-content-ai::filament.projects.content_planning_publish') }}
                </span>
                <span wire:loading wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit" class="inline-flex items-center gap-1">
                    <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                </span>
            </button>
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

        <div class="cp-plan-draft__filters-row">
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
        </div>

        <div
            class="cp-plan-draft__bulk"
            data-draft-bulk-toolbar="1"
            x-show="selected.length > 0"
            x-cloak
            style="display:none"
        >
            <span class="cp-plan-draft__bulk-count" x-text="selectedCountLabel()"></span>
            <div class="cp-plan-draft__bulk-actions">
                <button
                    type="button"
                    class="cp-plan-btn cp-plan-btn--improve"
                    style="width:auto;flex:0 0 auto;"
                    :class="{ 'opacity-50 pointer-events-none': bulkBusy }"
                    :disabled="bulkBusy"
                    wire:loading.attr="disabled"
                    wire:target="markReviewedSelected"
                    @click="bulkMarkReviewed()"
                    data-draft-bulk-action="review"
                >
                    <span wire:loading.remove wire:target="markReviewedSelected" x-text="labelBulkMarkReviewed"></span>
                    <span wire:loading wire:target="markReviewedSelected" class="inline-flex items-center gap-1">
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    </span>
                </button>
                <button
                    type="button"
                    class="cp-plan-btn cp-plan-btn--danger"
                    style="width:auto;flex:0 0 auto;"
                    :class="{ 'opacity-50 pointer-events-none': bulkBusy }"
                    :disabled="bulkBusy"
                    wire:loading.attr="disabled"
                    wire:target="archiveSelected"
                    @click="bulkArchive()"
                    data-draft-bulk-action="remove"
                >
                    <span wire:loading.remove wire:target="archiveSelected" x-text="labelBulkRemove"></span>
                    <span wire:loading wire:target="archiveSelected" class="inline-flex items-center gap-1">
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    </span>
                </button>
            </div>
        </div>
    @endif

    <x-seo-content-ai::list-table-loading-shell
        class="cp-plan-draft__body"
        preset="livewire-page"
        targets="setDraftReviewFilter,setDraftTypeFilter,setDraftDomainFilter,draftReviewFilter,draftTypeFilter,draftDomainFilter,onDomainContextChanged,markReviewedSelected,archiveSelected"
    >
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
                            <th class="w-10 px-4 py-2.5">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300 text-primary-600"
                                    :checked="allVisibleSelected()"
                                    :indeterminate.prop="someVisibleSelected()"
                                    @change="toggleSelectAllVisible()"
                                    :disabled="bulkBusy || visibleIds().length === 0"
                                    aria-label="{{ __('seo-content-ai::filament.projects.planning_bulk_select_all') }}"
                                    data-draft-select-all="1"
                                >
                            </th>
                            <th class="cp-plan-draft-table__col-stt px-2 py-2.5 text-center">{{ __('seo-content-ai::filament.projects.planning_col_stt') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_article') }}</th>
                            <th class="px-3 py-2.5 cp-plan-draft-table__col-domain">{{ __('seo-content-ai::filament.projects.planning_col_domain') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.planning_col_keywords') }}</th>
                            <th class="px-3 py-2.5 cp-plan-draft-table__col-post-type">{{ __('seo-content-ai::filament.projects.planning_col_post_type') }}</th>
                            <th class="px-3 py-2.5">{{ __('seo-content-ai::filament.projects.suggestions_col_plan') }}</th>
                            <th class="px-3 py-2.5 w-20">{{ __('seo-content-ai::filament.projects.planning_col_review') }}</th>
                            <th class="px-3 py-2.5 cp-plan-draft-table__col-added">{{ __('seo-content-ai::filament.projects.planning_col_added') }}</th>
                            <th class="px-3 py-2.5 cp-plan-draft-table__col-actions">{{ __('seo-content-ai::filament.projects.planning_col_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody wire:ignore class="divide-y divide-gray-100 dark:divide-white/5" data-draft-tbody-alpine-owned="1">
                        @foreach ($rows as $ssrRow)
                            <tr data-draft-ssr-row="{{ (int) ($ssrRow['id'] ?? 0) }}">
                                <td class="px-4 py-3 align-top">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-primary-600 opacity-40"
                                        disabled
                                        aria-hidden="true"
                                        tabindex="-1"
                                    >
                                </td>
                                <td class="cp-plan-draft-table__col-stt px-2 py-3 align-top text-center text-xs text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                                <td class="px-3 py-3 align-top">
                                    <div class="cp-plan-article-cell">
                                        <div class="min-w-0 flex-1">
                                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ trim((string) ($ssrRow['title'] ?? '')) !== '' ? $ssrRow['title'] : '—' }}
                                                <span class="cp-plan-seo-inline"> · SEO {{ $ssrRow['seo_score_label'] }}</span>
                                            </div>
                                            @if (($ssrRow['description'] ?? '') !== '')
                                                <p class="mt-0.5 text-xs text-gray-500">{{ \Illuminate\Support\Str::limit((string) $ssrRow['description'], 160) }}</p>
                                            @endif
                                            @if (($ssrRow['post_type'] ?? '') === 'product' && trim((string) ($ssrRow['product_description'] ?? '')) !== '')
                                                <p class="mt-0.5 text-xs leading-snug text-gray-500 dark:text-gray-400">
                                                    <span class="font-medium text-gray-600 dark:text-gray-300">{{ $boot['productDescriptionLabel'] ?? 'Mô tả sản phẩm:' }}</span>
                                                    {{ \Illuminate\Support\Str::limit((string) $ssrRow['product_description'], 200) }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-600 dark:text-gray-300 cp-plan-draft-table__col-domain">
                                    {{ $ssrRow['domain'] ?? '—' }}
                                </td>
                                <td class="px-3 py-3 align-top text-sm text-gray-700 dark:text-gray-200">{{ $ssrRow['keyword'] }}</td>
                                <td class="px-3 py-3 align-top text-xs text-gray-600 cp-plan-draft-table__col-post-type">{{ $ssrRow['post_type_label'] }}</td>
                                <td class="px-3 py-3 align-top">
                                    <span class="cp-plan-badge cp-plan-badge--{{ $ssrRow['type'] === 'create' ? 'create' : ($ssrRow['type'] === 'improve' ? 'improve' : 'rewrite') }}">{{ $ssrRow['plan_label'] }}</span>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500">{{ ! empty($ssrRow['planning_reviewed']) ? ($boot['labelReviewed'] ?? 'Reviewed') : ($boot['labelUnreviewed'] ?? 'Unreviewed') }}</td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500 cp-plan-draft-table__col-added" @if (($ssrRow['added_at'] ?? '') !== '') title="{{ $ssrRow['added_at'] }}" @endif>{{ $ssrRow['added_label'] }}</td>
                                <td class="px-3 py-3 align-top cp-plan-draft-table__col-actions"></td>
                            </tr>
                        @endforeach
                        <template x-for="row in rows" :key="row.id">
                            <tr x-show="!alpineReady || row.visible" class="cp-plan-draft-table__row" :data-draft-plan="row.type">
                                <td class="px-4 py-3 align-top">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-primary-600"
                                        :checked="selected.includes(row.id)"
                                        :disabled="bulkBusy"
                                        @change="toggleSelect(row.id)"
                                    >
                                </td>
                                <td class="cp-plan-draft-table__col-stt px-2 py-3 align-top text-center text-xs text-gray-500 tabular-nums" x-text="displayStt(row)"></td>
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
                                                    <template x-if="row.title_href && row.title">
                                                        <a :href="row.title_href" :target="row.title_external ? '_blank' : null" :rel="row.title_external ? 'noopener' : null" class="font-medium text-primary-600 hover:underline dark:text-primary-400" @dblclick.prevent="startEdit(row, 'title')" x-text="row.title"></a>
                                                    </template>
                                                    <template x-if="!row.title_href || !row.title">
                                                        <span class="font-medium text-gray-900 dark:text-gray-100" @dblclick.prevent="startEdit(row, 'title')" x-text="row.title && String(row.title).trim() !== '' ? row.title : domainBlank"></span>
                                                    </template>
                                                    <span class="cp-plan-seo-inline" x-show="row.type !== 'create' || (row.seo_score_label && row.seo_score_label !== '—')" x-text="' · ' + seoPrefix + ' ' + row.seo_score_label"></span>
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

                                            <template x-if="showProductDescription(row)">
                                                <p class="mt-0.5 text-xs leading-snug text-gray-500 dark:text-gray-400">
                                                    <span class="font-medium text-gray-600 dark:text-gray-300" x-text="productDescriptionLabel"></span>
                                                    <span x-text="' ' + row.product_description"></span>
                                                </p>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-700 dark:text-gray-200 cp-plan-draft-table__col-domain">
                                    <template x-if="editingDomainId === row.id">
                                        <select
                                            class="cp-plan-inline-select cp-plan-inline-select--domain"
                                            :data-domain-edit="row.id"
                                            :value="row.site_id ? String(row.site_id) : ''"
                                            :disabled="row.saving_domain"
                                            :class="{ 'opacity-50 pointer-events-none': row.saving_domain }"
                                            @change="changeDomain(row, $event)"
                                            @keydown.escape.prevent="cancelDomainEdit()"
                                            @blur="onDomainBlur(row)"
                                            :aria-label="'{{ __('seo-content-ai::filament.projects.planning_col_domain') }}'"
                                        >
                                            <option value="">{{ __('seo-content-ai::filament.projects.planning_domain_blank') }}</option>
                                            <template x-for="opt in siteOptions" :key="'d-' + opt.value">
                                                <option :value="String(opt.value)" :selected="Number(row.site_id || 0) === Number(opt.value)" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="editingDomainId !== row.id">
                                        <span
                                            class="cursor-default"
                                            :title="domainEditHint"
                                            @dblclick.prevent="startDomainEdit(row)"
                                            x-text="row.domain || domainBlank"
                                        ></span>
                                    </template>
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
                                <td class="px-3 py-3 align-top text-xs text-gray-600 dark:text-gray-300 cp-plan-draft-table__col-post-type">
                                    <template x-if="row.can_edit_post_type && editingPostTypeId === row.id">
                                        <select
                                            class="cp-plan-inline-select"
                                            :data-post-type-edit="row.id"
                                            :value="row.post_type === 'product' ? 'product' : 'post'"
                                            :disabled="row.saving_post_type"
                                            :class="{ 'opacity-50 pointer-events-none': row.saving_post_type }"
                                            @change="changePostType(row, $event)"
                                            @keydown.escape.prevent="cancelPostTypeEdit()"
                                            @blur="onPostTypeBlur(row)"
                                            :aria-label="'{{ __('seo-content-ai::filament.projects.planning_col_post_type') }}'"
                                        >
                                            <template x-for="opt in postTypeSelectOptions(row)" :key="opt.value">
                                                <option :value="opt.value" :selected="(row.post_type === 'product' ? 'product' : 'post') === opt.value" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="row.can_edit_post_type && editingPostTypeId !== row.id">
                                        <span
                                            class="cursor-default"
                                            :title="postTypeEditHint"
                                            @dblclick.prevent="startPostTypeEdit(row)"
                                            x-text="row.post_type_label"
                                        ></span>
                                    </template>
                                    <template x-if="!row.can_edit_post_type">
                                        <span x-text="row.post_type_label"></span>
                                    </template>
                                </td>
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
                                <td class="px-3 py-3 align-top cp-plan-draft-table__col-actions">
                                    <div class="cp-plan-row-actions">
                                        <template x-if="row.can_clone_idea">
                                            <button
                                                type="button"
                                                class="cp-plan-icon-btn"
                                                :class="{ 'opacity-50 pointer-events-none': row.cloning }"
                                                :disabled="row.cloning"
                                                @click="cloneIdea(row)"
                                                :title="labelCloneIdea"
                                                :aria-label="labelCloneIdea"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5.2A2.2 2.2 0 0013.8 3H7.5A2.2 2.2 0 005.3 5.2V11.5A2.2 2.2 0 007.5 13.7H10"/></svg>
                                            </button>
                                        </template>
                                        <template x-if="row.can_edit_article && row.article_edit_url">
                                            <a
                                                :href="row.article_edit_url"
                                                target="_blank"
                                                rel="noopener"
                                                class="cp-plan-icon-btn"
                                                :title="labelEditArticle"
                                                :aria-label="labelEditArticle"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 9.5-9.5z"/></svg>
                                            </a>
                                        </template>
                                        <template x-if="row.can_open_public && row.article_public_url">
                                            <a
                                                :href="row.article_public_url"
                                                target="_blank"
                                                rel="noopener"
                                                class="cp-plan-icon-btn"
                                                :title="labelOpenPublic"
                                                :aria-label="labelOpenPublic"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/></svg>
                                            </a>
                                        </template>
                                        <template x-if="row.can_check_index && row.check_index_url">
                                            <a
                                                :href="row.check_index_url"
                                                target="_blank"
                                                rel="noopener"
                                                class="cp-plan-icon-btn"
                                                :title="labelCheckIndex"
                                                :aria-label="labelCheckIndex"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                                            </a>
                                        </template>
                                        <template x-if="row.can_skip_seo_audit">
                                            <button
                                                type="button"
                                                class="cp-plan-icon-btn cp-plan-icon-btn--warn"
                                                @click="skipRow(row)"
                                                :title="labelSkipSeoAudit"
                                                :aria-label="labelSkipSeoAudit"
                                            >
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M1 1l22 22"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/></svg>
                                            </button>
                                        </template>
                                        <button
                                            type="button"
                                            class="cp-plan-icon-btn cp-plan-icon-btn--danger"
                                            @click="archiveRow(row)"
                                            :title="labelRemove"
                                            :aria-label="labelRemove"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        @endif
    </x-seo-content-ai::list-table-loading-shell>
</section>
