@php
    $renderRestore = $renderRestore ?? true;
    $renderDelete = $renderDelete ?? true;
    $canRestoreFromWordPress = $renderRestore
        && ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager()
        && (int) ($record->wordpressLink?->wp_post_id ?? 0) > 0;
    $canDeleteArticle = $renderDelete
        && \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::canDelete($record);
    $dangerActionLabels = [
        'restore' => __('seo-content-ai::filament.article_list.fetch_from_wordpress'),
        'restore_heading' => __('seo-content-ai::filament.article_list.fetch_from_wordpress_heading'),
        'restore_description' => __('seo-content-ai::filament.article_list.fetch_from_wordpress_description'),
        'restore_submit' => __('seo-content-ai::filament.article_list.fetch_from_wordpress_submit'),
        'restore_progress' => __('seo-content-ai::filament.article_list.fetch_from_wordpress_progress'),
        'delete' => __('seo-content-ai::filament.article_list.delete_article'),
        'delete_heading' => __('seo-content-ai::filament.article_list.delete_heading'),
        'delete_description' => __('seo-content-ai::filament.article_list.delete_description'),
        'delete_submit' => __('seo-content-ai::filament.article_list.delete_submit'),
        'cancel' => __('seo-content-ai::filament.article_list.delete_cancel'),
    ];
@endphp

@once
    <script>
        window.__seoResolveEditArticleWire = function resolveEditArticleWire(wireId) {
            const resolvedId = String(wireId ?? window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim();
            if (resolvedId === '' || typeof Livewire === 'undefined') {
                return null;
            }

            return Livewire.find(resolvedId);
        };

        window.seoArticleRestoreAction = function seoArticleRestoreAction(config) {
            const pageLocked = () => Boolean(window.__seoArticleHeavyActionOverlay?.locked);

            return {
                canRestore: Boolean(config.canRestore),
                labels: config.labels ?? {},
                restoreModalOpen: false,
                restoreBusy: false,

                openRestoreModal() {
                    if (!this.canRestore || this.restoreBusy || pageLocked()) {
                        return;
                    }

                    this.restoreModalOpen = true;
                },

                closeRestoreModal() {
                    if (this.restoreBusy) {
                        return;
                    }

                    this.restoreModalOpen = false;
                },

                async confirmRestore() {
                    if (!this.canRestore || this.restoreBusy) {
                        return;
                    }

                    const wire = window.__seoResolveEditArticleWire?.(config.wireId);
                    if (!wire?.call) {
                        return;
                    }

                    this.restoreModalOpen = false;
                    this.restoreBusy = true;
                    window.__seoBeginArticleHeavyActionClient?.('restore');
                    window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                        this.labels.restore_progress ?? 'Đang lấy nội dung từ WordPress…',
                    );

                    try {
                        const ok = await wire.call('syncArticleFromWordPress');
                        if (!ok) {
                            window.__seoEndArticleHeavyActionClient?.();
                        }
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                    } finally {
                        this.restoreBusy = false;
                    }
                },
            };
        };

        window.seoArticleDeleteAction = function seoArticleDeleteAction(config) {
            const pageLocked = () => Boolean(window.__seoArticleHeavyActionOverlay?.locked);

            return {
                labels: config.labels ?? {},
                deleteModalOpen: false,
                deleteBusy: false,

                openDeleteModal() {
                    if (this.deleteBusy || pageLocked()) {
                        return;
                    }

                    this.deleteModalOpen = true;
                },

                closeDeleteModal() {
                    if (this.deleteBusy) {
                        return;
                    }

                    this.deleteModalOpen = false;
                },

                async confirmDelete() {
                    if (this.deleteBusy) {
                        return;
                    }

                    const wire = window.__seoResolveEditArticleWire?.(config.wireId);
                    if (!wire?.call) {
                        return;
                    }

                    this.deleteModalOpen = false;
                    this.deleteBusy = true;
                    window.__seoArticleHeavyActionOverlay?.show('delete', { persistUntilUnload: true });
                    window.__seoArticleAutosaveLock?.set('article-heavy-action', true);

                    try {
                        await wire.call('delete');
                    } catch (error) {
                        window.__seoEndArticleHeavyActionClient?.();
                        this.deleteBusy = false;
                    }
                },
            };
        };
    </script>
