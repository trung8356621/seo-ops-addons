@php
    /**
     * Tab Publish — bộ chọn danh mục kiểu WordPress.
     * - Danh sách checkbox, hỗ trợ chọn nhiều.
     * - Fetch từ WordPress → localStorage (seo_wp_category_ids_{articleId}), không ghi DB tự động.
     * - post_type = post  → taxonomy `category`
     * - post_type = product → taxonomy `product_category` (product_cat)
     */
    $resolvedPostType = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::normalizePostType(
        \Omnichannel\Addons\Content\Support\ArticlePostTypeResolver::resolve($this->record),
    );
    $publishCategoriesInitial = [
        'articleId' => (int) $this->record->getKey(),
        'selectedIds' => $this->articleCategoryIds,
        'postType' => $resolvedPostType,
        'recordType' => (string) ($this->record->type ?? ''),
        'currentTermWpId' => (int) ($this->record->wordpressLink?->wp_post_id ?? 0),
        'isTaxonomy' => $this->isTaxonomyEntityForPublish(),
        'categoryTaxonomy' => $this->resolvePublishCategoryTaxonomy($resolvedPostType),
        // Phase 2: empty on SSR — Alpine loads via $wire.getPublishCategoryOptions() (no publishing taxonomy query on editor shell).
        'options' => ['category' => [], 'product_category' => []],
    ];
@endphp

