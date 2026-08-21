@php
    $publishBoxLabels = [
        'postType' => [
            'article' => __('seo-content-ai::filament.article_list.post_type_article'),
            'product' => __('seo-content-ai::filament.article_list.post_type_product'),
            'category' => __('seo-content-ai::filament.article_list.post_type_category'),
            'product_category' => __('seo-content-ai::filament.article_list.post_type_product_category'),
        ],
        'status' => [
            'draft' => 'Draft',
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
        ],
        'visibility' => [
            'public' => 'Public',
            'private' => 'Private',
        ],
    ];
    $publishBoxInitial = [
        'postType' => \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::normalizePostType(
            \Omnichannel\Addons\Content\Support\ArticlePostTypeResolver::resolve($this->record),
        ),
        'status' => $articleStatus,
        'visibility' => $visibility,
        'publishDay' => $publishDay,
        'publishMonth' => $publishMonth,
        'publishYear' => $publishYear,
        'publishHour' => $publishHour,
        'publishMinute' => $publishMinute,
        'publishWhenLabel' => $this->getPublishWhenLabel(),
        'showPublishScheduleRow' => $this->shouldShowPublishScheduleRow(),
        'publishedAtSidebarLabel' => $this->getPublishedAtSidebarLabel(),
    ];
    $articleRevisionCount = app(\Omnichannel\Addons\Content\Services\SeoArticleRevisionService::class)
        ->countForArticle((int) $record->getKey());
    $publishBoxInitial['revisionCount'] = $articleRevisionCount;

    $record->loadMissing('user');
    $articleAuthorName = $record->user_id === null
        ? __('seo-content-ai::filament.article_list.system')
        : trim((string) ($record->user?->display_name ?? $record->user?->email ?? __('seo-content-ai::filament.article_list.system')));
    $articleAuthorIsCurrentUser = $record->user_id !== null
        && auth()->id() !== null
        && (int) $record->user_id === (int) auth()->id();
    $currentUserLabel = trim((string) (auth()->user()?->display_name ?? auth()->user()?->email ?? ''));
@endphp