@endonce

@if ($canRestoreFromWordPress)
    <div
        class="seo-editor-restore-action inline-flex"
        data-seo-restore-action-wrap
        wire:ignore
        x-data="seoArticleRestoreAction(@js([
            'wireId' => $this->getId(),
            'canRestore' => true,
            'labels' => $dangerActionLabels,
        ]))"
    >
        <button
            type="button"
            data-seo-restore-wp-btn
            class="seo-editor-menu-item"
            x-bind:disabled="restoreBusy"
            x-on:click="openRestoreModal()"
            x-bind:title="labels.restore"
            x-bind:aria-label="labels.restore"
            role="menuitem"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12M12 16.5V3" />
            </svg>
            <span x-text="labels.restore"></span>
        </button>

        <template x-teleport="body">
            <div
                x-show="restoreModalOpen"
                x-cloak
                class="seo-article-confirm-modal"
                role="dialog"
                aria-modal="true"
                x-bind:aria-label="labels.restore_heading"
                x-on:keydown.escape.window="closeRestoreModal()"
            >
                <button type="button" class="seo-article-confirm-modal__backdrop" x-on:click="closeRestoreModal()" tabindex="-1" aria-hidden="true"></button>
                <div class="seo-article-confirm-modal__panel">
                    <h2 class="seo-article-confirm-modal__title" x-text="labels.restore_heading"></h2>
                    <p class="seo-article-confirm-modal__description" x-text="labels.restore_description"></p>
                    <div class="seo-article-confirm-modal__actions">
                        <button type="button" class="seo-article-confirm-modal__btn seo-article-confirm-modal__btn--ghost" x-on:click="closeRestoreModal()" x-text="labels.cancel"></button>
                        <button type="button" class="seo-article-confirm-modal__btn seo-article-confirm-modal__btn--primary" x-on:click="confirmRestore()" x-text="labels.restore_submit"></button>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif

@if ($canDeleteArticle)
    <div
        class="seo-editor-delete-action inline-flex"
        data-seo-delete-action-wrap
        wire:ignore
        x-data="seoArticleDeleteAction(@js([
            'wireId' => $this->getId(),
            'labels' => $dangerActionLabels,
        ]))"
    >
        <button
            type="button"
            data-seo-delete-article-btn
            class="seo-editor-menu-item seo-editor-menu-item--danger"
            x-bind:disabled="deleteBusy"
            x-on:click="openDeleteModal()"
            x-bind:title="labels.delete"
            x-bind:aria-label="labels.delete"
            role="menuitem"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
            </svg>
            <span x-text="labels.delete"></span>
        </button>

        <template x-teleport="body">
            <div
                x-show="deleteModalOpen"
                x-cloak
                class="seo-article-confirm-modal"
                role="dialog"
                aria-modal="true"
                x-bind:aria-label="labels.delete_heading"
                x-on:keydown.escape.window="closeDeleteModal()"
            >
                <button type="button" class="seo-article-confirm-modal__backdrop" x-on:click="closeDeleteModal()" tabindex="-1" aria-hidden="true"></button>
                <div class="seo-article-confirm-modal__panel">
                    <h2 class="seo-article-confirm-modal__title" x-text="labels.delete_heading"></h2>
                    <p class="seo-article-confirm-modal__description" x-text="labels.delete_description"></p>
                    <div class="seo-article-confirm-modal__actions">
                        <button type="button" class="seo-article-confirm-modal__btn seo-article-confirm-modal__btn--ghost" x-on:click="closeDeleteModal()" x-text="labels.cancel"></button>
                        <button type="button" class="seo-article-confirm-modal__btn seo-article-confirm-modal__btn--danger" x-on:click="confirmDelete()" x-text="labels.delete_submit"></button>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