@once
    <script>
        window.seoPublishCategoriesData = function seoPublishCategoriesData(initial) {
            return {
                articleId: Number(initial.articleId ?? 0),
                selectedIds: Array.isArray(initial.selectedIds) ? initial.selectedIds.map(Number) : [],
                postType: initial.postType ?? 'article',
                recordType: initial.recordType ?? '',
                currentTermWpId: Number(initial.currentTermWpId ?? 0),
                isTaxonomy: initial.isTaxonomy === true,
                categoryTaxonomy: initial.categoryTaxonomy ?? 'category',
                optionsByTaxonomy: initial.options ?? { category: [], product_category: [] },
                catalogStatus: initial.status ?? {},
                searchQuery: '',
                highlightError: false,
                wpFetchedAt: '',
                wpFetchedCount: 0,
                _saveTimer: null,
                _optionsLoaded: false,
                _optionsLoading: false,
                _optionsError: false,

                init() {
                    window.__seoPushPublishCategoriesToWire = () => this.pushCategoriesToWire();
                    window.__seoPublishCategoriesSnapshot = () => {
                        if (typeof this.resolveEffectiveCategoryIds === 'function') {
                            return this.resolveEffectiveCategoryIds();
                        }

                        return Array.isArray(this.selectedIds) ? this.selectedIds.map(Number) : [];
                    };
                    window.__seoEnsureBeforePublishAction = () => {
                        void this.pushCategoriesToWire();

                        return true;
                    };
                    window.__seoEnsureCategoriesBeforeSync = () => this.ensureCategoriesBeforeSync();
                    window.__seoResetPublishTabPrimed = () => {};
                    this.syncCategoryTaxonomyFromPostType();
                    this.restoreWpCategoriesFromStorage();
                    if (this.requiresCategories() && this.resolveEffectiveCategoryIds().length === 0) {
                        this.highlightError = true;
                    }
                    this.emitPublishingCategoriesChanged();
                    window.addEventListener('seo-wp-categories-fetched', (event) => this.onWpCategoriesFetched(event));
                    window.addEventListener('seo-assistant-open-publishing', () => {
                        void this.ensurePublishCategoryOptions();
                    });
                    window.addEventListener('seo-sidebar-open-publish-tab', () => {
                        void this.ensurePublishCategoryOptions();
                    });
                    window.addEventListener('seo-publish-tab-request-sync', () => {
                        void this.ensurePublishCategoryOptions();
                    });
                },

                async ensurePublishCategoryOptions() {
                    if (this._optionsLoaded || this._optionsLoading) {
                        return;
                    }
                    this._optionsLoading = true;
                    this._optionsError = false;
                    try {
                        const opts = await this.$wire.getPublishCategoryOptions();
                        if (opts && typeof opts === 'object') {
                            this.catalogStatus = opts.status ?? {};
                            this.optionsByTaxonomy = {
                                category: Array.isArray(opts.category) ? opts.category : [],
                                product_category: Array.isArray(opts.product_category) ? opts.product_category : [],
                            };
                            this._optionsLoaded = true;
                            const tax = this.categoryTaxonomy || 'category';
                            const status = this.catalogStatus?.[tax] ?? this.catalogStatus?.category ?? null;
                            if (status && status.ok === false) {
                                this._optionsError = true;
                            }
                        } else {
                            this._optionsError = true;
                        }
                    } catch (e) {
                        this._optionsLoaded = false;
                        this._optionsError = true;
                        this.catalogStatus = {
                            category: { ok: false, code: 'error', message: 'Không tải được taxonomy catalog.' },
                            product_category: { ok: false, code: 'error', message: 'Không tải được taxonomy catalog.' },
                        };
                    } finally {
                        this._optionsLoading = false;
                    }
                },

                retryPublishCategoryOptions() {
                    this._optionsLoaded = false;
                    this._optionsError = false;
                    void this.ensurePublishCategoryOptions();
                },

                syncCategoryTaxonomyFromPostType() {
                    if (this.isTaxonomyEntity()) {
                        this.categoryTaxonomy = this.postType === 'product_category'
                            || this.recordType === 'product_category'
                            ? 'product_category'
                            : 'category';

                        return;
                    }

                    const resolved = typeof window.__seoResolvePublishCategoryRequirement === 'function'
                        ? window.__seoResolvePublishCategoryRequirement(this.postType, this.recordType)
                        : null;
                    if (resolved?.taxonomy) {
                        this.categoryTaxonomy = resolved.taxonomy;

                        return;
                    }

                    this.categoryTaxonomy = this.postType === 'product' ? 'product_category' : 'category';
                },

                isTaxonomyEntity() {
                    return this.isTaxonomy
                        || this.postType === 'category'
                        || this.postType === 'product_category'
                        || this.recordType === 'category'
                        || this.recordType === 'product_category';
                },

                requiresParentTerm() {
                    return this.isTaxonomyEntity();
                },

                requiresCategories() {
                    if (this.isTaxonomyEntity()) {
                        return false;
                    }

                    const resolved = typeof window.__seoResolvePublishCategoryRequirement === 'function'
                        ? window.__seoResolvePublishCategoryRequirement(this.postType, this.recordType)
                        : null;
                    if (resolved && typeof resolved === 'object') {
                        return resolved.required === true;
                    }

                    // Fallback when resolver JS not loaded yet.
                    if (this.postType === 'page') {
                        return false;
                    }

                    return this.postType === 'article'
                        || this.postType === 'post'
                        || this.postType === 'product';
                },

                emitPublishingCategoriesChanged() {
                    const selectedIds = typeof this.resolveEffectiveCategoryIds === 'function'
                        ? this.resolveEffectiveCategoryIds()
                        : (Array.isArray(this.selectedIds) ? this.selectedIds.map(Number) : []);
                    window.dispatchEvent(new CustomEvent('seo-publishing-categories-changed', {
                        detail: {
                            articleId: this.articleId,
                            postType: this.postType,
                            recordType: this.recordType,
                            selectedIds,
                            categoryTaxonomy: this.categoryTaxonomy,
                            required: this.requiresCategories(),
                        },
                    }));
                },

                taxonomy() {
                    return this.categoryTaxonomy;
                },

                taxonomyLabel() {
                    if (this.isTaxonomyEntity()) {
                        return this.categoryTaxonomy === 'product_category'
                            ? 'Danh mục cha (product_cat)'
                            : 'Danh mục cha (category)';
                    }

                    return this.taxonomy() === 'product_category'
                        ? 'Danh mục sản phẩm (product_cat)'
                        : 'Chuyên mục (category)';
                },

                catalogState() {
                    const status = this.catalogStatus?.[this.taxonomy()] ?? null;
                    if (! status || typeof status !== 'object') {
                        return { ok: true, code: 'ok', message: '' };
                    }

                    return status;
                },

                catalogUnavailable() {
                    return this.catalogState().ok === false;
                },

                emptyCatalogMessage() {
                    if (this.catalogUnavailable()) {
                        const message = String(this.catalogState().message || '').trim();

                        return message !== ''
                            ? message
                            : 'Không lấy được taxonomy catalog từ WordPress. Không dùng danh mục local làm nguồn.';
                    }

                    return 'WordPress chưa có term nào cho taxonomy này.';
                },

                allOptions() {
                    const options = this.optionsByTaxonomy[this.taxonomy()] ?? [];
                    if (! this.isTaxonomyEntity() || this.currentTermWpId <= 0) {
                        return options;
                    }

                    return options.filter((opt) => Number(opt.id) !== Number(this.currentTermWpId));
                },

                filteredOptions() {
                    const q = this.searchQuery.trim().toLowerCase();
                    if (q === '') {
                        return this.allOptions();
                    }

                    return this.allOptions().filter((opt) => String(opt.label).toLowerCase().includes(q));
                },

                isChecked(id) {
                    return this.selectedIds.includes(Number(id));
                },

                toggle(id) {
                    if (this.isTaxonomyEntity()) {
                        this.selectParentTerm(id);

                        return;
                    }

                    id = Number(id);
                    this.selectedIds = this.isChecked(id)
                        ? this.selectedIds.filter((v) => v !== id)
                        : [...this.selectedIds, id];

                    if (this.selectedIds.length > 0) {
                        this.highlightError = false;
                    }

                    this.queueSave();
                    this.emitPublishingCategoriesChanged();
                },

                selectParentTerm(id) {
                    id = Number(id);
                    this.selectedIds = this.selectedIds[0] === id ? [] : [id];
                    this.highlightError = false;
                    this.queueSave();
                    this.emitPublishingCategoriesChanged();
                },

                isParentSelected(id) {
                    return this.selectedIds[0] === Number(id);
                },

                queueSave() {
                    clearTimeout(this._saveTimer);
                    this._saveTimer = setTimeout(() => {
                        this.$wire.applyArticleCategoriesFromClient(this.selectedIds);
                    }, 400);
                },

                onPostTypeChanged(event) {
                    const next = event.detail?.postType;
                    if (typeof next !== 'string' || next === '') {
                        return;
                    }

                    this.postType = next;
                    this.syncCategoryTaxonomyFromPostType();
                    this.selectedIds = this.filterValidCategoryIds(this.selectedIds);
                    this.queueSave();
                    this.emitPublishingCategoriesChanged();
                },

                readWireCategoryIds() {
                    try {
                        const raw = typeof this.$wire?.get === 'function'
                            ? this.$wire.get('articleCategoryIds')
                            : this.$wire?.articleCategoryIds;

                        if (Array.isArray(raw)) {
                            return raw.map(Number).filter((id) => id > 0);
                        }
                    } catch (error) {
                        console.warn('Không đọc được articleCategoryIds từ Livewire', error);
                    }

                    return [];
                },

                normalizeRawCategoryIds(categoryIds) {
                    return (Array.isArray(categoryIds) ? categoryIds : [])
                        .map(Number)
                        .filter((id) => id > 0);
                },

                syncPostTypeFromWire() {
                    const wirePostType = String(this.$wire?.articlePostType ?? '').trim();
                    if (wirePostType === '' || wirePostType === this.postType) {
                        return;
                    }

                    this.postType = wirePostType;
                    this.syncCategoryTaxonomyFromPostType();
                },

                filterValidCategoryIds(categoryIds) {
                    const optionIds = new Set(this.allOptions().map((opt) => Number(opt.id)));
                    const raw = this.normalizeRawCategoryIds(categoryIds);
                    if (optionIds.size === 0) {
                        return this.catalogUnavailable() ? raw : [];
                    }

                    return raw.filter((id) => optionIds.has(id));
                },

                applyWpCategories(categoryIds, fetchedAt = '', persistSelection = true) {
                    const raw = this.normalizeRawCategoryIds(categoryIds);
                    if (raw.length === 0) {
                        return;
                    }

                    const valid = this.filterValidCategoryIds(raw);
                    const idsToUse = valid.length > 0 ? valid : raw;

                    if (window.__seoWpCategoryStorage?.save) {
                        window.__seoWpCategoryStorage.save(this.articleId, idsToUse, fetchedAt);
                    }

                    this.wpFetchedAt = fetchedAt || new Date().toISOString();
                    this.wpFetchedCount = idsToUse.length;

                    if (persistSelection) {
                        this.selectedIds = idsToUse;
                        this.highlightError = false;
                    }
                },

                restoreWpCategoriesFromStorage() {
                    if (!window.__seoWpCategoryStorage?.load || this.articleId <= 0) {
                        return;
                    }

                    const stored = window.__seoWpCategoryStorage.load(this.articleId);
                    if (!stored?.categoryIds?.length) {
                        return;
                    }

                    this.applyWpCategories(stored.categoryIds, stored.fetchedAt ?? '', this.selectedIds.length === 0);
                },

                onWpCategoriesFetched(event) {
                    const detail = event?.detail ?? {};
                    if (Number(detail.articleId) !== Number(this.articleId)) {
                        return;
                    }

                    this.applyWpCategories(detail.categoryIds ?? [], detail.fetchedAt ?? '');
                },

                wpSyncHint() {
                    if (this.wpFetchedCount < 1) {
                        return '';
                    }

                    const when = this.wpFetchedAt ? new Date(this.wpFetchedAt).toLocaleString() : '';

                    return when !== ''
                        ? `Đã fetch ${this.wpFetchedCount} danh mục từ WordPress (localStorage, ${when}).`
                        : `Đã fetch ${this.wpFetchedCount} danh mục từ WordPress (localStorage).`;
                },

                resolveRawCategoryIds() {
                    const selected = this.normalizeRawCategoryIds(this.selectedIds);
                    if (selected.length > 0) {
                        return selected;
                    }

                    return this.readWireCategoryIds();
                },

                resolveEffectiveCategoryIds() {
                    const raw = this.resolveRawCategoryIds();
                    if (raw.length === 0) {
                        return [];
                    }

                    const filtered = this.filterValidCategoryIds(raw);

                    return filtered.length > 0 ? filtered : raw;
                },

                async pushCategoriesToWire() {
                    clearTimeout(this._saveTimer);
                    this.syncPostTypeFromWire();

                    if (this.isTaxonomyEntity()) {
                        const parentIds = this.selectedIds.length > 0 ? [Number(this.selectedIds[0])] : [];
                        await this.$wire.applyArticleCategoriesFromClient(parentIds);

                        return;
                    }

                    const categoryIds = this.resolveEffectiveCategoryIds();
                    if (categoryIds.length > 0) {
                        await this.$wire.applyArticleCategoriesFromClient(categoryIds);
                    }
                },

                async ensureCategoriesBeforeSync() {
                    await this.ensurePublishCategoryOptions();
                    this.syncPostTypeFromWire();
                    await this.pushCategoriesToWire();

                    if (this.isTaxonomyEntity()) {
                        return true;
                    }

                    if (! this.requiresCategories()) {
                        return true;
                    }

                    const categoryIds = this.resolveEffectiveCategoryIds();
                    if (categoryIds.length > 0) {
                        if (this.selectedIds.length === 0) {
                            this.selectedIds = categoryIds;
                        }

                        this.highlightError = false;

                        return true;
                    }

                    this.highlightError = true;
                    window.dispatchEvent(new CustomEvent('seo-sidebar-open-publish-tab'));
                    window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Chưa chọn danh mục',
                            body: this.taxonomy() === 'product_category'
                                ? 'Chọn ít nhất 1 danh mục product_cat trước khi đăng lên WordPress.'
                                : 'Chọn ít nhất 1 danh mục category trước khi đăng lên WordPress.',
                            status: 'warning',
                        },
                    }));

                    return false;
                },

                ensureBeforePublishAction() {
                    void this.pushCategoriesToWire();

                    return true;
                },
            };
        };
    </script>
