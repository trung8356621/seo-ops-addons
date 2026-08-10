<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * User-facing Featured/Gallery mutations — persist immediately, return canonical snapshot.
 */
final class ArticleEditorMediaMutationService
{
    public function __construct(
        private readonly ArticleMediaLocalService $mediaLocal,
        private readonly ArticleEditorMediaSnapshotService $snapshots,
        private readonly ArticleEditorSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function setFeatured(
        SeoArticle $article,
        User $user,
        array $item,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion = null,
    ): array {
        $this->assertWritable($article, $user, $editorSessionId, $expectedSnapshotVersion);

        if ($this->supportsProductGallery($article)) {
            throw ArticleEditorSessionException::make(
                'featured_managed_by_gallery',
                'Product posts use the first gallery item as featured.',
                [],
                422,
            );
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            throw ArticleEditorSessionException::make(
                'featured_url_required',
                'Featured image URL is required.',
                [],
                422,
            );
        }

        $refId = $this->resolveRefId($article, $item, $url);
        if ($refId <= 0) {
            throw ArticleEditorSessionException::make(
                'featured_media_id_required',
                'Featured media identity is required. Chọn ảnh từ tab Local/WordPress (có ID), không dùng ảnh trong bài thiếu identity.',
                [],
                422,
            );
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($article, $refId, $url): array {
            $this->mediaLocal->applyFeaturedLocal($article, $refId, $url);
            $this->snapshots->bumpVersion($article);

            return $this->snapshots->build($article->fresh() ?? $article);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function clearFeatured(
        SeoArticle $article,
        User $user,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion = null,
    ): array {
        $this->assertWritable($article, $user, $editorSessionId, $expectedSnapshotVersion);

        if ($this->supportsProductGallery($article)) {
            throw ArticleEditorSessionException::make(
                'featured_managed_by_gallery',
                'Clear product featured by clearing the product album.',
                [],
                422,
            );
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($article): array {
            $this->mediaLocal->clearFeaturedLocal($article);
            $this->snapshots->bumpVersion($article);

            return $this->snapshots->build($article->fresh() ?? $article);
        });
    }

    /**
     * Replace full product album (featured = item[0]).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function replaceGallery(
        SeoArticle $article,
        User $user,
        array $items,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion = null,
    ): array {
        $this->assertWritable($article, $user, $editorSessionId, $expectedSnapshotVersion);

        if (! $this->supportsProductGallery($article)) {
            throw ArticleEditorSessionException::make(
                'gallery_not_supported',
                'Product gallery is not available for this article.',
                [],
                422,
            );
        }

        $album = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $album[] = [
                'id' => $this->resolveRefId($article, $item, $url),
                'url' => $url,
                'source' => $this->resolveSource($item, $url),
                'asset_key' => $this->resolveAssetKey($item),
            ];
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($article, $album): array {
            $this->mediaLocal->saveProductAlbumLocal($article, $album);
            $this->snapshots->bumpVersion($article);

            return $this->snapshots->build($article->fresh() ?? $article);
        });
    }

    /**
     * Reorder by stable item ids from snapshot.
     *
     * @param  list<string>  $orderedIds
     * @return array<string, mixed>
     */
    public function reorderGallery(
        SeoArticle $article,
        User $user,
        array $orderedIds,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion = null,
    ): array {
        $snapshot = $this->snapshots->build($article);
        $byId = [];
        foreach (($snapshot['gallery']['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        $reordered = [];
        foreach ($orderedIds as $id) {
            $key = (string) $id;
            if (! isset($byId[$key])) {
                continue;
            }
            $row = $byId[$key];
            $reordered[] = [
                'id' => (int) ($row['wp_attachment_id'] ?? $row['media_id'] ?? 0),
                'url' => (string) ($row['url'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'asset_key' => (string) ($row['asset_key'] ?? $row['id'] ?? ''),
            ];
            unset($byId[$key]);
        }
        foreach ($byId as $row) {
            $reordered[] = [
                'id' => (int) ($row['wp_attachment_id'] ?? $row['media_id'] ?? 0),
                'url' => (string) ($row['url'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'asset_key' => (string) ($row['asset_key'] ?? $row['id'] ?? ''),
            ];
        }

        return $this->replaceGallery($article, $user, $reordered, $editorSessionId, $expectedSnapshotVersion);
    }

    private function assertWritable(
        SeoArticle $article,
        User $user,
        ?string $editorSessionId,
        int|string|null $expectedSnapshotVersion,
    ): void {
        $this->sessions->assertArticleEditable($article);

        $active = $this->sessions->findActiveSession($article);
        if ($active !== null) {
            $this->sessions->assertOwningActiveSessionForWrite(
                $article,
                $user,
                $editorSessionId,
                null, // media metadata: document_version not required
            );
        } elseif (trim((string) $editorSessionId) !== '') {
            // Client thinks it owns a session that no longer exists.
            throw ArticleEditorSessionException::make(
                \Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode::SESSION_EXPIRED,
                'Editor session expired.',
                [],
                409,
            );
        }

        $this->snapshots->assertExpectedVersion($article, $expectedSnapshotVersion);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveRefId(SeoArticle $article, array $item, string $url): int
    {
        $wpAttachmentId = max(0, (int) (
            $item['wp_attachment_id']
            ?? $item['wpAttachmentId']
            ?? $item['attachment_id']
            ?? $item['attachmentId']
            ?? 0
        ));
        $seoMediaId = max(0, (int) (
            $item['seo_media_id']
            ?? $item['seoMediaId']
            ?? $item['media_id']
            ?? $item['mediaId']
            ?? 0
        ));
        $rawId = max(0, (int) ($item['id'] ?? 0));
        $refId = $wpAttachmentId > 0 ? $wpAttachmentId : ($seoMediaId > 0 ? $seoMediaId : $rawId);
        if ($refId > 0) {
            return $refId;
        }

        return $this->mediaLocal->resolveLocalRefIdFromImageUrl((int) ($article->site_id ?? 0), $url);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveAssetKey(array $item): string
    {
        return trim((string) ($item['asset_key'] ?? $item['assetKey'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSource(array $item, string $url): string
    {
        $source = strtolower(trim((string) ($item['source'] ?? '')));
        if ($source === 'wp') {
            return 'wordpress';
        }
        if (in_array($source, ['wordpress', 'local', 'generated', 'uploaded'], true)) {
            return $source;
        }
        if (str_contains($url, '/wp-content/uploads/')) {
            return 'wordpress';
        }
        if (str_contains($url, '/storage/uploads/seo_media/')
            || str_contains($url, '/storage/seo/')
            || str_contains($url, '/seo-media/')
            || str_contains($url, '/storage/')) {
            return 'local';
        }

        return 'uploaded';
    }

    private function supportsProductGallery(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');
        $postType = ArticlePostTypeResolver::resolve($article);
        if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
            return true;
        }

        return strtolower(trim((string) ($article->articleMetas->firstWhere('meta_key', 'canary_type')?->meta_value ?? ''))) === 'product_gallery';
    }
}
