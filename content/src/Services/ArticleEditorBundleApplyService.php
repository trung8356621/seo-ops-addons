<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;

final class ArticleEditorBundleApplyService
{
    public function __construct(
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleMediaLocalService $mediaLocal,
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function apply(SeoArticle $article, array $bundle, ArticleEditorSaveContext $context): void
    {
        $categoryIds = $bundle['category_ids'] ?? null;
        if (is_array($categoryIds)) {
            $this->applyCategories($article, $context, $categoryIds);
        }

        $faqs = $bundle['faqs'] ?? null;
        if (
            is_array($faqs)
            && ! $this->shouldSkipMalformedFaqsBundle($faqs)
            && ! $this->shouldSkipUnhydratedEmptyFaqsWipe($article, $bundle, $faqs)
        ) {
            $this->faqEditor->saveFromEditor($article, $faqs);
            $article->unsetRelation('faqs');
        }

        $featuredImage = $bundle['featured_image'] ?? $this->featuredImageFromMediaSnapshot($bundle);
        if (is_array($featuredImage) && trim((string) ($featuredImage['url'] ?? '')) !== '') {
            $this->persistFeaturedImage($article, $context, $featuredImage);
        }

        $productAlbum = $bundle['product_album'] ?? $this->productAlbumFromMediaSnapshot($bundle);
        if (is_array($productAlbum)) {
            $this->persistProductAlbum($article, $context, $productAlbum);
        }

        $this->persistSeoMetaFields($article, $context);
        $this->persistArticlePostTypeMeta($article, $context->postType);
    }

    public function applySeoMetaOnly(SeoArticle $article, string $focusKeyword, string $seoMetaDescription): void
    {
        $context = new ArticleEditorSaveContext(
            title: trim((string) ($article->title ?? '')),
            slug: trim((string) ($article->slug ?? '')),
            postType: ArticlePostTypeResolver::resolve($article),
            status: (string) ($article->status ?? 'draft'),
            visibility: 'public',
            publishDay: '01',
            publishMonth: '01',
            publishYear: '2020',
            publishHour: '00',
            publishMinute: '00',
            seoMetaDescription: trim($seoMetaDescription),
            focusKeyword: trim($focusKeyword),
        );

        $this->persistSeoMetaFields($article, $context);
    }

    /**
     * @param  list<int|string>  $categoryIds
     */
    private function applyCategories(SeoArticle $article, ArticleEditorSaveContext $context, array $categoryIds): void
    {
        $ids = collect($categoryIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($this->isTaxonomyEntity($article, $context->postType)) {
            $parentId = $ids[0] ?? 0;
            // Root terms (parent 0) must keep explicit meta "0".
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_parent_id'],
                ['meta_value' => (string) max(0, $parentId)],
            );

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'category_ids'],
            ['meta_value' => json_encode($ids, JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function persistFeaturedImage(SeoArticle $article, ArticleEditorSaveContext $context, array $item): void
    {
        if ($this->supportsProductGallery($article, $context->postType)) {
            return;
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return;
        }

        $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
        $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($localRefId <= 0) {
            $localRefId = $this->mediaLocal->resolveLocalRefIdFromImageUrl(
                (int) ($article->site_id ?? 0),
                $url,
            );
        }

        if ($localRefId > 0) {
            $this->mediaLocal->applyFeaturedLocal($article, $localRefId, $url);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function persistProductAlbum(SeoArticle $article, ArticleEditorSaveContext $context, array $items): void
    {
        if (! $this->supportsProductGallery($article, $context->postType)) {
            return;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $album = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
            $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? $item['id'] ?? 0));
            $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

            if ($localRefId <= 0) {
                $localRefId = $this->mediaLocal->resolveLocalRefIdFromImageUrl($siteId, $url);
            }

            $album[] = [
                'id' => $localRefId,
                'url' => $url,
            ];
        }

        $this->mediaLocal->saveProductAlbumLocal($article, $album);
    }

    private function persistSeoMetaFields(SeoArticle $article, ArticleEditorSaveContext $context): void
    {
        $seoDescription = trim($context->seoMetaDescription);

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            if ($seoDescription === '') {
                $article->articleMetas()->where('meta_key', $key)->delete();

                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $seoDescription],
            );
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId > 0 && auth()->id() !== null) {
            KeywordFocusAttach::syncMainKeyword(
                $article,
                $siteId,
                (int) auth()->id(),
                trim($context->focusKeyword),
            );
        }
    }

    private function persistArticlePostTypeMeta(SeoArticle $article, string $postType): void
    {
        $normalized = SeoProjectTask::normalizePostType($postType);

        $wpSlug = match ($normalized) {
            SeoProjectTask::POST_TYPE_PRODUCT => 'product',
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'product_cat',
            SeoProjectTask::POST_TYPE_CATEGORY => 'category',
            default => 'post',
        };

        $article->articleMetas()->where('meta_key', 'wp_post_type')->delete();

        if (in_array($wpSlug, ['product_cat', 'category'], true)) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_entity'],
                ['meta_value' => 'term'],
            );
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_taxonomy'],
                ['meta_value' => $wpSlug],
            );

            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_entity'],
            ['meta_value' => 'post'],
        );
        $article->articleMetas()->where('meta_key', 'wp_taxonomy')->delete();
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>|null
     */
    private function featuredImageFromMediaSnapshot(array $bundle): ?array
    {
        $snapshot = is_array($bundle['media_snapshot'] ?? null) ? $bundle['media_snapshot'] : [];
        $featured = is_array($snapshot['featured'] ?? null) ? $snapshot['featured'] : null;
        if ($featured === null || trim((string) ($featured['url'] ?? '')) === '') {
            return null;
        }

        return $this->normalizeMediaSnapshotItem($featured);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return list<array<string, mixed>>|null
     */
    private function productAlbumFromMediaSnapshot(array $bundle): ?array
    {
        $snapshot = is_array($bundle['media_snapshot'] ?? null) ? $bundle['media_snapshot'] : [];
        $gallery = is_array($snapshot['gallery'] ?? null) ? $snapshot['gallery'] : [];
        if (! (bool) ($gallery['required'] ?? false) || ! is_array($gallery['items'] ?? null)) {
            return null;
        }

        $items = [];
        if (is_array($snapshot['featured'] ?? null)) {
            $items[] = $snapshot['featured'];
        }
        foreach ($gallery['items'] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return array_values(array_filter(array_map(
            fn (array $item): ?array => trim((string) ($item['url'] ?? '')) !== ''
                ? $this->normalizeMediaSnapshotItem($item)
                : null,
            $items,
        )));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeMediaSnapshotItem(array $item): array
    {
        return [
            'url' => (string) ($item['url'] ?? ''),
            'wp_attachment_id' => (int) ($item['wp_attachment_id'] ?? 0),
            'seo_media_id' => (int) ($item['media_id'] ?? $item['seo_media_id'] ?? 0),
            'id' => $item['id'] ?? $item['asset_key'] ?? null,
            'asset_key' => (string) ($item['asset_key'] ?? $item['id'] ?? ''),
            'source' => (string) ($item['source'] ?? ''),
            'alt' => (string) ($item['alt'] ?? ''),
            'slug' => (string) ($item['slug'] ?? $item['filename'] ?? ''),
        ];
    }

    /**
     * @param  list<mixed>  $faqs
     */
    private function shouldSkipMalformedFaqsBundle(array $faqs): bool
    {
        foreach ($faqs as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (
                array_key_exists('text', $row)
                && ! array_key_exists('answer', $row)
                && ! array_key_exists('question', $row)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lazy FAQ (Phase 2): client gửi faqs:[] khi panel chưa hydrate → không được xóa DB.
     *
     * @param  array<string, mixed>  $bundle
     * @param  list<mixed>  $faqs
     */
    private function shouldSkipUnhydratedEmptyFaqsWipe(SeoArticle $article, array $bundle, array $faqs): bool
    {
        if ($faqs !== []) {
            return false;
        }

        $source = strtolower(trim((string) ($bundle['faqs_source'] ?? '')));
        if ($source === 'editor') {
            return false;
        }

        if ($source === 'none' || $source === '') {
            return $article->faqs()->exists();
        }

        return $article->faqs()->exists();
    }

    private function supportsProductGallery(SeoArticle $article, string $postType): bool
    {
        $type = strtolower(SeoProjectTask::normalizePostType($postType));

        if (! in_array($type, ['product', 'e-commerce'], true)) {
            return false;
        }

        return ! $this->wpContent->isTaxonomyRecord($article);
    }

    private function isTaxonomyEntity(SeoArticle $article, string $postType): bool
    {
        if ($this->wpContent->isTaxonomyRecord($article)) {
            return true;
        }

        $type = SeoProjectTask::normalizePostType($postType);

        return in_array($type, [SeoProjectTask::POST_TYPE_CATEGORY, SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY], true);
    }
}
