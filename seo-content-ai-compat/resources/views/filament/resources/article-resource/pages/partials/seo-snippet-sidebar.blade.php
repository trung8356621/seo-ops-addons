@php
    $serp = $this->getGoogleSerpPreview();
    $serpType = (string) ($serp['type'] ?? 'article');
    $serpMeta = is_array($serp['meta'] ?? null) ? $serp['meta'] : [];
    $previewTitle = (string) ($serp['title'] ?? '');
    $previewDescription = (string) ($serp['description'] ?? '');
    $displayUrl = (string) ($serp['display_url'] ?? '');
    $isProductPreview = $serpType === 'product';
    $ratingValue = isset($serpMeta['rating_value']) ? (float) $serpMeta['rating_value'] : null;
    $reviewCount = isset($serpMeta['review_count']) ? (int) $serpMeta['review_count'] : null;
    $priceDisplay = (string) ($serpMeta['price'] ?? '');
    $availabilityLabel = (string) ($serpMeta['availability_label'] ?? '');
    $fallbackUrl = $this->getPermalinkBase() !== '' ? $this->getPermalinkBase() . '/sample-post' : '#';
@endphp

<div
    class="wp-seo-sidebar-sticky"
    x-data="{ seoPreviewDevice: 'desktop' }"
    wire:key="google-serp-preview-{{ md5($seoTitle . '|' . $seoMetaDescription . '|' . $articleSlug . '|' . $this->getVirtualCommentsCount()) }}"
>
    <div class="wp-postbox wp-seo-preview-box">
        <div class="wp-postbox-header">
            <h2>Xem trước Google</h2>
            <div class="wp-seo-preview-devices" role="group" aria-label="Chế độ xem trước">
                <button
                    type="button"
                    class="wp-seo-preview-device-btn"
                    x-bind:class="{ 'is-active': seoPreviewDevice === 'desktop' }"
                    x-on:click="seoPreviewDevice = 'desktop'"
                    title="Desktop"
                    aria-label="Xem trước desktop"
                    x-bind:aria-pressed="seoPreviewDevice === 'desktop'"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <rect x="2" y="3" width="20" height="14" rx="2" stroke-width="1.75" />
                        <path d="M8 21h8M12 17v4" stroke-width="1.75" stroke-linecap="round" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="wp-seo-preview-device-btn"
                    x-bind:class="{ 'is-active': seoPreviewDevice === 'mobile' }"
                    x-on:click="seoPreviewDevice = 'mobile'"
                    title="Mobile"
                    aria-label="Xem trước mobile"
                    x-bind:aria-pressed="seoPreviewDevice === 'mobile'"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <rect x="7" y="2" width="10" height="20" rx="2" stroke-width="1.75" />
                        <circle cx="12" cy="18" r="0.75" fill="currentColor" stroke="none" />
                    </svg>
                </button>
            </div>
        </div>
        <div class="wp-postbox-inside wp-seo-preview-box__inside">
            <div
                class="wp-seo-snippet"
                x-bind:class="seoPreviewDevice === 'mobile' ? 'wp-seo-snippet--mobile' : 'wp-seo-snippet--desktop'"
            >
                <p class="wp-seo-snippet__title line-clamp-1">
                    {{ $previewTitle !== '' ? $previewTitle : 'Tiêu đề SEO sẽ hiển thị ở đây' }}
                </p>

                <p class="wp-seo-snippet__url line-clamp-1">
                    {{ $displayUrl !== '' ? $displayUrl : $fallbackUrl }}
                </p>

                @if ($isProductPreview && ($ratingValue !== null || $priceDisplay !== '' || $availabilityLabel !== ''))
                    <div class="wp-seo-snippet__rich">
                        @if ($ratingValue !== null)
                            <span class="wp-seo-snippet__stars" aria-hidden="true">
                                @for ($star = 1; $star <= 5; $star++)
                                    @php
                                        $filled = $ratingValue >= $star - 0.25;
                                    @endphp
                                    <svg
                                        class="wp-seo-snippet__star {{ $filled ? 'is-filled' : '' }}"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                @endfor
                            </span>
                            @if ($reviewCount !== null && $reviewCount > 0)
                                <span class="wp-seo-snippet__reviews">{{ number_format($reviewCount) }} reviews</span>
                            @endif
                        @endif

                        @if ($priceDisplay !== '')
                            <span class="wp-seo-snippet__price">{{ $priceDisplay }}</span>
                        @endif

                        @if ($availabilityLabel !== '')
                            <span class="wp-seo-snippet__availability">· {{ $availabilityLabel }}</span>
                        @endif
                    </div>
                @endif

                <p class="wp-seo-snippet__desc line-clamp-2">
                    {{ $previewDescription !== '' ? $previewDescription : 'Mô tả meta sẽ hiển thị tại đây.' }}
                </p>
            </div>
        </div>
    </div>
</div>
