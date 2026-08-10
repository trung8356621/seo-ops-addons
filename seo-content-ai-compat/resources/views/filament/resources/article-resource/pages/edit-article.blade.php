@push('styles')
    @vite([
        'addons/media/resources/js/article-media-picker-cache-bootstrap.js',
        'addons/content/resources/css/article-edit-page.css',
    ])
    {{-- Inline fallback: topbar hide không phụ thuộc hashed CSS nếu Vite stale --}}
    <style id="article-editor-ui-revision-style">
        body.article-editor-page .fi-topbar,
        html.article-editor-page .fi-topbar,
        body:has(.article-editor-page) .fi-topbar,
        body:has([data-article-editor-runtime-marker="sticky-help-v1"]) .fi-topbar {
            display: block !important;
            position: fixed !important;
            top: 0;
            right: 0;
            left: 0;
            width: 100%;
            height: 0;
            min-height: 0;
            padding: 0;
            overflow: visible !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            z-index: 55;
            pointer-events: none;
        }
        body.article-editor-page .fi-topbar *,
        html.article-editor-page .fi-topbar *,
        body:has(.article-editor-page) .fi-topbar * {
            visibility: hidden !important;
            pointer-events: none !important;
        }
        body.article-editor-page .fi-topbar .global-help-topbar-host,
        body.article-editor-page .fi-topbar .global-help-topbar-host *,
        html.article-editor-page .fi-topbar .global-help-topbar-host,
        html.article-editor-page .fi-topbar .global-help-topbar-host * {
            /* Help nằm cạnh nút More trong toolbar — ẩn host fixed trên topbar */
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }
        body.article-editor-page .seo-article-editor-sticky-header {
            padding-right: 0.75rem;
        }
        body.article-editor-page .fi-main,
        body:has(.article-editor-page) .fi-main,
        body:has(.seo-article-edit-page) .fi-main {
            padding-inline: 0 !important;
            padding-top: 0 !important;
        }
        .seo-article-editor-sticky-header {
            position: sticky;
            top: 0;
            z-index: 40;
            width: 100%;
            margin: 0;
            border-radius: 0;
            border: 0;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.55rem 1rem;
            box-sizing: border-box;
            background: rgb(255 255 255 / 97%);
        }
        .wp-article-edit-layout {
            padding-inline: 0.75rem;
            padding-top: 0.75rem;
            box-sizing: border-box;
        }
        .seo-article-editor-sticky-header__title,
        .seo-article-editor-sticky-header__meta {
            display: none !important;
        }
    </style>
@endpush

@push('scripts')
<script>
    (function () {
        document.body.classList.add('article-editor-page');
        document.documentElement.classList.add('article-editor-page');
        window.__ARTICLE_EDITOR_UI_REVISION__ = 'sticky-help-v1';
        window.__ARTICLE_EDITOR_HELP__ = window.__ARTICLE_EDITOR_HELP__ || { revision: 'sticky-help-v1' };
        document.addEventListener('livewire:navigated', function () {
            if (!document.querySelector('[data-article-editor-runtime-marker="sticky-help-v1"], .seo-article-edit-page')) {
                document.body.classList.remove('article-editor-page');
                document.documentElement.classList.remove('article-editor-page');
            }
        });
    })();
</script>
@endpush

<x-filament-panels::page @class(['seo-article-edit-page', 'article-editor-page']) data-article-editor-page>
{{-- Runtime revision marker: sticky-help-v1 — single root: phải nằm TRONG page component --}}
<meta name="article-editor-ui-revision" content="sticky-help-v1">
<div
    data-article-editor-runtime-marker="sticky-help-v1"
    style="display:none"
    aria-hidden="true"
></div>
@if ($this->editorPreparing)
    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 py-20 text-center" wire:poll.3s="pollEditorReadiness">
        <x-filament::loading-indicator class="h-10 w-10" />
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
            {{ __('seo-content-ai::filament.projects.article_editor_preparing_title') }}
        </h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ $this->editorPreparingMessage }}
        </p>
        <button
            type="button"
            wire:click="forceOpenEditorWhilePreparing"
            wire:loading.attr="disabled"
            wire:target="forceOpenEditorWhilePreparing"
            class="mt-2 inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
        >
            <span wire:loading.remove wire:target="forceOpenEditorWhilePreparing">
                {{ __('seo-content-ai::filament.projects.article_editor_preparing_open_anyway') }}
            </span>
            <span wire:loading wire:target="forceOpenEditorWhilePreparing" class="inline-flex items-center gap-2">
                <x-filament::loading-indicator class="h-4 w-4" />
                {{ __('seo-content-ai::filament.projects.article_editor_preparing_opening') }}
            </span>
        </button>
    </div>
