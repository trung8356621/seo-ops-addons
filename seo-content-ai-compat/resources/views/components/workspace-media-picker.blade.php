@php
    use Omnichannel\Addons\Seo\Support\SeoAccessControl;

    $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
    $pickerConfig = [
        'siteId' => $siteId,
        'endpoint' => route('seo.media.workspace-picker'),
        'wordPressLinked' => true,
    ];
@endphp

@vite('addons/media/resources/js/article-media-picker-cache-bootstrap.js')

<div
    wire:ignore
    x-data="seoWorkspaceMediaPicker(@js($pickerConfig))"
    x-on:keydown.escape.window="open && closePicker()"
>
    <div
        x-show="open"
        x-cloak
        class="seo-article-media-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="seo-workspace-media-modal-title"
    >
        <button
            type="button"
            class="seo-article-media-modal__backdrop"
            x-on:click="closePicker()"
            aria-label="Đóng"
        ></button>
        <div class="seo-article-media-modal__panel">
            <div class="seo-article-media-modal__header">
                <h2 id="seo-workspace-media-modal-title" class="seo-article-media-modal__title">
                    Chọn image/video từ thư viện
                </h2>
                <button type="button" class="seo-article-media-modal__close" x-on:click="closePicker()" aria-label="Đóng">
                    ×
                </button>
            </div>

            <div class="seo-article-media-modal__tabs">
                <button
                    type="button"
                    class="seo-article-media-modal__tab"
                    x-bind:class="{ 'is-active': pickerTab === 'original' }"
                    x-on:click="switchPickerTab('original')"
                >
                    Gốc (WP)
                </button>
                <button
                    type="button"
                    class="seo-article-media-modal__tab"
                    x-bind:class="{ 'is-active': pickerTab === 'local' }"
                    x-on:click="switchPickerTab('local')"
                >
                    Nội bộ (Laravel)
                </button>
            </div>

            <div class="seo-article-media-modal__toolbar">
                <div class="seo-article-media-modal__search-wrap">
                    <input
                        type="search"
                        x-model="pickerSearchQuery"
                        x-on:keydown.enter.prevent="applyPickerSearch()"
                        class="seo-article-media-modal__search"
                        x-bind:placeholder="pickerSearchPlaceholder()"
                        autocomplete="off"
                        x-on:keydown.escape="closePicker()"
                    />
                    <span
                        x-show="pickerSearching"
                        x-cloak
                        class="seo-article-media-modal__search-spinner"
                        aria-hidden="true"
                    ></span>
                </div>
                <button
                    type="button"
                    class="seo-article-media-modal__reload"
                    x-on:click="applyPickerSearch()"
                    x-bind:disabled="pickerLoading || pickerSearching"
                    title="Nhấn Enter để tìm"
                    aria-label="Tìm"
                >
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="seo-article-media-modal__reload"
                    x-on:click="reloadPickerImages()"
                    x-bind:disabled="pickerLoading"
                    title="Tải lại thư viện"
                    aria-label="Tải lại thư viện"
                >
                    <span x-show="!pickerLoading">
                        <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                    </span>
                    <span x-show="pickerLoading" x-cloak class="seo-article-media-modal__button-spinner"></span>
                </button>
            </div>

            <p x-show="pickerError" x-cloak class="seo-article-media-modal__error" x-text="pickerError"></p>

            <div class="seo-article-media-modal__body">
                <div
                    x-show="pickerLoading && pickerImages.length === 0"
                    x-cloak
                    class="seo-article-media-modal__skeleton-grid"
                    aria-busy="true"
                    aria-label="Đang tải thư viện ảnh"
                >
                    @for ($i = 0; $i < 12; $i++)
                        <div class="seo-article-media-modal__skeleton"></div>
                    @endfor
                </div>

                <div
                    class="seo-article-media-modal__results"
                    x-show="!pickerLoading || pickerImages.length > 0"
                    x-cloak
                    x-bind:class="{ 'is-busy': pickerSearching || (pickerLoading && pickerImages.length > 0) }"
                >
                    <div
                        x-show="pickerSearching || (pickerLoading && pickerImages.length > 0)"
                        x-cloak
                        class="seo-article-media-modal__overlay"
                        aria-busy="true"
                        aria-live="polite"
                    >
                        <div class="seo-article-media-modal__skeleton-grid">
                            @for ($i = 0; $i < 12; $i++)
                                <div class="seo-article-media-modal__skeleton"></div>
                            @endfor
                        </div>
                        <p class="seo-article-media-modal__overlay-label">Đang tìm ảnh…</p>
                    </div>

                    <p
                        x-show="!pickerSearching && !pickerLoading && pickerImages.length === 0 && !pickerError"
                        x-cloak
                        class="seo-article-media-modal__empty"
                    >
                        Không có media phù hợp.
                    </p>

                    <div class="seo-article-media-modal__grid" x-show="pickerImages.length > 0">
                        <template x-for="image in pickerImages" x-bind:key="image.picker_key">
                            <button
                                type="button"
                                class="seo-article-media-modal__item"
                                x-on:click="selectPickerImage(image)"
                                x-bind:data-picker-key="image.picker_key"
                                x-bind:data-picker-url="image.url"
                                x-bind:data-picker-alt="image.alt || ''"
                                x-bind:data-picker-slug="image.slug || ''"
                                x-bind:data-picker-wp="image.wp_attachment_id || 0"
                                x-bind:data-picker-seo="image.seo_media_id || 0"
                                x-bind:data-picker-media-type="image.media_type || 'image'"
                            >
                                <template x-if="image.media_type === 'video'">
                                    <span class="seo-article-media-modal__thumb seo-article-media-modal__thumb--video" aria-hidden="true">▶</span>
                                </template>
                                <template x-if="image.media_type !== 'video'">
                                    <img
                                        class="seo-article-media-modal__thumb"
                                        x-bind:src="image.thumb_url || image.url"
                                        x-bind:alt="image.alt || image.slug || ''"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </template>
                                <span class="seo-article-media-modal__slug" x-text="image.slug || image.alt || '—'"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="seo-article-media-modal__pagination" x-show="pickerTotalPages > 1" x-cloak>
                <button
                    type="button"
                    class="seo-article-media-modal__page-btn"
                    x-on:click="pickerPrevPage()"
                    x-bind:disabled="pickerLoading || pickerPage <= 1"
                >
                    Trước
                </button>
                <span x-text="`${pickerPage} / ${pickerTotalPages}`"></span>
                <button
                    type="button"
                    class="seo-article-media-modal__page-btn"
                    x-on:click="pickerNextPage()"
                    x-bind:disabled="pickerLoading || pickerPage >= pickerTotalPages"
                >
                    Sau
                </button>
            </div>
        </div>
    </div>
</div>
