@viteReactRefresh
@vite('addons/media/resources/js/media-image-editor-page.jsx')

<div
    id="seo-media-image-editor-root"
    data-image-url="{{ $imageUrl }}"
    data-image-id="{{ $imageId }}"
    data-seo-media-id="{{ $imageId }}"
    data-wp-attachment-id="{{ $wpAttachmentId }}"
    data-site-id="{{ $siteId }}"
    data-article-id="{{ $articleId }}"
    data-pending-wp-sync="{{ $pendingWpSync ? '1' : '0' }}"
    data-library-url="{{ \Omnichannel\Addons\Media\Filament\Pages\MediaLibrary::getUrl() }}"
    data-initial-tab="{{ $tab ?? '' }}"
    data-can-delete-original="{{ $canDeleteOriginal ? '1' : '0' }}"
></div>