@else
@if ($this->recordDomainDiffersFromGlobal)
    <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
        {{ __('seo-content-ai::filament.article_list.record_domain_note', [
            'domain' => (string) ($this->record?->site?->domain ?? ('#'.(int) ($this->record?->site_id ?? 0))),
        ]) }}
    </div>
@endif
@php
    $seoActiveArticleOperation = app(\Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService::class)
        ->activeOperation($record);
    $seoHasActiveArticleOperation = is_array($seoActiveArticleOperation)
        && in_array((string) ($seoActiveArticleOperation['raw_status'] ?? ''), [
            \Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService::STATUS_PENDING,
            \Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService::STATUS_PROCESSING,
        ], true);
@endphp
<script>
    window.__SEO_ACTIVE_ARTICLE_OPERATION__ = @js($seoHasActiveArticleOperation ? $seoActiveArticleOperation : null);
</script>
@once
<script>
        window.__seoArticleHeavyActionOverlay = {
            id: 'seo-article-heavy-action-overlay',
            locked: false,
            persistUntilUnload: false,
            action: null,
            guardTimer: null,
            copyForAction(action) {
                const map = {
                    save: {
                        title: 'Đang cập nhật bài viết',
                        message: 'Đang lưu nội dung — vui lòng chờ…',
                    },
                    sync: {
                        title: 'Đang đưa vào hàng đợi WordPress',
                        message: 'Đang lưu và xếp hàng đồng bộ — tab sẽ tự đóng khi xong…',
                    },
                    restore: {
                        title: 'Đang đồng bộ từ WordPress',
                        message: 'Đang ghi đè bài viết bằng bản WordPress — vui lòng chờ…',
                    },
                    delete: {
                        title: 'Đang xóa bài viết',
                        message: 'Đang xóa bài viết — vui lòng chờ…',
                    },
                };

                return map[action] ?? map.sync;
            },
            show(action = 'sync', options = {}) {
                const allowed = ['save', 'sync', 'restore', 'delete'];
                const normalized = allowed.includes(action) ? action : 'sync';
                this.locked = true;
                this.persistUntilUnload = Boolean(options.persistUntilUnload);
                this.action = normalized;
                let overlay = document.getElementById(this.id);

                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.id = this.id;
                    overlay.className = 'seo-article-sync-overlay';
                    overlay.setAttribute('role', 'alert');
                    overlay.setAttribute('aria-live', 'assertive');
                    overlay.setAttribute('aria-busy', 'true');

                    const panel = document.createElement('div');
                    panel.className = 'seo-article-sync-overlay__panel';

                    const spinner = document.createElement('div');
                    spinner.className = 'seo-article-sync-overlay__spinner';
                    spinner.setAttribute('aria-hidden', 'true');

                    const title = document.createElement('strong');
                    title.setAttribute('data-heavy-action-title', '');

                    const message = document.createElement('span');
                    message.setAttribute('data-heavy-action-message', '');
                    message.textContent = 'Vui lòng chờ — không chỉnh sửa cho đến khi hoàn tất.';

                    panel.append(spinner, title, message);
                    overlay.appendChild(panel);
                    document.body.appendChild(overlay);
                }

                const copy = this.copyForAction(normalized);
                const title = overlay.querySelector('[data-heavy-action-title]');
                if (title) {
                    title.textContent = String(options.title || copy.title);
                }

                const message = overlay.querySelector('[data-heavy-action-message]');
                if (message) {
                    message.textContent = String(options.message || copy.message);
                }

                document.documentElement.classList.add('seo-article-sync-locked');
                document.querySelectorAll('body > *').forEach((element) => {
                    if (element.id !== this.id && !element.hasAttribute('inert')) {
                        element.setAttribute('data-seo-heavy-action-inert', '1');
                        element.setAttribute('inert', '');
                    }
                });

                if (!window.__seoArticleHeavyActionKeyBlocker) {
                    window.__seoArticleHeavyActionKeyBlocker = (event) => {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    };
                    window.addEventListener('keydown', window.__seoArticleHeavyActionKeyBlocker, true);
                }

                if (!this.guardTimer) {
                    this.guardTimer = window.setInterval(() => {
                        if (!this.locked) {
                            return;
                        }

                        if (
                            !document.getElementById(this.id)
                            || !document.documentElement.classList.contains('seo-article-sync-locked')
                        ) {
                            this.show(this.action ?? 'sync');
                        }
                    }, 150);
                }
            },
            setStatusMessage(text) {
                const overlay = document.getElementById(this.id);
                const message = overlay?.querySelector('[data-heavy-action-message]');
                if (message && text) {
                    message.textContent = String(text);
                }
            },
            hide() {
                if (this.persistUntilUnload) {
                    return;
                }

                this.locked = false;
                this.action = null;
                if (this.guardTimer) {
                    window.clearInterval(this.guardTimer);
                    this.guardTimer = null;
                }

                document.getElementById(this.id)?.remove();
                document.documentElement.classList.remove('seo-article-sync-locked');
                document.querySelectorAll('[data-seo-heavy-action-inert]').forEach((element) => {
                    element.removeAttribute('inert');
                    element.removeAttribute('data-seo-heavy-action-inert');
                });

                if (window.__seoArticleHeavyActionKeyBlocker) {
                    window.removeEventListener('keydown', window.__seoArticleHeavyActionKeyBlocker, true);
                    delete window.__seoArticleHeavyActionKeyBlocker;
                }
            },
        };

        window.__seoBeginArticleHeavyActionClient = function beginArticleHeavyActionClient(action = 'sync') {
            const allowed = ['save', 'sync', 'restore', 'delete'];
            const normalized = allowed.includes(action) ? action : 'sync';
            window.__seoArticleHeavyActionOverlay?.show(normalized);
            window.__seoArticleAutosaveLock?.set('article-heavy-action', true);
            window.dispatchEvent(new CustomEvent('article-wordpress-sync-lock', {
                detail: { action: normalized },
            }));

            return normalized;
        };

        window.__seoEndArticleHeavyActionClient = function endArticleHeavyActionClient() {
            if (window.__seoArticleHeavyActionEnding) {
                return;
            }

            if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                return;
            }

            window.__seoArticleHeavyActionEnding = true;
            try {
                window.__seoArticleHeavyActionOverlay?.hide();
                window.__seoArticleAutosaveLock?.set('article-heavy-action', false);
                window.dispatchEvent(new CustomEvent('article-wordpress-sync-unlock'));
            } finally {
                window.__seoArticleHeavyActionEnding = false;
            }
        };

        window.__seoYieldForHeavyActionPaint = function yieldForHeavyActionPaint() {
            return new Promise((resolve) => {
                requestAnimationFrame(() => requestAnimationFrame(resolve));
            });
        };

        window.__seoRunWordPressPhasedSync = async function runWordPressSync(wire, payload = {}) {
            const articleId = @js((int) $record->getKey());
            await window.__seoRunArticleEditorApiAction?.('sync', wire, {
                html: payload.html ?? '',
                seoAnalysis: payload.seoAnalysis ?? null,
                articleId,
            });
        };

        (function bootstrapActiveArticleOperationOverlay() {
            const op = window.__SEO_ACTIVE_ARTICLE_OPERATION__;
            if (!op || typeof op !== 'object') {
                return;
            }
            const status = String(op.status || '');
            if (status !== 'queued' && status !== 'processing') {
                return;
            }
            // WP sync đang chạy — không khóa editor chờ; chuyển Sync Queue.
            window.__SEO_EDITOR_EXITING__ = true;
            window.__seoArticleOperationTracker?.stop?.();
            const url = typeof window.__SEO_ARTICLES_SYNC_QUEUE_URL__ === 'string'
                && window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim() !== ''
                ? window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim()
                : '/seo/articles?tab=queue';
            window.location.replace(url);
        })();