@once
    <script>
        window.seoPublishBoxData = function seoPublishBoxData(initial, labels) {
            const minutePool = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

            return {
                postType: initial.postType ?? 'article',
                status: initial.status ?? 'draft',
                visibility: initial.visibility ?? 'public',
                publishDay: initial.publishDay ?? '',
                publishMonth: initial.publishMonth ?? '',
                publishYear: initial.publishYear ?? '',
                publishHour: initial.publishHour ?? '',
                publishMinute: initial.publishMinute ?? '',
                publishWhenLabel: initial.publishWhenLabel ?? 'Not scheduled',
                showPublishScheduleRow: initial.showPublishScheduleRow ?? false,
                publishedAtSidebarLabel: initial.publishedAtSidebarLabel ?? null,
                revisionCount: Number(initial.revisionCount ?? 0),
                savedAtLabel: '',
                saveInFlight: false,
                publishIso: '',
                labels,
                editingPostType: false,
                editingStatus: false,
                editingVisibility: false,
                editingPublishAt: false,
                _backup: null,
                pageActionLocked: false,
                activeHeavyAction: null,

                init() {
                    this.publishIso = this.buildIso();
                    window.__seoPublishBoxPush = () => this.pushToWire();
                    window.__seoPublishBoxSnapshot = () => ({
                        post_type: this.postType,
                        status: this.status,
                        visibility: this.visibility,
                        publish_day: this.publishDay,
                        publish_month: this.publishMonth,
                        publish_year: this.publishYear,
                        publish_hour: this.publishHour,
                        publish_minute: this.publishMinute,
                        publish_immediately: window.__seoPublishSyncSnapshot?.()?.publish_immediately ?? true,
                    });

                    window.__seoPublishBoxApplySchedule = (schedule) => {
                        if (!schedule || typeof schedule !== 'object') {
                            return;
                        }

                        this.publishDay = schedule.publishDay ?? this.publishDay;
                        this.publishMonth = schedule.publishMonth ?? this.publishMonth;
                        this.publishYear = schedule.publishYear ?? this.publishYear;
                        this.publishHour = schedule.publishHour ?? this.publishHour;
                        this.publishMinute = schedule.publishMinute ?? this.publishMinute;
                        if (schedule.status) {
                            this.status = schedule.status;
                        }
                        if (schedule.publishWhenLabel) {
                            this.publishWhenLabel = schedule.publishWhenLabel;
                        }
                        this.publishIso = this.buildIso();
                    };

                    const lockPage = (event) => {
                        this.pageActionLocked = true;
                        const action = event?.detail?.action;
                        if (action === 'save' || action === 'sync') {
                            this.activeHeavyAction = action;
                        }
                    };
                    const unlockPage = () => {
                        if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                            return;
                        }

                        this.pageActionLocked = false;
                        this.activeHeavyAction = null;
                    };

                    window.addEventListener('article-wordpress-sync-lock', lockPage);
                    window.addEventListener('article-wordpress-sync-unlock', unlockPage);
                    window.addEventListener('article-editor-save-finished', () => {
                        this.saveInFlight = false;
                        this.activeHeavyAction = null;
                    });
                    window.addEventListener('article-editor-save-patched', (event) => {
                        this.applySavePatch(event?.detail ?? {});
                    });

                    if (window.__seoArticleHeavyActionOverlay?.locked) {
                        lockPage();
                    }
                },

                applySavePatch(patch) {
                    const box = patch?.publish_box ?? {};
                    const article = patch?.article ?? {};

                    if (article.status) {
                        this.status = article.status;
                    }
                    if (article.post_type) {
                        this.postType = article.post_type;
                    }
                    if (box.visibility) {
                        this.visibility = box.visibility;
                    }
                    if (box.publish_day) {
                        this.publishDay = box.publish_day;
                    }
                    if (box.publish_month) {
                        this.publishMonth = box.publish_month;
                    }
                    if (box.publish_year) {
                        this.publishYear = box.publish_year;
                    }
                    if (box.publish_hour) {
                        this.publishHour = box.publish_hour;
                    }
                    if (box.publish_minute) {
                        this.publishMinute = box.publish_minute;
                    }

                    this.publishWhenLabel = box.publish_when_label ?? this.publishWhenLabel;
                    this.publishedAtSidebarLabel = box.published_at_sidebar_label ?? this.publishedAtSidebarLabel;
                    this.showPublishScheduleRow = Boolean(box.show_publish_schedule_row ?? this.showPublishScheduleRow);
                    this.savedAtLabel = box.saved_at_label ?? this.savedAtLabel;
                    this.publishIso = this.buildIso();

                    if (patch?.revision_count != null) {
                        this.revisionCount = Number(patch.revision_count);
                    }
                },

                isPublishActionDisabled() {
                    return Boolean(this.$wire?.articleHeavyActionBusy) || this.pageActionLocked || this.saveInFlight;
                },

                postTypeLabel() {
                    return this.labels.postType[this.postType] ?? this.labels.postType.article;
                },

                statusLabel() {
                    return this.labels.status[this.status] ?? this.labels.status.draft;
                },

                visibilityLabel() {
                    return this.labels.visibility[this.visibility] ?? this.labels.visibility.public;
                },

                saveButtonTitle() {
                    return (this.status === 'scheduled' ? 'Cập nhật lịch' : 'Cập nhật') + ' (Ctrl+S)';
                },

                pad(value) {
                    const n = Number(value || 0);
                    if (Number.isNaN(n)) {
                        return '00';
                    }

                    return String(n).padStart(2, '0');
                },

                buildIso() {
                    const y = String(this.publishYear || '').padStart(4, '0');
                    const m = this.pad(this.publishMonth);
                    const d = this.pad(this.publishDay);
                    const h = this.pad(this.publishHour);
                    const i = this.pad(this.publishMinute);

                    return `${y}-${m}-${d}T${h}:${i}`;
                },

                formatScheduleLabel(date) {
                    const weekdayMap = ['CN', 'Th2', 'Th3', 'Th4', 'Th5', 'Th6', 'Th7'];
                    const weekday = weekdayMap[date.getDay()] ?? 'Th';

                    return `${weekday} ${date.getDate()}, ${date.getFullYear()} at ${this.pad(date.getHours())}:${this.pad(date.getMinutes())}`;
                },

                snapshot() {
                    return {
                        postType: this.postType,
                        status: this.status,
                        visibility: this.visibility,
                        publishDay: this.publishDay,
                        publishMonth: this.publishMonth,
                        publishYear: this.publishYear,
                        publishHour: this.publishHour,
                        publishMinute: this.publishMinute,
                        publishWhenLabel: this.publishWhenLabel,
                    };
                },

                restoreSnapshot(snapshot) {
                    if (!snapshot) {
                        return;
                    }

                    Object.assign(this, snapshot);
                    this.publishIso = this.buildIso();
                },

                beginEdit(field) {
                    this._backup = this.snapshot();
                    this[`editing${field}`] = true;
                },

                cancelEdit(field) {
                    this.restoreSnapshot(this._backup);
                    this._backup = null;
                    this[`editing${field}`] = false;
                },

                applyPostType() {
                    this.editingPostType = false;
                    this._backup = null;
                    window.dispatchEvent(new CustomEvent('seo-publish-post-type-changed', {
                        detail: { postType: this.postType },
                    }));
                    // Update Livewire articlePostType only (skipRender) — no WP sync.
                    void this.pushToWire();
                },

                applyStatus() {
                    this.visibility = this.status === 'private' ? 'private' : 'public';
                    if (this.status !== 'scheduled') {
                        this.publishWhenLabel = '';
                    }
                    this.editingStatus = false;
                    this._backup = null;
                },

                applyVisibility() {
                    if (this.visibility === 'private') {
                        this.status = 'private';
                    } else if (this.status === 'private') {
                        this.status = 'draft';
                    }

                    this.editingVisibility = false;
                    this._backup = null;
                },

                randomizePublishAtFuture() {
                    const base = new Date();
                    base.setHours(base.getHours() + Math.floor(Math.random() * 8) + 1);
                    base.setMinutes(minutePool[Math.floor(Math.random() * minutePool.length)]);
                    base.setSeconds(0);
                    base.setMilliseconds(0);

                    this.publishYear = String(base.getFullYear());
                    this.publishMonth = String(base.getMonth() + 1).padStart(2, '0');
                    this.publishDay = String(base.getDate()).padStart(2, '0');
                    this.publishHour = String(base.getHours()).padStart(2, '0');
                    this.publishMinute = String(base.getMinutes()).padStart(2, '0');
                    this.publishIso = this.buildIso();

                    if (this.visibility !== 'private') {
                        this.status = 'scheduled';
                    }
                },

                beginPublishAtEdit() {
                    this.beginEdit('PublishAt');
                    this.randomizePublishAtFuture();
                },

                applyPublishIso() {
                    if (!this.publishIso || !this.publishIso.includes('T')) {
                        return;
                    }

                    const [datePart, timePart] = this.publishIso.split('T');
                    const [y, m, d] = datePart.split('-');
                    const [h, i] = timePart.split(':');
                    this.publishYear = y || this.publishYear;
                    this.publishMonth = m || this.publishMonth;
                    this.publishDay = d || this.publishDay;
                    this.publishHour = h || this.publishHour;
                    this.publishMinute = i || this.publishMinute;
                },

                applyPublishAt() {
                    this.applyPublishIso();

                    const dt = new Date(this.publishIso);
                    if (Number.isNaN(dt.getTime())) {
                        return;
                    }

                    this.publishWhenLabel = this.formatScheduleLabel(dt);

                    if (this.visibility !== 'private') {
                        this.status = dt > new Date() ? 'scheduled' : 'published';
                    }

                    this.editingPublishAt = false;
                    this._backup = null;
                },

                pushToWire() {
                    return this.$wire.applyPublishBoxFromClient(
                        this.postType,
                        this.status,
                        this.visibility,
                        this.publishDay,
                        this.publishMonth,
                        this.publishYear,
                        this.publishHour,
                        this.publishMinute,
                    );
                },

                async requestSave() {
                    if (this.isPublishActionDisabled()) {
                        return;
                    }

                    try {
                        if (typeof window.__seoExecuteHeavyArticleAction !== 'function') {
                            throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
                        }

                        await window.__seoExecuteHeavyArticleAction('save', null);
                        window.__seoResetPublishTabPrimed?.();
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                        this.activeHeavyAction = null;
                        this.saveInFlight = false;
                        if (typeof window.FilamentNotification !== 'undefined') {
                            new window.FilamentNotification()
                                .title('Không lưu được bài viết')
                                .body(error?.message ?? 'Lưu thất bại.')
                                .danger()
                                .send();
                        }
                    }
                },

                async openPublishTabForSync() {
                    window.dispatchEvent(new CustomEvent('seo-sidebar-open-publish-tab'));
                },
            };
        };
    </script>
