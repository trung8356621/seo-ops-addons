function readConfig() {
    const el = document.getElementById('keyword-detail-panel-config');
    if (!el?.textContent?.trim()) {
        return null;
    }

    try {
        return JSON.parse(el.textContent);
    } catch {
        return null;
    }
}

function resolveLivewireComponent(config) {
    const livewireId = config?.livewireId ?? '';
    if (livewireId === '' || typeof window.Livewire?.find !== 'function') {
        return null;
    }

    return window.Livewire.find(livewireId);
}

/** @type {AbortController|null} */
let loadController = null;

/** @type {ReturnType<typeof createKeywordDetailPanel>|null} */
let panelController = null;

function extractRecordKeyFromRow(row) {
    const checkbox = row.querySelector('.fi-ta-record-checkbox[value]');
    if (checkbox?.value) {
        return String(checkbox.value);
    }

    const wireKey = row.getAttribute('wire:key') ?? '';
    const match = wireKey.match(/\.table\.records\.(.+)$/);

    return match?.[1] ? String(match[1]) : null;
}

function highlightRow(root, keywordId) {
    root.querySelectorAll('.fi-ta-row.keyword-row-selected').forEach((row) => {
        row.classList.remove('keyword-row-selected');
    });

    if (!keywordId) {
        return;
    }

    const row = root.querySelector(`.fi-ta-row[data-keyword-id="${keywordId}"]`);
    row?.classList.add('keyword-row-selected');
}

function handleKeywordRowSelect(root, config, recordKey) {
    const keywordId = Number(recordKey);
    if (!Number.isFinite(keywordId) || keywordId <= 0) {
        return;
    }

    highlightRow(root, keywordId);

    const component = resolveLivewireComponent(config);
    if (component) {
        component.call('selectKeyword', String(recordKey));

        return;
    }

    panelController?.openPanel(keywordId);
}

function installRowClickLayers(root, config) {
    const tableShell = root.querySelector('.keyword-table-shell');
    if (!tableShell) {
        return;
    }

    const interactiveSelector = [
        'a',
        'button',
        'input',
        'textarea',
        'select',
        'label',
        '[role="button"]',
        '[role="menuitem"]',
        '[role="menu"]',
        '.keyword-item__actions',
        '.keyword-item__menu',
        '.keyword-item__menu-btn',
        '.fi-ta-selection-cell',
        '.fi-ta-actions-cell',
    ].join(',');

    tableShell.querySelectorAll('.fi-ta-row').forEach((row) => {
        const recordKey = extractRecordKeyFromRow(row);
        if (!recordKey) {
            return;
        }

        row.dataset.keywordId = recordKey;

        row.querySelectorAll('td').forEach((cell) => {
            if (
                cell.classList.contains('fi-ta-selection-cell')
                || cell.classList.contains('fi-ta-actions-cell')
            ) {
                return;
            }

            // Drop legacy full-cell overlays — they blocked nested controls (… menu).
            cell.querySelectorAll(':scope > .keyword-row-click-layer').forEach((layer) => {
                layer.remove();
            });

            cell.classList.add('keyword-row-click-cell');

            if (cell.dataset.keywordRowClickBound === '1') {
                return;
            }

            cell.dataset.keywordRowClickBound = '1';

            cell.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }

                if (target.closest(interactiveSelector)) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                handleKeywordRowSelect(root, config, recordKey);
            });
        });
    });

    if (config?.selectedKeywordId) {
        highlightRow(root, Number(config.selectedKeywordId));
    }
}

