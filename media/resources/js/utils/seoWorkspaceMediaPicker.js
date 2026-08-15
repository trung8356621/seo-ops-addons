import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from './articleMediaPickerCache';

export function createSeoWorkspaceMediaPicker(config = {}) {
    return {
        open: false,
        mode: 'ai-chat',
        siteId: Number(config.siteId || 0),
        endpoint: String(config.endpoint || '').trim(),
        wordPressLinked: config.wordPressLinked !== false,
        pickerLoading: false,
        pickerSearching: false,
        pickerSearchQuery: '',
        pickerTab: 'local',
        pickerImages: [],
        pickerPage: 1,
        pickerTotalPages: 1,
        pickerError: null,
        _pickerSearchTimer: null,

        init() {
            this._onOpen = (event) => {
                this.openPicker(event?.detail ?? {});
            };
            this._onSiteChanged = (event) => {
                const siteId = Number(event?.detail?.siteId || 0);
                if (siteId > 0) {
                    this.siteId = siteId;
                }
            };

            window.addEventListener('seo-open-workspace-media-picker', this._onOpen);
            window.addEventListener('seoGlobalSiteChanged', this._onSiteChanged);
            window.addEventListener('domain-context-changed', this._onSiteChanged);
        },

        destroy() {
            window.removeEventListener('seo-open-workspace-media-picker', this._onOpen);
            window.removeEventListener('seoGlobalSiteChanged', this._onSiteChanged);
            window.removeEventListener('domain-context-changed', this._onSiteChanged);
        },

        openPicker(detail = {}) {
            if (this.siteId <= 0) {
                window.alert('Chọn domain trước khi mở thư viện ảnh.');

                return;
            }

            this.mode = String(detail.mode || 'ai-chat');
            this.open = true;
            this.pickerSearchQuery = '';
            this.pickerError = null;
            this.pickerTab = 'local';
            this.pickerPage = 1;
            this.fetchPickerImages({ resetPage: true });
        },

        closePicker() {
            this.open = false;
            this.pickerImages = [];
            this.pickerSearchQuery = '';
            this.pickerError = null;
            this.pickerLoading = false;
            this.pickerSearching = false;
        },

        pickerSearchPlaceholder() {
            if (this.pickerTab === 'local') {
                return 'Tìm slug, alt, tên file (Laravel)…';
            }

            return 'Tìm slug, alt, caption (WP search)…';
        },

        schedulePickerSearch() {
            this.pickerSearching = true;
            clearTimeout(this._pickerSearchTimer);
            this._pickerSearchTimer = setTimeout(() => this.runPickerSearch(), 400);
        },

        async runPickerSearch() {
            await this.fetchPickerImages({ resetPage: true });
        },

        async switchPickerTab(tab) {
            if (this.pickerTab === tab) {
                return;
            }

            this.pickerTab = tab;
            this.pickerSearchQuery = '';
            this.pickerImages = [];
            this.pickerPage = 1;
            this.pickerSearching = false;

            if (this.tryHydratePickerFromCache(tab, 1)) {
                return;
            }

            await this.fetchPickerImages({ resetPage: true });
        },

        async fetchPickerImages({ resetPage = false, skipCache = false } = {}) {
            if (resetPage) {
                this.pickerPage = 1;
            }

            if (
                !skipCache
                && this.pickerSearchQuery.trim() === ''
                && this.tryHydratePickerFromCache(this.pickerTab, this.pickerPage || 1)
            ) {
                return;
            }

            this.pickerLoading = true;
            this.pickerError = null;

            try {
                if (!this.endpoint) {
                    throw new Error('Media picker endpoint is unavailable');
                }

                const url = new URL(this.endpoint, window.location.origin);
                url.searchParams.set('tab', this.pickerTab);
                url.searchParams.set('page', String(this.pickerPage || 1));
                if (this.pickerSearchQuery.trim() !== '') {
                    url.searchParams.set('search', this.pickerSearchQuery.trim());
                }

                const response = await fetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const detail = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(detail?.error || `Media picker failed with status ${response.status}`);
                }

                this.applyPickerPayload(detail);
                this.persistPickerCacheFromFetch(detail);
            } catch (error) {
                this.pickerImages = [];
                this.pickerError = error?.message || 'Không tải được thư viện media.';
            } finally {
                this.pickerLoading = false;
                this.pickerSearching = false;
            }
        },

        applyPickerPayload(detail) {
            if (!detail || typeof detail !== 'object') {
                return;
            }

            this.pickerTab = detail.tab ?? this.pickerTab;
            this.pickerPage = Number(detail.page || 1);
            this.pickerTotalPages = Number(detail.totalPages || 1);
            this.pickerError = detail.error ? String(detail.error) : null;
            this.pickerImages = Array.isArray(detail.images) ? detail.images : [];
        },

        tryHydratePickerFromCache(tab, page) {
            if (!isArticleMediaPickerCacheableTab(tab) || this.pickerSearchQuery.trim() !== '') {
                return false;
            }

            if (this.siteId <= 0) {
                return false;
            }

            const cached = readArticleMediaPickerCache(this.siteId, tab, page);
            if (!cached) {
                return false;
            }

            this.applyPickerPayload(cached);
            this.pickerLoading = false;
            this.pickerSearching = false;

            return true;
        },

        persistPickerCacheFromFetch(detail) {
            if (this.pickerSearchQuery.trim() !== '') {
                return;
            }

            const tab = detail?.tab ?? this.pickerTab;
            if (!isArticleMediaPickerCacheableTab(tab) || this.siteId <= 0) {
                return;
            }

            writeArticleMediaPickerCache(this.siteId, tab, Number(detail?.page || this.pickerPage || 1), detail);
        },

        async pickerPrevPage() {
            if (this.pickerPage <= 1) {
                return;
            }

            const prevPage = this.pickerPage - 1;
            if (this.pickerSearchQuery.trim() === '' && this.tryHydratePickerFromCache(this.pickerTab, prevPage)) {
                return;
            }

            this.pickerPage = prevPage;
            await this.fetchPickerImages();
        },

        async pickerNextPage() {
            if (this.pickerPage >= this.pickerTotalPages) {
                return;
            }

            const nextPage = this.pickerPage + 1;
            if (this.pickerSearchQuery.trim() === '' && this.tryHydratePickerFromCache(this.pickerTab, nextPage)) {
                return;
            }

            this.pickerPage = nextPage;
            await this.fetchPickerImages();
        },

        async reloadPickerImages() {
            await this.fetchPickerImages({ skipCache: true });
        },

        selectPickerImage(image) {
            if (!image || !String(image.url || '').trim()) {
                return;
            }

            const mediaType = String(image.media_type || 'image').toLowerCase() === 'video'
                ? 'video'
                : 'image';
            const payload = {
                url: String(image.url || '').trim(),
                alt: String(image.alt || '').trim(),
                slug: String(image.slug || '').trim(),
                wpAttachmentId: Number(image.wp_attachment_id || 0),
                seoMediaId: Number(image.seo_media_id || 0),
                mediaType,
            };

            if (this.mode === 'ai-chat') {
                if (mediaType !== 'image') {
                    return;
                }

                window.dispatchEvent(new CustomEvent('seo-global-ai-chat-image-selected', {
                    detail: payload,
                }));
                this.closePicker();

                return;
            }

            window.dispatchEvent(new CustomEvent('seo-workspace-media-selected', {
                detail: payload,
            }));
            this.closePicker();
        },
    };
}
