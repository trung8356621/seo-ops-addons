<script>
    (function () {
        if (window.__BULK_SELECT_RELOAD_GUARD__) {
            return;
        }
        window.__BULK_SELECT_RELOAD_GUARD__ = true;

        const confirmMessage = @json(__('seo-content-ai::filament.common.bulk_select_reload_confirm'));
        const livewireSelectKeys = [
            'selectedArticleIds',
            'selectedTaskIds',
            'selectedKeywordRefs',
            'selectedSuggestionIds',
            'selectedRecords',
        ];

        function isCheckedCheckbox(node) {
            return node instanceof HTMLInputElement
                && node.type === 'checkbox'
                && node.checked
                && ! node.disabled;
        }

        function livewireHasSelection() {
            if (! window.Livewire || typeof window.Livewire.all !== 'function') {
                return false;
            }

            try {
                return window.Livewire.all().some((component) => {
                    return livewireSelectKeys.some((key) => {
                        try {
                            const value = component.get(key);
                            if (Array.isArray(value)) {
                                return value.length > 0;
                            }
                            if (value && typeof value === 'object' && typeof value.length === 'number') {
                                return value.length > 0;
                            }

                            return false;
                        } catch {
                            return false;
                        }
                    });
                });
            } catch {
                return false;
            }
        }

        function alpineStoreHasSelection() {
            try {
                const store = window.Alpine && typeof window.Alpine.store === 'function'
                    ? window.Alpine.store('pqOpsUi')
                    : null;
                if (! store) {
                    return false;
                }
                if (typeof store.selectedCount === 'function') {
                    return store.selectedCount() > 0;
                }
                const ids = typeof store.selectedIdsSnapshot === 'function'
                    ? store.selectedIdsSnapshot()
                    : store.selectedIds;

                return Array.isArray(ids) && ids.length > 0;
            } catch {
                return false;
            }
        }

        function hasBulkSelection() {
            if (document.querySelector('.fi-ta-record-checkbox:checked, .fi-ta-row input[type="checkbox"]:checked')) {
                return true;
            }

            const named = livewireSelectKeys
                .map((key) => 'input[type="checkbox"][wire\\:model="' + key + '"]:checked, input[type="checkbox"][wire\\:model.live="' + key + '"]:checked')
                .join(', ');
            if (document.querySelector(named)) {
                return true;
            }

            for (const table of document.querySelectorAll('table')) {
                if (! table.querySelector('thead input[type="checkbox"]')) {
                    continue;
                }
                const checked = Array.from(table.querySelectorAll('tbody input[type="checkbox"]')).some(isCheckedCheckbox);
                if (checked) {
                    return true;
                }
            }

            return alpineStoreHasSelection() || livewireHasSelection();
        }

        function isReloadShortcut(event) {
            if (event.defaultPrevented) {
                return false;
            }
            if (event.key === 'F5' || event.code === 'F5') {
                return true;
            }

            return (event.ctrlKey || event.metaKey) && ! event.altKey && (event.key === 'r' || event.key === 'R');
        }

        window.addEventListener('keydown', (event) => {
            if (! isReloadShortcut(event) || ! hasBulkSelection()) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (window.confirm(confirmMessage)) {
                window.location.reload();
            }
        }, true);
    })();
</script>