</script>
@endonce

    <div
        x-data="{
            syncPageLocked: false,
            heavyPageAction: null,
            articleId: @js((int) $record->getKey()),
            init() {
                /* Phase 6C.4: no Alpine media picker bootstrap */
                if (window.__SEO_EDITOR_EXITING__) {
                    return;
                }
                const activeOp = window.__SEO_ACTIVE_ARTICLE_OPERATION__;
                if (activeOp && typeof activeOp === 'object') {
                    // Tracker sẽ redirect Sync Queue cho WP sync queued/processing.
                    window.__seoArticleOperationTracker?.apply?.(this.articleId, activeOp);
                    return;
                }
                if (window.__seoArticleHeavyActionOverlay?.locked) {
                    this.syncPageLocked = true;
                    this.heavyPageAction = window.__seoArticleHeavyActionOverlay.action ?? 'sync';
                    return;
                }
                window.__seoArticleOperationTracker?.bootstrap?.(this.articleId);
            },
            lockPageForHeavyAction(action = 'sync') {
                if (this.syncPageLocked || document.getElementById('seo-article-heavy-action-overlay')) {
                    return false;
                }

                this.syncPageLocked = true;
                this.heavyPageAction = window.__seoBeginArticleHeavyActionClient?.(action) ?? (action === 'save' ? 'save' : 'sync');

                clearTimeout(this._heavyActionUnlockTimer);
                this._heavyActionUnlockTimer = setTimeout(() => {
                    if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                        return;
                    }

                    if (!this.$wire?.articleHeavyActionBusy) {
                        window.__seoEndArticleHeavyActionClient?.();
                    }
                }, 120000);

                return true;
            },
            unlockPageAfterHeavyActionFailure() {
                if (window.__seoArticleHeavyActionOverlay?.persistUntilUnload) {
                    return;
                }

                clearTimeout(this._heavyActionUnlockTimer);
                this.syncPageLocked = false;
                this.heavyPageAction = null;
            },
            async openArticleMediaModal(mode, blockId = null) {
                // Shared Media Picker (React). Alpine only forwards open events.
                if (typeof window.__seoOpenSharedMediaPicker === 'function') {
                    window.__seoOpenSharedMediaPicker({
                        mode: mode === 'editor-block' ? 'content_image' : mode,
                        blockId: blockId,
                        articleId: this.articleId,
                    });
                }
            },
            closeArticleMediaModal() {
                window.__seoArticleAutosaveLock?.set('media-picker-modal', false);
            },

        }"
        x-on:close-article-media-modal.window="closeArticleMediaModal()"
                                        x-on:article-wordpress-sync-lock.window="lockPageForHeavyAction($event.detail?.action ?? 'sync')"
        x-on:article-wordpress-sync-unlock.window="unlockPageAfterHeavyActionFailure()"
        x-on:seo-open-article-media-picker.window="openArticleMediaModal('editor-block', $event.detail?.blockId ?? null)"
        x-on:seo-open-featured-media-picker.window="openArticleMediaModal('featured')"
        x-on:seo-open-gallery-media-picker.window="openArticleMediaModal('gallery')"
        x-on:seo-article-editor-notify.window="
            const payload = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            if (typeof window.__seoShowArticleEditorToast === 'function') {
                window.__seoShowArticleEditorToast(payload);
                return;
            }
            const title = String(payload.title ?? '').trim();
            const body = String(payload.body ?? '').trim();
            if (title === '' && body === '') {
                return;
            }
            if (typeof FilamentNotification === 'undefined') {
                return;
            }
            const toast = new FilamentNotification();
            if (title !== '') {
                toast.title(title);
            }
            if (body !== '') {
                toast.body(body);
            }
            const status = String(payload.status ?? 'success');
            if (status === 'danger' || status === 'error') {
                toast.danger();
            } else if (status === 'warning') {
                toast.warning();
            } else if (status === 'info') {
                toast.info();
            } else {
                toast.success();
            }
            toast.send();
        "
        x-on:open-article-media-modal.window="
            const wireMode = $wire.mediaPickerMode || 'featured';
            const mode = wireMode === 'editor-block'
                ? 'editor-block'
                : (wireMode === 'gallery' ? 'gallery' : 'featured');
            openArticleMediaModal(mode);
        "
        x-on:flush-article-faqs.window="
            if (this._faqFlushFinalizeTimer) {
                clearTimeout(this._faqFlushFinalizeTimer);
            }
            const targetAtFlush = $wire.pendingEditorCollectTarget;
            this._faqFlushFinalizeTimer = setTimeout(() => {
                this._faqFlushFinalizeTimer = null;
                // Chỉ fallback khi saveArticleFaqs chưa clear pending (tránh double collect → double generate FAQ).
                if ($wire.pendingEditorCollectTarget && $wire.pendingEditorCollectTarget === targetAtFlush) {
                    $wire.finalizePendingEditorCollect();
                }
            }, 1200);
        "
        x-on:editor-html-collected.window="
            const detail = $event.detail && typeof $event.detail === 'object' ? $event.detail : {};
            const articleId = @js((int) $record->getKey());
            if (detail.target === 'sync') {
                if (!window.__seoArticleHeavyActionOverlay?.locked) {
                    window.__seoBeginArticleHeavyActionClient?.('sync');
                }
                (async () => {
                    try {
                        await window.__seoRunArticleEditorApiAction?.('sync', $wire, {
                            html: detail.html ?? '',
                            seoAnalysis: detail.seoAnalysis ?? null,
                            articleId,
                        });
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                        if (!error?.notificationShown && typeof FilamentNotification !== 'undefined') {
                            new FilamentNotification()
                                .title(@js(__('seo-content-ai::filament.automation.wp_sync_blocked_title')))
                                .body(error?.message ?? @js(__('seo-content-ai::filament.automation.wp_sync_blocked_body')))
                                .danger()
                                .send();
                        }
                    }
                })();
            } else if (detail.target === 'generate-faq') {
                $wire.generateArticleFaqs(detail.html ?? '');
            } else if (detail.target === 'quick-translate') {
                $wire.quickTranslateLinkedArticle(detail.html ?? '');
            } else if (detail.target === 'save' || !detail.target) {
                (async () => {
                    try {
                        await window.__seoRunArticleEditorApiAction?.('save', null, {
                            html: detail.html ?? '',
                            seoAnalysis: detail.seoAnalysis ?? null,
                            articleId,
                        });
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                        window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
                    }
                })();
            }
        "
        x-on:generate-article-faqs.window="$wire.requestGenerateArticleFaqs()"
        x-on:import-markdown-faq-debug.window="$wire.importMarkdownFaqDebug($event.detail?.markdown ?? '')"
        x-on:article-editor-shortcut.window="
            const action = $event.detail?.action;
            if (syncPageLocked || $wire.articleHeavyActionBusy) {
                return;
            }
            if (action === 'save' || action === 'save-close') {
                if (window.__SEO_EDITOR_NETWORK_STATUS__?.unavailable) {
                    if (typeof FilamentNotification !== 'undefined') {
                        new FilamentNotification()
                            .title('Không thể lưu khi đang mất kết nối.')
                            .body('Giữ thay đổi trên editor — sẽ lưu lại khi kết nối trở lại.')
                            .warning()
                            .send();
                    }
                    return;
                }
                const runSave = async () => {
                    if (typeof window.__seoExecuteHeavyArticleAction !== 'function') {
                        throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
                    }

                    await window.__seoExecuteHeavyArticleAction(action === 'save-close' ? 'save-close' : 'save', null);
                    window.__seoResetPublishTabPrimed?.();
                };
                runSave().catch((error) => {
                    window.__seoEndArticleHeavyActionClient?.();
                    window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
                    if (typeof FilamentNotification !== 'undefined') {
                        new FilamentNotification()
                            .title('Không lưu được bài viết')
                            .body(error?.message ?? 'Lưu thất bại.')
                            .danger()
                            .send();
                    }
                });
            } else if (action === 'sync') {
                if (window.__SEO_EDITOR_NETWORK_STATUS__?.unavailable) {
                    return;
                }
                @php
                    $syncIsContentManager = \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager();
                    $syncInContentProject = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::articleIsInContentProject($record);
                    $syncEligibility = app(\Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncEligibility::class)
                        ->evaluate($record);
                    $syncContentProjectEligible = $syncInContentProject && ($syncEligibility['allowed'] ?? false);
                @endphp
                @if (! $syncIsContentManager && ! $syncInContentProject)
                    window.dispatchEvent(new CustomEvent('seo-publish-tab-request-sync'));
                @elseif (! $syncIsContentManager && $syncContentProjectEligible)
                    {{-- CP update-existing: Published or rewrite/improve with wp_post_id. --}}
                    (async () => {
                        try {
                            if (typeof window.__seoExecuteHeavyArticleAction !== 'function') {
                                throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
                            }
                            await window.__seoExecuteHeavyArticleAction('sync', null);
                        } catch (error) {
                            window.__seoEndArticleHeavyActionClient?.();
                            if (typeof FilamentNotification !== 'undefined') {
                                new FilamentNotification()
                                    .title('Không đồng bộ được WordPress')
                                    .body(error?.message ?? 'Đồng bộ thất bại.')
                                    .danger()
                                    .send();
                            }
                        }
                    })();
                @endif
            } else if (action === 'preview') {
                const url = @js($this->getArticlePreviewUrl());
                if (url) {
                    window.open(url, '_blank', 'noopener');
                }
            } else if (action === 'toggle-seo') {
                window.dispatchEvent(new CustomEvent('google-serp-preview-open-edit'));
            }
        "
        x-on:seo-rename-attachment-slugs.window="$wire.renameAttachmentSlugsOnWordPress($event.detail.items ?? [], !!($event.detail.silent ?? false))"
        x-on:seo-update-attachment-meta.window="$wire.updateAttachmentMetaOnWordPress($event.detail.items ?? [], !!($event.detail.silent ?? false))"
        x-on:save-article-faqs.window="$wire.saveArticleFaqs($event.detail.faqs ?? [])"
        x-on:dismiss-faq-extract-debug.window="$wire.clearFaqExtractDebug()"
        x-on:extract-article-faqs-with-context.window="$wire.extractFaqsFromSelection($event.detail.html ?? '', $event.detail.articleHtml ?? '')"
        x-on:renew-article-faq.window="$wire.renewArticleFaq($event.detail.index, $event.detail.question, $event.detail.answer)"
        x-on:preview-generate-article-image-prompt.window="
            $wire.previewGenerateArticleImagePrompt(
                $event.detail.userBrief ?? '',
                $event.detail.target ?? 'editor',
                $event.detail.loaiSanPhamCategoryArticleId ?? 0,
                $event.detail.loaiSanPhamCustom ?? ''
            ).then((result) => {
                window.dispatchEvent(new CustomEvent('article-generate-image-prompt-preview', { detail: result ?? {} }));
            });
        "
        x-on:generate-article-video.window="$wire.generateArticleVideoFromEditor($event.detail.selectionText ?? '', $event.detail.selectionHtml ?? '', $event.detail.userBrief ?? '', $event.detail.activeBlockId ?? '')"
        x-on:check-faq-question.window="
            $wire.checkFaqQuestionDuplicate($event.detail.question, $event.detail.faqId).then((result) => {
                window.dispatchEvent(new CustomEvent('faq-duplicate-checked', {
                    detail: {
                        index: $event.detail.index,
                        duplicate: result?.duplicate ?? false,
                        duplicate_scope: result?.duplicate_scope ?? null,
                    },
                }));
            });
        "
        class="wp-article-edit seo-article-edit-content max-w-none"
    >
        <div wire:ignore id="seo-article-ai-launcher-root"></div>

        @if ($this->hasWpDataOutOfSync())
            <div
                class="mb-4 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-600 dark:bg-danger-950/40 dark:text-danger-200"
                role="alert"
            >
                Dữ liệu không đồng bộ, vui lòng xem lại.
            </div>
        @endif

        <div
            class="seo-article-edit-back seo-article-editor-sticky-header"
            data-seo-sticky-editor-header
            data-article-editor-ui-revision="sticky-help-v1"
        >
            <div class="seo-article-editor-sticky-header__left">
                <a
                    href="{{ \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('index') }}"
                    class="seo-article-edit-back-link seo-article-edit-back-link--icon"
                    title="{{ __('seo-content-ai::filament.article_list.back_to_articles') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.back_to_articles') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </a>
                <span
                    class="seo-article-editor-sticky-header__save-status"
                    data-seo-sticky-save-status
                    data-status="saved"
                    aria-live="polite"
                ></span>
                <button
                    type="button"
                    class="seo-article-editor-sticky-header__draft-alert"
                    data-seo-sticky-draft-alert
                    hidden
                    title="Có nháp chưa lưu — bấm để chọn lại"
                    aria-label="Có nháp chưa lưu — bấm để chọn lại"
                >
                    !
                </button>
            </div>
            <div class="seo-article-editor-sticky-header__right">
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-shortcuts-slot')
            </div>
        </div>

        <div
            class="seo-article-editor-network-banner"
            data-seo-network-banner
            data-status="available"
            role="status"
            aria-live="polite"
            hidden
        >
            <span data-seo-network-banner-text>Mất kết nối mạng — các thay đổi hiện chưa được lưu.</span>
        </div>

        <div class="wp-article-edit-layout">
            {{-- Cột chính (giống WP post editor) --}}
            <div class="wp-article-edit-main space-y-4">
                <div class="wp-postbox">
                    <div class="wp-postbox-title-toolbar">
                        <input
                            type="text"
                            wire:model.blur="articleTitle"
                            placeholder="Thêm tiêu đề bài viết"
                            class="wp-title-input"
                        />
                    </div>

                    <div
                        class="wp-permalink mt-3 flex flex-wrap items-baseline gap-x-1 gap-y-1 text-sm text-gray-600 dark:text-gray-400"
                        data-seo-permalink-root
                        data-permalink-base="{{ rtrim($this->getPermalinkBase(), '/') }}"
                        data-permalink-suffix="{{ $this->getPermalinkSuffix() }}"
                        data-article-slug="{{ trim($this->articleSlug) }}"
                    >
                        <span class="font-medium text-gray-700 dark:text-gray-300">Đường dẫn:</span>
                        @php($displayPermalink = trim($this->getDisplayPermalink()))
                        @if($displayPermalink !== '' && (int) ($record->wordpressLink?->wp_post_id ?? 0) > 0)
                            <a
                                href="{{ $displayPermalink }}"
                                target="_blank"
                                rel="noopener"
                                data-seo-permalink-url
                                class="text-sky-600 dark:text-sky-400 hover:underline break-all"
                            >{{ $displayPermalink }}</a>
                        @else
                            <span
                                data-seo-permalink-url
                                class="break-all text-gray-500 dark:text-gray-400"
                                title="URL dự kiến, chưa tồn tại trên WordPress"
                            >{{ $displayPermalink !== '' ? $displayPermalink : (trim($this->getPermalinkBase()) !== '' ? rtrim($this->getPermalinkBase(), '/') . '/' . $this->getDisplaySlug() : '#') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Phase 2: single core bootstrap only (identity + content + endpoints + minimal settings). --}}
                <script type="application/json" id="seo-article-core-bootstrap">@json($this->getEditorCoreBootstrap())</script>
                <script>
                    window.__SEO_I18N_LOCALE__ = @js(app()->getLocale());
                    window.__SEO_ARTICLES_LIST_URL__ = @js(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('index'));
                    window.__SEO_ARTICLES_SYNC_QUEUE_URL__ = @js(\Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('index').'?tab=queue');
                    {{-- Minimal picker config — no focus-keyword DB resolve, no i18n bulk from server beyond static keys in JS fallback --}}
                    window.__SEO_ARTICLE_MEDIA_PICKER__ = @json($this->getArticleMediaPickerMinimalPayload());
                    window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ = @js($this->getId());
                    window.__SEO_ARTICLE_EDITOR_PERF_DEBUG__ = @js((bool) config('seo-content-ai.article_editor_perf_debug', false));
                </script>

                @if ($this->hasAiHistoryPendingBanner())
                    @php($aiBannerTarget = (string) ($this->aiHistoryPendingBanner['target'] ?? ''))
                    @php($aiBannerRun = $this->aiHistoryPendingBanner['run_id'] ?? '-')
                    @php($aiBannerAttempt = $this->aiHistoryPendingBanner['attempt'] ?? '-')
                    @php($aiHistoryUrl = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('prompts', ['record' => $record]))
                    <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-50">
                        <p class="font-medium">
                            @if ($aiBannerTarget === 'outline')
                                {{ __('seo-content-ai::filament.article_ai_history.banner_outline', ['run' => $aiBannerRun, 'attempt' => $aiBannerAttempt]) }}
                            @else
                                {{ __('seo-content-ai::filament.article_ai_history.banner_content', ['run' => $aiBannerRun, 'attempt' => $aiBannerAttempt]) }}
                            @endif
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-3 py-1.5 text-xs"
                                wire:click="undoAiHistoryPendingApply"
                                wire:loading.attr="disabled"
                                wire:target="undoAiHistoryPendingApply"
                            >
                                {{ __('seo-content-ai::filament.article_ai_history.banner_undo') }}
                            </button>
                            <a
                                href="{{ $aiHistoryUrl }}"
                                target="_blank"
                                rel="noopener"
                                class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-3 py-1.5 text-xs"
                            >
                                {{ __('seo-content-ai::filament.article_ai_history.banner_view_source') }}
                            </a>
                            <button
                                type="button"
                                class="fi-btn fi-btn-color-primary fi-btn-size-sm rounded-lg px-3 py-1.5 text-xs"
                                x-on:click="document.querySelector('[data-seo-page-action=save]')?.click()"
                            >
                                {{ __('seo-content-ai::filament.article_ai_history.banner_save') }}
                            </button>
                        </div>
                    </div>
                @endif

                <div wire:ignore id="seo-article-editor-root" class="w-full seo-article-editor-compact min-w-0"></div>

                <button
                    type="button"
                    id="seo-faq-debug-dismiss-wire"
                    class="hidden"
                    wire:click="clearFaqExtractDebug"
                    wire:loading.attr="disabled"
                    tabindex="-1"
                    aria-hidden="true"
                ></button>

                <div wire:ignore id="seo-article-faq-root" class="w-full mt-4"></div>
            </div>

            {{-- Cột phải: preview + sidebar giữa + xuất bản --}}
            <aside
                class="wp-article-edit-sidebar"
                x-data="{ aiChatOpen: false, syncOpen: false }"
                x-on:seo-article-ai-chat-open.window="aiChatOpen = true"
                x-on:seo-article-ai-chat-close.window="aiChatOpen = false"
                x-on:seo-assistant-open-publishing.window="aiChatOpen = false; syncOpen = true"
                x-on:seo-sidebar-open-publish-tab.window="aiChatOpen = false; syncOpen = true"
                x-on:seo-publish-tab-request-sync.window="aiChatOpen = false; syncOpen = true; $nextTick(() => window.__seoPublishTabRequestSync?.())"
            >
                <div class="wp-article-edit-rail">
                    <div x-show="!aiChatOpen" x-cloak class="wp-article-edit-rail-top">
                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.seo-polylang-widget')
                    </div>

                    <div
                        class="wp-article-edit-rail-center"
                        x-bind:class="{ 'is-chat': aiChatOpen }"
                    >
                        <div class="wp-article-edit-sidebar-window">
                            <div
                                x-show="!aiChatOpen"
                                x-cloak
                                class="wp-article-edit-sidebar-scroll seo-assistant-host seo-assistant-sidebar space-y-3"
                                x-data="seoAssistantNavigator(@js([
                                    'postType' => \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::normalizePostType($this->articlePostType),
                                    'supportsProductGallery' => $this->supportsProductGallery(),
                                ]))"
                                x-init="initWorkspace()"
                                x-bind:class="{ 'is-panel-filter': panelFilterActive }"
                            >
                                {{-- Phase 6C.1: React runtime owns dock chips/search/health. Publishing/Article = shell boundary items inside React nav (not registry). --}}
                                <div
                                    wire:ignore
                                    id="article-editor-sidebar-navigation-root"
                                    class="seo-assistant-dock-react-root"
                                ></div>
                                <div
                                    wire:ignore
                                    id="article-editor-sidebar-panel-root"
                                    class="seo-assistant-sidebar-panel-root sr-only"
                                    aria-hidden="true"
                                ></div>

                                <div class="seo-assistant-widget-layer space-y-3">
                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-panel-root="seo"
                                        x-show="isWidgetVisible('seo')"
                                        x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'seo' }"
                                    >
                                        <div wire:ignore id="seo-article-seo-assistant-root"></div>
                                    </div>

                                                                                {{-- Phase 6C.3: Featured/Gallery UI owned by React runtime (Shared Media Picker). --}}
                                        <div
                                            class="seo-assistant-panel-slot"
                                            data-assistant-panel-root="featured"
                                            x-show="isWidgetVisible('featured')"
                                            x-cloak
                                            x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'featured' }"
                                        >
                                            <div wire:ignore id="seo-article-featured-root"></div>
                                        </div>