@endonce

<div
    class="wp-postbox"
    x-data="seoPublishCategoriesData(@js($publishCategoriesInitial))"
    x-on:seo-publish-post-type-changed.window="onPostTypeChanged($event)"
>
    <div class="wp-postbox-header">
        <h2 x-text="taxonomyLabel()"></h2>
    </div>
    <div class="wp-postbox-inside">
        <template x-if="requiresParentTerm()">
            <div class="space-y-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Chọn <strong>danh mục cha</strong> cùng taxonomy trên WordPress. Để trống = danh mục gốc (<code>parent_id = 0</code>).
                </p>
                <input
                    type="search"
                    x-model="searchQuery"
                    placeholder="Tìm danh mục cha..."
                    class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-800 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    :disabled="_optionsLoading || _optionsError"
                />

                <div class="max-h-52 space-y-0.5 overflow-y-auto rounded border border-gray-300 bg-white p-2 dark:border-gray-600 dark:bg-gray-900">
                    <template x-if="_optionsLoading">
                        <div class="flex items-center gap-2 py-2 text-xs text-gray-500 dark:text-gray-400">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <span>Đang tải danh mục...</span>
                        </div>
                    </template>
                    <template x-if="!_optionsLoading && _optionsError">
                        <div class="space-y-2 py-1">
                            <p class="text-xs text-rose-600 dark:text-rose-400">Không tải được danh mục</p>
                            <button
                                type="button"
                                class="text-xs font-semibold text-sky-700 hover:underline dark:text-sky-300"
                                @click="retryPublishCategoryOptions()"
                            >Thử lại</button>
                        </div>
                    </template>
                    <template x-if="!_optionsLoading && !_optionsError && allOptions().length === 0">
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="emptyCatalogMessage()"></p>
                    </template>

                    <template x-if="!_optionsLoading && !_optionsError">
                        <div>
                            <template x-for="option in filteredOptions()" :key="'parent-' + option.id">
                                <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-xs text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800">
                                    <input
                                        type="radio"
                                        name="taxonomy_parent_term"
                                        class="h-3.5 w-3.5 border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-900"
                                        x-bind:checked="isParentSelected(option.id)"
                                        x-on:change="selectParentTerm(option.id)"
                                    />
                                    <span x-text="option.label"></span>
                                </label>
                            </template>
                        </div>
                    </template>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <span x-show="selectedIds.length === 0" x-cloak>Danh mục gốc — không có parent.</span>
                    <span x-show="selectedIds.length > 0" x-cloak x-text="`Parent term ID: ${selectedIds[0]}`"></span>
                </p>
            </div>
        </template>

        <template x-if="!requiresParentTerm() && !requiresCategories()">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Loại bài hiện tại không cần chọn danh mục.
            </p>
        </template>

        <template x-if="requiresCategories()">
            <div class="space-y-2">
                <input
                    type="search"
                    x-model="searchQuery"
                    placeholder="Tìm danh mục..."
                    class="w-full rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-800 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    :disabled="_optionsLoading || _optionsError"
                />

                <div
                    class="max-h-52 space-y-0.5 overflow-y-auto rounded border bg-white p-2 transition-colors dark:bg-gray-900"
                    x-bind:class="highlightError
                        ? 'border-red-500 ring-2 ring-red-300 dark:ring-red-800'
                        : 'border-gray-300 dark:border-gray-600'"
                >
                    <template x-if="_optionsLoading">
                        <div class="flex items-center gap-2 py-2 text-xs text-gray-500 dark:text-gray-400">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                            </svg>
                            <span>Đang tải danh mục...</span>
                        </div>
                    </template>

                    <template x-if="!_optionsLoading && _optionsError">
                        <div class="space-y-2 py-1">
                            <p class="text-xs text-rose-600 dark:text-rose-400">Không tải được danh mục</p>
                            <button
                                type="button"
                                class="text-xs font-semibold text-sky-700 hover:underline dark:text-sky-300"
                                @click="retryPublishCategoryOptions()"
                            >Thử lại</button>
                        </div>
                    </template>

                    <template x-if="!_optionsLoading && !_optionsError && allOptions().length === 0">
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="emptyCatalogMessage()"></p>
                    </template>

                    <template x-if="!_optionsLoading && !_optionsError">
                        <div>
                            <template x-for="option in filteredOptions()" :key="option.id">
                                <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-xs text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800">
                                    <input
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-900"
                                        x-bind:checked="isChecked(option.id)"
                                        x-on:change="toggle(option.id)"
                                    />
                                    <span x-text="option.label"></span>
                                </label>
                            </template>

                            <template x-if="allOptions().length > 0 && filteredOptions().length === 0">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Không có danh mục khớp từ khóa.</p>
                            </template>
                        </div>
                    </template>
                </div>

                <p class="text-xs text-sky-700 dark:text-sky-300" x-show="wpSyncHint() !== ''" x-text="wpSyncHint()" x-cloak></p>

                <p class="text-xs" x-bind:class="highlightError ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
                    <span x-show="highlightError" x-cloak>Chưa chọn danh mục.</span>
                    <span x-show="!highlightError" x-text="`Đã chọn ${selectedIds.length} danh mục`"></span>
                </p>
            </div>
        </template>
    </div>
</div>
