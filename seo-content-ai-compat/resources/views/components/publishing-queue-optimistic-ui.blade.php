{{-- Factory is also inlined in publishing-queue-hub x-data (Livewire often skips this script). --}}
<script>
    window.createPqOpsUi = function createPqOpsUi() {
        return {
            selectedIds: [],
            pageIds: [],
            processingRows: {},
            wire: null,
            bindWire(wire, selectedIds, pageIds) {
                this.wire = wire || this.wire;
                this.pageIds = (Array.isArray(pageIds) ? pageIds : [])
                    .map((id) => Number(id))
                    .filter((id) => id > 0);
                const incoming = (Array.isArray(selectedIds) ? selectedIds : [])
                    .map((id) => Number(id))
                    .filter((id) => id > 0);
                if (incoming.length > 0 || (this.selectedIds || []).length === 0) {
                    this.selectedIds = incoming;
                }
            },
            selectedIdsSnapshot() {
                return (this.selectedIds || []).map((id) => Number(id)).filter((id) => id > 0);
            },
            selectedCount() {
                return this.selectedIdsSnapshot().length;
            },
            isSelected(id) {
                return this.selectedIdsSnapshot().includes(Number(id || 0));
            },
            isPageAllSelected() {
                const page = this.pageIds || [];
                if (page.length === 0) {
                    return false;
                }
                return page.every((id) => this.isSelected(id));
            },
            toggleRow(id) {
                const tid = Number(id || 0);
                if (tid <= 0) {
                    return;
                }
                if (this.isSelected(tid)) {
                    this.selectedIds = this.selectedIdsSnapshot().filter((value) => value !== tid);
                } else {
                    this.selectedIds = [...this.selectedIdsSnapshot(), tid];
                }
            },
            selectPage() {
                this.selectedIds = [...new Set([
                    ...this.selectedIdsSnapshot(),
                    ...(this.pageIds || []).map((id) => Number(id)),
                ])].filter((id) => id > 0);
            },
            togglePage() {
                if (this.isPageAllSelected()) {
                    const pageSet = new Set((this.pageIds || []).map((id) => Number(id)));
                    this.selectedIds = this.selectedIdsSnapshot().filter((id) => ! pageSet.has(id));
                    return;
                }
                this.selectPage();
            },
            clearSelection() {
                this.selectedIds = [];
                this.syncSelectionToLivewire();
            },
            flushSelection() {
                this.syncSelectionToLivewire();
            },
            syncSelectionToLivewire() {
                if (! this.wire || typeof this.wire.set !== 'function') {
                    return Promise.resolve();
                }
                const ids = this.selectedIdsSnapshot();
                const pending = this.wire.set('selectedTaskIds', ids, false);
                try {
                    this.wire.set('selectAllMatching', false, false);
                } catch (e) {}
                if (pending && typeof pending.then === 'function') {
                    return pending;
                }
                return Promise.resolve();
            },
            runBulk(method, kind, confirmMessage, args) {
                if (confirmMessage && ! window.confirm(String(confirmMessage))) {
                    return Promise.resolve();
                }
                this.beginRowsProcessing(this.selectedIdsSnapshot(), kind || 'publishing');
                const wire = this.wire;
                const name = String(method || '');
                if (! wire || ! name || typeof wire[name] !== 'function') {
                    return Promise.resolve();
                }
                const invoke = () => {
                    if (Array.isArray(args) && args.length > 0) {
                        return wire[name](...args);
                    }
                    return wire[name]();
                };
                return this.syncSelectionToLivewire().then(() => invoke());
            },
            beginRowProcessing(tid, kind) {
                const id = Number(tid || 0);
                if (id <= 0) {
                    return;
                }
                this.processingRows = {
                    ...(this.processingRows || {}),
                    [id]: String(kind || 'publishing'),
                };
            },
            beginRowsProcessing(ids, kind) {
                const list = Array.isArray(ids) ? ids : [];
                list.forEach((id) => this.beginRowProcessing(id, kind));
            },
            beginSelectedProcessing(kind) {
                this.beginRowsProcessing(this.selectedIdsSnapshot(), kind);
            },
            clearRowProcessing(tid) {
                const id = Number(tid || 0);
                if (id <= 0) {
                    return;
                }
                const next = { ...(this.processingRows || {}) };
                delete next[id];
                this.processingRows = next;
            },
            isRowProcessing(tid) {
                return !! this.processingRows?.[Number(tid || 0)];
            },
            rowProcessingKind(tid) {
                return this.processingRows?.[Number(tid || 0)] || null;
            },
        };
    };
    document.addEventListener('alpine:init', () => {
        if (! window.Alpine || typeof window.Alpine.store !== 'function') {
            return;
        }
        try {
            if (window.Alpine.store('pqOpsUi') && typeof window.Alpine.store('pqOpsUi').toggleRow === 'function') {
                return;
            }
        } catch (e) {}
        window.Alpine.store('pqOpsUi', window.createPqOpsUi());
    });
</script>
