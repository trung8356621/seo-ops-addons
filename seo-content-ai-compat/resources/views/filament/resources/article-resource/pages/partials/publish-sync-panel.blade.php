@php
    use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;

    $publishSyncInitial = [
        'publishImmediately' => true,
        'publishDay' => $publishDay,
        'publishMonth' => $publishMonth,
        'publishYear' => $publishYear,
        'publishHour' => $publishHour,
        'publishMinute' => $publishMinute,
        'publishWhenLabel' => $this->getPublishWhenLabel(),
        'status' => $articleStatus,
        'hasWpPost' => (int) ($record->wordpressLink?->wp_post_id ?? 0) > 0,
        'canSync' => ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager()
            && ! \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::articleIsInContentProject($record),
        'displayTimezone' => SeoDisplayTimezone::name(),
    ];
@endphp

@once
    <script>
        window.seoSchedulePartsInTimezone = function seoSchedulePartsInTimezone(timezone, addMinutes = 0) {
            const instant = Date.now() + addMinutes * 60_000;
            const formatter = new Intl.DateTimeFormat('en-GB', {
                timeZone: timezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                weekday: 'short',
                hour12: false,
            });
            const parts = Object.fromEntries(
                formatter.formatToParts(new Date(instant))
                    .filter((part) => part.type !== 'literal')
                    .map((part) => [part.type, part.value]),
            );

            return {
                publishYear: parts.year ?? '',
                publishMonth: parts.month ?? '',
                publishDay: parts.day ?? '',
                publishHour: parts.hour ?? '',
                publishMinute: parts.minute ?? '',
                weekday: parts.weekday ?? '',
            };
        };

        window.seoFormatScheduleLabelInTimezone = function seoFormatScheduleLabelInTimezone(timezone, addMinutes = 0) {
            const parts = window.seoSchedulePartsInTimezone(timezone, addMinutes);
            const weekdayMap = {
                Sun: 'CN',
                Mon: 'Th2',
                Tue: 'Th3',
                Wed: 'Th4',
                Thu: 'Th5',
                Fri: 'Th6',
                Sat: 'Th7',
            };
            const weekday = weekdayMap[parts.weekday] ?? 'Th';
            const day = String(parts.publishDay).replace(/^0+/, '') || '0';
            const hour = String(parts.publishHour).padStart(2, '0');
            const minute = String(parts.publishMinute).padStart(2, '0');

            return `${weekday} ${day}, ${parts.publishYear} at ${hour}:${minute}`;
        };

        window.seoPublishSyncData = function seoPublishSyncData(initial) {
            return {
                publishImmediately: initial.publishImmediately !== false,
                publishDay: initial.publishDay ?? '',
                publishMonth: initial.publishMonth ?? '',
                publishYear: initial.publishYear ?? '',
                publishHour: initial.publishHour ?? '',
                publishMinute: initial.publishMinute ?? '',
                publishWhenLabel: initial.publishWhenLabel ?? '',
                status: initial.status ?? 'draft',
                publishIso: '',
                displayTimezone: initial.displayTimezone ?? 'Asia/Ho_Chi_Minh',
                hasWpPost: initial.hasWpPost === true,
                canSync: initial.canSync === true,
                syncInFlight: false,
                pageActionLocked: false,

                init() {
                    this.publishIso = this.buildIso();
                    if (this.publishImmediately) {
                        this.applyPublishImmediatelySchedule();
                    }

                    window.__seoPublishSyncSnapshot = () => ({
                        publish_immediately: this.publishImmediately,
                    });
                    window.__seoPublishSyncApplySchedule = () => this.applyScheduleToPublishBox();

                    const lockPage = () => {
                        this.pageActionLocked = true;
                    };
                    const unlockPage = () => {
                        if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                            return;
                        }
                        this.pageActionLocked = false;
                        this.syncInFlight = false;
                    };

                    window.addEventListener('article-wordpress-sync-lock', lockPage);
                    window.addEventListener('article-wordpress-sync-unlock', unlockPage);
                    window.addEventListener('article-wordpress-sync-queued', () => {
                        // Editor đang thoát sau enqueue — không khóa lại UI.
                        if (window.__SEO_EDITOR_EXITING__) {
                            this.syncInFlight = false;
                            this.pageActionLocked = false;
                            return;
                        }
                        this.syncInFlight = true;
                        this.pageActionLocked = true;
                    });
                    window.addEventListener('article-editor-save-patched', (event) => {
                        const box = event?.detail?.publish_box ?? {};
                        const article = event?.detail?.article ?? {};
                        if (article.status) {
                            this.status = article.status;
                        }
                        if (box.publish_when_label) {
                            this.publishWhenLabel = box.publish_when_label;
                        }
                    });

                    window.__seoPublishTabRequestSync = () => this.requestSync();
                    window.addEventListener('seo-publish-tab-request-sync', () => this.requestSync());
                },

                pad(value) {
                    const n = Number(value || 0);
                    return Number.isNaN(n) ? '00' : String(n).padStart(2, '0');
                },

                buildIso() {
                    const y = String(this.publishYear || '').padStart(4, '0');
                    const m = this.pad(this.publishMonth);
                    const d = this.pad(this.publishDay);
                    const h = this.pad(this.publishHour);
                    const i = this.pad(this.publishMinute);
                    return `${y}-${m}-${d}T${h}:${i}`;
                },

                formatScheduleLabel() {
                    return window.seoFormatScheduleLabelInTimezone(this.displayTimezone, 0);
                },

                applyPublishImmediatelySchedule() {
                    const parts = window.seoSchedulePartsInTimezone(this.displayTimezone, 0);

                    this.publishYear = String(parts.publishYear);
                    this.publishMonth = String(parts.publishMonth).padStart(2, '0');
                    this.publishDay = String(parts.publishDay).padStart(2, '0');
                    this.publishHour = String(parts.publishHour).padStart(2, '0');
                    this.publishMinute = String(parts.publishMinute).padStart(2, '0');
                    this.publishIso = this.buildIso();
                    this.status = 'published';
                    // Đăng ngay = publish tức thì — không hiện dòng "lên lịch".
                    this.publishWhenLabel = '';
                    this.applyScheduleToPublishBox();
                },

                onPublishImmediatelyChange() {
                    if (this.publishImmediately) {
                        this.applyPublishImmediatelySchedule();
                    } else {
                        this.applyScheduleToPublishBox();
                    }
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

                formatScheduleLabelFromParts() {
                    const dt = new Date(this.publishIso);
                    if (Number.isNaN(dt.getTime())) {
                        return this.publishWhenLabel;
                    }

                    const weekdayMap = ['CN', 'Th2', 'Th3', 'Th4', 'Th5', 'Th6', 'Th7'];
                    const weekday = weekdayMap[dt.getDay()] ?? 'Th';

                    return `${weekday} ${dt.getDate()}, ${dt.getFullYear()} at ${this.pad(dt.getHours())}:${this.pad(dt.getMinutes())}`;
                },

                applyCustomSchedule() {
                    this.applyPublishIso();
                    const dt = new Date(this.publishIso);
                    if (Number.isNaN(dt.getTime())) {
                        return;
                    }

                    this.publishWhenLabel = this.formatScheduleLabelFromParts();
                    this.status = dt > new Date() ? 'scheduled' : 'published';
                    this.applyScheduleToPublishBox();
                },

                applyScheduleToPublishBox() {
                    if (typeof window.__seoPublishBoxApplySchedule === 'function') {
                        window.__seoPublishBoxApplySchedule({
                            publishDay: this.publishDay,
                            publishMonth: this.publishMonth,
                            publishYear: this.publishYear,
                            publishHour: this.publishHour,
                            publishMinute: this.publishMinute,
                            status: this.status,
                            publishWhenLabel: this.publishWhenLabel,
                            publishImmediately: this.publishImmediately,
                        });
                    }

                    void window.__seoPublishBoxPush?.();
                },

                isSyncDisabled() {
                    return !this.canSync || this.syncInFlight || this.pageActionLocked;
                },

                syncButtonTitle() {
                    return this.hasWpPost
                        ? 'Đồng bộ WordPress (Ctrl+Shift+S)'
                        : 'Đăng bài viết mới lên WordPress (Ctrl+Shift+S)';
                },

                async requestSync() {
                    if (this.isSyncDisabled()) {
                        return;
                    }

                    if (this.publishImmediately) {
                        this.applyPublishImmediatelySchedule();
                    } else {
                        this.applyCustomSchedule();
                    }

                    this.syncInFlight = true;
                    window.__seoBeginArticleHeavyActionClient?.('sync');
                    let actionFinished = false;

                    try {
                        if (typeof window.__seoEnsureCategoriesBeforeSync === 'function') {
                            const allowed = await window.__seoEnsureCategoriesBeforeSync();
                            if (!allowed) {
                                return;
                            }
                        }

                        if (typeof window.__seoExecuteHeavyArticleAction === 'function') {
                            await window.__seoExecuteHeavyArticleAction('sync', this.$wire, {
                                renameImagesBeforeWpSync: !this.hasWpPost,
                            });
                        } else {
                            await window.__seoPublishBoxPush?.();
                            await window.__seoPushPublishCategoriesToWire?.();
                            await this.$wire.requestSyncToWordPress();
                        }
                        actionFinished = true;
                        window.__seoResetPublishTabPrimed?.();
                    } catch (error) {
                        console.warn('Đồng bộ WordPress thất bại ở client', error);
                    } finally {
                        this.syncInFlight = false;
                        if (!actionFinished) {
                            window.__seoEndArticleHeavyActionClient?.();
                        }
                    }
                },
            };
        };
    </script>
