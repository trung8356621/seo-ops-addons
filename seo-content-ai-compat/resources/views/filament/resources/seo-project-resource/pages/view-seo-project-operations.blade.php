@php
    /** @var \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject $this */
    $payload = $this->operationsPayload;
    $stats = $payload['stats'] ?? [];
    $rows = $payload['rows'] ?? [];
    $paginator = $payload['paginator'] ?? null;
    $project = $this->project;
    $totalItems = (int) ($stats['total_items'] ?? 0);
    $activeCard = $this->activeSummaryCard;
    $selectedCount = $this->selectedCount;
    $hasActiveFilters = $this->hasActiveFilters;
    $summarySnapshot = $this->summarySnapshot
        ?? \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel::normalizeSummaryStats(
            is_array($stats) ? $stats : [],
        );
    $onNeedsReviewFilter = $this->workflowFilter === \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition::FILTER
        || $this->generationFilter === \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition::FILTER
        || $this->reportingFilter === \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRecentlyCompletedDefinition::FILTER;
    $projectId = (int) ($project?->getKey() ?? 0);
    $transitionMap = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsCounterTransitionMap::all();
    $cmOps = \Omnichannel\Addons\Seo\Support\SeoAccessControl::usesContentManagerOpsPresentation();
    $canDebugLifecycle = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canDebugContentProjectLifecycle();
    $failureTypes = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailureTypeMapper::filterKeys();
    $kpiCards = $cmOps
        ? [
            ['key' => 'normal', 'card' => 'normal', 'label' => __('seo-content-ai::filament.projects.ops_normal'), 'count_key' => 'normal', 'hint' => __('seo-content-ai::filament.projects.ops_normal_hint'), 'divider_before' => false],
            ['key' => 'recently_completed', 'card' => 'recently_completed', 'label' => __('seo-content-ai::filament.projects.ops_needs_review'), 'count_key' => 'needs_review', 'hint' => __('seo-content-ai::filament.projects.ops_needs_review_hint'), 'divider_before' => false],
            ['key' => 'waiting_review', 'card' => 'review', 'label' => __('seo-content-ai::filament.projects.ops_in_review'), 'count_key' => 'review', 'hint' => __('seo-content-ai::filament.projects.ops_in_review_hint'), 'divider_before' => false],
        ]
        : [
            ['key' => 'normal', 'card' => 'normal', 'label' => __('seo-content-ai::filament.projects.ops_normal'), 'count_key' => 'normal', 'hint' => __('seo-content-ai::filament.projects.ops_normal_hint'), 'divider_before' => false],
            ['key' => 'pending', 'card' => 'pending', 'label' => __('seo-content-ai::filament.projects.ops_pending'), 'count_key' => 'pending', 'hint' => null, 'divider_before' => false],
            ['key' => 'recently_completed', 'card' => 'recently_completed', 'label' => __('seo-content-ai::filament.projects.ops_needs_review'), 'count_key' => 'needs_review', 'hint' => __('seo-content-ai::filament.projects.ops_needs_review_hint'), 'divider_before' => false],
            ['key' => 'waiting_review', 'card' => 'review', 'label' => __('seo-content-ai::filament.projects.ops_in_review'), 'count_key' => 'review', 'hint' => __('seo-content-ai::filament.projects.ops_in_review_hint'), 'divider_before' => false],
            ['key' => 'failed', 'card' => 'failed', 'label' => __('seo-content-ai::filament.projects.ops_failed'), 'count_key' => 'failed', 'hint' => null, 'divider_before' => true],
        ];
@endphp

