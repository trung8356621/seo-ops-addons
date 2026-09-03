<script>
(() => {
    if (window.keywordDictionaryViewMode) {
        return;
    }

    const STORAGE_KEY = 'seo_ops_keyword_view_mode';
    const MAX_SELECTION_LENGTH = 200;
    const INTERACTIVE_SELECTOR = [
        'input',
        'textarea',
        'button',
        'a',
        'select',
        'label',
        '[role="button"]',
        '[role="menuitem"]',
        '[contenteditable="true"]',
        '.fi-ta-search-field',
        '.fi-pagination',
        '.fi-ta-filters',
        '.fi-ta-actions',
        '.keyword-item__actions',
        '.keyword-item__menu',
        '.keyword-view-mode-toggle',
    ].join(',');

    function readStoredMode() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored === 'quick' || stored === 'detail') {
                return stored;
            }
        } catch (_error) {
            // ignore
        }

        return 'detail';
    }

    function persistMode(mode) {
        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch (_error) {
            // ignore
        }
    }

    function resolveLivewire(livewireId) {
        if (!livewireId || typeof window.Livewire?.find !== 'function') {
            return null;
        }

        return window.Livewire.find(livewireId);
    }

    function selectionTextFromResults(root) {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount < 1) {
            return null;
        }

        const text = String(selection.toString() || '').trim();
        if (text === '' || text.length > MAX_SELECTION_LENGTH) {
            return null;
        }

        const anchor = selection.anchorNode;
        const focus = selection.focusNode;
        if (!anchor || !focus || !root.contains(anchor) || !root.contains(focus)) {
            return null;
        }

        const anchorEl = anchor.nodeType === Node.ELEMENT_NODE ? anchor : anchor.parentElement;
        const focusEl = focus.nodeType === Node.ELEMENT_NODE ? focus : focus.parentElement;
        if (!(anchorEl instanceof Element) || !(focusEl instanceof Element)) {
            return null;
        }

        if (!anchorEl.closest('.fi-ta-row, tbody') || !focusEl.closest('.fi-ta-row, tbody')) {
            return null;
        }

        if (anchorEl.closest(INTERACTIVE_SELECTOR) || focusEl.closest(INTERACTIVE_SELECTOR)) {
            return null;
        }

        return text;
    }

    function focusTableSearch(root) {
        const input = root.querySelector('.fi-ta-search-field input, input[type="search"]');
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        input.focus({ preventScroll: true });
    }

    window.keywordDictionaryViewMode = function keywordDictionaryViewMode(livewireId) {
        return {
            mode: readStoredMode(),
            livewireId: String(livewireId || ''),

            setMode(next) {
                if (next !== 'quick' && next !== 'detail') {
                    return;
                }

                this.mode = next;
                persistMode(next);

                const layout = this.$el;
                if (layout instanceof HTMLElement) {
                    layout.setAttribute('data-keyword-view-mode', next);
                    layout.classList.toggle('is-quick-mode', next === 'quick');
                    layout.classList.toggle('is-detail-mode', next === 'detail');
                }

                if (next === 'quick') {
                    const component = resolveLivewire(this.livewireId);
                    component?.call?.('closeSidebar');
                }
            },

            onResultsMouseUp(event) {
                if (this.mode !== 'quick') {
                    return;
                }

                const root = event.currentTarget;
                if (!(root instanceof HTMLElement)) {
                    return;
                }

                const text = selectionTextFromResults(root);
                if (!text) {
                    return;
                }

                const component = resolveLivewire(this.livewireId);
                if (!component) {
                    return;
                }

                const current = String(component.get?.('tableSearch') ?? '').trim();
                if (current === text) {
                    focusTableSearch(root);
                    return;
                }

                component.set('tableSearch', text);
                focusTableSearch(root);
            },
        };
    };
})();
</script>