@endonce

<div
    class="wp-publish-box__inside wp-publish-box__inside--article-info"
    x-data="seoPublishBoxData(@js($publishBoxInitial), @js($publishBoxLabels))"
>
        <div class="wp-publish-meta space-y-2">
            <div class="rounded border border-amber-200 bg-amber-50/70 p-2 text-xs dark:border-amber-900/60 dark:bg-amber-950/20">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5">
                        <span class="font-semibold text-amber-800 dark:text-amber-200">Debug WP ID</span>
                    </div>
                    @if ($record->wordpressLink?->wp_post_id)
                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Đã nối</span>
                    @else
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Thiếu WP ID</span>
                    @endif
                </div>
                <div class="mt-2 flex items-center gap-1.5">
                    <span class="text-gray-500 dark:text-gray-400">WP ID:</span>
                    @if ($record->wordpressLink?->wp_post_id)
                        <strong class="text-gray-800 dark:text-gray-100">{{ $record->wordpressLink->wp_post_id }}</strong>
                    @else
                        <strong class="text-amber-700 dark:text-amber-300">Chưa nối</strong>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input
                        type="number"
                        min="1"
                        step="1"
                        wire:model.defer="manualWpPostId"
                        class="w-28 rounded border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-900"
                        placeholder="WP ID"
                    />
                    <button
                        type="button"
                        wire:click="lookupManualWordPressPostId"
                        wire:loading.attr="disabled"
                        wire:target="lookupManualWordPressPostId"
                        class="rounded border border-gray-300 bg-white px-2 py-1 text-gray-700 hover:bg-gray-50 disabled:cursor-wait disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                    >
                        <span wire:loading.remove wire:target="lookupManualWordPressPostId">Kiểm tra trùng</span>
                        <span wire:loading wire:target="lookupManualWordPressPostId">Đang kiểm tra...</span>
                    </button>
                    <button
                        type="button"
                        wire:click="linkManualWordPressPostId"
                        wire:loading.attr="disabled"
                        wire:target="linkManualWordPressPostId"
                        class="text-sky-600 hover:underline disabled:cursor-wait disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="linkManualWordPressPostId">Nối WP ID</span>
                        <span wire:loading wire:target="linkManualWordPressPostId">Đang kiểm tra...</span>
                    </button>
                </div>
                @if (is_array($this->manualWpPostLookup))
                    <div class="mt-2 rounded border border-gray-200 bg-white p-2 text-[11px] text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @if (! empty($this->manualWpPostLookup['message']))
                            <div>{{ $this->manualWpPostLookup['message'] }}</div>
                        @endif
                        @php($duplicates = $this->manualWpPostLookup['duplicates'] ?? [])
                        @if (! empty($duplicates))
                            <div class="mt-1 space-y-1">
                                @foreach ($duplicates as $duplicate)
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate">
                                            #{{ $duplicate['id'] }} {{ $duplicate['title'] }}
                                            @if (! empty($duplicate['current']))
                                                <span class="text-emerald-600 dark:text-emerald-300">(bài hiện tại)</span>
                                            @endif
                                        </span>
                                        @if (! empty($duplicate['current']))
                                            <span class="shrink-0 text-emerald-600 dark:text-emerald-300">Đang mở</span>
                                        @else
                                            <a href="{{ $duplicate['edit_url'] }}" target="_blank" rel="noopener" class="shrink-0 text-sky-600 hover:underline">Mở</a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.article_list.author') }}:</span>
                <strong class="text-gray-800 dark:text-gray-100">{{ $articleAuthorName }}</strong>
                @if ($articleAuthorIsCurrentUser)
                    <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Bạn</span>
                @elseif ($currentUserLabel !== '')
                    <span class="ml-1 text-[10px] text-amber-700 dark:text-amber-300">(login: {{ $currentUserLabel }})</span>
                @endif
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.article_list.post_type') }}:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="postTypeLabel()"></strong>
                <button
                    type="button"
                    x-on:click="beginEdit('PostType')"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 flex items-center gap-2" x-show="editingPostType" x-cloak>
                    <x-select
                        x-model="postType"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                    >
                        <option value="article">{{ __('seo-content-ai::filament.article_list.post_type_article') }}</option>
                        <option value="product">{{ __('seo-content-ai::filament.article_list.post_type_product') }}</option>
                        @if ($this->isTaxonomyArticle())
                            <option value="category">{{ __('seo-content-ai::filament.article_list.post_type_category') }}</option>
                            <option value="product_category">{{ __('seo-content-ai::filament.article_list.post_type_product_category') }}</option>
                        @endif
                    </x-select>
                    <button type="button" x-on:click="applyPostType()" class="text-sky-600 hover:underline">Đồng ý</button>
                    <button type="button" x-on:click="cancelEdit('PostType')" class="text-sky-600 hover:underline">Hủy</button>
                </div>
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">Trạng thái:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="statusLabel()"></strong>
                <button
                    type="button"
                    x-on:click="beginEdit('Status')"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 flex items-center gap-2" x-show="editingStatus" x-cloak>
                    <x-select
                        x-model="status"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                    >
                        <option value="draft">Bản nháp</option>
                        <option value="published">Đã xuất bản</option>
                        <option value="scheduled">Đã lên lịch</option>
                        <option value="private">Riêng tư</option>
                    </x-select>
                    <button type="button" x-on:click="applyStatus()" class="text-sky-600 hover:underline">Đồng ý</button>
                    <button type="button" x-on:click="cancelEdit('Status')" class="text-sky-600 hover:underline">Hủy</button>
                </div>
                <div class="mt-2">
                    <button
                        type="button"
                        wire:click="reconcileObservedWordPressState"
                        wire:loading.attr="disabled"
                        wire:target="reconcileObservedWordPressState"
                        class="text-xs font-semibold text-sky-700 hover:underline"
                    >
                        <span wire:loading.remove wire:target="reconcileObservedWordPressState">Kiểm tra lại trạng thái</span>
                        <span wire:loading wire:target="reconcileObservedWordPressState" class="inline-flex items-center gap-1 opacity-50">
                            <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            Đang kiểm tra
                        </span>
                    </button>
                </div>
            </div>

            <div class="text-xs">
                <span class="text-gray-500 dark:text-gray-400">Hiển thị:</span>
                <strong class="text-gray-800 dark:text-gray-100" x-text="visibilityLabel()"></strong>
                <button
                    type="button"
                    x-on:click="beginEdit('Visibility')"
                    class="ml-1 text-sky-600 hover:underline"
                >
                    Chỉnh sửa
                </button>
                <div class="mt-2 flex items-center gap-2" x-show="editingVisibility" x-cloak>
                    <x-select
                        x-model="visibility"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1"
                    >
                        <option value="public">Công khai</option>
                        <option value="private">Riêng tư</option>
                    </x-select>
                    <button type="button" x-on:click="applyVisibility()" class="text-sky-600 hover:underline">Đồng ý</button>
                    <button type="button" x-on:click="cancelEdit('Visibility')" class="text-sky-600 hover:underline">Hủy</button>
                </div>
            </div>

            @if (filled($publishBoxInitial['publishedAtSidebarLabel'] ?? null))
                <div class="text-xs" x-show="publishedAtSidebarLabel" x-cloak>
                    <span class="text-gray-500 dark:text-gray-400">Ngày đăng:</span>
                    <strong class="text-gray-800 dark:text-gray-100" x-text="publishedAtSidebarLabel"></strong>
                </div>
            @endif

            <div class="text-xs text-emerald-700 dark:text-emerald-300" x-show="savedAtLabel" x-cloak>
                <span x-text="savedAtLabel"></span>
            </div>

            @if (! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager())
                <div class="text-xs">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.article_list.review') }}:</span>
                    <strong class="{{ app(\Omnichannel\Addons\Content\Services\ArticleReviewService::class)->isCanonicallyApproved($record) ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-800 dark:text-gray-100' }}">
                        {{ $this->getReviewStatusLabel() }}
                    </strong>
                    @if ($this->getReviewedAtLabel())
                        <span class="text-gray-500 dark:text-gray-400">({{ $this->getReviewedAtLabel() }})</span>
                    @endif
                    @if ($this->getVirtualCommentsCount() > 0)
                        <span class="text-gray-500 dark:text-gray-400">
                            · {{ __('seo-content-ai::filament.article_list.virtual_comments_count', ['count' => $this->getVirtualCommentsCount()]) }}
                        </span>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-between gap-2 text-xs">
                <span
                    class="text-gray-500 dark:text-gray-400"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_history') }}"
                >
                    {{ __('seo-content-ai::filament.article_list.page_action_history') }}
                    (<span data-seo-revision-count>{{ $articleRevisionCount }}</span>)
                </span>
                @if ($articleRevisionCount > 0)
                    <button
                        type="button"
                        wire:click.stop="clearArticleRevisionHistory"
                        wire:confirm="Xóa toàn bộ {{ $articleRevisionCount }} phiên bản lịch sử? Thao tác không thể hoàn tác."
                        wire:loading.attr="disabled"
                        wire:target="clearArticleRevisionHistory"
                        class="shrink-0 text-rose-600 hover:underline disabled:opacity-50"
                        title="Xóa sạch lịch sử chỉnh sửa"
                    >
                        <span wire:loading.remove wire:target="clearArticleRevisionHistory">🗑 Dọn dẹp</span>
                        <span wire:loading wire:target="clearArticleRevisionHistory">Đang xóa…</span>
                    </button>
                @endif
            </div>
        </div>

        {{-- Article actions (Save / Review / View WP) live only in top page action bar --}}
</div>
