import { uploadLocalMediaFiles } from './utils/seoLocalMediaUpload';

const MEDIA_LIBRARY_REMOVE_MS = 280;

function registerSeoMediaLibraryActions() {
    Alpine.data('seoMediaLibraryActions', () => ({
        selectedKeys: [],
        selectedLookup: {},
        selectedCount: 0,
        selectionAnchorKey: null,
        selectionStorageScope: '',
        localMediaUploading: false,
        init() {
            this.selectionStorageScope = this.resolveSelectionStorageScope();
            this.restoreSelectionFromStorage();

            this.$nextTick(() => {
                this.pruneSelectionToVisibleCards();
            });

            this.handleDomRefreshed = () => {
                const nextScope = this.resolveSelectionStorageScope();
                if (nextScope !== this.selectionStorageScope) {
                    this.selectionStorageScope = nextScope;
                    this.restoreSelectionFromStorage();
                }
                this.pruneSelectionToVisibleCards();
            };

            window.addEventListener('seo-media-library-dom-refreshed', this.handleDomRefreshed);
        },
        destroy() {
            if (this.handleDomRefreshed) {
                window.removeEventListener('seo-media-library-dom-refreshed', this.handleDomRefreshed);
            }
        },
        resolveSelectionStorageScope() {
            const scope = this.$root?.dataset?.selectionScope ?? '';
            return scope.trim() !== '' ? scope : 'default';
        },
        storageKey() {
            return `seo-media-library:selected:${this.selectionStorageScope}`;
        },
        visibleCardKeys() {
            return Array.from(this.$root.querySelectorAll('.seo-media-library-card[data-select-key]'))
                .map((card) => card.dataset.selectKey)
                .filter((key) => typeof key === 'string' && key.length > 0);
        },
        escapeSelectorValue(value) {
            if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
                return CSS.escape(value);
            }
            return String(value).replace(/["\\]/g, '\\$&');
        },
        findCardElement(key) {
            if (typeof key !== 'string' || key.length === 0) {
                return null;
            }

            return this.$root.querySelector(
                `.seo-media-library-card[data-select-key="${this.escapeSelectorValue(key)}"]`,
            );
        },
        markCardsRemoving(keys) {
            (Array.isArray(keys) ? keys : []).forEach((key) => {
                this.findCardElement(key)?.classList.add('is-removing');
            });
        },
        unmarkCardsRemoving(keys) {
            (Array.isArray(keys) ? keys : []).forEach((key) => {
                this.findCardElement(key)?.classList.remove('is-removing');
            });
        },
        wait(ms) {
            return new Promise((resolve) => {
                window.setTimeout(resolve, ms);
            });
        },
        removeKeysFromSelection(keys) {
            const removeSet = new Set(Array.isArray(keys) ? keys : []);
            if (removeSet.size === 0) {
                return;
            }

            this.setSelectedKeys(this.selectedKeys.filter((key) => !removeSet.has(key)));

            if (this.selectionAnchorKey && removeSet.has(this.selectionAnchorKey)) {
                this.selectionAnchorKey = null;
            }
        },
        async animateDeleteKeys(keys, deleteAction) {
            const normalizedKeys = Array.isArray(keys)
                ? keys.filter((key) => typeof key === 'string' && key.length > 0)
                : [];

            if (normalizedKeys.length === 0) {
                return;
            }

            this.markCardsRemoving(normalizedKeys);
            await this.wait(MEDIA_LIBRARY_REMOVE_MS);

            let result = null;
            try {
                result = await deleteAction();
            } catch {
                this.unmarkCardsRemoving(normalizedKeys);
                return;
            }

            if (!result?.success) {
                this.unmarkCardsRemoving(normalizedKeys);
                return;
            }

            const removedKeys = Array.isArray(result.removed_keys) ? result.removed_keys : [];
            const keptKeys = normalizedKeys.filter((key) => !removedKeys.includes(key));

            if (keptKeys.length > 0) {
                this.unmarkCardsRemoving(keptKeys);
            }

            if (removedKeys.length > 0) {
                this.removeKeysFromSelection(removedKeys);
            }
        },
        async deleteCard(key, $wire) {
            if (!window.confirm('Delete this image? This action cannot be undone.')) {
                return;
            }

            await this.animateDeleteKeys([key], () => $wire.deleteLibraryImage(key));
        },
        rebuildSelectedLookup() {
            const lookup = {};
            this.selectedKeys.forEach((key) => {
                lookup[key] = true;
            });
            this.selectedLookup = lookup;
            this.selectedCount = this.selectedKeys.length;
        },
        setSelectedKeys(keys) {
            const seen = new Set();
            const normalized = [];
            (Array.isArray(keys) ? keys : []).forEach((key) => {
                if (typeof key !== 'string' || key.length === 0 || seen.has(key)) {
                    return;
                }
                seen.add(key);
                normalized.push(key);
            });

            this.selectedKeys = normalized;
            this.rebuildSelectedLookup();
            this.persistSelectionToStorage();
        },
        isSelected(key) {
            return !!this.selectedLookup[key];
        },
        persistSelectionToStorage() {
            try {
                localStorage.setItem(this.storageKey(), JSON.stringify(this.selectedKeys));
            } catch {
                // Ignore storage quota or privacy mode errors.
            }
        },
        restoreSelectionFromStorage() {
            let storedKeys = [];
            try {
                const raw = localStorage.getItem(this.storageKey());
                if (raw) {
                    const decoded = JSON.parse(raw);
                    if (Array.isArray(decoded)) {
                        storedKeys = decoded;
                    }
                }
            } catch {
                storedKeys = [];
            }

            this.setSelectedKeys(storedKeys);
        },
        pruneSelectionToVisibleCards() {
            const visibleSet = new Set(this.visibleCardKeys());
            const kept = this.selectedKeys.filter((key) => visibleSet.has(key));
            if (kept.length !== this.selectedKeys.length) {
                this.setSelectedKeys(kept);
            }

            if (this.selectionAnchorKey && !visibleSet.has(this.selectionAnchorKey)) {
                this.selectionAnchorKey = null;
            }
        },
        toggleCardSelection(key, shiftKey = false) {
            if (typeof key !== 'string' || key.length === 0) {
                return;
            }

            if (shiftKey && this.selectionAnchorKey) {
                const orderedKeys = this.visibleCardKeys();
                const fromIndex = orderedKeys.indexOf(this.selectionAnchorKey);
                const toIndex = orderedKeys.indexOf(key);
                if (fromIndex !== -1 && toIndex !== -1) {
                    const start = Math.min(fromIndex, toIndex);
                    const end = Math.max(fromIndex, toIndex);
                    this.setSelectedKeys(orderedKeys.slice(start, end + 1));
                    return;
                }
            }

            if (this.isSelected(key)) {
                this.setSelectedKeys(this.selectedKeys.filter((item) => item !== key));
            } else {
                this.setSelectedKeys([...this.selectedKeys, key]);
            }
            this.selectionAnchorKey = key;
        },
        clearSelection() {
            this.selectionAnchorKey = null;
            this.setSelectedKeys([]);
        },
        runResizeSelected($wire) {
            if (!this.selectedCount) {
                return;
            }
            $wire.resizeSelectedImagesFromClient(this.selectedKeys, this.selectionAnchorKey);
            this.clearSelection();
        },
        async runDeleteSelected($wire) {
            if (!this.selectedCount) {
                return;
            }

            const keys = [...this.selectedKeys];
            await this.animateDeleteKeys(
                keys,
                () => $wire.deleteSelectedImagesFromClient(keys, this.selectionAnchorKey),
            );
            this.clearSelection();
        },
        absoluteUrl(url) {
            if (!url) {
                return '';
            }
            if (url.startsWith('http://') || url.startsWith('https://')) {
                return url;
            }
            if (url.startsWith('/')) {
                return `${window.location.origin}${url}`;
            }

            return url;
        },
        async downloadUrl(url, filename) {
            const abs = this.absoluteUrl(url);
            if (!abs) {
                return;
            }

            try {
                const response = await fetch(abs, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('fetch failed');
                }

                const blob = await response.blob();
                const objectUrl = URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                anchor.href = objectUrl;
                anchor.download = filename || 'image';
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
                URL.revokeObjectURL(objectUrl);
            } catch {
                const anchor = document.createElement('a');
                anchor.href = abs;
                anchor.target = '_blank';
                anchor.rel = 'noopener';
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
            }
        },
        downloadCard(card) {
            if (!card) {
                return;
            }

            const url = card.dataset.imageUrl;
            const name = card.dataset.downloadName || card.dataset.imageSlug || 'image';
            this.downloadUrl(url, name);
        },
        downloadSelected() {
            const cards = this.selectedKeys
                .map((key) => this.$root.querySelector(`.seo-media-library-card[data-select-key="${this.escapeSelectorValue(key)}"]`))
                .filter(Boolean);

            if (!cards.length) {
                return;
            }

            cards.forEach((card, index) => {
                setTimeout(() => {
                    this.downloadCard(card);
                }, index * 350);
            });
        },
        openLocalMediaUploadPicker() {
            if (this.localMediaUploading) {
                return;
            }

            this.$refs.localMediaUploadInput?.click();
        },
        async onLocalMediaUploadChange(event) {
            const input = event?.target;
            const files = input?.files;

            if (!files?.length || this.localMediaUploading) {
                return;
            }

            const siteId = Number(this.$wire?.siteId ?? 0);
            if (!siteId) {
                if (typeof this.$wire?.notifyLocalMediaUpload === 'function') {
                    await this.$wire.notifyLocalMediaUpload('danger', 'Chưa chọn domain', 'Hãy chọn domain trước khi upload ảnh.');
                }
                if (input) {
                    input.value = '';
                }

                return;
            }

            this.localMediaUploading = true;

            try {
                const uploaded = await uploadLocalMediaFiles(files, { siteId, source: 'library' });
                const count = uploaded.length;

                if (typeof this.$wire?.refreshAfterLocalUpload === 'function') {
                    await this.$wire.refreshAfterLocalUpload(count);
                } else if (typeof this.$wire?.loadImages === 'function') {
                    await this.$wire.loadImages();
                } else if (typeof Livewire !== 'undefined') {
                    Livewire.dispatch('seo-media-library-refresh');
                }

                window.dispatchEvent(new CustomEvent('seo-media-library-dom-refreshed'));
            } catch (error) {
                const message = error?.message ?? 'Không thể upload ảnh.';
                if (typeof this.$wire?.notifyLocalMediaUpload === 'function') {
                    await this.$wire.notifyLocalMediaUpload(
                        'danger',
                        'Upload thất bại',
                        message,
                    );
                }
            } finally {
                this.localMediaUploading = false;
                if (input) {
                    input.value = '';
                }
            }
        },
    }));
}

if (window.Alpine) {
    registerSeoMediaLibraryActions();
} else {
    document.addEventListener('alpine:init', registerSeoMediaLibraryActions);
}

document.addEventListener('livewire:navigated', () => {
    window.dispatchEvent(new CustomEvent('seo-media-library-dom-refreshed'));
});

if (typeof Livewire !== 'undefined') {
    Livewire.hook('morph.updated', () => {
        window.requestAnimationFrame(() => {
            window.dispatchEvent(new CustomEvent('seo-media-library-dom-refreshed'));
        });
    });
}

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) {
        return;
    }

    const data = event.data;
    if (data && data.type === 'seo-image-splitter-saved') {
        if (typeof Livewire !== 'undefined') {
            Livewire.dispatch('seo-media-library-refresh');
        }

        return;
    }

    if (!data || data.type !== 'seo-magic-eraser-saved') {
        return;
    }

    if (typeof Livewire !== 'undefined') {
        Livewire.dispatch('seo-magic-eraser-saved', {
            url: data.url,
            imageId: data.imageId ?? null,
            pendingWpSync: !!data.pendingWpSync,
        });
        Livewire.dispatch('seo-media-library-refresh');
    }
});