@endonce

<div
    class="wp-postbox"
    x-data="seoPublishSyncData(@js($publishSyncInitial))"
>
    <div class="wp-postbox-header">
        <h2>{{ __('seo-content-ai::filament.article_list.publish_schedule_title') }}</h2>
    </div>
    <div class="wp-postbox-inside space-y-3">
        <label class="flex cursor-pointer items-start gap-2 text-xs text-gray-800 dark:text-gray-100">
            <input
                type="checkbox"
                class="mt-0.5 h-3.5 w-3.5 rounded border-gray-300 text-sky-600 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-900"
                x-model="publishImmediately"
                x-on:change="onPublishImmediatelyChange()"
            />
            <span>
                <strong>{{ __('seo-content-ai::filament.article_list.publish_immediately') }}</strong>
                <span class="block text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.article_list.publish_immediately_hint') }}
                </span>
            </span>
        </label>

        <div class="space-y-2" x-show="!publishImmediately" x-cloak>
            <label class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.article_list.publish_custom_schedule') }}
            </label>
            <input
                x-model="publishIso"
                x-on:change="applyPublishIso()"
                type="datetime-local"
                step="60"
                class="seo-publish-datetime-input w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm py-1.5 px-2"
            />
            <button
                type="button"
                x-on:click="applyCustomSchedule()"
                class="text-xs text-sky-600 hover:underline"
            >
                {{ __('seo-content-ai::filament.article_list.apply_schedule') }}
            </button>
            <p class="text-xs text-gray-600 dark:text-gray-300" x-show="publishWhenLabel" x-text="publishWhenLabel"></p>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400" x-show="publishImmediately && publishWhenLabel" x-cloak>
            <span>{{ __('seo-content-ai::filament.article_list.scheduled_for') }}:</span>
            <strong class="text-gray-800 dark:text-gray-100" x-text="publishWhenLabel"></strong>
        </p>

        {{-- Sync UI entry: top page action bar only. requestSync() kept for event bridge. --}}
    </div>
</div>