<div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-panel-root="images"
                                        x-show="isWidgetVisible('images')"
                                        x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'images' }"
                                    >
                                <div wire:ignore id="seo-article-image-assistant-root"></div>
                                    </div>

                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-panel-root="reviews"
                                        x-show="supportsProductGalleryUi && isWidgetVisible('reviews')"
                                        x-cloak
                                        x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'reviews' }"
                                    >
                                <div wire:ignore id="seo-article-reviews-assistant-root"></div>
                                    </div>

                                    <div
                                        class="seo-assistant-panel-slot"
                                        data-assistant-panel-root="links"
                                        x-show="isWidgetVisible('links')"
                                        x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'links' }"
                                    >
                                        <div wire:ignore id="seo-article-links-root"></div>
                                    </div>

                                            <div
                                                class="seo-assistant-panel-slot"
                                                data-assistant-panel-root="publishing"
                                                data-assistant-shell-boundary="publishing"
                                                x-show="isWidgetVisible('publishing')"
                                                x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'publishing' }"
                                            >
                                                <section
                                                    id="seo-publishing-assistant"
                                                    class="seo-assistant-widget seo-assistant-widget--publishing seo-assistant-widget--static"
                                                >
                                                    <header class="seo-assistant-widget__header seo-assistant-widget__header--static">
                                                        <div class="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                                                            <svg class="seo-assistant-widget__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 16V4m0 12-4-4m4 4 4-4M4 20h16"/></svg>
                                                            <span class="seo-assistant-widget__title">Publishing Assistant</span>
                                                        </div>
                                                    </header>
                                                    <div class="seo-assistant-widget__body space-y-3">
                                                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-categories')
                                                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-sync-panel')
                                                    </div>
                                                </section>
                                            </div>

                                            <div
                                                class="seo-assistant-panel-slot"
                                                data-assistant-panel-root="article"
                                                data-assistant-shell-boundary="article"
                                                x-show="isWidgetVisible('article')"
                                                x-bind:class="{ 'is-active': panelFilterActive && runtimeActivePanel === 'article' }"
                                            >
                                                <section class="seo-assistant-widget seo-assistant-widget--article-info seo-assistant-widget--static">
                                                    <header class="seo-assistant-widget__header seo-assistant-widget__header--static">
                                                        <div class="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                                                            <svg class="seo-assistant-widget__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
                                                            <span class="seo-assistant-widget__title">Article Information</span>
                                                        </div>
                                                    </header>
                                                    <div class="seo-assistant-widget__body space-y-3">
                                                        @include('seo-content-ai::filament.resources.article-resource.pages.partials.publish-sidebar')
                                                    </div>
                                                </section>
                                            </div>
                                </div>
                            </div>

                            <div
                                x-show="aiChatOpen"
                                x-cloak
                                wire:ignore
                                id="seo-article-ai-chat-root"
                                class="wp-sidebar-ai-chat wp-article-edit-sidebar-scroll wp-article-edit-sidebar-scroll--chat"
                            ></div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        {{-- Phase 6C.3: Shared Media Picker portal (React). Alpine modal removed. --}}
        <div wire:ignore id="article-editor-media-picker-root" data-editor-portal="media.picker"></div>


    </div>

    @push('scripts')
        @viteReactRefresh
        @vite('addons/content/resources/js/article-editor.jsx')
    @endpush

    @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-assign-content-project-modals', ['record' => $record])
@endif
</x-filament-panels::page>