<x-filament-panels::page>
    <div
        class="space-y-4"
        x-data="{
            detailsOpen: @entangle('executionDetailsOpen'),
            needsRefresh: false,
            projectId: {{ $projectId }},
            onNeedsReviewFilter: {{ $onNeedsReviewFilter ? 'true' : 'false' }},
            canonicalCounters: @js($summarySnapshot),
            pendingTransitions: [],
            previousCounts: {},
            counterAnimating: {},
            cardPulse: {},
            exitingRows: {},
            removedItemIds: {},
            processingRows: {},
            claimBusy: {},
            lazyBusy: false,
            summaryRequestId: 0,
            graceMs: {{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsOptimisticCounterMerge::GRACE_MS }},
            transitionMap: @js($transitionMap),
            optimisticFailureMessage: @js(__('seo-content-ai::filament.projects.ops_optimistic_update_failed')),
            debugOpen: false,
            debugBulk: false,
            debugTaskId: 0,
            debugTo: '',
            debugFrom: '',
            debugTitle: '',
            debugNeedsAt: false,
            debugAt: '',
            debugCount: 0,
            selectArticleOpen: false,
            selectArticleLocalLoading: false,
            selectArticleSearchTimer: null,
            missingArticleOpen: false,
            missingArticleTaskId: 0,
            missingArticleTitle: '',
            missingArticlePreviousId: 0,
            missingArticleBusy: false,
            restartKeywordOpen: false,
            restartKeywordTaskId: 0,
            restartKeywordItemTitle: '',
            restartKeywordInput: '',
            restartKeywordBusy: false,
            restartKeywordError: '',
            restartKeywordPollTimer: null,
            openSelectExistingArticleModal(taskId) {
                const id = Number(taskId || 0);
                if (id <= 0) return;
                this.selectArticleOpen = true;
                this.selectArticleLocalLoading = true;
                $wire.openSelectExistingArticle(id).finally(() => {
                    this.selectArticleLocalLoading = false;
                });
            },
            closeSelectExistingArticleModal() {
                this.selectArticleOpen = false;
                $wire.closeSelectExistingArticle();
            },
            openMissingArticleConfirmModal(detail) {
                const id = Number(detail?.taskId || 0);
                if (id <= 0) return;
                this.missingArticleOpen = true;
                this.missingArticleBusy = false;
                this.missingArticleTaskId = id;
                this.missingArticleTitle = String(detail?.title || ('#' + id));
                this.missingArticlePreviousId = Number(detail?.previousId || 0);
            },
            closeMissingArticleConfirmModal() {
                this.missingArticleOpen = false;
                this.missingArticleBusy = false;
                this.missingArticleTaskId = 0;
                this.missingArticleTitle = '';
                this.missingArticlePreviousId = 0;
                $wire.closeMissingArticleConfirm();
            },
            confirmMissingArticleRecreate() {
                const id = Number(this.missingArticleTaskId || 0);
                if (id <= 0 || this.missingArticleBusy) return;
                this.missingArticleBusy = true;
                $wire.confirmRecreateMissingArticle(id).finally(() => {
                    this.missingArticleBusy = false;
                    this.missingArticleOpen = false;
                    this.missingArticleTaskId = 0;
                    this.missingArticleTitle = '';
                    this.missingArticlePreviousId = 0;
                });
            },
            openRestartWithKeywordModal(detail) {
                const id = Number(detail?.taskId || 0);
                if (id <= 0) return;
                if (this.restartKeywordPollTimer) {
                    clearTimeout(this.restartKeywordPollTimer);
                    this.restartKeywordPollTimer = null;
                }
                const switchingItem = this.restartKeywordTaskId !== id;
                this.restartKeywordOpen = true;
                this.restartKeywordBusy = false;
                this.restartKeywordError = '';
                this.restartKeywordTaskId = id;
                this.restartKeywordItemTitle = String(detail?.title || ('#' + id));
                if (switchingItem) {
                    this.restartKeywordInput = '';
                }
            },
            closeRestartWithKeywordModal() {
                if (this.restartKeywordPollTimer) {
                    clearTimeout(this.restartKeywordPollTimer);
                    this.restartKeywordPollTimer = null;
                }
                const tid = Number(this.restartKeywordTaskId || 0);
                this.restartKeywordOpen = false;
                this.restartKeywordBusy = false;
                this.restartKeywordError = '';
                this.restartKeywordTaskId = 0;
                this.restartKeywordItemTitle = '';
                this.restartKeywordInput = '';
                if (tid > 0) {
                    $dispatch('cp-ops-row-processing-clear', { taskId: tid });
                }
            },
            async waitRestartKeywordTerminal(taskId, executionRef) {
                const maxAttempts = 150;
                for (let attempt = 0; attempt < maxAttempts; attempt++) {
                    await new Promise((resolve) => {
                        this.restartKeywordPollTimer = setTimeout(resolve, attempt === 0 ? 800 : 2000);
                    });
                    this.restartKeywordPollTimer = null;
                    if (! this.restartKeywordOpen || Number(this.restartKeywordTaskId) !== Number(taskId)) {
                        return 'cancelled';
                    }
                    let status = null;
                    try {
                        status = await $wire.pollRestartWithKeywordStatus(Number(taskId), executionRef || null);
                    } catch (e) {
                        continue;
                    }
                    if (! status || ! status.ok) {
                        continue;
                    }
                    if (status.running) {
                        continue;
                    }
                    if (status.terminal === 'completed') {
                        return 'completed';
                    }
                    if (status.terminal === 'failed') {
                        return 'failed';
                    }
                }
                return 'timeout';
            },
            confirmRestartWithKeyword() {
                const id = Number(this.restartKeywordTaskId || 0);
                const keyword = String(this.restartKeywordInput || '').trim();
                if (id <= 0 || keyword === '' || this.restartKeywordBusy) return;
                this.restartKeywordBusy = true;
                this.restartKeywordError = '';
                $dispatch('cp-ops-row-processing', { taskId: id, kind: 'generation' });
                $wire.confirmRestartWithKeyword(id, keyword)
                    .then(async (result) => {
                        if (! result || result.ok !== true) {
                            this.restartKeywordBusy = false;
                            this.restartKeywordError = String(result?.message || 'Không bắt đầu được generation.');
                            $dispatch('cp-ops-row-processing-clear', { taskId: id });
                            return;
                        }
                        const terminal = await this.waitRestartKeywordTerminal(id, result.execution_ref || null);
                        if (terminal === 'completed') {
                            this.closeRestartWithKeywordModal();
                            $dispatch('cp-ops-row-processing-clear', { taskId: id });
                            try { await $wire.finalizeRestartWithKeywordSuccess(); } catch (e) {}
                            return;
                        }
                        if (terminal === 'cancelled') {
                            return;
                        }
                        this.restartKeywordBusy = false;
                        this.restartKeywordError = terminal === 'timeout'
                            ? 'Generation quá lâu — kiểm tra trạng thái item trên bảng.'
                            : 'Generation thất bại. Từ khóa cũ được giữ nguyên.';
                        $dispatch('cp-ops-row-processing-clear', { taskId: id });
                        try { await this.doLazyRefresh(true); } catch (e) {}
                    })
                    .catch(() => {
                        this.restartKeywordBusy = false;
                        this.restartKeywordError = 'Không bắt đầu được generation.';
                        $dispatch('cp-ops-row-processing-clear', { taskId: id });
                    });
            },
            scheduleSelectArticleSearch() {
                if (this.selectArticleSearchTimer) {
                    clearTimeout(this.selectArticleSearchTimer);
                }
                this.selectArticleSearchTimer = setTimeout(() => {
                    this.selectArticleLocalLoading = true;
                    $wire.searchSelectExistingArticles().finally(() => {
                        this.selectArticleLocalLoading = false;
                    });
                }, 280);
            },
            openDebugLifecycle(detail) {
                this.debugBulk = false;
                this.debugTaskId = Number(detail.taskId || 0);
                this.debugTo = String(detail.to || '');
                this.debugFrom = String(detail.from || '');
                this.debugTitle = String(detail.title || '');
                this.debugNeedsAt = !!detail.needsAt;
                this.debugAt = '';
                if (detail.scheduledRaw) {
                    try {
                        const d = new Date(detail.scheduledRaw);
                        if (!Number.isNaN(d.getTime()) && d.getTime() > Date.now()) {
                            this.debugAt = d.toISOString().slice(0, 16);
                        }
                    } catch (e) {}
                }
                if (this.debugNeedsAt && !this.debugAt) {
                    const n = new Date(Date.now() + 3600 * 1000);
                    this.debugAt = new Date(n.getTime() - n.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                }
                this.debugOpen = true;
            },
            openDebugBulk(detail) {
                this.debugBulk = true;
                this.debugTaskId = 0;
                this.debugTo = 'scheduled';
                this.debugFrom = 'published';
                this.debugTitle = '';
                this.debugCount = Number(detail.count || 0);
                this.debugNeedsAt = true;
                const n = new Date(Date.now() + 3600 * 1000);
                this.debugAt = new Date(n.getTime() - n.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                this.debugOpen = true;
            },
            async confirmDebugLifecycle() {
                if (!this.debugOpen) return;
                const to = this.debugTo;
                const at = this.debugNeedsAt ? (this.debugAt || null) : null;
                if (this.debugNeedsAt && !at) return;
                this.debugOpen = false;
                try {
                    if (this.debugBulk) {
                        await $wire.debugLifecycleBulkToScheduled(at);
                    } else {
                        await $wire.debugLifecycleOne(this.debugTaskId, to, at);
                    }
                } catch (e) {}
            },
            dirtyKey() { return 'cp-ops-dirty-' + this.projectId; },
            reducedMotion() {
                try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
            },
            wait(ms) {
                return new Promise((resolve) => window.setTimeout(resolve, ms));
            },
            nowMs() { return Date.now(); },
            pendingDeltaSum() {
                const now = this.nowMs();
                const sum = {};
                (this.pendingTransitions || []).forEach((t) => {
                    if (! t || t.reconciled) return;
                    if (Number(t.expiresAt || 0) > 0 && Number(t.expiresAt) < now) return;
                    const deltas = t.deltas || {};
                    Object.keys(deltas).forEach((key) => {
                        sum[key] = Number(sum[key] || 0) + Number(deltas[key] || 0);
                    });
                });
                return sum;
            },
            displayCount(key) {
                const base = Number(this.canonicalCounters?.[key] ?? 0);
                const delta = Number(this.pendingDeltaSum()?.[key] ?? 0);
                return Math.max(0, base + delta);
            },
            counterAria(key, label) {
                const n = this.displayCount(key);
                if (key === 'needs_review') {
                    return (label || 'Needs Review') + ' cÃ²n ' + n + ' bÃ i';
                }
                return (label || key) + ': ' + n;
            },
            animateKeys(keys) {
                const list = Array.isArray(keys) ? keys : [];
                if (list.length === 0) return;
                const anim = { ...(this.counterAnimating || {}) };
                const pulse = { ...(this.cardPulse || {}) };
                list.forEach((key) => {
                    anim[key] = true;
                    pulse[key] = true;
                });
                this.counterAnimating = anim;
                this.cardPulse = pulse;
                const hold = this.reducedMotion() ? 120 : 260;
                window.setTimeout(() => {
                    const a2 = { ...(this.counterAnimating || {}) };
                    const p2 = { ...(this.cardPulse || {}) };
                    list.forEach((key) => {
                        a2[key] = false;
                        p2[key] = false;
                    });
                    this.counterAnimating = a2;
                    this.cardPulse = p2;
                }, hold);
            },
            isReconciled(transition, canonical) {
                const deltas = transition?.deltas || {};
                const baseline = transition?.baseline || {};
                const keys = Object.keys(deltas);
                if (keys.length === 0) return false;
                for (let i = 0; i < keys.length; i++) {
                    const key = keys[i];
                    const d = Number(deltas[key] || 0);
                    if (d === 0) continue;
                    const diff = Number(canonical?.[key] ?? 0) - Number(baseline?.[key] ?? 0);
                    if (d > 0 && diff < d) return false;
                    if (d < 0 && diff > d) return false;
                }
                return true;
            },
            prunePendingAgainstCanonical(canonical) {
                const now = this.nowMs();
                const seen = {};
                this.pendingTransitions = (this.pendingTransitions || []).filter((t) => {
                    if (! t || t.reconciled) return false;
                    const opId = String(t.operationId || '');
                    if (opId !== '' && seen[opId]) return false;
                    if (opId !== '') seen[opId] = true;
                    if (this.isReconciled(t, canonical)) return false;
                    if (Number(t.expiresAt || 0) > 0 && Number(t.expiresAt) < now) return false;
                    return true;
                });
            },
            acceptCanonicalSummary(summary, requestId) {
                if (requestId != null && Number(requestId) > 0 && Number(requestId) !== Number(this.summaryRequestId)) {
                    return false;
                }
                const before = {};
                Object.keys(summary || {}).forEach((key) => {
                    before[key] = this.displayCount(key);
                });
                // Also keep keys only in current display
                Object.keys(this.canonicalCounters || {}).forEach((key) => {
                    if (before[key] == null) before[key] = this.displayCount(key);
                });
                this.canonicalCounters = summary || {};
                this.prunePendingAgainstCanonical(this.canonicalCounters);
                const changed = [];
                Object.keys(before).forEach((key) => {
                    const after = this.displayCount(key);
                    if (after !== Number(before[key] ?? 0)) {
                        changed.push(key);
                    }
                });
                if (changed.length > 0) {
                    this.previousCounts = { ...(this.previousCounts || {}), ...before };
                    this.animateKeys(changed);
                }
                return true;
            },
            registerPendingTransition(detail) {
                const action = String(detail?.action || '');
                const itemId = Number(detail?.itemId || 0);
                const deltas = detail?.deltas || this.transitionMap[action] || {};
                const operationId = String(detail?.operationId || ('cp-op-' + this.nowMs() + '-' + itemId));
                if ((this.pendingTransitions || []).some((t) => String(t.operationId || '') === operationId)) {
                    return operationId;
                }
                const keys = Object.keys(deltas);
                const prevDisplay = {};
                keys.forEach((key) => { prevDisplay[key] = this.displayCount(key); });
                const acceptedAt = this.nowMs();
                const row = {
                    operationId,
                    itemId,
                    action,
                    deltas: { ...deltas },
                    baseline: { ...(this.canonicalCounters || {}) },
                    acceptedAt,
                    expiresAt: acceptedAt + Number(this.graceMs || 12000),
                    reconciled: false,
                };
                this.previousCounts = { ...(this.previousCounts || {}), ...prevDisplay };
                this.pendingTransitions = [...(this.pendingTransitions || []), row];
                this.animateKeys(keys);
                return operationId;
            },
            rollbackPendingByOperationIds(operationIds) {
                const ids = new Set((operationIds || []).map((id) => String(id)));
                if (ids.size === 0) return;
                const keys = {};
                const before = {};
                (this.pendingTransitions || []).forEach((t) => {
                    if (! ids.has(String(t.operationId || ''))) return;
                    Object.keys(t.deltas || {}).forEach((key) => {
                        keys[key] = true;
                        before[key] = this.displayCount(key);
                    });
                });
                this.previousCounts = { ...(this.previousCounts || {}), ...before };
                this.pendingTransitions = (this.pendingTransitions || []).filter(
                    (t) => ! ids.has(String(t.operationId || '')),
                );
                this.animateKeys(Object.keys(keys));
            },
            resetRowOptimistic() {
                this.exitingRows = {};
                this.removedItemIds = {};
                this.claimBusy = {};
                this.processingRows = {};
                this.counterAnimating = {};
                this.cardPulse = {};
            },
            isRowVisible(tid) {
                const id = Number(tid || 0);
                if (id <= 0) return true;
                if (this.removedItemIds?.[id]) return false;
                if (this.exitingRows?.[id]?.removed) return false;
                return true;
            },
            beginRowProcessing(tid, kind) {
                const id = Number(tid || 0);
                if (id <= 0) return;
                this.processingRows = { ...(this.processingRows || {}), [id]: String(kind || 'generation') };
            },
            clearRowProcessing(tid) {
                const id = Number(tid || 0);
                if (id <= 0) return;
                const next = { ...(this.processingRows || {}) };
                delete next[id];
                this.processingRows = next;
            },
            isRowProcessing(tid) {
                return !!this.processingRows?.[Number(tid || 0)];
            },
            rowProcessingKind(tid) {
                return this.processingRows?.[Number(tid || 0)] || null;
            },
            markRowRemoved(tid) {
                const id = Number(tid || 0);
                if (id <= 0) return;
                this.removedItemIds = { ...(this.removedItemIds || {}), [id]: true };
            },
            unmarkRowRemoved(tid) {
                const id = Number(tid || 0);
                if (id <= 0) return;
                const next = { ...(this.removedItemIds || {}) };
                delete next[id];
                this.removedItemIds = next;
            },
            resetOptimistic() {
                // Filter/page change: drop presentation pending and row exit state.
                this.pendingTransitions = [];
                this.resetRowOptimistic();
            },
            markDirty() {
                this.needsRefresh = true;
                try { sessionStorage.setItem(this.dirtyKey(), '1'); } catch (e) {}
            },
            clearDirty() {
                this.needsRefresh = false;
                try {
                    sessionStorage.removeItem(this.dirtyKey());
                    sessionStorage.removeItem('cp-ops-dirty-global');
                } catch (e) {}
            },
            async maybeLazyRefresh(force = false) {
                await this.doLazyRefresh(force);
            },
            async doLazyRefresh(force = false) {
                if (this.lazyBusy) return;
                this.lazyBusy = true;
                const requestId = ++this.summaryRequestId;
                try {
                    let summary = null;
                    if (force) {
                        const result = await $wire.manualRefreshOps();
                        summary = result?.summary || null;
                    } else {
                        const result = await $wire.fetchOpsSummaryOnly(requestId);
                        summary = result?.summary || null;
                    }
                    if (! summary) return;
                    if (requestId !== this.summaryRequestId) return;
                    this.acceptCanonicalSummary(summary, requestId);
                    if (force && (this.pendingTransitions || []).length === 0) {
                        this.resetRowOptimistic();
                    }
                    this.clearDirty();
                } finally {
                    this.lazyBusy = false;
                }
            },
            rowSelector(tid) {
                return '[data-ops-row=\'' + tid + '\']';
            },
            async beginRowExit(tid) {
                if (this.exitingRows[tid]?.busy || this.exitingRows[tid]?.removed) {
                    return false;
                }
                const els = Array.from(this.$root.querySelectorAll(this.rowSelector(tid)));
                if (els.length === 0) {
                    this.exitingRows = { ...this.exitingRows, [tid]: { removed: true } };
                    return true;
                }
                const reduced = this.reducedMotion();
                this.exitingRows = { ...this.exitingRows, [tid]: { phase: 'highlight', busy: true } };
                els.forEach((el) => el.classList.add('cp-ops-row--highlight'));
                await this.wait(reduced ? 90 : 140);

                els.forEach((el) => {
                    const height = el.getBoundingClientRect().height;
                    el.style.boxSizing = 'border-box';
                    el.style.height = height + 'px';
                    el.style.overflow = 'hidden';
                });
                await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

                this.exitingRows = { ...this.exitingRows, [tid]: { phase: 'exit', busy: true } };
                els.forEach((el) => {
                    el.classList.remove('cp-ops-row--highlight');
                    el.classList.add('cp-ops-row--exit');
                    el.style.opacity = '0';
                    el.style.transform = reduced ? 'none' : 'translateX(8px)';
                    el.style.height = '0px';
                    el.style.paddingTop = '0px';
                    el.style.paddingBottom = '0px';
                    el.style.marginTop = '0px';
                    el.style.marginBottom = '0px';
                    el.style.borderWidth = '0px';
                });

                await Promise.race([
                    this.wait(reduced ? 150 : 380),
                    Promise.all(els.map((el) => new Promise((resolve) => {
                        const done = () => { el.removeEventListener('transitionend', done); resolve(); };
                        el.addEventListener('transitionend', done);
                    }))),
                ]);

                this.exitingRows = { ...this.exitingRows, [tid]: { removed: true, busy: false } };
                return true;
            },
            async rollbackRowExit(tid) {
                const els = Array.from(this.$root.querySelectorAll(this.rowSelector(tid)));
                els.forEach((el) => {
                    el.classList.remove('cp-ops-row--highlight', 'cp-ops-row--exit');
                    el.style.height = '';
                    el.style.opacity = '';
                    el.style.transform = '';
                    el.style.paddingTop = '';
                    el.style.paddingBottom = '';
                    el.style.marginTop = '';
                    el.style.marginBottom = '';
                    el.style.borderWidth = '';
                    el.style.overflow = '';
                    el.classList.add('cp-ops-row--rollback');
                });
                const next = { ...this.exitingRows };
                delete next[tid];
                this.exitingRows = next;
                await this.wait(this.reducedMotion() ? 100 : 160);
                els.forEach((el) => el.classList.remove('cp-ops-row--rollback'));
            },
            async notifyFailure() {
                try {
                    await $wire.notifyOptimisticFailure(this.optimisticFailureMessage);
                } catch (e) {}
            },
            openNeedsReviewArticle(taskId, isNeedsReview, url) {
                // Legacy alias — claim only; real anchors open editor in new tab.
                return this.claimNeedsReviewArticle(taskId, isNeedsReview);
            },
            async claimNeedsReviewArticle(taskId, isNeedsReview) {
                const tid = Number(taskId || 0);
                if (tid <= 0) return;
                if (this.claimBusy[tid]) return;
                this.claimBusy = { ...this.claimBusy, [tid]: true };

                const deltas = this.transitionMap.mark_viewed || { needs_review: -1 };
                const operationId = 'mark-viewed-' + tid + '-' + this.nowMs();
                const claimPromise = $wire.claimNeedsReviewItem(tid, !!isNeedsReview);

                try {
                    if (isNeedsReview) {
                        if (this.onNeedsReviewFilter) {
                            this.beginRowExit(tid);
                        }
                        await this.wait(this.reducedMotion() ? 80 : 150);
                        this.registerPendingTransition({
                            operationId,
                            itemId: tid,
                            action: 'mark_viewed',
                            deltas,
                        });
                        if (this.onNeedsReviewFilter) {
                            this.markRowRemoved(tid);
                            try { await $wire.persistOptimisticRemovals([tid]); } catch (e) {}
                        }
                    }

                    const result = await claimPromise;
                    if (! result?.ok) {
                        throw new Error('claim_failed');
                    }
                } catch (e) {
                    if (isNeedsReview) {
                        this.rollbackPendingByOperationIds([operationId]);
                        this.unmarkRowRemoved(tid);
                        try { await $wire.clearOptimisticRemovals([tid]); } catch (err) {}
                        await this.rollbackRowExit(tid);
                    }
                    await this.notifyFailure();
                } finally {
                    const busy = { ...this.claimBusy };
                    delete busy[tid];
                    this.claimBusy = busy;
                }
            },
            async handleItemTransition(detail) {
                const action = String(detail?.action || '');
                const taskIds = Array.isArray(detail?.taskIds) ? detail.taskIds : [];
                const deltas = detail?.deltas || this.transitionMap[action] || {};
                const baseOperationId = String(detail?.operationId || ('cp-op-' + this.nowMs()));
                const shouldExit = ['approve', 'approve_from_needs_review', 'approve_self_edit', 'schedule', 'schedule_from_needs_review', 'schedule_from_review', 'mark_viewed', 'content_manager_handoff', 'debug_published_to_scheduled', 'debug_published_to_approved', 'debug_scheduled_to_approved', 'debug_approved_to_scheduled', 'debug_approved_to_published', 'debug_scheduled_to_published'].includes(action);
                const ids = [];

                for (const rawId of taskIds) {
                    const tid = Number(rawId || 0);
                    if (tid <= 0) continue;
                    ids.push(tid);
                    if (shouldExit) {
                        this.beginRowExit(tid);
                    }
                }
                await this.wait(this.reducedMotion() ? 80 : 150);
                for (const tid of ids) {
                    this.registerPendingTransition({
                        operationId: baseOperationId + '-' + tid,
                        itemId: tid,
                        action,
                        deltas,
                    });
                }
                // Wait for exit motion to start, then permanently drop rows from Livewire list.
                await this.wait(this.reducedMotion() ? 120 : 280);
                for (const tid of ids) {
                    this.markRowRemoved(tid);
                }
                try {
                    await $wire.persistOptimisticRemovals(ids);
                } catch (e) {}
            },
            init() {
                try {
                    if (sessionStorage.getItem(this.dirtyKey()) === '1') {
                        this.needsRefresh = true;
                    }
                } catch (e) {}

                window.addEventListener('project-item-updated', () => this.markDirty());
                window.addEventListener('cp-ops-client-reset-optimistic', () => this.resetOptimistic());
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        this.maybeLazyRefresh(false);
                    }
                });
                window.addEventListener('pageshow', () => this.maybeLazyRefresh(false));

                if (this.needsRefresh) {
                    this.maybeLazyRefresh(false);
                }
            },
        }"
        x-on:cp-ops-client-reset-optimistic.window="resetOptimistic()"
        x-on:cp-ops-row-processing.window="beginRowProcessing(($event.detail || {}).taskId, ($event.detail || {}).kind)"
        x-on:cp-ops-row-processing-clear.window="clearRowProcessing(($event.detail || {}).taskId)"
        x-on:cp-ops-item-transition.window="handleItemTransition($event.detail || {})"
        x-on:cp-ops-debug-lifecycle.window="openDebugLifecycle($event.detail || {})"
        x-on:cp-ops-debug-lifecycle-bulk.window="openDebugBulk($event.detail || {})"
        x-on:open-select-existing-article.window="openSelectExistingArticleModal($event.detail?.taskId)"
        x-on:close-select-existing-article.window="selectArticleOpen = false"
        x-on:open-missing-article-confirm.window="openMissingArticleConfirmModal($event.detail || {})"
        x-on:close-missing-article-confirm.window="missingArticleOpen = false"
        x-on:open-restart-with-keyword.window="openRestartWithKeywordModal($event.detail || {})"
        x-on:close-restart-with-keyword.window="closeRestartWithKeywordModal()"
    >
        @if ($this->settingsOpen)
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-gray-600 dark:text-gray-300">{{ $project?->description ?: 'â€”' }}</p>
                @if ($payload['last_execution_at'] ?? null)
                    <p class="mt-2 text-xs text-gray-500">
                        Last execution: {{ $payload['last_execution_at'] }}
                        @if ($payload['last_execution_status'] ?? null)
                            Â· {{ $payload['last_execution_status'] }}
                        @endif
                    </p>
                @endif
            </div>
        @endif

        @php
            $kpiCardsResolved = array_map(static function (array $card) use ($summarySnapshot, $stats): array {
                $countKey = $card['count_key'] ?? null;
                $card['value'] = $countKey
                    ? (int) ($summarySnapshot[$countKey] ?? $stats[$card['key']] ?? 0)
                    : (int) ($stats[$card['key']] ?? 0);
                $card['filter'] = (string) ($card['card'] ?? $card['key']);

                return $card;
            }, $kpiCards);
        @endphp
        <x-seo-content-ai::content-project-summary-cards
            :cards="$kpiCardsResolved"
            :active="$activeCard"
            wire-method="applySummaryFilter"
            aria-label="Project summary"
            loading-targets="applySummaryFilter,clearFilters,search,generationFilter,lifecycleFilter,workflowFilter,reportingFilter,queueFilter,scheduledFilter,failedOnly,failureTypeFilter,manualRefreshOps,lazyRefreshOps"
        />

        @if ($activeCard === 'failed' || $this->failedOnly)
            <div
                class="cp-ops-failed-quick"
                role="group"
                aria-label="{{ __('seo-content-ai::filament.projects.ops_failure_quick_filter') }}"
            >
                <button
                    type="button"
                    wire:click="applyFailureTypeFilter('')"
                    @class(['cp-ops-failed-quick__chip', 'is-active' => $this->failureTypeFilter === ''])
                >
                    {{ __('seo-content-ai::filament.projects.ops_failure_all') }}
                </button>
                @foreach ($failureTypes as $ftype)
                    <button
                        type="button"
                        wire:click="applyFailureTypeFilter('{{ $ftype }}')"
                        @class(['cp-ops-failed-quick__chip', 'is-active' => $this->failureTypeFilter === $ftype])
                    >
                        {{ __('seo-content-ai::filament.projects.ops_failure_'.$ftype) }}
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Filter toolbar --}}
        <div @class(['opacity-50 pointer-events-none' => $this->bulkRunning])>
            <div class="mb-2 flex flex-wrap items-center justify-end gap-2">
                <span
                    class="text-xs text-gray-500 dark:text-gray-400"
                    x-show="lazyBusy"
                    x-cloak
                >{{ __('seo-content-ai::filament.projects.ops_lazy_refreshing') }}</span>
                <button
                    type="button"
                    class="fi-btn fi-btn-color-gray fi-size-sm inline-flex items-center gap-1"
                    @click="doLazyRefresh(true)"
                    :disabled="lazyBusy"
                    wire:loading.attr="disabled"
                    wire:target="manualRefreshOps"
                >
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" x-bind:class="{ 'animate-spin': lazyBusy }" />
                    <span>{{ __('seo-content-ai::filament.projects.ops_manual_refresh') }}</span>
                </button>
            </div>
            <x-seo-content-ai::content-project-filter-toolbar variant="content_project" :content-manager="$cmOps" />
            @if ($cmOps && \Omnichannel\Addons\Seo\Support\SeoAccessControl::canManageContentProjectWorkflow())
                <div class="mt-3 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-gray-900/40">
                    <label class="flex cursor-pointer items-start gap-2">
                        <input
                            type="checkbox"
                            wire:model.live="generatePostImages"
                            class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                        />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ __('seo-content-ai::filament.projects.ops_generate_post_images') }}
                            </span>
                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.ops_generate_post_images_help') }}
                            </span>
                        </span>
                    </label>
                </div>
            @endif
            @if ($onNeedsReviewFilter)
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        wire:click="markAllRecentlyCompletedViewed"
                        wire:confirm="{{ __('seo-content-ai::filament.projects.ops_mark_all_viewed_confirm') }}"
                        wire:loading.attr="disabled"
                        wire:target="markAllRecentlyCompletedViewed"
                        class="fi-btn fi-btn-color-gray fi-size-sm inline-flex items-center gap-1"
                    >
                        <x-filament::icon wire:loading.remove wire:target="markAllRecentlyCompletedViewed" icon="heroicon-o-check-circle" class="h-4 w-4" />
                        <svg wire:loading wire:target="markAllRecentlyCompletedViewed" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span>{{ __('seo-content-ai::filament.projects.ops_mark_all_viewed') }}</span>
                    </button>
                </div>
            @endif
            @if ($cmOps && $onNeedsReviewFilter && (int) ($summarySnapshot['needs_review'] ?? 0) === 0)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.ops_needs_review_empty') }}</p>
            @endif
            <x-seo-content-ai::content-project-bulk-selection-toolbar
                variant="content_project"
                :selected-count="$selectedCount"
                :bulk-menu-groups="$this->itemActionBulkMenu"
            />
        </div>

        {{-- Debug lifecycle modal — Alpine first; no WordPress --}}
        <div
            x-show="debugOpen"
            x-cloak
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4"
            @keydown.escape.window="debugOpen = false"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" @click.outside="debugOpen = false">
                <h3 class="mb-2 text-base font-semibold">{{ __('seo-content-ai::filament.projects.ops_debug_lifecycle_title') }}</h3>
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">{{ __('seo-content-ai::filament.projects.ops_debug_lifecycle_warning') }}</p>
                <template x-if="debugTo === 'published'">
                    <p class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ __('seo-content-ai::filament.projects.ops_debug_lifecycle_published_warn') }}</p>
                </template>
                <div class="space-y-2 text-sm">
                    <p x-show="!debugBulk"><span class="text-gray-500">Item:</span> <span x-text="debugTitle"></span></p>
                    <p x-show="debugBulk"><span class="text-gray-500">Items:</span> <span x-text="debugCount"></span></p>
                    <p><span class="text-gray-500">From:</span> <span x-text="debugFrom"></span> → <span x-text="debugTo"></span></p>
                    <label class="block" x-show="debugNeedsAt">
                        <span class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.ops_debug_schedule_at') }}</span>
                        <input type="datetime-local" x-model="debugAt" class="fi-input mt-1 block w-full rounded-lg text-sm" />
                    </label>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="debugOpen = false" class="fi-btn fi-btn-color-gray fi-size-sm">{{ __('seo-content-ai::filament.projects.archive_cancel') }}</button>
                    <button type="button" @click="confirmDebugLifecycle()" class="fi-btn fi-btn-color-warning fi-size-sm">
                        {{ __('seo-content-ai::filament.projects.ops_debug_lifecycle_confirm') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Missing article recreate modal — teleport to body (z-index + padding) --}}
        <template x-teleport="body">
            <div
                x-show="missingArticleOpen"
                x-cloak
                x-transition.opacity.duration.150ms
                class="cp-ops-dialog-overlay"
                @keydown.escape.window="if (missingArticleOpen) { closeMissingArticleConfirmModal() }"
                @click.self="closeMissingArticleConfirmModal()"
            >
                <div
                    x-show="missingArticleOpen"
                    x-transition:enter="ease-out duration-150"
                    x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                    class="cp-ops-dialog cp-ops-dialog--sm"
                    @click.outside="closeMissingArticleConfirmModal()"
                >
                    <div class="cp-ops-dialog__header">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.projects.missing_article_confirm_title') }}
                        </h3>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('seo-content-ai::filament.projects.missing_article_confirm_body') }}
                        </p>
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            <span class="font-medium" x-text="missingArticleTitle"></span>
                            <template x-if="missingArticlePreviousId > 0">
                                <span class="ml-1 text-xs opacity-80">(ID <span x-text="missingArticlePreviousId"></span>)</span>
                            </template>
                        </p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.projects.missing_article_confirm_hint') }}
                        </p>
                    </div>
                    <div class="cp-ops-dialog__footer flex justify-end gap-2">
                        <button
                            type="button"
                            class="fi-btn fi-btn-color-gray fi-size-sm"
                            @click="closeMissingArticleConfirmModal()"
                            :disabled="missingArticleBusy"
                        >
                            {{ __('seo-content-ai::filament.projects.missing_article_confirm_cancel') }}
                        </button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-color-primary fi-size-sm inline-flex items-center gap-1"
                            @click="confirmMissingArticleRecreate()"
                            :disabled="missingArticleBusy"
                            :class="{ 'opacity-50 pointer-events-none': missingArticleBusy }"
                        >
                            <svg x-show="missingArticleBusy" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            <span>{{ __('seo-content-ai::filament.projects.missing_article_confirm_create') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        {{-- Fresh keyword restart modal --}}
        {{-- Restart-with-keyword modal (keep in-tree — avoid teleport orphan after Livewire morph) --}}
        <div
            x-show="restartKeywordOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="cp-ops-dialog-overlay"
            @keydown.escape.window="if (restartKeywordOpen && !restartKeywordBusy) { closeRestartWithKeywordModal() }"
            @click.self="if (!restartKeywordBusy) { closeRestartWithKeywordModal() }"
        >
            <div
                x-show="restartKeywordOpen"
                x-transition:enter="ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                class="cp-ops-dialog cp-ops-dialog--sm"
            >
                <div class="cp-ops-dialog__header">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('seo-content-ai::filament.projects.restart_with_keyword_title') }}
                    </h3>
                    <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-800 dark:bg-gray-800/60 dark:text-gray-200">
                        <span class="font-medium" x-text="restartKeywordItemTitle"></span>
                    </p>
                    <label class="mt-4 block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.projects.restart_with_keyword_input_label') }}
                        </span>
                        <input
                            type="text"
                            x-model="restartKeywordInput"
                            class="fi-input mt-1 block w-full rounded-lg text-sm"
                            placeholder="{{ __('seo-content-ai::filament.projects.restart_with_keyword_input_placeholder') }}"
                            :disabled="restartKeywordBusy"
                            @keydown.enter.prevent="confirmRestartWithKeyword()"
                        />
                    </label>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.projects.restart_with_keyword_helper') }}
                    </p>
                    <p
                        x-show="restartKeywordBusy"
                        x-cloak
                        class="mt-3 inline-flex items-center gap-2 text-sm text-sky-700 dark:text-sky-300"
                    >
                        <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span>{{ __('seo-content-ai::filament.projects.ops_running') }}…</span>
                    </p>
                    <p
                        x-show="restartKeywordError"
                        x-cloak
                        class="mt-3 text-sm text-rose-600 dark:text-rose-400"
                        x-text="restartKeywordError"
                    ></p>
                </div>
                <div class="cp-ops-dialog__footer flex justify-end gap-2">
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-gray fi-size-sm"
                        @click="closeRestartWithKeywordModal()"
                        :disabled="restartKeywordBusy"
                    >
                        {{ __('seo-content-ai::filament.projects.restart_with_keyword_cancel') }}
                    </button>
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-primary fi-size-sm inline-flex items-center gap-1"
                        @click="confirmRestartWithKeyword()"
                        :disabled="restartKeywordBusy || !String(restartKeywordInput || '').trim()"
                        :class="{ 'opacity-50 pointer-events-none': restartKeywordBusy || !String(restartKeywordInput || '').trim() }"
                    >
                        <svg x-show="restartKeywordBusy" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span>{{ __('seo-content-ai::filament.projects.restart_with_keyword_submit') }}</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Select Existing Article modal — teleport to body (z-index + padding) --}}
        <template x-teleport="body">
        <div
            x-show="selectArticleOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="cp-ops-dialog-overlay"
            @keydown.escape.window="if (selectArticleOpen) { closeSelectExistingArticleModal() }"
            @click.self="closeSelectExistingArticleModal()"
        >
            <div
                x-show="selectArticleOpen"
                x-transition:enter="ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                class="cp-ops-dialog"
                @click.outside="closeSelectExistingArticleModal()"
            >
                {{-- Header --}}
                <div class="cp-ops-dialog__header shrink-0 bg-gradient-to-b from-gray-50 to-white dark:from-gray-800/80 dark:to-gray-900">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-warning-50 text-warning-600 ring-1 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/30">
                            <x-filament::icon icon="heroicon-o-link" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold tracking-tight text-gray-950 dark:text-white">
                                {{ __('seo-content-ai::filament.projects.select_existing_article_title') }}
                            </h3>
                            <p class="mt-1 text-sm leading-snug text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.select_existing_article_help') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="closeSelectExistingArticleModal()"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                            aria-label="Close"
                        >
                            <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                        </button>
                    </div>

                    {{-- Search --}}
                    <div class="mt-4 space-y-3">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">
                                {{ __('seo-content-ai::filament.projects.select_existing_article_search') }}
                            </span>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                                </span>
                                <input
                                    type="search"
                                    wire:model.live.debounce.300ms="selectExistingArticleQuery"
                                    class="fi-input block w-full rounded-xl border-gray-200 py-2.5 pl-9 pr-3 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600"
                                    placeholder="{{ __('seo-content-ai::filament.projects.select_existing_article_search_placeholder') }}"
                                    autocomplete="off"
                                />
                            </div>
                        </label>

                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">
                                {{ __('seo-content-ai::filament.projects.select_existing_article_direct') }}
                            </span>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="selectExistingArticleDirect"
                                    class="fi-input block min-w-0 flex-1 rounded-xl border-gray-200 py-2.5 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600"
                                    placeholder="{{ __('seo-content-ai::filament.projects.select_existing_article_direct_placeholder') }}"
                                    autocomplete="off"
                                    @keydown.enter.prevent="$wire.resolveSelectExistingArticleDirect()"
                                />
                                <button
                                    type="button"
                                    wire:click="resolveSelectExistingArticleDirect"
                                    wire:loading.attr="disabled"
                                    wire:target="resolveSelectExistingArticleDirect,confirmSelectExistingArticle"
                                    class="fi-btn fi-btn-color-primary fi-size-sm inline-flex shrink-0 items-center gap-1.5 rounded-xl px-3"
                                >
                                    <x-filament::icon wire:loading.remove wire:target="resolveSelectExistingArticleDirect" icon="heroicon-m-check" class="h-4 w-4" />
                                    <svg wire:loading wire:target="resolveSelectExistingArticleDirect" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span>{{ __('seo-content-ai::filament.projects.select_existing_article_resolve') }}</span>
                                </button>
                            </div>
                        </label>

                        @if (filled($this->selectExistingArticleError))
                            <div class="flex items-start gap-2 rounded-xl border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
                                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>{{ $this->selectExistingArticleError }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Results (scroll) --}}
                <div class="cp-ops-dialog__scroll overscroll-contain bg-white dark:bg-gray-900" style="max-height: 18rem;">
                    <div x-show="selectArticleLocalLoading || $wire.selectExistingArticleLoading" class="space-y-2">
                        @foreach (range(1, 3) as $_)
                            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                                <div class="h-4 w-3/4 animate-pulse rounded bg-gray-100 dark:bg-gray-800"></div>
                                <div class="mt-2 flex gap-2">
                                    <div class="h-5 w-20 animate-pulse rounded-md bg-gray-100 dark:bg-gray-800"></div>
                                    <div class="h-5 w-16 animate-pulse rounded-md bg-gray-100 dark:bg-gray-800"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div x-show="!selectArticleLocalLoading && !$wire.selectExistingArticleLoading">
                        @forelse ($this->selectExistingArticleResults as $hit)
                            @php
                                $hitId = (int) ($hit['id'] ?? 0);
                                $hitTitle = (string) ($hit['title'] ?? ('Article #'.$hitId));
                                $hitWp = ! empty($hit['wp_post_id']) ? (int) $hit['wp_post_id'] : null;
                                $hitSlug = trim((string) ($hit['slug'] ?? ''));
                                $hitDomain = trim((string) ($hit['domain'] ?? ''));
                            @endphp
                            <button
                                type="button"
                                wire:click="confirmSelectExistingArticle({{ $hitId }})"
                                wire:loading.attr="disabled"
                                wire:target="confirmSelectExistingArticle,resolveSelectExistingArticleDirect"
                                class="group mb-1 flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-primary-50/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50 dark:hover:bg-primary-500/10"
                            >
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 ring-1 ring-gray-200 transition group-hover:bg-white group-hover:text-primary-600 group-hover:ring-primary-200 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:group-hover:bg-gray-900 dark:group-hover:text-primary-400">
                                    <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $hitTitle }}</span>
                                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                            #{{ $hitId }}
                                        </span>
                                        @if ($hitWp)
                                            <span class="inline-flex items-center rounded-md bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30">
                                                WP {{ $hitWp }}
                                            </span>
                                        @endif
                                        @if ($hitSlug !== '')
                                            <span class="inline-flex max-w-[12rem] truncate items-center rounded-md bg-gray-50 px-1.5 py-0.5 text-[11px] text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-800/60 dark:text-gray-400 dark:ring-gray-700" title="/{{ ltrim($hitSlug, '/') }}/">
                                                /{{ ltrim($hitSlug, '/') }}/
                                            </span>
                                        @endif
                                        @if ($hitDomain !== '')
                                            <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                                                {{ $hitDomain }}
                                            </span>
                                        @endif
                                    </span>
                                </span>
                                <span class="mt-1 shrink-0 text-gray-300 opacity-0 transition group-hover:opacity-100 group-hover:text-primary-500 dark:text-gray-600 dark:group-hover:text-primary-400">
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
                                </span>
                            </button>
                        @empty
                            <div class="flex flex-col items-center px-4 py-10 text-center">
                                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-50 text-gray-400 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-6 w-6" />
                                </div>
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.projects.select_existing_article_empty') }}</p>
                                <p class="mt-1 max-w-xs text-xs text-gray-400">{{ __('seo-content-ai::filament.projects.select_existing_article_direct_placeholder') }}</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Footer --}}
                <div class="cp-ops-dialog__footer shrink-0">
                    <p class="flex items-start gap-2 text-xs leading-snug text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-4 w-4 shrink-0 text-gray-400" />
                        <span>{{ __('seo-content-ai::filament.projects.select_existing_article_no_generate') }}</span>
                    </p>
                </div>
            </div>
        </div>
        </template>

        {{-- Loading skeleton --}}
        <div wire:loading.delay.shortest wire:target="applySummaryFilter,clearFilters,search,generationFilter,lifecycleFilter,workflowFilter,reportingFilter,queueFilter,scheduledFilter,failedOnly,failureTypeFilter,gotoPage,previousPage,nextPage" class="space-y-2">
            @foreach (range(1, 4) as $_)
                <div class="h-14 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
            @endforeach
        </div>

        {{-- Canonical table (desktop) + cards (mobile) --}}
        <div wire:loading.remove.delay.shortest wire:target="applySummaryFilter,clearFilters,search,generationFilter,lifecycleFilter,workflowFilter,reportingFilter,queueFilter,scheduledFilter,failedOnly,failureTypeFilter">
            @if ($totalItems === 0)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center dark:border-gray-600 dark:bg-gray-900">
                    <x-filament::icon icon="heroicon-o-inbox" class="mx-auto h-8 w-8 text-gray-400" />
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.projects.item_empty') }}</p>
                    <p class="mt-1 text-xs text-gray-500">Add items in Edit project, then Generate working items.</p>
                </div>
            @else
                <x-seo-content-ai::content-project-items-list
                    variant="content_project"
                    :rows="$rows"
                    :has-active-filters="$hasActiveFilters"
                    :show-checkbox="true"
                    :use-row-visibility="true"
                />
            @endif
        </div>

        @if ($paginator && count($rows) > 0)
            <div class="mt-2">{{ $paginator->links() }}</div>
        @endif

        {{-- Execution details drawer --}}
        <div
            x-show="detailsOpen"
            x-cloak
            class="fixed inset-0 z-50 flex justify-end"
            x-on:keydown.escape.window="detailsOpen = false; $wire.closeExecutionDetails()"
        >
            <div class="absolute inset-0 bg-black/40" @click="detailsOpen = false; $wire.closeExecutionDetails()"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h3 class="font-semibold">{{ __('seo-content-ai::filament.projects.item_execution_details_heading') }}</h3>
                    <button type="button" @click="detailsOpen = false; $wire.closeExecutionDetails()" class="text-sm text-gray-500" aria-label="Close details">âœ•</button>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    @php
                        $detailTaskId = (int) ($this->executionDetailsTaskId ?? 0);
                        $detailRow = collect($rows)->firstWhere('task_id', $detailTaskId);
                        $existingLink = is_array($detailRow) ? ($detailRow['existing_article_link'] ?? null) : null;
                    @endphp
                    @if ($existingLink === 'unlinked')
                        <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                            {{ __('seo-content-ai::filament.projects.existing_article_unlinked') }}
                        </p>
                    @elseif ($existingLink === 'ambiguous')
                        <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                            {{ __('seo-content-ai::filament.projects.existing_article_ambiguous') }}
                        </p>
                    @elseif ($existingLink === 'conflict')
                        <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                            {{ __('seo-content-ai::filament.projects.existing_article_conflict') }}
                        </p>
                    @endif
                    @forelse ($this->executionDetailsRows as $exec)
                        <div class="mb-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                            <div class="font-medium">Run #{{ $exec['run_id'] }} Â· #{{ $exec['id'] }} Â· {{ $exec['status'] }}</div>
                            <div class="text-gray-500">{{ $exec['action'] }} Â· {{ $exec['started_at'] ?? 'â€”' }} â†’ {{ $exec['finished_at'] ?? 'â€”' }}</div>
                            @if ($exec['error'] !== '')
                                <div class="mt-1 text-danger-600">{{ $exec['error'] }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.item_execution_empty') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <x-seo-content-ai::content-project-ops-styles />
</x-filament-panels::page>
