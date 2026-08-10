<x-filament-panels::page>
    @php
        $canDeleteMedia = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canDeleteSeoMedia();
    @endphp
    @vite([
        'addons/media/resources/css/media-library.css',
        'addons/media/resources/js/media-library-actions.js',
        'addons/media/resources/js/media-library-page.jsx',
    ])
    {{-- Inline override: rename modal above preview + actions/footer without waiting for vite rebuild. --}}
    <style>
        .seo-wp-rename-modal { z-index: 100100 !important; padding: 1rem; box-sizing: border-box; }
        .seo-wp-rename-modal__panel {
            display: flex !important;
            flex-direction: column !important;
            max-height: min(90vh, 760px) !important;
            overflow: hidden !important;
            padding: 0 !important;
        }
        .seo-wp-rename-modal__head { padding: 1rem 1.1rem .65rem; flex-shrink: 0; }
        .seo-wp-rename-modal__body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow: auto !important;
            padding: 0 1.1rem .5rem !important;
        }
        .seo-wp-rename-modal__actions {
            flex-shrink: 0 !important;
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: .55rem !important;
            padding: .85rem 1.1rem 1rem !important;
            border-top: 1px solid #e2e8f0 !important;
            background: #fff !important;
            margin-top: 0 !important;
        }
        .seo-wp-rename-modal__btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: .35rem !important;
            min-height: 2.35rem !important;
            padding: .55rem .9rem !important;
            border-radius: .5rem !important;
            border: 1px solid #cbd5e1 !important;
            font-size: .875rem !important;
            font-weight: 600 !important;
            line-height: 1.2 !important;
            cursor: pointer !important;
        }
        .seo-wp-rename-modal__btn.is-ghost { background: #fff !important; color: #334155 !important; }
        .seo-wp-rename-modal__btn.is-danger {
            background: #dc2626 !important;
            border-color: #b91c1c !important;
            color: #fff !important;
        }
        .seo-wp-rename-modal__btn.is-danger:disabled {
            background: #f87171 !important;
            border-color: #f87171 !important;
            color: #fff !important;
            opacity: 1 !important;
            cursor: not-allowed !important;
        }
        .seo-wp-rename-modal__block {
            margin: .55rem 0 0 !important;
            padding: .55rem .65rem !important;
            border-radius: .45rem !important;
            font-size: .82rem !important;
            line-height: 1.35 !important;
            background: #fff7ed !important;
            color: #9a3412 !important;
            border: 1px solid #fed7aa !important;
        }
        /* Legacy class on old bundle until rebuild */
        .seo-wp-rename-modal__actions button.is-danger {
            background: #dc2626 !important;
            border-color: #b91c1c !important;
            color: #fff !important;
            min-height: 2.35rem !important;
            padding: .55rem .9rem !important;
            border-radius: .5rem !important;
            font-weight: 600 !important;
        }
        .seo-wp-rename-modal__actions button.is-danger:disabled {
            background: #f87171 !important;
            opacity: 1 !important;
            cursor: not-allowed !important;
        }
    </style>

    <div
        class="seo-media-library"
        x-data="seoMediaLibraryActions"
        data-selection-scope="{{ $activeTab }}|{{ (int) ($siteId ?? 0) }}|{{ (int) $page }}|{{ (string) ($filterMonth ?? '') }}|{{ (string) ($filterSearch ?? '') }}"
    >
        <div class="seo-media-library-tabs-bar">
            <button
                type="button"
                wire:click="$set('activeTab', 'original')"
                class="seo-media-library-tab {{ $activeTab === 'original' ? 'is-active' : '' }}"
            >
                Original (WP)
            </button>
            <button
                type="button"
                wire:click="$set('activeTab', 'local')"
                class="seo-media-library-tab {{ in_array($activeTab, ['local', 'generated'], true) ? 'is-active' : '' }}"
            >
                Local (Laravel)
            </button>
        </div>

        @php
            $mediaToolsSiteParams = $siteId ? ['siteId' => (int) $siteId] : [];
            $canMediaTools = ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager();
            $canManagerMediaTools = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canAccessManagerFeatures();
        @endphp
        @if ($canManagerMediaTools || $canMediaTools)
            <div class="seo-media-library-tools mb-3 flex flex-wrap gap-2">
                @if ($canManagerMediaTools)
                    <a
                        href="{{ \Omnichannel\Addons\Media\Filament\Pages\WatermarkSettingsPage::getUrl($mediaToolsSiteParams) }}"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                    >
                        {{ __('seo-content-ai::filament.nav.watermark_batch') }}
                    </a>
                    <a
                        href="{{ \Omnichannel\Addons\Media\Filament\Pages\ImageOptimizationSettings::getUrl($mediaToolsSiteParams) }}"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                    >
                        {{ __('seo-content-ai::filament.nav.image_optimization') }}
                    </a>
                @endif
                @if ($canMediaTools)
                    <a
                        href="{{ \Omnichannel\Addons\Media\Filament\Pages\WatermarkEditor::getUrl($mediaToolsSiteParams) }}"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                    >
                        {{ __('seo-content-ai::filament.nav.watermark_designer') }}
                    </a>
                    <a
                        href="{{ \Omnichannel\Addons\Media\Filament\Pages\ImageProcessingPage::getUrl($mediaToolsSiteParams) }}"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                    >
                        {{ __('seo-content-ai::filament.nav.image_processing') }}
                    </a>
                @endif
            </div>
        @endif

        <div class="seo-media-library-filters-card">
            <input
                type="file"
                x-ref="localMediaUploadInput"
                class="seo-media-library-upload-input"
                accept="image/jpeg,image/png,image/gif,image/webp"
                multiple
                x-on:change="onLocalMediaUploadChange($event)"
            />
            <div class="seo-media-library-filters">
                @unless ($this->hasLockedGlobalSite())
                    <div class="seo-media-library-field">
                        <label class="seo-media-library-label" for="media-library-site">Domain</label>
                        <x-select id="media-library-site" wire:model.live="siteId" class="text-sm">
                            <option value="">-- Select domain --</option>
                            @foreach ($this->sites as $site)
                                <option value="{{ $site->id }}">{{ $site->domain }}</option>
                            @endforeach
                        </x-select>
                    </div>
                @else
                    <div class="seo-media-library-field">
                        <label class="seo-media-library-label">Domain</label>
                        <div class="seo-media-library-select">
                            {{ $this->currentSiteDomain() ?? ('Site #' . (int) ($siteId ?? 0)) }}
                        </div>
                    </div>
                @endunless

                <div class="seo-media-library-field seo-media-library-field-search">
                    <label class="seo-media-library-label" for="media-library-search">Search</label>
                    <div class="seo-media-library-search-row">
                        <input
                            id="media-library-search"
                            type="search"
                            wire:model.live.debounce.400ms="filterSearch"
                            class="seo-media-library-search"
                            placeholder="{{ $activeTab === 'original' ? 'Slug, alt, caption (WP search)...' : 'Slug, alt, title...' }}"
                            autocomplete="off"
                        />
                        @if (filled($filterSearch))
                            <button type="button" wire:click="clearSearchFilter" class="seo-media-library-clear-search">
                                Clear
                            </button>
                        @endif
                    </div>
                </div>

                <div class="seo-media-library-field">
                    <label class="seo-media-library-label" for="media-library-month">Published month (optional)</label>
                    <div class="seo-media-library-month-row">
                        <input
                            id="media-library-month"
                            type="month"
                            wire:model.live="filterMonth"
                            class="seo-media-library-month"
                            placeholder="mm/yyyy"
                        />
                        @if (filled($filterMonth))
                            <button type="button" wire:click="clearMonthFilter" class="seo-media-library-clear-month">
                                Clear month
                            </button>
                        @endif
                    </div>
                </div>

                <div
                    class="seo-media-library-field seo-media-library-field-upload"
                    x-show="['local', 'generated'].includes($wire.activeTab)"
                    x-cloak
                >
                    <label class="seo-media-library-label">{{ __('seo-content-ai::filament.media_tools.upload') }}</label>
                    <button
                        type="button"
                        class="seo-media-library-upload-btn"
                        x-on:click="openLocalMediaUploadPicker()"
                        x-bind:disabled="localMediaUploading || !$wire.siteId"
                        wire:loading.attr="disabled"
                        wire:target="loadImages"
                    >
                        <span x-show="!localMediaUploading">{{ __('seo-content-ai::filament.media_tools.upload') }}</span>
                        <span x-show="localMediaUploading" x-cloak>{{ __('seo-content-ai::filament.media_tools.uploading') }}</span>
                    </button>
                </div>
            </div>
        </div>

        @if ($siteId && ! empty($images))
            <div class="seo-media-library-resize-bar">
                <div class="seo-media-library-resize-bar__left">
                    <span class="seo-media-library-resize-bar__label">
                        {{ __('seo-content-ai::filament.media_tools.selected') }}:
                        <strong x-text="selectedCount">0</strong>
                    </span>
                    <button
                        type="button"
                        class="seo-media-library-resize-bar__link"
                        x-on:click="clearSelection()"
                        x-show="selectedCount > 0"
                        x-cloak
                    >
                        {{ __('seo-content-ai::filament.media_tools.clear_selection') }}
                    </button>
                </div>
                <div class="seo-media-library-resize-bar__controls">
                    <label class="seo-media-library-resize-field">
                        <span>{{ __('seo-content-ai::filament.media_tools.width') }}</span>
                        <input
                            type="number"
                            min="1"
                            wire:model="resizeWidth"
                            placeholder="px"
                            class="seo-media-library-resize-input"
                            wire:loading.attr="disabled"
                            wire:target="resizeSelectedImages"
                        />
                    </label>
                    <span class="seo-media-library-resize-times">×</span>
                    <label class="seo-media-library-resize-field">
                        <span>{{ __('seo-content-ai::filament.media_tools.height') }}</span>
                        <input
                            type="number"
                            min="1"
                            wire:model="resizeHeight"
                            placeholder="px"
                            class="seo-media-library-resize-input"
                            wire:loading.attr="disabled"
                            wire:target="resizeSelectedImages"
                        />
                    </label>
                    <button
                        type="button"
                        class="seo-media-library-resize-submit"
                        @click="runResizeSelected($wire)"
                        wire:loading.attr="disabled"
                        wire:target="resizeSelectedImagesFromClient,deleteSelectedImagesFromClient"
                        x-bind:disabled="selectedCount === 0"
                    >
                        <span wire:loading.remove wire:target="resizeSelectedImagesFromClient">{{ __('seo-content-ai::filament.media_tools.resize_images') }}</span>
                        <span wire:loading wire:target="resizeSelectedImagesFromClient">{{ __('seo-content-ai::filament.media_tools.resizing') }}</span>
                    </button>
                    <button
                        type="button"
                        class="seo-media-library-bar-icon-btn seo-media-library-bar-action-btn"
                        title="Download selected images"
                        aria-label="Download selected images"
                        @click="downloadSelected()"
                        x-bind:disabled="selectedCount === 0"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 1 0-1.09-1.03l-2.955 3.129V2.75Z"/>
                            <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/>
                        </svg>
                        <span>Download</span>
                    </button>
                    @if ($canDeleteMedia)
                    <button
                        type="button"
                        class="seo-media-library-bar-icon-btn seo-media-library-bar-action-btn is-danger"
                        title="Delete selected images"
                        aria-label="Delete selected images"
                        @click="if (selectedCount > 0 && window.confirm(`Delete ${selectedCount} selected images? This action cannot be undone.`)) { runDeleteSelected($wire) }"
                        wire:loading.attr="disabled"
                        wire:target="deleteSelectedImagesFromClient"
                        x-bind:disabled="selectedCount === 0"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 4.5a.75.75 0 1 0 1.5-.06l-.3-4.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 4.5a.75.75 0 1 0 1.5.06l.3-4.5Z" clip-rule="evenodd"/>
                        </svg>
                        <span wire:loading.remove wire:target="deleteSelectedImagesFromClient">Delete</span>
                        <span wire:loading wire:target="deleteSelectedImagesFromClient">…</span>
                    </button>
                    @endif
                </div>
                <p class="seo-media-library-resize-hint">
                    Click / Shift+click to select range.@if ($canDeleteMedia) "Download" / "Delete" applies to all selected images.@else "Download" applies to all selected images.@endif
                </p>
            </div>
        @endif

        @if ($activeTab !== 'watermark-config')
        <div class="seo-media-library-meta" wire:loading.remove wire:target="activeTab,siteId,filterMonth,filterSearch,page,loadImages,previousPage,nextPage,clearMonthFilter,clearSearchFilter,previewApplyWatermark,previewOptimize">
            @if ($total > 0)
                {{ $total }} images · Page {{ $page }}/{{ $totalPages }}
                @if (filled($filterSearch))
                    · Search: "{{ $filterSearch }}"
                @endif
                @if (filled($filterMonth))
                    · Month {{ $this->filterMonthLabel() }}
                @else
                    · All months
                @endif
                @if ($activeTab === 'original')
                    · WordPress Media API
                @elseif (in_array($activeTab, ['local', 'generated'], true))
                    · seo_media
                @endif
            @elseif ($siteId)
                No images{{ filled($filterMonth) ? ' in selected month' : '' }}.
            @endif
        </div>

        <div wire:loading wire:target="activeTab,siteId,filterMonth,page,loadImages,previousPage,nextPage,clearMonthFilter,previewApplyWatermark,previewOptimize" class="seo-media-library-meta">
            Loading images...
        </div>
        @endif

        @if ($loadError)
            <div class="seo-media-library-alert is-error">
                {{ $loadError }}
            </div>
        @elseif (in_array($activeTab, ['local', 'generated'], true))
            <div class="seo-media-library-alert">
                Click / Shift+click to select — double-click to preview/watermark. Download or delete from card actions or top toolbar.
            </div>
        @else
            <div class="seo-media-library-alert">
                Original (WP) tab. Shift+click selects range — delete only removes Laravel staging copy (if exists).
            </div>
        @endif

        @if (empty($images) && ! $loadError && $siteId)
            <div class="seo-media-library-empty">
                @if (filled($filterSearch))
                    No images match "{{ $filterSearch }}".
                @elseif (filled($filterMonth))
                    No images in month {{ $this->filterMonthLabel() }}.
                @else
                    No images for this domain.
                @endif
            </div>
        @elseif (! $siteId)
            <div class="seo-media-library-empty">
                Select a domain to view media library.
            </div>
        @elseif (! empty($images))
            <div class="seo-media-library-grid">
                @foreach ($images as $image)
                    @php
                        $itemKind = (string) ($image['kind'] ?? 'local');
                        $url = (string) ($image['url'] ?? '');
                        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                        $mediaTypeRaw = strtolower(trim((string) ($image['media_type'] ?? 'image')));
                        $videoExts = ['mp4', 'webm', 'mov', 'm4v', 'ogg', 'ogv', 'avi', 'mpeg', 'mpg'];
                        $mediaType = $mediaTypeRaw === 'video' || in_array($ext, $videoExts, true) ? 'video' : 'image';
                        $imageForPreview = $image;
                        $imageForPreview['media_type'] = $mediaType;
                        $editKey = $itemKind . '-' . $image['id'];
                        $wpAttachmentId = (int) ($image['wp_attachment_id'] ?? 0);
                        $seoMediaId = (int) ($image['seo_media_id'] ?? 0);
                        $articleId = (int) ($image['article_id'] ?? 0);
                        $articleEditUrl = $image['article_edit_url'] ?? null;
                    @endphp
                    @php
                        $downloadName = ($image['slug'] ?? 'image') . '.' . pathinfo(parse_url($image['url'] ?? '', PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
                        if (! str_contains($downloadName, '.')) {
                            $downloadName .= '.jpg';
                        }
                    @endphp
                    <article
                        class="seo-media-library-card"
                        x-bind:class="{ 'is-selected': isSelected(@js($editKey)) }"
                        wire:key="media-{{ $itemKind }}-{{ $image['id'] }}"
                        data-select-key="{{ $editKey }}"
                        data-image-url="{{ $image['url'] }}"
                        data-image-slug="{{ $image['slug'] ?? 'image' }}"
                        data-download-name="{{ $downloadName }}"
                    >
                        <div class="seo-media-library-thumb-wrap">
                            <button
                                type="button"
                                class="seo-media-library-thumb-btn"
                                title="Click select · Shift+click range · Double-click preview"
                                x-on:click="
                                    clearTimeout($el._selectTimer);
                                    $el._selectTimer = setTimeout(() => toggleCardSelection(@js($editKey), $event.shiftKey), 220);
                                "
                                x-on:dblclick.prevent="
                                    clearTimeout($el._selectTimer);
                                    $wire.openImagePreview(@js($imageForPreview));
                                "
                            >
                                @if ($mediaType === 'video')
                                    <span class="seo-media-library-thumb seo-media-library-thumb--video" aria-hidden="true">▶</span>
                                @else
                                    <img
                                        src="{{ $image['url'] }}"
                                        alt="{{ $image['alt'] ?: $image['slug'] }}"
                                        class="seo-media-library-thumb"
                                        loading="lazy"
                                        onerror="this.src='https://placehold.co/400x400?text=No+Image'"
                                    />
                                @endif
                            </button>
                            <div class="seo-media-library-card-actions">
                                <button
                                    type="button"
                                    class="seo-media-library-card-icon-btn"
                                    title="Download image"
                                    aria-label="Download image"
                                    @click.stop="downloadCard($el.closest('.seo-media-library-card'))"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 1 0-1.09-1.03l-2.955 3.129V2.75Z"/>
                                        <path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/>
                                    </svg>
                                </button>
                                @if ($canDeleteMedia)
                                <button
                                    type="button"
                                    class="seo-media-library-card-icon-btn is-danger"
                                    title="Delete image"
                                    aria-label="Delete image"
                                    @click.stop="deleteCard(@js($editKey), $wire)"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 4.5a.75.75 0 1 0 1.5-.06l-.3-4.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 4.5a.75.75 0 1 0 1.5.06l.3-4.5Z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                                @endif
                            </div>
                        </div>

                        <div class="seo-media-library-body">
                            <div class="seo-media-library-slug-wrap">
                                @if ($editingKey === $editKey)
                                    <div
                                        class="seo-media-library-slug-edit"
                                        x-data
                                        x-on:click.outside="$wire.cancelSlugEdit()"
                                    >
                                        <input
                                            type="text"
                                            wire:model="editingSlug"
                                            wire:keydown.enter="saveSlugEdit"
                                            wire:keydown.escape="cancelSlugEdit"
                                            class="seo-media-library-slug-input"
                                            x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                        />
                                        <button
                                            type="button"
                                            wire:click="saveSlugEdit"
                                            class="seo-media-library-slug-save-btn"
                                            title="Save slug"
                                            aria-label="Save slug"
                                        >
                                            <svg
                                                class="seo-media-library-slug-save-icon"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path fill-rule="evenodd" d="M14.279 2.152a1.126 1.126 0 0 1 1.516 1.52l.854.845-9.083 9.035-3.719-.47 1.016-3.77.854-.845 9.132-9.115Zm1.72 4.888-1.72-1.704-8.699 8.66-1.016 3.771 3.718-.47 8.717-8.657Z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>
                                @else
                                    <p
                                        class="seo-media-library-slug is-editable"
                                        title="Double-click to edit slug"
                                        wire:dblclick="beginSlugEdit(@js($editKey), @js($image['slug'] ?? ''), {{ (int) $image['id'] }}, @js($image['url'] ?? ''), {{ $wpAttachmentId }}, @js($itemKind), {{ $seoMediaId }})"
                                    >
                                        {{ $image['slug'] ?: '—' }}
                                    </p>
                                @endif
                            </div>
                            @if (filled($image['alt'] ?? ''))
                                <p class="seo-media-library-alt" title="{{ $image['alt'] }}">
                                    {{ $image['alt'] }}
                                </p>
                            @endif

                            <div class="seo-media-library-card-footer">
                                @if ($articleId > 0 && filled($articleEditUrl))
                                    <a
                                        href="{{ $articleEditUrl }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="seo-media-library-article-link"
                                        title="Open article in editor"
                                    >
                                        Article #{{ $articleId }}
                                    </a>
                                @else
                                    <span class="seo-media-library-article-muted">Not linked to article</span>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($totalPages > 1)
                <div class="seo-media-library-pagination">
                    <button
                        type="button"
                        class="seo-media-library-page-btn"
                        wire:click="previousPage"
                        @disabled($page <= 1)
                    >
                        Previous
                    </button>
                    <span class="seo-media-library-meta">{{ $page }} / {{ $totalPages }}</span>
                    <button
                        type="button"
                        class="seo-media-library-page-btn"
                        wire:click="nextPage"
                        @disabled($page >= $totalPages)
                    >
                        Next
                    </button>
                </div>
            @endif
        @endif
    </div>

    @if ($previewOpen && $previewImage)
        <div
            class="seo-media-preview-backdrop"
            wire:click="closeImagePreview"
            wire:keydown.escape.window="closeImagePreview"
        >
            @php
                $previewMediaType = strtolower(trim((string) ($previewImage['media_type'] ?? 'image'))) === 'video' ? 'video' : 'image';
            @endphp
            <div
                class="seo-media-preview-modal"
                role="dialog"
                aria-modal="true"
                aria-label="{{ $previewMediaType === 'video' ? 'Preview video' : 'Preview image' }}"
                x-on:click.stop
            >
                <div class="seo-media-preview-modal__head">
                    <div>
                        <h3 class="seo-media-preview-modal__title">{{ $previewImage['slug'] ?? ($previewMediaType === 'video' ? 'Video' : 'Image') }}</h3>
                        @if (! empty($previewImage['article_id']) && ! empty($previewImage['article_edit_url']))
                            <a
                                href="{{ $previewImage['article_edit_url'] }}"
                                class="seo-media-preview-modal__article"
                                target="_blank"
                                rel="noopener"
                            >
                                Article #{{ $previewImage['article_id'] }} -> Editor
                            </a>
                        @endif
                    </div>
                    <button type="button" class="seo-media-preview-modal__close" wire:click="closeImagePreview" aria-label="Close">
                        ×
                    </button>
                </div>

                <div class="seo-media-preview-modal__body">
                    @if ($previewMediaType === 'video')
                        <video
                            src="{{ $previewImage['url'] }}"
                            controls
                            preload="metadata"
                            class="seo-media-preview-modal__video"
                        ></video>
                    @else
                        <img
                            src="{{ $previewImage['url'] }}"
                            alt=""
                            class="seo-media-preview-modal__img"
                            wire:loading.class="is-loading"
                            wire:target="previewApplyWatermark,previewOptimize,previewRestore"
                        />
                    @endif
                </div>

                @if ($previewMessage)
                    <p class="seo-media-preview-modal__msg is-{{ $previewMessageType ?? 'info' }}">
                        {{ $previewMessage }}
                    </p>
                @endif

                <div class="seo-media-preview-modal__actions">
                    @php
                        $canEditImage = ($previewImage['kind'] ?? '') !== 'generated'
                            && (
                                (int) ($previewImage['seo_media_id'] ?? 0) > 0
                                || (int) ($previewImage['wp_attachment_id'] ?? 0) > 0
                                || ($previewImage['kind'] ?? '') === 'wordpress'
                            );
                    @endphp
                    @if ($canEditImage && $previewMediaType === 'image')
                        <button
                            type="button"
                            class="seo-media-preview-btn is-edit"
                            wire:click="openImageEditor"
                            wire:loading.attr="disabled"
                            wire:target="openImageEditor"
                        >
                            <span wire:loading.remove wire:target="openImageEditor">Edit image</span>
                            <span wire:loading wire:target="openImageEditor">Preparing...</span>
                        </button>
                    @endif
                    @php
                        $canRenameWp = ($previewMediaType === 'image')
                            && (
                                (int) ($previewImage['wp_attachment_id'] ?? 0) > 0
                                || ($previewImage['kind'] ?? '') === 'wordpress'
                            )
                            && app(\Omnichannel\Addons\WordPress\Services\WordPressMediaRenameService::class)->canRenameWordPressMedia();
                        $renameUrl = (string) ($previewImage['url'] ?? '');
                        $renamePath = (string) (parse_url($renameUrl, PHP_URL_PATH) ?: $renameUrl);
                        $renameSlug = (string) pathinfo($renamePath, PATHINFO_FILENAME);
                        if ($renameSlug === '') {
                            $renameSlug = (string) ($previewImage['slug'] ?? '');
                        }
                        $wpRenamePayload = [
                            'siteId' => (int) $siteId,
                            'attachmentId' => (int) ($previewImage['wp_attachment_id'] ?? $previewImage['id'] ?? 0),
                            'oldUrl' => $renameUrl,
                            'previewUrl' => $renameUrl,
                            'currentSlug' => $renameSlug,
                            'sourceAction' => 'media_library',
                        ];
                    @endphp
                    @if ($canRenameWp)
                        <button
                            type="button"
                            class="seo-media-preview-btn"
                            title="Đổi tên file và cập nhật các tham chiếu được phát hiện."
                            data-seo-wp-rename-b64="{{ base64_encode(json_encode($wpRenamePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}"
                            onclick="(function (el) {
                                try {
                                    var raw = el.getAttribute('data-seo-wp-rename-b64') || '';
                                    var detail = JSON.parse(atob(raw));
                                    window.dispatchEvent(new CustomEvent('seo-wordpress-media-rename-open', { detail: detail }));
                                } catch (err) {
                                    console.error('seo-wp-rename-open failed', err);
                                }
                            })(this)"
                        >
                            Đổi tên ảnh
                        </button>
                    @endif
                    @if ($previewCanSyncToWp && $previewMediaType === 'image')
                        <button
                            type="button"
                            class="seo-media-preview-btn is-sync-wp"
                            wire:click="previewSyncToWordPress"
                            wire:loading.attr="disabled"
                            wire:target="previewSyncToWordPress"
                        >
                            <span wire:loading.remove wire:target="previewSyncToWordPress">Sync to WordPress</span>
                            <span wire:loading wire:target="previewSyncToWordPress">Syncing...</span>
                        </button>
                    @endif
                    @if ($previewCanRestore && $previewMediaType === 'image')
                        <button
                            type="button"
                            class="seo-media-preview-btn is-restore"
                            wire:click="previewRestore"
                            wire:loading.attr="disabled"
                            wire:target="previewRestore"
                        >
                            <span wire:loading.remove wire:target="previewRestore">Restore original</span>
                            <span wire:loading wire:target="previewRestore">Restoring...</span>
                        </button>
                    @else
                        <button
                            type="button"
                            class="seo-media-preview-btn is-primary"
                            wire:click="previewApplyWatermark"
                            wire:loading.attr="disabled"
                            wire:target="previewApplyWatermark"
                            @if (($previewImage['kind'] ?? '') === 'generated') disabled @endif
                        >
                            <span wire:loading.remove wire:target="previewApplyWatermark">Apply watermark</span>
                            <span wire:loading wire:target="previewApplyWatermark">Processing...</span>
                        </button>
                    @endif
                    @php
                        $splitterSeoMediaId = (int) ($previewImage['seo_media_id'] ?? 0);
                        if ($splitterSeoMediaId <= 0 && (string) ($previewImage['kind'] ?? '') !== 'wordpress') {
                            $splitterSeoMediaId = (int) ($previewImage['id'] ?? 0);
                        }
                        $splitterUrl = $splitterSeoMediaId > 0
                            ? \Omnichannel\Addons\Media\Filament\Pages\MediaImageEditor::urlForMedia($splitterSeoMediaId, 'splitter')
                            : null;
                    @endphp
                    @if ($splitterUrl && $previewMediaType === 'image')
                        <a
                            href="{{ $splitterUrl }}"
                            class="seo-media-preview-btn"
                            target="_blank"
                            rel="noopener"
                        >
                            Split grid
                        </a>
                    @endif
                    @if ($previewCanOptimize && $previewMediaType === 'image')
                        <button
                            type="button"
                            class="seo-media-preview-btn"
                            wire:click="previewOptimize"
                            wire:loading.attr="disabled"
                            wire:target="previewOptimize"
                            @if (($previewImage['kind'] ?? '') === 'generated') disabled @endif
                        >
                            <span wire:loading.remove wire:target="previewOptimize">Optimize image</span>
                            <span wire:loading wire:target="previewOptimize">Optimizing...</span>
                        </button>
                    @endif
                </div>
                @if ($previewProcessingStatus && $previewProcessingStatus !== 'original')
                    <p class="seo-media-preview-modal__hint">
                        Status:
                        @if ($previewProcessingStatus === 'watermarked') watermarked
                        @elseif ($previewProcessingStatus === 'optimized') optimized
                        @elseif ($previewProcessingStatus === 'restored') restored original
                        @elseif ($previewProcessingStatus === 'edited_pending') edited (not synced to WordPress)
                        @endif
                    </p>
                @endif
                @if ($previewCanSyncToWp)
                    <p class="seo-media-preview-modal__hint">
                        Image has been edited and saved on server (not on WordPress yet) - click "Sync to WordPress" to update. After sync, staging copy will be removed.
                    </p>
                @endif
                @if (($previewImage['kind'] ?? '') === 'generated')
                    <p class="seo-media-preview-modal__hint">AI-generated image: preview only - save to local library to process.</p>
                @endif
            </div>
        </div>
    @endif

</x-filament-panels::page>
