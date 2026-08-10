@php
    $isContentManager = \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager();
    $internalPreviewUrl = trim((string) ($this->getArticlePreviewUrl() ?? ''));
    $wpPreviewUrl = (int) ($record->wordpressLink?->wp_post_id ?? 0) > 0 ? trim((string) $this->getArticlePermalink()) : '';
    $hasWpPreview = $wpPreviewUrl !== '';
    $hasInternalPreview = $internalPreviewUrl !== '';
    $reviewBootstrap = $this->getArticleReviewBootstrap();
    $reviewActionEndpoint = route('seo.articles.review-actions.store', ['article' => $record->getKey()]);
    $reviewBadgeMap = [
        'draft' => __('seo-content-ai::filament.article_review.badge.draft'),
        'pending_review' => __('seo-content-ai::filament.article_review.badge.pending_review'),
        'approved' => __('seo-content-ai::filament.article_review.badge.approved'),
        'archived' => __('seo-content-ai::filament.article_review.badge.archived'),
    ];
    $reviewToggleLabels = [
        'complete' => __('seo-content-ai::filament.article_review.toggle.complete'),
        'completed' => __('seo-content-ai::filament.article_review.toggle.completed'),
        'approve' => __('seo-content-ai::filament.article_review.toggle.approve'),
        'approved' => __('seo-content-ai::filament.article_review.toggle.approved'),
    ];
    $reviewPageActionsConfig = [
        'reviewStatus' => $reviewBootstrap['review_status'],
        'reviewBadgeMap' => $reviewBadgeMap,
        'reviewToggleLabels' => $reviewToggleLabels,
        'reviewActions' => $reviewBootstrap['available_actions'],
        'reviewLatest' => $reviewBootstrap['latest_review'],
        'reviewEndpoint' => $reviewActionEndpoint,
        'reviewGenericError' => __('seo-content-ai::filament.article_review.errors.invalid_transition'),
    ];
    $inContentProject = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::articleIsInContentProject($record);
    $wpSyncEligibility = app(\Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncEligibility::class)
        ->evaluate($record);
    $contentProjectWpSyncEligible = $inContentProject && ($wpSyncEligibility['allowed'] ?? false);
    $isContentArchived = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::articleIsContentArchived($record);
    $contentProjectUrl = $inContentProject
        ? \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::articleContentProjectUrl($record)
        : null;
    $saveLabel = __('seo-content-ai::filament.article_list.page_action_save_label');
    $saveCloseLabel = __('seo-content-ai::filament.article_list.page_action_save_close_label');
    $saveCloseTitle = __('seo-content-ai::filament.article_list.page_action_save_close_help');
    $syncLabel = __('seo-content-ai::filament.article_list.page_action_sync_label');
    $syncTitle = __('seo-content-ai::filament.article_list.sync_to_wordpress');
    $previewLabel = __('seo-content-ai::filament.article_list.page_action_preview_label');
    $historyUrl = route('seo.articles.revisions.compare', ['article' => $record->getKey()]);
    $promptsUrl = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('prompts', ['record' => $record]);
@endphp