function createKeywordDetailPanel(root, config) {
    const layout = root;
    const panel = root.querySelector('[data-keyword-detail-panel]');
    const phraseEl = root.querySelector('[data-keyword-detail-phrase]');
    const phraseWrap = root.querySelector('[data-keyword-detail-phrase-wrap]');
    const emptyEl = root.querySelector('[data-keyword-detail-empty]');
    const loadingEl = root.querySelector('[data-keyword-detail-loading]');
    const errorEl = root.querySelector('[data-keyword-detail-error]');
    const contentEl = root.querySelector('[data-keyword-detail-content]');
    const quickActionsEl = root.querySelector('[data-keyword-detail-quick-actions]');
    const footerEl = root.querySelector('[data-keyword-detail-footer]');
    const footerEditBtn = root.querySelector('[data-keyword-detail-footer-edit]');
    const editBtn = root.querySelector('[data-keyword-detail-edit]');
    const analyzeBtn = root.querySelector('[data-keyword-detail-analyze]');
    const deleteBtn = root.querySelector('[data-keyword-detail-delete]');
    const closeBtn = root.querySelector('[data-keyword-detail-close]');
    const backdrop = root.querySelector('[data-keyword-detail-backdrop]');

    const state = {
        open: false,
        loading: false,
        keywordId: null,
    };

    function isOverlayViewport() {
        return !window.matchMedia('(min-width: 1280px)').matches;
    }

    function refreshActionsBar() {
        const hasAnalyze = analyzeBtn && !analyzeBtn.classList.contains('hidden');
        const hasEdit = editBtn && !editBtn.classList.contains('hidden');
        const hasDelete = deleteBtn && !deleteBtn.classList.contains('hidden');
        const hasFooterEdit = footerEditBtn && !footerEditBtn.classList.contains('hidden');

        quickActionsEl?.classList.toggle('hidden', !(hasEdit || hasDelete));
        footerEl?.classList.toggle('hidden', !(hasFooterEdit || hasAnalyze));
    }

    function setAnalyzeButton(contentAnalysisUrl) {
        if (!analyzeBtn) {
            return;
        }

        const url = typeof contentAnalysisUrl === 'string' ? contentAnalysisUrl.trim() : '';

        if (url === '') {
            analyzeBtn.classList.add('hidden');
            analyzeBtn.classList.add('pointer-events-none', 'opacity-50');
            analyzeBtn.setAttribute('href', '#');

            refreshActionsBar();

            return;
        }

        analyzeBtn.classList.remove('hidden');
        analyzeBtn.classList.remove('pointer-events-none', 'opacity-50');
        analyzeBtn.setAttribute('href', url);
        refreshActionsBar();
    }

    function setActionsVisibility({ canEdit, canDelete }) {
        if (editBtn) {
            editBtn.classList.toggle('hidden', !canEdit);
        }
        if (footerEditBtn) {
            footerEditBtn.classList.toggle('hidden', !canEdit);
        }
        if (deleteBtn) {
            deleteBtn.classList.toggle('hidden', !canDelete);
        }

        refreshActionsBar();
    }

    function showEmptyState() {
        phraseWrap?.classList.add('hidden');
        loadingEl?.classList.add('hidden');
        errorEl?.classList.add('hidden');
        contentEl?.classList.add('hidden');
        quickActionsEl?.classList.add('hidden');
        footerEl?.classList.add('hidden');
        emptyEl?.classList.remove('hidden');
    }

    function showLoadingState() {
        emptyEl?.classList.add('hidden');
        phraseWrap?.classList.add('hidden');
        errorEl?.classList.add('hidden');
        contentEl?.classList.add('hidden');
        quickActionsEl?.classList.add('hidden');
        footerEl?.classList.add('hidden');
        loadingEl?.classList.remove('hidden');
    }

    function showContentState() {
        emptyEl?.classList.add('hidden');
        loadingEl?.classList.add('hidden');
        errorEl?.classList.add('hidden');
        phraseWrap?.classList.remove('hidden');
        contentEl?.classList.remove('hidden');
        quickActionsEl?.classList.remove('hidden');
        footerEl?.classList.remove('hidden');
    }

    function showErrorState(message) {
        emptyEl?.classList.add('hidden');
        loadingEl?.classList.add('hidden');
        phraseWrap?.classList.add('hidden');
        contentEl?.classList.add('hidden');
        quickActionsEl?.classList.add('hidden');
        footerEl?.classList.add('hidden');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.classList.remove('hidden');
        }
    }

    function setPanelOpen(open) {
        state.open = open;
        const overlayMode = isOverlayViewport();
        layout.classList.toggle('is-panel-open', open);
        layout.classList.toggle('is-overlay-mode', overlayMode);
        backdrop?.classList.toggle('is-visible', open && overlayMode);
        backdrop?.setAttribute('aria-hidden', open && overlayMode ? 'false' : 'true');
        panel?.classList.toggle('is-visible', open);
        panel?.classList.toggle('is-hidden', !open);
        panel?.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('keyword-drawer-open', open && overlayMode);
    }

    function restoreLayoutOpenClass() {
        if (!state.open) {
            return;
        }
        layout.classList.add('is-panel-open');
        layout.classList.toggle('is-overlay-mode', isOverlayViewport());
    }

    function isAssignDrawerOpen() {
        return document.body.classList.contains('assign-drawer-open');
    }

    function openAssignFromDetail(payload) {
        window.dispatchEvent(new CustomEvent('assign-content-project:open', {
            detail: {
                ...payload,
                source: payload.source || 'keyword_detail',
            },
        }));
    }

    async function openPanel(keywordId) {
        const id = Number(keywordId);
        if (!Number.isFinite(id) || id <= 0) {
            return;
        }

        const component = resolveLivewireComponent(config);
        if (!component) {
            setPanelOpen(true);
            showErrorState(String(config.errorLabel ?? 'Livewire unavailable.'));

            return;
        }

        if (state.keywordId === id && state.open && !state.loading && contentEl?.innerHTML.trim() !== '') {
            return;
        }

        loadController?.abort();
        loadController = new AbortController();
        const { signal } = loadController;

        state.keywordId = id;
        state.loading = true;

        setPanelOpen(true);
        showLoadingState();
        highlightRow(layout, id);

        try {
            const result = await component.call('loadKeywordDetailPanel', id);

            if (signal.aborted) {
                return;
            }

            if (result?.error) {
                showErrorState(String(result.error));
                state.loading = false;

                return;
            }

            if (phraseEl) {
                phraseEl.textContent = String(result?.phrase ?? '');
            }
            if (contentEl) {
                contentEl.innerHTML = String(result?.html ?? '');

                if (typeof window.Alpine?.initTree === 'function') {
                    window.Alpine.initTree(contentEl);
                }
            }

            setActionsVisibility({
                canEdit: Boolean(result?.canEdit),
                canDelete: Boolean(result?.canDelete),
            });
            setAnalyzeButton(result?.contentAnalysisUrl);

            showContentState();
            state.loading = false;
        } catch (error) {
            if (signal.aborted || error?.name === 'AbortError') {
                return;
            }

            showErrorState(error?.message ?? String(config.errorLabel ?? 'Could not load keyword detail.'));
            state.loading = false;
        }
    }

    function closePanel(notifyLivewire = true) {
        loadController?.abort();
        loadController = null;

        state.open = false;
        state.loading = false;
        state.keywordId = null;

        setPanelOpen(false);
        showEmptyState();
        highlightRow(layout, null);

        if (contentEl) {
            contentEl.innerHTML = '';
        }
        if (phraseEl) {
            phraseEl.textContent = '';
        }
        setActionsVisibility({ canEdit: false, canDelete: false });
        setAnalyzeButton('');

        if (notifyLivewire) {
            resolveLivewireComponent(config)?.call('closeSidebar');
        }
    }

    closeBtn?.addEventListener('click', () => {
        if (isAssignDrawerOpen()) {
            return;
        }
        closePanel(true);
    });
    backdrop?.addEventListener('click', () => {
        if (isAssignDrawerOpen()) {
            return;
        }
        closePanel(true);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.open && !isAssignDrawerOpen()) {
            closePanel(true);
        }
    });

    contentEl?.addEventListener('click', (event) => {
        const assignArticleButton = event.target.closest('[data-assign-article]');
        if (assignArticleButton) {
            event.preventDefault();

            const articleId = Number(assignArticleButton.dataset.assignArticle);
            if (!Number.isFinite(articleId) || articleId <= 0) {
                return;
            }

            // Client-side open — avoid Filament mountAction morph resetting this panel.
            openAssignFromDetail({
                mode: 'article',
                source: 'keyword_detail',
                article_ids: [articleId],
                options: {
                    show_quick_create: true,
                    show_article_fields: true,
                    show_keyword_override: true,
                    show_title_override: true,
                },
            });

            return;
        }

        const assignButton = event.target.closest('[data-assign-link-map]');
        if (!assignButton) {
            return;
        }

        event.preventDefault();

        const mapId = Number(assignButton.dataset.assignLinkMap);
        if (!Number.isFinite(mapId) || mapId <= 0) {
            return;
        }

        const keywordId = Number(state.keywordId);
        openAssignFromDetail({
            mode: 'keyword',
            source: 'keyword_detail',
            keyword_ids: Number.isFinite(keywordId) && keywordId > 0 ? [keywordId] : [],
            map_id: mapId,
        });
    });

    return {
        openPanel,
        closePanel,
        isOpen: () => state.open,
        restoreLayoutOpenClass,
        getKeywordId: () => state.keywordId,
    };
}

