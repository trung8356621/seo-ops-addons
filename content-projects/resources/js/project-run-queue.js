function registerSeoProjectRunQueue() {
    if (window.__seoProjectRunQueueRegistered) {
        return;
    }

    window.__seoProjectRunQueueRegistered = true;

    Alpine.store('seoRunQueue', {
        isRunning: false,
        stopRequested: false,
        currentTaskId: null,
        forceStopHandler: null,

        requestStop() {
            this.stopRequested = true;
            if (typeof this.forceStopHandler === 'function') {
                queueMicrotask(() => this.forceStopHandler());
            }
        },

        reset() {
            this.isRunning = false;
            this.stopRequested = false;
            this.currentTaskId = null;
            document.body.classList.remove('seo-run-queue-active');
        },

        setRunning(isRunning) {
            this.isRunning = isRunning;
            document.body.classList.toggle('seo-run-queue-active', isRunning);
        },
    });

    Alpine.data('seoProjectRunQueue', (config = {}) => ({
        config,
        removedTaskIds: [],
        runSettingsOpen: false,
        syncAllOpen: false,
        bulkConfirmOpen: false,
        bulkBusy: false,
        genericStepOpen: false,
        genericStepLoading: false,
        genericSelectedNodeId: '',
        genericPreview: null,
        genericPreviewError: null,
        selectedTaskIds: [],
        selectedNodeIds: [],
        bulkAction: null,
        bulkPreview: null,
        generatePostImages: Boolean(config?.runSettings?.generate_post_images ?? false),
        runSettingsSubmitting: false,
        forceStopBusy: false,
        syncAllBusy: false,

        init() {
            const store = Alpine.store('seoRunQueue');
            if (store) {
                store.forceStopHandler = () => this.forceStopRunQueue();
            }

            // PHP engine: no JS article orchestration. Optional read-only progress poll.
            if (Boolean(this.config.phpEngine)) {
                this.config.autorun = false;
                const terminal = ['completed', 'cancelled', 'failed'].includes(String(this.config.runStatus || ''));
                if (terminal) {
                    store?.reset();
                    this.config.engineUiRunning = false;
                } else if (['running', 'stopping'].includes(String(this.config.runStatus || ''))) {
                    this.config.engineUiRunning = true;
                }
                const pollMs = Number(this.config.progressPollMs || 0);
                if (pollMs > 0 && ! terminal) {
                    this.$nextTick(() => {
                        this.startPhpEngineProgressPoll(pollMs);
                    });
                }

                return;
            }

            const hasTaskIds = Array.isArray(this.config.taskIds) && this.config.taskIds.length > 0;
            // Chỉ autorun khi URL ?autorun=1 — KHÔNG tự chạy lại vì status=running (F5 spam).
            const shouldRun = Boolean(this.config.autorun) && hasTaskIds;

            if (!shouldRun) {
                if (
                    Boolean(this.config.autorun)
                    && this.config.runStatus === 'running'
                    && !hasTaskIds
                ) {
                    this.$nextTick(() => {
                        queueMicrotask(() => this.completeEmptyQueue());
                    });
                }

                return;
            }

            // Tránh Alpine re-init (Livewire refresh) spawn queue thứ 2 chỉ còn vài task cuối.
            if (store?.isRunning) {
                return;
            }

            this.$nextTick(() => {
                queueMicrotask(() => this.processQueue());
            });
        },

        startPhpEngineProgressPoll(pollMs) {
            const wire = this.resolveWire();
            if (!wire?.pollRunProgress) {
                return;
            }

            const tick = async () => {
                if (! Boolean(this.config.phpEngine)) {
                    return;
                }

                try {
                    const response = await wire.pollRunProgress();
                    if (response?.stats) {
                        this.updateStats(response.stats);
                    }
                    if (response?.status) {
                        this.config.runStatus = String(response.status);
                    }
                    if (typeof response?.engineUiRunning === 'boolean') {
                        this.config.engineUiRunning = response.engineUiRunning;
                    }

                    const status = String(response?.status || this.config.runStatus || '');
                    if (['completed', 'cancelled', 'failed'].includes(status)) {
                        this.config.engineUiRunning = false;
                        Alpine.store('seoRunQueue')?.reset();
                        try {
                            await wire.refresh();
                        } catch (_error) {
                            // ignore
                        }

                        return;
                    }

                    if (['running', 'stopping'].includes(status)) {
                        this.config.engineUiRunning = true;
                    }
                } catch (_error) {
                    // ignore transient poll errors
                }

                window.setTimeout(tick, pollMs);
            };

            window.setTimeout(tick, pollMs);
        },

        async forceStopRunQueue() {
            if (this.forceStopBusy) {
                return;
            }

            const store = Alpine.store('seoRunQueue');
            store.stopRequested = true;
            this.forceStopBusy = true;

            const wire = this.resolveWire();
            try {
                if (wire?.forceStopRunQueue) {
                    await wire.forceStopRunQueue();
                } else if (! Boolean(this.config.phpEngine) && wire?.completeRunQueue) {
                    await wire.completeRunQueue(true);
                }
                this.config.runStatus = Boolean(this.config.phpEngine) ? 'stopping' : 'completed';
                store.reset();
                if (! Boolean(this.config.phpEngine)) {
                    window.location.reload();
                } else if (wire?.refresh) {
                    await wire.refresh();
                }
            } catch (error) {
                window.alert(error?.message ? String(error.message) : 'Không dừng được run.');
            } finally {
                this.forceStopBusy = false;
            }
        },

        /**
         * @deprecated Remove after PHP engine default-on stabilization window
         * (canary pass + failure/stop/edit verified + rollback rarely needed).
         */
        handleStartQueue(detail = {}) {
            if (Boolean(this.config.phpEngine)) {
                window.alert('PHP engine đang bật — không chạy lại queue từ trình duyệt. Dùng Start trên server.');

                return;
            }

            const taskIds = Array.isArray(detail?.taskIds)
                ? detail.taskIds.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            if (taskIds.length === 0) {
                window.alert('Không có hạng mục để chạy lại.');

                return;
            }

            const confirmMessage = String(detail?.confirm ?? '').trim();
            if (confirmMessage !== '' && ! window.confirm(confirmMessage)) {
                return;
            }

            if (taskIds.length === 1) {
                this.runSingleTask(taskIds[0]);

                return;
            }

            this.startQueue(taskIds, {
                partial: true,
                refresh: false,
                preserveActions: true,
            });
        },

        /**
         * @deprecated Remove after PHP engine default-on stabilization window
         * (canary pass + failure/stop/edit verified + rollback rarely needed).
         */
        async runSingleTask(taskId, options = {}) {
            if (Boolean(this.config.phpEngine)) {
                window.alert('PHP engine đang bật — không chạy article sync từ JS.');

                return;
            }

            const id = Number(taskId);
            if (id <= 0) {
                window.alert('Task ID không hợp lệ.');

                return;
            }

            const confirmMessage = String(options?.confirm ?? '').trim();
            if (confirmMessage !== '' && ! window.confirm(confirmMessage)) {
                return;
            }

            const store = Alpine.store('seoRunQueue');
            const wire = this.resolveWire();

            if (! wire?.runItemQueued) {
                window.alert('Không kết nối được Livewire (runItemQueued). Hard refresh (Ctrl+F5).');
                console.error('[seo-run-queue] resolveWire failed', this.config);

                return;
            }

            if (store.isRunning) {
                window.alert('Đang có queue chạy — bấm Dừng hoặc đợi xong.');

                return;
            }

            store.setRunning(true);
            store.stopRequested = false;
            store.currentTaskId = id;
            this.markRowRunning(id);

            console.info('[seo-run-queue] runSingleTask start', { taskId: id, livewireId: this.config?.livewireId });

            try {
                const response = await wire.runItemQueued(id, true);
                console.info('[seo-run-queue] runSingleTask response', response);

                if (response?.stats) {
                    this.updateStats(response.stats);
                }

                if (response?.success && response?.item) {
                    this.applyItemResult(id, response.item, response.displayError ?? '', {
                        preserveActions: true,
                        highlight: true,
                    });

                    const stats = response.item?.step_stats ?? {};
                    const skipped = Number(stats.skipped ?? 0);
                    const completed = Number(stats.completed ?? 0);
                    if (skipped > 0 && completed === 0) {
                        window.alert(
                            `Chạy xong nhưng AI bị bỏ qua hết (${skipped} bước skipped). Xem storage/logs và cột «Chạy lần cuối».`,
                        );
                    }
                } else {
                    const message = response?.message ?? 'Không chạy được quy trình.';
                    this.applyItemFailure(id, message, {
                        preserveActions: true,
                        highlight: true,
                    });
                    window.alert(message);
                }

                this.scrollRowIntoView(id);
                this.bumpRowToTop(id);
            } catch (error) {
                const message = error?.message ? String(error.message) : 'Lỗi Livewire khi chạy lại.';
                console.error('[seo-run-queue] runSingleTask error', error);
                this.applyItemFailure(id, message, {
                    preserveActions: true,
                    highlight: true,
                });
                window.alert(message);
            } finally {
                store.currentTaskId = null;
                store.reset();
            }
        },

        bumpRowToTop(taskId) {
            const row = this.findRow(taskId);
            const tbody = row?.closest('tbody');
            if (! row || ! tbody) {
                return;
            }

            tbody.insertBefore(row, tbody.firstChild);
            tbody.querySelectorAll('tr[data-run-task-id]').forEach((tr, index) => {
                const cells = tr.querySelectorAll('td');
                const indexCell = cells[1] ?? cells[0];
                if (indexCell) {
                    indexCell.textContent = String(index + 1);
                }
            });
        },

        visibleTaskIds() {
            return Array.from(this.$el.querySelectorAll('tr[data-run-task-id]'))
                .map((row) => Number(row.getAttribute('data-run-task-id')))
                .filter((id) => id > 0);
        },

        allVisibleSelected() {
            const visible = this.visibleTaskIds();
            if (visible.length === 0) {
                return false;
            }

            return visible.every((id) => this.selectedTaskIds.includes(id));
        },

        toggleSelectAll(checked) {
            const visible = this.visibleTaskIds();
            if (checked) {
                this.selectedTaskIds = Array.from(new Set([...this.selectedTaskIds, ...visible]));
            } else {
                this.selectedTaskIds = this.selectedTaskIds.filter((id) => ! visible.includes(id));
            }
        },

        bulkSelectedLabel() {
            const template = this.config.labels?.bulkSelected ?? 'Đã chọn :count bài';

            return template.replace(':count', String(this.selectedTaskIds.length));
        },

        bulkActionLabel(action = null) {
            const key = action || this.bulkAction;
            const actions = this.config.bulkActions || {};
            if (actions[key]?.label) {
                return String(actions[key].label);
            }

            if (key === 'regenerate_outline') {
                return this.config.labels?.bulkActionOutline ?? 'Tạo lại dàn ý';
            }
            if (key === 'regenerate_article') {
                return this.config.labels?.bulkActionArticle ?? 'Tạo lại bài từ dàn ý';
            }
            if (key === 'regenerate_outline_and_article') {
                return this.config.labels?.bulkActionOutlineAndArticle ?? 'Tạo lại dàn ý và bài viết';
            }

            return key || '';
        },

        canBulkAction(action) {
            if (! this.config.canRetryWorkflowSteps) {
                return false;
            }
            const roles = this.config.roleAvailability || {};
            if (action === 'regenerate_outline') {
                return Boolean(roles.has_outline_role);
            }
            if (action === 'regenerate_article') {
                return Boolean(roles.has_content_role);
            }
            if (action === 'regenerate_outline_and_article') {
                return Boolean(roles.has_outline_role) && Boolean(roles.has_content_role);
            }

            return false;
        },

        bulkConfirmText() {
            const template = this.config.labels?.bulkConfirmBody
                ?? 'Action: :action — Hợp lệ: :valid — Không hợp lệ: :invalid. Workflow: :workflow.';
            const preview = this.bulkPreview || {};

            return template
                .replace(':action', this.bulkActionLabel())
                .replace(':valid', String(preview.valid_count ?? 0))
                .replace(':invalid', String(preview.invalid_count ?? 0))
                .replace(':workflow', String(preview.workflow_name || '—'))
                .replace(':articles', String(this.selectedTaskIds.length));
        },

        async openBulkRerunPreview(action) {
            if (this.selectedTaskIds.length === 0 || this.bulkBusy) {
                return;
            }
            if (! this.canBulkAction(action)) {
                window.alert('Workflow chưa gán đủ vai trò thực thi cho action này.');
                return;
            }

            const wire = this.resolveWire();
            if (! wire?.previewBulkRerunByAction) {
                window.alert('Không kết nối được Livewire (previewBulkRerunByAction). Hard refresh (Ctrl+F5).');
                return;
            }

            this.bulkBusy = true;
            this.bulkAction = action;
            try {
                const preview = await wire.previewBulkRerunByAction(this.selectedTaskIds, action);
                this.bulkPreview = preview || null;
                if (! preview?.success && preview?.message) {
                    window.alert(String(preview.message));
                    return;
                }
                this.bulkConfirmOpen = true;
            } catch (error) {
                window.alert(error?.message ? String(error.message) : 'Không tải được preview bulk.');
            } finally {
                this.bulkBusy = false;
            }
        },

        openBulkConfirm() {
            // Legacy no-op — dùng openBulkRerunPreview.
        },

        async confirmBulkRetry() {
            const wire = this.resolveWire();
            if (! wire?.bulkRerunByAction) {
                window.alert('Không kết nối được Livewire (bulkRerunByAction). Hard refresh (Ctrl+F5).');
                return;
            }
            if (! this.bulkAction || ! this.bulkPreview?.can_execute) {
                return;
            }

            const allowPartial = Number(this.bulkPreview?.invalid_count ?? 0) > 0
                && Number(this.bulkPreview?.valid_count ?? 0) > 0;

            this.bulkBusy = true;
            try {
                const response = await wire.bulkRerunByAction(
                    this.selectedTaskIds,
                    this.bulkAction,
                    allowPartial,
                );
                window.alert(response?.message ?? 'Đã xử lý bulk rerun.');
                this.bulkConfirmOpen = false;
                this.selectedTaskIds = [];
                this.bulkAction = null;
                this.bulkPreview = null;
                window.location.reload();
            } catch (error) {
                window.alert(error?.message ? String(error.message) : 'Bulk rerun thất bại.');
            } finally {
                this.bulkBusy = false;
            }
        },

        async openGenericStepPicker() {
            const steps = Array.isArray(this.config?.genericPickerSteps)
                ? this.config.genericPickerSteps
                : [];
            if (! steps.length) {
                window.alert('Không có bước generic trong workflow hiện tại.');
                return;
            }
            this.genericStepOpen = true;
            this.genericSelectedNodeId = String(steps[0]?.node_id || '');
            this.genericPreview = null;
            this.genericPreviewError = null;
            if (this.genericSelectedNodeId) {
                await this.refreshGenericStepPreview();
            }
        },

        closeGenericStepPicker() {
            this.genericStepOpen = false;
            this.genericPreview = null;
            this.genericPreviewError = null;
            this.genericSelectedNodeId = '';
        },

        genericPickerSteps() {
            return Array.isArray(this.config?.genericPickerSteps)
                ? this.config.genericPickerSteps
                : [];
        },

        genericSelectedStep() {
            const id = String(this.genericSelectedNodeId || '');
            return this.genericPickerSteps().find((s) => String(s.node_id) === id) || null;
        },

        async refreshGenericStepPreview() {
            const nodeId = String(this.genericSelectedNodeId || '');
            if (! nodeId) {
                this.genericPreview = null;
                this.genericPreviewError = 'Chưa chọn bước.';
                return;
            }
            const wire = this.resolveWire();
            if (! wire?.previewBulkGenericStep) {
                this.genericPreviewError = 'Thiếu Livewire previewBulkGenericStep. Hard refresh.';
                return;
            }
            this.genericStepLoading = true;
            this.genericPreviewError = null;
            try {
                const previewWrap = await wire.previewBulkGenericStep(this.selectedTaskIds, nodeId);
                this.genericPreview = previewWrap?.preview || previewWrap || null;
                if (! this.genericPreview?.can_execute && (this.genericPreview?.invalid || []).length === 0) {
                    this.genericPreviewError = previewWrap?.message
                        || this.genericPreview?.message
                        || 'Không thể chạy lại bước này.';
                }
            } catch (error) {
                this.genericPreview = null;
                this.genericPreviewError = error?.message
                    ? String(error.message)
                    : 'Không tải được preview.';
            } finally {
                this.genericStepLoading = false;
            }
        },

        async confirmGenericStepRerun() {
            const nodeId = String(this.genericSelectedNodeId || '');
            const preview = this.genericPreview;
            if (! nodeId || ! preview?.can_execute) {
                return;
            }
            const wire = this.resolveWire();
            if (! wire?.bulkRerunGenericStep) {
                window.alert('Thiếu Livewire bulkRerunGenericStep. Hard refresh.');
                return;
            }
            const invalidCount = Number(preview.invalid_count || 0);
            const allowPartial = invalidCount > 0 && Number(preview.valid_count || 0) > 0;
            this.bulkBusy = true;
            try {
                const response = await wire.bulkRerunGenericStep(
                    this.selectedTaskIds,
                    nodeId,
                    allowPartial,
                );
                window.alert(response?.message ?? 'Đã xử lý.');
                this.closeGenericStepPicker();
                this.selectedTaskIds = [];
                window.location.reload();
            } catch (error) {
                window.alert(error?.message ? String(error.message) : 'Generic step rerun thất bại.');
            } finally {
                this.bulkBusy = false;
            }
        },

        async retryWorkflowStep(taskId, nodeId) {
            const id = Number(taskId);
            const node = String(nodeId ?? '').trim();
            if (id <= 0 || node === '') {
                return;
            }

            const wire = this.resolveWire();
            if (! wire?.retryWorkflowStep) {
                window.alert('Không kết nối được Livewire (retryWorkflowStep). Hard refresh (Ctrl+F5).');
                return;
            }

            const store = Alpine.store('seoRunQueue');
            if (store.isRunning) {
                window.alert('Đang có queue chạy — bấm Dừng hoặc đợi xong.');
                return;
            }

            store.setRunning(true);
            store.currentTaskId = id;
            this.markRowRunning(id);

            try {
                const response = await wire.retryWorkflowStep(id, node);
                const message = String(response?.message ?? '').trim();
                if (response?.success) {
                    this.applyItemResult(
                        id,
                        {
                            status: 'success',
                            message: message || 'Đã chạy lại prompt.',
                            ...(response?.item && typeof response.item === 'object' ? response.item : {}),
                        },
                        '',
                        { preserveActions: true, highlight: true },
                    );
                    window.location.reload();
                } else {
                    this.applyItemFailure(
                        id,
                        message || 'Không chạy được prompt.',
                        { preserveActions: true, highlight: true },
                    );
                }
            } catch (error) {
                this.applyItemFailure(
                    id,
                    error?.message ? String(error.message) : 'Lỗi khi chạy lại prompt.',
                    { preserveActions: true, highlight: true },
                );
            } finally {
                store.currentTaskId = null;
                store.reset();
            }
        },

        async cancelWorkflowStep(taskId, nodeId) {
            const id = Number(taskId);
            const node = String(nodeId ?? '').trim();
            if (id <= 0) {
                return;
            }

            const wire = this.resolveWire();
            if (! wire?.cancelWorkflowStep) {
                window.alert('Không kết nối được Livewire (cancelWorkflowStep). Hard refresh (Ctrl+F5).');
                return;
            }

            try {
                const response = await wire.cancelWorkflowStep(id, node);
                const message = String(response?.message ?? '').trim();
                const cancelled = Number(response?.cancelled ?? 0);
                const alreadyIdle = response?.already_idle === true;
                const row = this.findRow(id);
                const messageCell = row?.querySelector('[data-run-message]');
                if (messageCell && message) {
                    messageCell.textContent = message;
                }

                // Chỉ bỏ busy khi server thực sự cancel (hoặc đã idle cancel trước đó).
                // Không sơn Failed hàng chính — status hàng chính ≠ step retry.
                if (response?.success && (cancelled > 0 || alreadyIdle)) {
                    if (row) {
                        row.querySelectorAll('[data-run-busy-step]').forEach((el) => el.remove());
                    }
                    window.location.reload();
                    return;
                }

                if (messageCell) {
                    messageCell.textContent = message
                        || `Không ngắt được (cancelled=${cancelled}, active_before=${Number(response?.active_before ?? 0)}).`;
                }
            } catch (error) {
                const message = error?.message
                    ? String(error.message)
                    : 'Lỗi khi ngắt bước.';
                const row = this.findRow(id);
                const messageCell = row?.querySelector('[data-run-message]');
                if (messageCell) {
                    messageCell.textContent = message;
                }
            }
        },

        startRerunAllQueue() {
            // Entry «Chạy lại toàn bộ» đã gỡ.
        },

        openRerunSettingsModal() {
            // no-op — dùng bulk/per-prompt thay thế
        },

        async confirmRerunSettings() {
            this.runSettingsOpen = false;
        },

        openSyncAllConfirm() {
            if (! this.config.canSyncAll) {
                return;
            }

            this.syncAllOpen = true;
        },

        async confirmSyncAll() {
            const wire = this.resolveWire();
            if (! wire?.syncAllCompleted) {
                window.alert('Không kết nối được Livewire để sync.');

                return;
            }

            if (this.syncAllBusy) {
                return;
            }

            this.syncAllBusy = true;

            try {
                await wire.syncAllCompleted();
                this.syncAllOpen = false;
                await wire.refresh?.();
            } catch (error) {
                const message = error?.message ? String(error.message) : 'Không dispatch được sync.';
                window.alert(message);
            } finally {
                this.syncAllBusy = false;
            }
        },

        isRowVisible(taskId) {
            const id = Number(taskId);

            return id <= 0 || ! this.removedTaskIds.map(Number).includes(id);
        },

        hideArchivedRow(taskId) {
            const id = Number(taskId);
            if (id <= 0) {
                return;
            }

            this.removedTaskIds = Array.from(new Set([...this.removedTaskIds.map(Number), id]));

            // x-show trên <tr> không ổn định — xóa DOM ngay sau archive.
            const row = this.findRow(id);
            row?.remove();
        },

        archiveTaskRow(taskId) {
            const id = Number(taskId);
            if (id <= 0) {
                return;
            }

            if (this.config?.canArchiveItems === false) {
                return;
            }

            const confirmMessage = String(
                this.config.labels?.archiveConfirm
                ?? 'Gỡ hạng mục khỏi project tháng và đưa vào kho lưu trữ domain?',
            );
            if (! window.confirm(confirmMessage)) {
                return;
            }

            const row = this.findRow(id);
            const status = String(row?.dataset?.runItemStatus ?? '');

            this.hideArchivedRow(id);
            this.bumpStatsAfterArchive(status);

            const wire = this.resolveWire();
            if (! wire?.archiveItem) {
                return;
            }

            Promise.resolve(wire.archiveItem(id)).catch(() => {
                // Row đã xóa; notification lỗi do Livewire/Filament.
            });
        },

        bumpStatsAfterArchive(status) {
            const totalEl = document.querySelector('[data-run-stat="total"]');
            const total = Math.max(0, Number(totalEl?.textContent ?? 0) - 1);
            this.setStatValue('total', total);

            if (status === 'success') {
                const el = document.querySelector('[data-run-stat="succeeded"]');
                this.setStatValue('succeeded', Math.max(0, Number(el?.textContent ?? 0) - 1));

                return;
            }

            if (status === 'failed') {
                const el = document.querySelector('[data-run-stat="failed"]');
                this.setStatValue('failed', Math.max(0, Number(el?.textContent ?? 0) - 1));

                return;
            }

            if (status === 'pending' || status === 'manual') {
                const el = document.querySelector('[data-run-stat="pending"]');
                this.setStatValue('pending', Math.max(0, Number(el?.textContent ?? 0) - 1));
            }
        },

        async completeEmptyQueue() {
            const wire = this.resolveWire();
            if (!wire) {
                return;
            }

            await wire.completeRunQueue(false);
            await wire.refresh();
        },

        resolveWire() {
            const livewireId = String(this.config?.livewireId ?? '').trim();
            if (livewireId !== '' && typeof window.Livewire?.find === 'function') {
                const component = window.Livewire.find(livewireId);
                if (component?.call) {
                    return {
                        runItemQueued: (taskId, markCompleted = false) => component.call('runItemQueued', taskId, markCompleted),
                        retryWorkflowStep: (taskId, nodeId) => component.call('retryWorkflowStep', taskId, nodeId),
                        cancelWorkflowStep: (taskId, nodeId) => component.call('cancelWorkflowStep', taskId, nodeId),
                        bulkRetryWorkflowSteps: (taskIds, nodeIds) => component.call('bulkRetryWorkflowSteps', taskIds, nodeIds),
                        previewBulkRerunByAction: (taskIds, action) => component.call('previewBulkRerunByAction', taskIds, action),
                        bulkRerunByAction: (taskIds, action, allowPartial = false) => component.call('bulkRerunByAction', taskIds, action, allowPartial),
                        previewBulkGenericStep: (taskIds, nodeId) => component.call('previewBulkGenericStep', taskIds, nodeId),
                        bulkRerunGenericStep: (taskIds, nodeId, allowPartial = false) => component.call('bulkRerunGenericStep', taskIds, nodeId, allowPartial),
                        beginRunQueue: () => component.call('beginRunQueue'),
                        finalizePartialQueue: () => component.call('finalizePartialQueue'),
                        completeRunQueue: (stopped) => component.call('completeRunQueue', stopped),
                        forceStopRunQueue: () => component.call('forceStopRunQueue'),
                        archiveItem: (taskId) => component.call('archiveItem', taskId),
                        updateRunSettingsForRerun: (settings) => component.call('updateRunSettingsForRerun', settings),
                        syncAllCompleted: () => component.call('syncAllCompleted'),
                        refresh: async () => {
                            if (typeof component.$wire?.$refresh === 'function') {
                                await component.$wire.$refresh();

                                return;
                            }

                            if (typeof component.$refresh === 'function') {
                                await component.$refresh();
                            }
                        },
                        checkArticleEditorReady: (articleId) => component.call('checkArticleEditorReady', articleId),
                    };
                }
            }

            if (this.$wire?.runItemQueued) {
                return {
                    runItemQueued: (taskId, markCompleted = false) => this.$wire.runItemQueued(taskId, markCompleted),
                    retryWorkflowStep: (taskId, nodeId) => this.$wire.retryWorkflowStep(taskId, nodeId),
                    cancelWorkflowStep: (taskId, nodeId) => this.$wire.cancelWorkflowStep(taskId, nodeId),
                    bulkRetryWorkflowSteps: (taskIds, nodeIds) => this.$wire.bulkRetryWorkflowSteps(taskIds, nodeIds),
                    previewBulkRerunByAction: (taskIds, action) => this.$wire.previewBulkRerunByAction(taskIds, action),
                    bulkRerunByAction: (taskIds, action, allowPartial = false) => this.$wire.bulkRerunByAction(taskIds, action, allowPartial),
                    previewBulkGenericStep: (taskIds, nodeId) => this.$wire.previewBulkGenericStep(taskIds, nodeId),
                    bulkRerunGenericStep: (taskIds, nodeId, allowPartial = false) => this.$wire.bulkRerunGenericStep(taskIds, nodeId, allowPartial),
                    beginRunQueue: () => this.$wire.beginRunQueue(),
                    finalizePartialQueue: () => this.$wire.finalizePartialQueue(),
                    completeRunQueue: (stopped) => this.$wire.completeRunQueue(stopped),
                    forceStopRunQueue: () => this.$wire.forceStopRunQueue(),
                    archiveItem: (taskId) => this.$wire.archiveItem(taskId),
                    updateRunSettingsForRerun: (settings) => this.$wire.updateRunSettingsForRerun(settings),
                    syncAllCompleted: () => this.$wire.syncAllCompleted(),
                    refresh: async () => {
                        if (typeof this.$wire.$refresh === 'function') {
                            await this.$wire.$refresh();
                        }
                    },
                    checkArticleEditorReady: (articleId) => this.$wire.checkArticleEditorReady(articleId),
                };
            }

            if (this.$wire?.archiveItem) {
                return {
                    archiveItem: (taskId) => this.$wire.archiveItem(taskId),
                };
            }

            return null;
        },

        /**
         * @deprecated Remove after PHP engine default-on stabilization window
         * (canary pass + failure/stop/edit verified + rollback rarely needed).
         * JS must not orchestrate when config.phpEngine / orchestration=php.
         */
        async processQueue() {
            if (Boolean(this.config.phpEngine)) {
                return;
            }

            const taskIds = Array.isArray(this.config.taskIds)
                ? this.config.taskIds.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            if (taskIds.length === 0) {
                return;
            }

            await this.startQueue(taskIds, {
                partial: false,
                refresh: true,
                preserveActions: false,
            });
        },

        /**
         * @deprecated Remove after PHP engine default-on stabilization window
         * (canary pass + failure/stop/edit verified + rollback rarely needed).
         */
        async startQueue(taskIds, options = {}) {
            if (Boolean(this.config.phpEngine)) {
                window.alert('PHP engine đang bật — JS không được orchestration article.');

                return;
            }

            const store = Alpine.store('seoRunQueue');
            const wire = this.resolveWire();

            if (!wire?.runItemQueued) {
                window.alert('Không kết nối được Livewire để chạy lại hạng mục.');

                return;
            }

            if (store.isRunning) {
                window.alert('Đang có queue chạy — bấm Dừng hoặc đợi xong rồi thử lại.');

                return;
            }

            const normalizedTaskIds = Array.isArray(taskIds)
                ? taskIds.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            if (normalizedTaskIds.length === 0) {
                return;
            }

            const partial = options.partial === true;
            const shouldRefresh = options.refresh !== false && ! partial;
            const preserveActions = options.preserveActions === true || partial;
            // Chạy lẻ: ghi completed ngay từng item. Queue dài: giữ running đến khi complete.
            const markCompletedPerItem = partial && normalizedTaskIds.length === 1;

            store.setRunning(true);
            store.stopRequested = false;

            let stopped = false;
            let lastFinishedTaskId = null;

            try {
                if (! partial && wire.beginRunQueue) {
                    await wire.beginRunQueue();
                }

                if (partial && ! markCompletedPerItem && wire.beginRunQueue) {
                    await wire.beginRunQueue();
                }

                for (const taskId of normalizedTaskIds) {
                    if (store.stopRequested) {
                        stopped = true;
                        break;
                    }

                    store.currentTaskId = taskId;
                    this.markRowRunning(taskId);

                    const response = await wire.runItemQueued(taskId, markCompletedPerItem);

                    if (response?.stats) {
                        this.updateStats(response.stats);
                    }

                    if (response?.success && response?.item) {
                        this.applyItemResult(taskId, response.item, response.displayError ?? '', {
                            preserveActions,
                            highlight: partial,
                        });
                        lastFinishedTaskId = taskId;
                    } else if (! response?.success) {
                        this.applyItemFailure(taskId, response?.message ?? 'Không chạy được quy trình.', {
                            preserveActions,
                            highlight: partial,
                        });
                        lastFinishedTaskId = taskId;
                    }

                    if (store.stopRequested) {
                        stopped = true;
                        break;
                    }
                }

                store.currentTaskId = null;

                if (partial) {
                    if (! markCompletedPerItem && wire.finalizePartialQueue) {
                        await wire.finalizePartialQueue();
                    }
                } else if (wire.completeRunQueue) {
                    await wire.completeRunQueue(stopped);
                }
            } catch (error) {
                const message = error?.message
                    ? String(error.message)
                    : 'Không chạy được quy trình.';
                if (store.currentTaskId) {
                    this.applyItemFailure(store.currentTaskId, message, {
                        preserveActions,
                        highlight: partial,
                    });
                }
                window.alert(message);
            } finally {
                store.currentTaskId = null;
                store.reset();
            }

            if (lastFinishedTaskId && partial) {
                this.scrollRowIntoView(lastFinishedTaskId);
            }

            if (! shouldRefresh) {
                return;
            }

            try {
                await wire.refresh();
            } catch (_error) {
                // ignore refresh errors after queue finished
            }

            if (stopped) {
                return;
            }

            const url = new URL(window.location.href);
            if (url.searchParams.has('autorun')) {
                url.searchParams.delete('autorun');
                window.history.replaceState({}, '', url.toString());
            }
        },

        markRowRunning(taskId) {
            const id = Number(taskId);
            if (id <= 0) {
                return;
            }

            const row = this.findRow(id);
            if (!row) {
                return;
            }

            row.dataset.runItemStatus = 'running';
            row.classList.remove(
                'bg-warning-50/40',
                'dark:bg-warning-500/5',
                'seo-run-row-just-finished',
                'seo-run-row-just-failed',
            );
            row.classList.add('bg-primary-50/40', 'dark:bg-primary-500/5');

            const runningLabel = this.escapeHtml(this.config.labels?.running ?? 'Đang chạy…');

            const statusCell = row.querySelector('[data-run-status]');
            if (statusCell) {
                if (! statusCell.dataset.runStatusBackup) {
                    statusCell.dataset.runStatusBackup = statusCell.innerHTML;
                }
                statusCell.innerHTML =
                    `<span class="inline-flex rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">${runningLabel}</span>`;
            }

            const messageCell = row.querySelector('[data-run-message]');
            if (messageCell) {
                if (! messageCell.dataset.runMessageBackup) {
                    messageCell.dataset.runMessageBackup = messageCell.innerHTML;
                }
                messageCell.textContent = this.config.labels?.running ?? 'Đang chạy…';
            }

            const actionsCell = row.querySelector('[data-run-actions]');
            if (actionsCell) {
                if (! actionsCell.dataset.runActionsBackup) {
                    actionsCell.dataset.runActionsBackup = actionsCell.innerHTML;
                }
                actionsCell.innerHTML = `<span class="text-xs text-primary-600 dark:text-primary-400">${runningLabel}</span>`;
            }
        },

        applyItemResult(taskId, item, displayError, options = {}) {
            const row = this.findRow(taskId);
            if (!row) {
                return;
            }

            const preserveActions = options.preserveActions === true;
            const highlight = options.highlight === true;

            row.classList.remove('bg-primary-50/40', 'dark:bg-primary-500/5', 'bg-warning-50/40', 'dark:bg-warning-500/5');

            const status = String(item?.status ?? 'failed');
            const statusCell = row.querySelector('[data-run-status]');
            const messageCell = row.querySelector('[data-run-message]');
            const actionsCell = row.querySelector('[data-run-actions]');

            if (status === 'success') {
                row.dataset.runItemStatus = 'success';

                if (statusCell) {
                    statusCell.innerHTML =
                        '<span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">OK</span>';
                    delete statusCell.dataset.runStatusBackup;
                }

                if (messageCell) {
                    const message = String(item?.message ?? '');
                    messageCell.innerHTML = `<p class="font-medium text-success-700 dark:text-success-400">${this.escapeHtml(message)}</p>`;
                    delete messageCell.dataset.runMessageBackup;
                }

                this.updateLastRunCell(row, item?.last_run_at);
                this.updateRetryBadge(row, item?.retry_count);

                const articleId = Number(item?.article_id ?? 0);
                const editorReady = item?.article_editor_ready !== false;
                if (articleId > 0 && !editorReady) {
                    this.pollArticleEditorReady(taskId, articleId, item);
                }

                if (highlight) {
                    row.classList.add('seo-run-row-just-finished');
                    window.setTimeout(() => {
                        row.classList.remove('seo-run-row-just-finished');
                    }, 5000);
                }
            } else {
                row.dataset.runItemStatus = 'failed';

                if (statusCell) {
                    statusCell.innerHTML =
                        `<span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">${this.escapeHtml(this.config.labels?.failed ?? 'Lỗi')}</span>`;
                    delete statusCell.dataset.runStatusBackup;
                }

                if (messageCell) {
                    messageCell.innerHTML =
                        `<p class="font-medium text-danger-600 dark:text-danger-400">${this.escapeHtml(displayError)}</p>`;
                    delete messageCell.dataset.runMessageBackup;
                }

                this.updateLastRunCell(row, item?.last_run_at);
                this.updateRetryBadge(row, item?.retry_count);

                if (highlight) {
                    row.classList.add('seo-run-row-just-failed');
                    window.setTimeout(() => {
                        row.classList.remove('seo-run-row-just-failed');
                    }, 5000);
                }
            }

            if (actionsCell) {
                if (preserveActions && actionsCell.dataset.runActionsBackup) {
                    actionsCell.innerHTML = actionsCell.dataset.runActionsBackup;
                    delete actionsCell.dataset.runActionsBackup;
                } else {
                    actionsCell.innerHTML = '—';
                    delete actionsCell.dataset.runActionsBackup;
                }
            }

            this.updateRetryBadge(row, item?.retry_count);
        },

        applyItemFailure(taskId, message, options = {}) {
            this.applyItemResult(taskId, { status: 'failed', message }, message, options);
        },

        scrollRowIntoView(taskId) {
            const row = this.findRow(taskId);
            if (! row) {
                return;
            }

            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
        },

        updateStats(stats) {
            this.setStatValue('total', stats.total);
            this.setStatValue('succeeded', stats.succeeded);
            this.setStatValue('failed', stats.failed);
            this.setStatValue('pending', stats.pending);
        },

        setStatValue(key, value) {
            const el = document.querySelector(`[data-run-stat="${key}"]`);
            if (el) {
                el.textContent = String(value ?? 0);
            }
        },

        updateLastRunCell(row, lastRunAt) {
            const cell = row?.querySelector('[data-run-last-run]');
            if (! cell) {
                return;
            }

            const raw = String(lastRunAt ?? '').trim();
            if (raw === '') {
                cell.textContent = '—';

                return;
            }

            const parsed = new Date(raw.replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) {
                cell.textContent = raw;

                return;
            }

            const pad = (value) => String(value).padStart(2, '0');
            cell.textContent = `${pad(parsed.getDate())}/${pad(parsed.getMonth() + 1)}/${parsed.getFullYear()} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}:${pad(parsed.getSeconds())}`;
        },

        updateRetryBadge(row, retryCount) {
            const badge = row?.querySelector('[data-run-retry-badge]');
            if (! badge) {
                return;
            }

            const count = Number(retryCount ?? 0);
            if (count <= 0) {
                badge.style.display = 'none';
                badge.textContent = '';
                badge.removeAttribute('title');

                return;
            }

            badge.style.display = '';
            badge.textContent = String(count);
            const template = this.config?.labels?.rerunBadgeTooltip;
            if (typeof template === 'string' && template.includes(':count')) {
                badge.title = template.replaceAll(':count', String(count));
            } else if (typeof template === 'string' && template !== '') {
                badge.title = template;
            } else {
                badge.title = `Đã chạy lại ${count} lần`;
            }
        },

        findRow(taskId) {
            return document.querySelector(`[data-run-task-id="${taskId}"]`);
        },

        escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        },

        async pollArticleEditorReady(taskId, articleId, item) {
            const wire = this.resolveWire();
            if (!wire?.checkArticleEditorReady) {
                return;
            }

            const maxAttempts = 120;
            let attempts = 0;

            while (attempts < maxAttempts) {
                attempts += 1;

                // Đừng refresh Livewire khi queue đang chạy — gây mất DOM hàng đã OK / Alpine re-init lệch.
                if (Alpine.store('seoRunQueue')?.isRunning) {
                    await new Promise((resolve) => {
                        window.setTimeout(resolve, 3000);
                    });
                    continue;
                }

                const response = await wire.checkArticleEditorReady(articleId);
                if (response?.ready) {
                    const row = this.findRow(taskId);
                    row?.querySelector('[data-run-article-preparing]')?.remove();

                    return;
                }

                await new Promise((resolve) => {
                    window.setTimeout(resolve, 3000);
                });
            }
        },
    }));
}

if (window.Alpine) {
    registerSeoProjectRunQueue();
} else {
    document.addEventListener('alpine:init', registerSeoProjectRunQueue);
}