@once
    <script>
        window.seoArticleEditorPageActions = function seoArticleEditorPageActions(config) {
            return {
                moreOpen: false,
                previewOpen: false,
                reviewMenuOpen: false,
                reviewStatus: config.reviewStatus ?? 'draft',
                reviewBadgeMap: config.reviewBadgeMap ?? {},
                reviewToggleLabels: config.reviewToggleLabels ?? {},
                reviewActions: Array.isArray(config.reviewActions) ? config.reviewActions : [],
                reviewLatest: config.reviewLatest ?? null,
                reviewEndpoint: config.reviewEndpoint ?? '',
                reviewLoading: false,
                reviewModalOpen: false,
                reviewModalAction: null,
                reviewNote: '',
                reviewNoteMax: 5000,
                reviewGenericError: config.reviewGenericError ?? '',
                editorWritable: false,
                editorSessionStatus: 'acquiring',
                editorLockReason: null,
                editorNetworkAvailable: true,

                init() {
                    const applyState = (detail) => {
                        const payload = detail && typeof detail === 'object' ? detail : {};
                        this.editorWritable = Boolean(payload.writable);
                        this.editorSessionStatus = String(payload.status || 'read_only');
                        this.editorLockReason = payload.reason_code || null;
                    };

                    if (window.__SEO_EDITOR_SESSION_STATE__) {
                        applyState(window.__SEO_EDITOR_SESSION_STATE__);
                    }

                    window.addEventListener('article-editor-session-state-changed', (event) => {
                        applyState(event.detail || {});
                    });

                    const applyNetwork = (detail) => {
                        const payload = detail && typeof detail === 'object' ? detail : {};
                        // Sync WP only when fully available (not unavailable / recovering).
                        this.editorNetworkAvailable = String(payload.status || '') === 'available';
                    };
                    if (window.__SEO_EDITOR_NETWORK_STATUS__) {
                        applyNetwork(window.__SEO_EDITOR_NETWORK_STATUS__);
                    }
                    window.addEventListener('article-editor:network-status', (event) => {
                        applyNetwork(event.detail || {});
                    });
                },

                canMutateDocument() {
                    return this.editorWritable === true;
                },

                canSyncDocument() {
                    return this.canMutateDocument() && this.editorNetworkAvailable === true;
                },

                notifyReadOnly() {
                    window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Chỉ đọc',
                            body: this.editorLockReason
                                ? ('Phiên không writable: ' + this.editorLockReason)
                                : 'Bài viết đang ở chế độ chỉ đọc.',
                            status: 'warning',
                        },
                    }));
                },

                reviewPrimaryAction() {
                    return this.reviewActions[0] ?? null;
                },

                // "Toggle reverse" actions (reopen/unapprove) hiển thị như một nút trạng thái
                // đã bấm (is-active) thay vì split-button hành động tiến tới.
                reviewIsToggleAction() {
                    const type = this.reviewPrimaryAction()?.type;

                    return type === 'reopen' || type === 'unapprove';
                },

                reviewToggleLabel() {
                    const action = this.reviewPrimaryAction();
                    if (! action) {
                        return '';
                    }

                    if (action.type === 'reopen') {
                        return this.reviewToggleLabels.completed || action.quick_label || '';
                    }

                    if (action.type === 'unapprove') {
                        return this.reviewToggleLabels.approved || action.quick_label || '';
                    }

                    return action.quick_label || '';
                },

                reviewBadgeLabelText() {
                    return this.reviewBadgeMap[this.reviewStatus] ?? this.reviewStatus;
                },

                reviewLatestTooltip() {
                    if (! this.reviewLatest) {
                        return '';
                    }

                    const parts = [];
                    if (this.reviewLatest.reviewer_name) {
                        parts.push(this.reviewLatest.reviewer_name);
                    }
                    if (this.reviewLatest.created_at) {
                        parts.push(new Date(this.reviewLatest.created_at).toLocaleString());
                    }
                    if (this.reviewLatest.note) {
                        parts.push(this.reviewLatest.note);
                    }

                    return parts.join(' · ');
                },

                openReviewModal(action) {
                    if (! action) {
                        return;
                    }

                    this.reviewModalAction = action;
                    this.reviewNote = '';
                    this.reviewModalOpen = true;
                    this.moreOpen = false;
                    this.previewOpen = false;
                    this.reviewMenuOpen = false;
                },

                closeReviewModal() {
                    if (this.reviewLoading) {
                        return;
                    }

                    this.reviewModalOpen = false;
                    this.reviewModalAction = null;
                    this.reviewNote = '';
                },

                reviewCsrfToken() {
                    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                },

                notifyReview(title, status = 'success') {
                    if (typeof window.FilamentNotification === 'undefined') {
                        return;
                    }

                    const toast = new window.FilamentNotification().title(title || '');
                    if (status === 'danger') {
                        toast.danger();
                    } else if (status === 'warning') {
                        toast.warning();
                    } else {
                        toast.success();
                    }
                    toast.send();
                },

                submitReviewAction(actionType, note) {
                    if (this.reviewLoading || ! actionType) {
                        return;
                    }

                    const trimmedNote = typeof note === 'string' ? note.trim() : null;

                    this.reviewLoading = true;

                    fetch(this.reviewEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.reviewCsrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            action: actionType,
                            note: trimmedNote ? trimmedNote : null,
                        }),
                    })
                        .then(async (response) => {
                            let payload = null;
                            try {
                                payload = await response.json();
                            } catch (error) {
                                payload = null;
                            }

                            if (! response.ok || ! payload || payload.success === false) {
                                this.notifyReview(payload?.message || this.reviewGenericError, 'danger');

                                return;
                            }

                            this.reviewStatus = payload.data.review_status;
                            this.reviewActions = payload.data.available_actions;
                            this.reviewLatest = payload.data.latest_review;
                            this.reviewModalOpen = false;
                            this.reviewModalAction = null;
                            this.reviewNote = '';

                            if (payload.message) {
                                this.notifyReview(payload.message, 'success');
                            }

                            if (this.$wire && typeof this.$wire.$refresh === 'function') {
                                this.$wire.$refresh();
                            }
                        })
                        .catch(() => {
                            this.notifyReview(this.reviewGenericError, 'danger');
                        })
                        .finally(() => {
                            this.reviewLoading = false;
                        });
                },
            };
        };
    </script>