function bindLivewireEventsOnce() {
    if (window.__keywordDetailEventsBound) {
        return;
    }

    window.__keywordDetailEventsBound = true;

    window.Livewire?.on('keyword-detail-open', ({ keywordId }) => {
        panelController?.openPanel(keywordId);
    });

    window.Livewire?.on('keyword-detail-close', () => {
        panelController?.closePanel(false);
    });

    window.addEventListener('assign-content-project:shell-open', () => {
        document.body.classList.add('assign-drawer-stacked');
        panelController?.restoreLayoutOpenClass?.();
    });

    window.addEventListener('assign-content-project:shell-close', () => {
        document.body.classList.remove('assign-drawer-stacked');
        panelController?.restoreLayoutOpenClass?.();
    });

    window.addEventListener('assign-content-project:success', () => {
        // Keep Keyword Detail mounted; optional badge refresh can be added later.
        panelController?.restoreLayoutOpenClass?.();
    });
}

export function toggleKeywordDictionaryFilters() {
    const page = document.querySelector('.keyword-dictionary-page');
    const container = page?.querySelector('.fi-ta-filters-above-content-ctn');

    if (!page || !container || typeof Alpine === 'undefined') {
        return;
    }

    const data = Alpine.$data(container);

    if (!data || typeof data.areFiltersOpen !== 'boolean') {
        return;
    }

    data.areFiltersOpen = !data.areFiltersOpen;
    page.classList.toggle('keyword-filters-expanded', data.areFiltersOpen);
}

