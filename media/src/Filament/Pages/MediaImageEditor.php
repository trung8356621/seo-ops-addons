<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Filament\Pages;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoMediaImageEditorResolverService;
use Omnichannel\Addons\Media\Services\SeoWpMediaEditedPendingService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class MediaImageEditor extends Page
{
    protected static ?string $slug = 'media-image-editor';

    protected static ?string $title = 'Chỉnh sửa ảnh';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.media-image-editor';

    protected static string $layout = 'seo-content-ai::filament.layouts.tool-fullscreen';

    #[Url]
    public ?int $media = null;

    /** Tab ban đầu: eraser (mặc định) hoặc splitter */
    #[Url]
    public ?string $tab = null;

    public int $imageId = 0;

    public string $imageUrl = '';

    public bool $pendingWpSync = false;

    public int $wpAttachmentId = 0;

    public int $siteId = 0;

    public int $articleId = 0;

    public bool $canDeleteOriginal = true;

    public function mount(): void
    {
        abort_unless($this->media !== null && $this->media > 0, 404);

        $seoMedia = SeoMedia::query()->findOrFail($this->media);
        abort_unless($this->canAccessMedia($seoMedia), 403);

        $this->imageId = (int) $seoMedia->id;
        $this->wpAttachmentId = (int) ($seoMedia->wp_attachment_id ?? 0);
        $this->siteId = (int) ($seoMedia->site_id ?? 0);
        $this->articleId = $seoMedia->firstArticleId() ?? 0;

        $pendingService = app(SeoWpMediaEditedPendingService::class);
        $siteId = (int) ($seoMedia->site_id ?? 0);
        $pending = $this->wpAttachmentId > 0 && $siteId > 0
            ? $pendingService->findForAttachment($siteId, $this->wpAttachmentId)
            : null;

        $this->imageUrl = $seoMedia->publicUrl();
        if ($pending?->edited_at !== null) {
            $this->imageUrl .= '?t='.$pending->edited_at->timestamp;
        }

        $this->pendingWpSync = $pendingService->hasPendingEdit($siteId, $this->wpAttachmentId);
        $this->canDeleteOriginal = SeoAccessControl::canDeleteSeoMedia();

        if ($this->articleId > 0) {
            $linkedArticle = SeoArticle::query()->find($this->articleId);
            if ($linkedArticle instanceof SeoArticle && (string) ($linkedArticle->type ?? '') === 'product') {
                $this->canDeleteOriginal = false;
            }
        }
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): ?string
    {
        return null;
    }

    public static function urlForMedia(int $mediaId, ?string $tab = null): string
    {
        return SeoMediaImageEditorResolverService::editorUrl($mediaId, $tab);
    }

    private function canAccessMedia(SeoMedia $media): bool
    {
        $articleId = $media->firstArticleId();
        if ($articleId !== null) {
            $article = SeoArticle::query()->find($articleId);

            return $article !== null && SeoAccessControl::canAccessArticle($article);
        }

        if ($media->site_id !== null) {
            return SeoAccessControl::canAccessSite((int) $media->site_id);
        }

        return auth()->check();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessContentFeatures();
    }
}