@endonce

{{-- Top bar: Save → Sync → Preview(split) → Duyệt bài | More --}}
<div
    class="seo-editor-page-actions"
    data-seo-page-actions-slot
    wire:ignore.self
    x-data="seoArticleEditorPageActions(@js($reviewPageActionsConfig))"
    x-bind:class="{ 'is-more-open': moreOpen }"
    x-on:click.outside="moreOpen = false; previewOpen = false; reviewMenuOpen = false"
    x-on:keydown.escape.window="moreOpen = false; previewOpen = false; reviewMenuOpen = false"
>
    <div class="seo-editor-page-actions__group seo-editor-page-actions__group--primary" data-seo-page-actions-primary>
        <button
            type="button"
            class="seo-editor-toolbar-btn seo-editor-toolbar-btn--primary seo-editor-toolbar-btn--labeled"
            title="{{ __('seo-content-ai::filament.article_list.page_action_save') }}"
            aria-label="{{ __('seo-content-ai::filament.article_list.page_action_save') }}"
            x-bind:disabled="!canMutateDocument()"
            x-bind:title="canMutateDocument() ? '{{ __('seo-content-ai::filament.article_list.page_action_save') }}' : 'Chỉ đọc — phiên chỉnh sửa không writable'"
            x-on:click="if (!canMutateDocument()) { notifyReadOnly(); return; } window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'save' } }))"
            data-seo-page-action="save"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-8H7v8M7 3v5h8" />
            </svg>
            <span class="seo-editor-toolbar-btn__label">{{ $saveLabel }}</span>
        </button>

        @if (! $isContentManager)
            @if ($inContentProject && $contentProjectWpSyncEligible)
                {{-- CP update-existing: Published or rewrite/improve with wp_post_id; never create. --}}
                <button
                    type="button"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--accent seo-editor-toolbar-btn--labeled"
                    title="{{ $syncTitle }}"
                    aria-label="{{ $syncTitle }}"
                    data-seo-page-action="sync"
                    data-seo-sync-mode="{{ $wpSyncEligibility['mode'] ?? 'post_publish_wordpress_sync' }}"
                    x-bind:disabled="!canSyncDocument()"
                    x-on:click="if (!canSyncDocument()) { if (!canMutateDocument()) { notifyReadOnly(); } return; } window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'sync' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1.1 15.9-3.3-9.7h2l1.5 5.1 1.5-5.1h1.9l1.5 5.1 1.5-5.1h2l-3.3 9.7h-1.9l-1.5-4.9-1.5 4.9h-1.9z"/>
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $syncLabel }}</span>
                </button>
                <button
                    type="button"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled"
                    title="{{ $saveCloseTitle }}"
                    aria-label="{{ $saveCloseTitle }}"
                    data-seo-page-action="save-close"
                    data-seo-content-project-url="{{ $contentProjectUrl ?? '' }}"
                    x-bind:disabled="!canMutateDocument()"
                    x-on:click="if (!canMutateDocument()) { notifyReadOnly(); return; } window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'save-close' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-8H7v8M7 3v5h8" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $saveCloseLabel }}</span>
                </button>
            @elseif ($inContentProject)
                {{-- Content Project chưa Published: không hiện Manual Sync WP. Save & Close = lưu nội dung + về project. --}}
                <button
                    type="button"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--accent seo-editor-toolbar-btn--labeled"
                    title="{{ $saveCloseTitle }}"
                    aria-label="{{ $saveCloseTitle }}"
                    data-seo-page-action="save-close"
                    data-seo-content-project-url="{{ $contentProjectUrl ?? '' }}"
                    x-bind:disabled="!canMutateDocument()"
                    x-on:click="if (!canMutateDocument()) { notifyReadOnly(); return; } window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'save-close' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-8H7v8M7 3v5h8" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $saveCloseLabel }}</span>
                </button>
            @else
                <button
                    type="button"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--accent seo-editor-toolbar-btn--labeled"
                    title="{{ $syncTitle }}"
                    aria-label="{{ $syncTitle }}"
                    data-seo-page-action="sync"
                    data-seo-sync-mode="wordpress_sync"
                    x-bind:disabled="!editorNetworkAvailable"
                    x-on:click="if (!editorNetworkAvailable) { return; } window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'sync' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1.1 15.9-3.3-9.7h2l1.5 5.1 1.5-5.1h1.9l1.5 5.1 1.5-5.1h2l-3.3 9.7h-1.9l-1.5-4.9-1.5 4.9h-1.9z"/>
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $syncLabel }}</span>
                </button>
            @endif
        @endif

        <div class="seo-editor-preview-split seo-editor-page-actions__desktop-only" data-seo-page-action="preview">
            @if ($hasWpPreview)
                <a
                    href="{{ $wpPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $previewLabel }}</span>
                </a>
            @elseif ($hasInternalPreview)
                <a
                    href="{{ $internalPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_preview_internal') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview_internal') }}"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $previewLabel }}</span>
                </a>
            @else
                <button
                    type="button"
                    class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                    title="{{ __('seo-content-ai::filament.article_list.page_action_preview') }}"
                    aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview') }}"
                    x-on:click="window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'preview' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span class="seo-editor-toolbar-btn__label">{{ $previewLabel }}</span>
                </button>
            @endif

            <button
                type="button"
                class="seo-editor-toolbar-btn seo-editor-preview-split__chevron"
                title="{{ __('seo-content-ai::filament.article_list.page_action_preview_menu') }}"
                aria-label="{{ __('seo-content-ai::filament.article_list.page_action_preview_menu') }}"
                x-bind:aria-expanded="previewOpen"
                x-on:click.stop="previewOpen = !previewOpen; moreOpen = false; reviewMenuOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                </svg>
            </button>

            <div
                class="seo-editor-preview-split__menu"
                x-show="previewOpen"
                x-cloak
                role="menu"
            >
                @if ($hasWpPreview)
                    <a
                        href="{{ $wpPreviewUrl }}"
                        target="_blank"
                        rel="noopener"
                        role="menuitem"
                        class="seo-editor-menu-item"
                        x-on:click="previewOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                        <span>{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}</span>
                    </a>
                @else
                    <span class="seo-editor-menu-item is-disabled" role="menuitem" aria-disabled="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                        <span>{{ __('seo-content-ai::filament.article_list.page_action_preview_wp') }}</span>
                    </span>
                @endif

                @if ($hasInternalPreview)
                    <a
                        href="{{ $internalPreviewUrl }}"
                        target="_blank"
                        rel="noopener"
                        role="menuitem"
                        class="seo-editor-menu-item"
                        x-on:click="previewOpen = false"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <span>{{ __('seo-content-ai::filament.article_list.page_action_preview_internal') }}</span>
                    </a>
                @endif
            </div>
        </div>

        <div class="seo-editor-review-cluster seo-editor-page-actions__desktop-only" data-seo-page-action="review">
            {{-- Toggle reverse (reopen/unapprove): 1 nút trạng thái đã bấm (is-active), chevron mở dropdown "kèm ghi chú" tuỳ chọn --}}
            <template x-if="reviewPrimaryAction() && reviewIsToggleAction()">
                <div
                    class="seo-editor-preview-split"
                    x-bind:class="{ 'is-open': reviewMenuOpen, 'is-disabled': reviewLoading }"
                >
                    <button
                        type="button"
                        class="seo-editor-toolbar-btn seo-editor-toolbar-btn--success seo-editor-toolbar-btn--labeled is-active seo-editor-preview-split__main"
                        x-bind:disabled="reviewLoading"
                        x-on:click="submitReviewAction(reviewPrimaryAction()?.type, null)"
                        x-bind:title="reviewPrimaryAction()?.label"
                        x-bind:aria-label="reviewToggleLabel()"
                        data-seo-page-action="review-toggle"
                    >
                        <span class="seo-editor-toolbar-btn__inner" x-show="! reviewLoading">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span class="seo-editor-toolbar-btn__label" x-text="reviewToggleLabel()"></span>
                        </span>
                        <span class="seo-editor-toolbar-btn__spinner" x-show="reviewLoading" aria-hidden="true"></span>
                    </button>
                    <button
                        type="button"
                        class="seo-editor-toolbar-btn seo-editor-toolbar-btn--success is-active seo-editor-preview-split__chevron"
                        x-bind:disabled="reviewLoading"
                        x-bind:aria-expanded="reviewMenuOpen"
                        x-on:click.stop="reviewMenuOpen = ! reviewMenuOpen; moreOpen = false; previewOpen = false"
                        x-bind:aria-label="reviewPrimaryAction()?.note_label"
                        x-bind:title="reviewPrimaryAction()?.note_label"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div class="seo-editor-preview-split__menu" x-show="reviewMenuOpen" x-cloak role="menu">
                        <button
                            type="button"
                            role="menuitem"
                            class="seo-editor-menu-item"
                            x-on:click="reviewMenuOpen = false; openReviewModal(reviewPrimaryAction())"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487 18.549 2.8a2.121 2.121 0 0 1 3 3l-1.687 1.688m-3-3L6.75 15.7l-.375 3.375 3.375-.375L18.862 7.487m-3-3 3 3M6.75 19.5H4.5a2.25 2.25 0 0 1-2.25-2.25v-11A2.25 2.25 0 0 1 4.5 4h5.25" />
                            </svg>
                            <span x-text="reviewPrimaryAction()?.note_label"></span>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Split button "tiến tới" (submit_review / approve / archive): main = quick, chevron mở dropdown kèm ghi chú --}}
            <template x-if="reviewPrimaryAction() && ! reviewIsToggleAction()">
                <div
                    class="seo-editor-preview-split"
                    x-bind:class="{ 'is-open': reviewMenuOpen, 'is-disabled': reviewLoading }"
                >
                    <button
                        type="button"
                        class="seo-editor-toolbar-btn seo-editor-toolbar-btn--success seo-editor-toolbar-btn--labeled seo-editor-preview-split__main"
                        x-bind:disabled="reviewLoading"
                        x-on:click="submitReviewAction(reviewPrimaryAction()?.type, null)"
                        x-bind:title="reviewPrimaryAction()?.label"
                        x-bind:aria-label="reviewPrimaryAction()?.quick_label"
                    >
                        <span class="seo-editor-toolbar-btn__inner" x-show="! reviewLoading">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span class="seo-editor-toolbar-btn__label" x-text="reviewPrimaryAction()?.quick_label"></span>
                        </span>
                        <span class="seo-editor-toolbar-btn__spinner" x-show="reviewLoading" aria-hidden="true"></span>
                    </button>
                    <button
                        type="button"
                        class="seo-editor-toolbar-btn seo-editor-preview-split__chevron"
                        x-bind:disabled="reviewLoading"
                        x-bind:aria-expanded="reviewMenuOpen"
                        x-on:click.stop="reviewMenuOpen = ! reviewMenuOpen; moreOpen = false; previewOpen = false"
                        x-bind:aria-label="reviewPrimaryAction()?.note_label"
                        x-bind:title="reviewPrimaryAction()?.note_label"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div class="seo-editor-preview-split__menu" x-show="reviewMenuOpen" x-cloak role="menu">
                        <button
                            type="button"
                            role="menuitem"
                            class="seo-editor-menu-item"
                            x-on:click="reviewMenuOpen = false; openReviewModal(reviewPrimaryAction())"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487 18.549 2.8a2.121 2.121 0 0 1 3 3l-1.687 1.688m-3-3L6.75 15.7l-.375 3.375 3.375-.375L18.862 7.487m-3-3 3 3M6.75 19.5H4.5a2.25 2.25 0 0 1-2.25-2.25v-11A2.25 2.25 0 0 1 4.5 4h5.25" />
                            </svg>
                            <span x-text="reviewPrimaryAction()?.note_label"></span>
                        </button>
                    </div>
                </div>
            </template>

            <template x-if="! reviewPrimaryAction() && reviewStatus !== 'draft'">
                <span
                    class="seo-editor-review-badge"
                    x-bind:data-status="reviewStatus"
                    x-bind:title="reviewLatestTooltip()"
                    data-seo-page-action="review-badge"
                >
                    <span x-text="reviewBadgeLabelText()"></span>
                </span>
            </template>
        </div>
    </div>

    <button
        type="button"
        class="seo-editor-toolbar-btn seo-editor-toolbar-btn--labeled"
        title="{{ __('seo-content-ai::filament.article_list.page_action_help') }}"
        aria-label="{{ __('seo-content-ai::filament.help.trigger_aria') }}"
        aria-haspopup="dialog"
        aria-controls="global-help-modal"
        data-seo-page-action="help"
        data-help-trigger
        x-on:click="
            moreOpen = false;
            previewOpen = false;
            reviewMenuOpen = false;
            if (window.Alpine?.store('help')) {
                Alpine.store('help').open({ trigger: $el });
            } else {
                window.dispatchEvent(new CustomEvent('seo-global-help:open', { detail: { trigger: $el } }));
            }
        "
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
        </svg>
        <span class="seo-editor-toolbar-btn__label">{{ __('seo-content-ai::filament.article_list.page_action_help') }}</span>
    </button>

    {{-- More: History, Prompts, Assign/Open project, Restore, Debug(JS), Delete --}}
    <div class="seo-editor-page-actions__more" data-seo-page-actions-more>
        <button
            type="button"
            class="seo-editor-toolbar-btn"
            title="{{ __('seo-content-ai::filament.article_list.page_action_more') }}"
            aria-label="{{ __('seo-content-ai::filament.article_list.page_action_more') }}"
            x-bind:aria-expanded="moreOpen"
            x-on:click="moreOpen = !moreOpen; previewOpen = false; reviewMenuOpen = false"
            data-seo-page-action="more"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
            </svg>
        </button>

        <div
            class="seo-editor-page-actions__more-panel"
            x-show="moreOpen"
            x-cloak
            role="menu"
            data-seo-page-actions-more-panel
        >
            {{-- Compact: Preview / Approve (tablet+) — cùng handler, chỉ hiện khi desktop primary ẩn --}}
            @if ($hasWpPreview)
                <a
                    href="{{ $wpPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    data-seo-page-action="preview"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>{{ $previewLabel }}</span>
                </a>
            @elseif ($hasInternalPreview)
                <a
                    href="{{ $internalPreviewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    data-seo-page-action="preview"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>{{ $previewLabel }}</span>
                </a>
            @else
                <button
                    type="button"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    data-seo-page-action="preview"
                    x-on:click="moreOpen = false; window.dispatchEvent(new CustomEvent('article-editor-shortcut', { detail: { action: 'preview' } }))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>{{ $previewLabel }}</span>
                </button>
            @endif

            <template x-if="reviewActions.length > 0">
                <button
                    type="button"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    x-bind:disabled="reviewLoading"
                    x-bind:title="reviewPrimaryAction()?.label"
                    data-seo-page-action="review"
                    x-on:click="moreOpen = false; submitReviewAction(reviewPrimaryAction()?.type, null)"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span x-text="reviewIsToggleAction() ? reviewToggleLabel() : reviewPrimaryAction()?.quick_label"></span>
                </button>
            </template>
            <template x-if="reviewActions.length > 0">
                <button
                    type="button"
                    class="seo-editor-menu-item seo-editor-page-actions__compact-only"
                    role="menuitem"
                    x-bind:disabled="reviewLoading"
                    x-bind:title="reviewPrimaryAction()?.note_label"
                    data-seo-page-action="review-with-note"
                    x-on:click="openReviewModal(reviewPrimaryAction())"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                    </svg>
                    <span x-text="reviewPrimaryAction()?.note_label"></span>
                </button>
            </template>
            <template x-if="reviewActions.length === 0 && reviewStatus && reviewStatus !== 'draft'">
                <div
                    class="seo-editor-menu-item is-disabled seo-editor-page-actions__compact-only"
                    role="menuitem"
                    aria-disabled="true"
                    x-bind:title="reviewLatestTooltip()"
                    data-seo-page-action="review-badge"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span x-text="reviewBadgeLabelText()"></span>
                </div>
            </template>

            <div class="seo-editor-menu-divider seo-editor-page-actions__compact-only" aria-hidden="true"></div>

            <a
                href="{{ $historyUrl }}"
                target="_blank"
                rel="noopener"
                class="seo-editor-menu-item"
                role="menuitem"
                data-seo-page-action="history"
                x-on:click="moreOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span>{{ __('seo-content-ai::filament.article_list.page_action_history') }}</span>
            </a>

            <a
                href="{{ $promptsUrl }}"
                target="_blank"
                rel="noopener"
                class="seo-editor-menu-item"
                role="menuitem"
                data-seo-page-action="prompts"
                x-on:click="moreOpen = false"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                </svg>
                <span>{{ __('seo-content-ai::filament.article_ai_history.menu') }}</span>
            </a>

            @if ($inContentProject && filled($contentProjectUrl))
                <a
                    href="{{ $contentProjectUrl }}"
                    class="seo-editor-menu-item"
                    role="menuitem"
                    data-seo-page-action="open-content-project"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.62-.627 1.498-.99 2.427-.99h11.646c.93 0 1.807.363 2.427.99m-16.5 0a2.25 2.25 0 0 0-.245.245l-1.26 1.49A2.25 2.25 0 0 0 3 13.186V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18v-4.814a2.25 2.25 0 0 0-.608-1.525l-1.26-1.49a2.25 2.25 0 0 0-.245-.245m-16.5 0V6.75A2.25 2.25 0 0 1 6 4.5h12A2.25 2.25 0 0 1 20.25 6.75v3.026" />
                    </svg>
                    <span>{{ __('seo-content-ai::filament.article_edit.open_content_project') }}</span>
                </a>
            @elseif (! $isContentArchived)
                <button
                    type="button"
                    class="seo-editor-menu-item"
                    role="menuitem"
                    data-seo-page-action="assign-content-project"
                    x-on:click="moreOpen = false; window.dispatchEvent(new CustomEvent('open-article-assign-content-project-modal'))"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                    </svg>
                    <span>{{ __('seo-content-ai::filament.article_list.assign_to_content_project') }}</span>
                </button>
            @endif

            @if (\Omnichannel\Addons\Seo\Support\SeoAccessControl::canAccessManagerFeatures())
                <button
                    type="button"
                    class="seo-editor-menu-item"
                    role="menuitem"
                    data-seo-page-action="editor-full-rewrite"
                    title="{{ __('seo-content-ai::filament.projects.type_rewrite_editor') }}"
                    aria-label="{{ __('seo-content-ai::filament.projects.type_rewrite_editor') }}"
                    wire:loading.attr="disabled"
                    wire:target="queueEditorFullRewrite"
                    wire:click="queueEditorFullRewrite"
                    x-on:click="moreOpen = false"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                    </svg>
                    <span wire:loading.remove wire:target="queueEditorFullRewrite">{{ __('seo-content-ai::filament.projects.type_rewrite_editor') }}</span>
                    <span wire:loading wire:target="queueEditorFullRewrite" class="inline-flex items-center gap-1">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        Đang viết lại…
                    </span>
                </button>
            @endif

            <div class="seo-editor-page-actions__group seo-editor-page-actions__group--secondary" data-seo-page-actions-secondary>
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-danger-actions', [
                    'record' => $record,
                    'renderDelete' => false,
                ])
            </div>

            <div class="seo-editor-menu-divider" aria-hidden="true"></div>

            <div class="seo-editor-page-actions__group seo-editor-page-actions__group--danger" data-seo-page-actions-danger>
                @include('seo-content-ai::filament.resources.article-resource.pages.partials.article-editor-danger-actions', [
                    'record' => $record,
                    'renderDelete' => true,
                    'renderRestore' => false,
                ])
            </div>
        </div>
    </div>

    {{-- Article Review note modal — teleport ra <body> để không bao giờ đẩy chiều cao toolbar;
         Alpine mở khung ngay (open=true) trước khi gọi fetch --}}
    <template x-teleport="body">
        <div
            class="seo-editor-review-modal"
            x-show="reviewModalOpen"
            x-cloak
            role="dialog"
            aria-modal="true"
            aria-labelledby="seo-article-review-modal-title"
        >
            <button
                type="button"
                class="seo-editor-review-modal__backdrop"
                x-on:click="closeReviewModal()"
                tabindex="-1"
                aria-hidden="true"
            ></button>

            <div class="seo-editor-review-modal__panel">
                <div class="seo-editor-review-modal__header">
                    <h2 id="seo-article-review-modal-title" class="seo-editor-review-modal__title" x-text="reviewModalAction?.note_modal_title"></h2>
                    <button
                        type="button"
                        class="seo-editor-review-modal__close"
                        x-on:click="closeReviewModal()"
                        aria-label="{{ __('seo-content-ai::filament.article_review.modal.cancel') }}"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="seo-editor-review-modal__body">
                    <textarea
                        x-model="reviewNote"
                        x-bind:maxlength="reviewNoteMax"
                        x-bind:disabled="reviewLoading"
                        rows="4"
                        placeholder="{{ __('seo-content-ai::filament.article_review.modal.note_placeholder') }}"
                        class="seo-editor-review-modal__textarea"
                    ></textarea>
                    <div class="seo-editor-review-modal__counter" x-text="`${reviewNote.length} / ${reviewNoteMax}`"></div>
                </div>

                <div class="seo-editor-review-modal__actions">
                    <button
                        type="button"
                        class="seo-editor-btn seo-editor-btn--ghost"
                        x-on:click="closeReviewModal()"
                        x-bind:disabled="reviewLoading"
                    >
                        {{ __('seo-content-ai::filament.article_review.modal.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="seo-editor-btn seo-editor-btn--primary"
                        x-bind:disabled="reviewLoading || reviewNote.trim().length < 3"
                        x-on:click="submitReviewAction(reviewModalAction?.type, reviewNote)"
                    >
                        <span x-show="! reviewLoading" x-text="reviewModalAction?.note_label"></span>
                        <span x-show="reviewLoading">…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