function bindTableRowLayersOnce(root, config) {
    if (window.__keywordRowLayersBound) {
        installRowClickLayers(root, config);

        return;
    }

    window.__keywordRowLayersBound = true;

    window.Livewire?.hook('morph.updated', ({ el }) => {
        if (!el?.closest?.('.keyword-table-shell') && !el?.querySelector?.('.keyword-table-shell')) {
            // Still restore layout class if ListKeywords morph wiped is-panel-open.
            panelController?.restoreLayoutOpenClass?.();

            return;
        }

        const layout = document.querySelector('.keyword-detail-layout');
        const latestConfig = readConfig();

        if (!layout || !latestConfig) {
            return;
        }

        installRowClickLayers(layout, latestConfig);
        panelController?.restoreLayoutOpenClass?.();
    });
}

export function initKeywordDetailPanel() {
    const config = readConfig();
    let root = document.querySelector('.keyword-detail-layout');
    if (!root) {
        const panel = document.querySelector('[data-keyword-detail-panel]');
        root = panel?.closest('.keyword-workspace-shell') ?? null;
        if (root && !root.classList.contains('keyword-detail-layout')) {
            root.classList.add('keyword-detail-layout');
        }
    }

    if (!config || !root) {
        return;
    }

    window.toggleKeywordDictionaryFilters = toggleKeywordDictionaryFilters;

    panelController = createKeywordDetailPanel(root, config);
    bindLivewireEventsOnce();
    bindTableRowLayersOnce(root, config);
    installRowClickLayers(root, config);
}
