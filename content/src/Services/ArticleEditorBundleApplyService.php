<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Content\Support\ArticleWordPressPostType;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;

final class ArticleEditorBundleApplyService
{
    public function __construct(
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleMediaLocalService $mediaLocal,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
    ) {}

    /**
     * Independent editor-bundle side effects (not body/document).
     * Safe to run after a document-noop ACK — does not rewrite body or capture revisions.
     *
     * @param  array<string, mixed>  $bundle
     * @return bool True when any independent state was written
     */
    public function apply(SeoArticle $article, array $bundle, ArticleEditorSaveContext $context): bool
    {
        $article->loadMissing('articleMetas');
        $changed = $this->applyCoreArticleFields($article, $context);

        $categoryIds = $bundle['category_ids'] ?? null;
        if (is_array($categoryIds) && $this->applyCategories($article, $context, $categoryIds)) {
            $changed = true;
        }

        $faqs = $bundle['faqs'] ?? null;
        if (
            is_array($faqs)
            && ! $this->shouldSkipMalformedFaqsBundle($faqs)
            && ! $this->shouldSkipUnhydratedEmptyFaqsWipe($article, $bundle, $faqs)
            && ! $this->faqsMatchPersisted($article, $faqs)
        ) {
            $this->faqEditor->saveFromEditor($article, $faqs);
            $article->unsetRelation('faqs');
            $changed = true;
        }

        $featuredImage = $bundle['featured_image'] ?? $this->featuredImageFromMediaSnapshot($bundle);
        if (is_array($featuredImage) && trim((string) ($featuredImage['url'] ?? '')) !== '') {
            if ($this->persistFeaturedImage($article, $context, $featuredImage)) {
                $changed = true;
            }
        }

        $productAlbum = $bundle['product_album'] ?? $this->productAlbumFromMediaSnapshot($bundle);
        if (is_array($productAlbum) && $productAlbum !== []) {
            if ($this->persistProductAlbum($article, $context, $productAlbum)) {
                $changed = true;
            }
        }

        if ($this->persistSeoMetaFields($article, $context)) {
            $changed = true;
        }

        if ($this->persistArticlePostTypeMeta($article, $context->postType)) {
            $changed = true;
        }

        if ($changed) {
            $this->syncFlags->markLocalEditPending($article);
        }

        return $changed;
    }

    /**
     * Lightweight articles-row metadata (title/slug/status/schedule) without body rewrite.
     */
    public function applyCoreArticleFields(SeoArticle $article, ArticleEditorSaveContext $context): bool
    {
        $slug = $context->normalizedSlug();
        $publishAt = $context->resolvePublishAtForSave();
        $title = trim($context->title);
        $status = $context->status;

        $payload = [];
        if ($title !== '' && $title !== trim((string) ($article->title ?? ''))) {
            $payload['title'] = $title;
        }

        $currentSlug = trim((string) ($article->slug ?? ''));
        $nextSlug = $slug !== '' ? $slug : null;
        if ($nextSlug !== $currentSlug && ! ($nextSlug === null && $currentSlug === '')) {
            $payload['slug'] = $nextSlug;
        }

        if ($status !== (string) ($article->status ?? 'draft')) {
            $payload['status'] = $status;
        }

        if ($payload !== []) {
            $article->update($payload);
        }

        $publishingChanged = false;
        if (class_exists(\Omnichannel\Addons\Publishing\Services\PublishingArticleStateWriter::class)) {
            $currentPubStatus = (string) ($article->publishingState?->publication_status
                ?? $article->status
                ?? 'draft');
            $currentPublishedAt = $article->publishingState?->published_at;
            $publishAtChanged = ($publishAt === null && $currentPublishedAt !== null)
                || ($publishAt !== null && (
                    $currentPublishedAt === null
                    || $publishAt->getTimestamp() !== $currentPublishedAt->getTimestamp()
                ));
            if ($status !== $currentPubStatus || $publishAtChanged || isset($payload['status'])) {
                app(\Omnichannel\Addons\Publishing\Services\PublishingArticleStateWriter::class)->upsert($article, [
                    'publication_status' => $status,
                    'published_at' => $publishAt,
                ]);
                $publishingChanged = true;
            }
        }

        return $payload !== [] || $publishingChanged;
    }

    public function applySeoMetaOnly(SeoArticle $article, string $focusKeyword, string $seoMetaDescription): void
    {
        $article->loadMissing('articleMetas');
        $context = new ArticleEditorSaveContext(
            title: trim((string) ($article->title ?? '')),
            slug: trim((string) ($article->slug ?? '')),
            postType: ArticleWordPressPostType::resolve($article),
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
    private function applyCategories(SeoArticle $article, ArticleEditorSaveContext $context, array $categoryIds): bool
    {
        $ids = collect($categoryIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($this->isTaxonomyEntity($article, $context->postType)) {
            $parentId = $ids[0] ?? 0;
            $next = (string) max(0, $parentId);
            $current = trim((string) ($article->articleMetas
                ->firstWhere('meta_key', 'wp_parent_id')?->meta_value ?? ''));
            if ($current === $next) {
                return false;
            }
            // Root terms (parent 0) must keep explicit meta "0".
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_parent_id'],
                ['meta_value' => $next],
            );
            $article->unsetRelation('articleMetas');

            return true;
        }

        $encoded = json_encode($ids, JSON_THROW_ON_ERROR);
        $current = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'category_ids')?->meta_value ?? ''));
        if ($current === $encoded) {
            return false;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'category_ids'],
            ['meta_value' => $encoded],
        );
        $article->unsetRelation('articleMetas');

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function persistFeaturedImage(SeoArticle $article, ArticleEditorSaveContext $context, array $item): bool
    {
        if ($this->supportsProductGallery($article, $context->postType)) {
            return false;
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return false;
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

        if ($localRefId <= 0) {
            return false;
        }

        $article->loadMissing('articleMetas');
        $existingUrl = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_URL)?->meta_value ?? ''));
        $existingId = (int) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        if ($existingUrl === $url && $existingId === $localRefId) {
            return false;
        }

        $this->mediaLocal->applyFeaturedLocal($article, $localRefId, $url);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function persistProductAlbum(SeoArticle $article, ArticleEditorSaveContext $context, array $items): bool
    {
        if (! $this->supportsProductGallery($article, $context->postType)) {
            return false;
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

        if ($this->productAlbumMatchesPersisted($article, $album)) {
            return false;
        }

        $this->mediaLocal->saveProductAlbumLocal($article, $album);

        return true;
    }

    private function persistSeoMetaFields(SeoArticle $article, ArticleEditorSaveContext $context): bool
    {
        $seoDescription = trim($context->seoMetaDescription);
        $changed = false;

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            $current = trim((string) ($article->articleMetas
                ->firstWhere('meta_key', $key)?->meta_value ?? ''));

            if ($seoDescription === '') {
                if ($current !== '') {
                    $article->articleMetas()->where('meta_key', $key)->delete();
                    $changed = true;
                }

                continue;
            }

            if ($current === $seoDescription) {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $seoDescription],
            );
            $changed = true;
        }

        $siteId = (int) ($article->site_id ?? 0);
        $focus = trim($context->focusKeyword);
        if ($siteId > 0 && auth()->id() !== null) {
            $currentFocus = trim((string) ($article->articleMetas
                ->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value ?? ''));
            if ($currentFocus !== $focus) {
                KeywordFocusAttach::syncMainKeyword(
                    $article,
                    $siteId,
                    (int) auth()->id(),
                    $focus,
                );
                $changed = true;
            }
        }

        if ($changed) {
            $article->unsetRelation('articleMetas');
        }

        return $changed;
    }

    private function persistArticlePostTypeMeta(SeoArticle $article, string $postType): bool
    {
        $classification = ArticleWordPressPostType::classificationForEditor($article, $postType);
        $current = ArticleContentClassification::for($article);

        $unchanged = $current->contentType() === $classification['content_type']
            && $current->isTerm() === $classification['wp_is_term']
            && ($current->wpPostType() ?? '') === ($classification['wp_post_type'] ?? '');

        if ($unchanged) {
            return false;
        }

        ArticleContentClassification::persist($article, $classification);

        if ($classification['wp_is_term']) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_taxonomy'],
                ['meta_value' => $classification['wp_post_type']],
            );
        } else {
            $article->articleMetas()->where('meta_key', 'wp_taxonomy')->delete();
        }

        $article->unsetRelation('articleMetas');

        return true;
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
     * Avoid delete+reinsert on every document-noop autosave when FAQs are unchanged.
     *
     * @param  list<mixed>  $faqs
     */
    private function faqsMatchPersisted(SeoArticle $article, array $faqs): bool
    {
        $incoming = [];
        foreach ($faqs as $row) {
            if (! is_array($row)) {
                continue;
            }
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            $more = trim((string) ($row['more'] ?? ''));
            $answerPlain = trim(html_entity_decode(strip_tags($answer), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($question === '' || $answerPlain === '') {
                continue;
            }
            $incoming[] = [
                'question' => $question,
                'answer' => $answer,
                'more' => $more,
            ];
        }

        $article->loadMissing('faqs');
        $existing = $article->faqs
            ->map(static fn ($faq): array => [
                'question' => trim((string) ($faq->question ?? '')),
                'answer' => trim((string) ($faq->answer ?? '')),
                'more' => trim((string) ($faq->more ?? '')),
            ])
            ->values()
            ->all();

        return $incoming === $existing;
    }

    /**
     * @param  list<array{id?: int, url?: string}>  $album
     */
    private function productAlbumMatchesPersisted(SeoArticle $article, array $album): bool
    {
        $article->loadMissing('articleMetas');
        $featuredUrl = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_URL)?->meta_value ?? ''));
        $featuredId = (int) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value ?? 0);
        $galleryRaw = (string) ($article->articleMetas
            ->firstWhere('meta_key', ArticleMediaLocalService::META_PRODUCT_GALLERY)?->meta_value ?? '');
        $gallery = [];
        if ($galleryRaw !== '') {
            $decoded = json_decode($galleryRaw, true);
            if (is_array($decoded)) {
                $gallery = $decoded;
            }
        }

        $persisted = [];
        if ($featuredUrl !== '') {
            $persisted[] = ['id' => $featuredId, 'url' => $featuredUrl];
        }
        foreach ($gallery as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $persisted[] = [
                'id' => max(0, (int) ($item['id'] ?? 0)),
                'url' => $url,
            ];
        }

        $normalizedIncoming = array_values(array_map(
            static fn (array $item): array => [
                'id' => max(0, (int) ($item['id'] ?? 0)),
                'url' => trim((string) ($item['url'] ?? '')),
            ],
            $album,
        ));

        return $normalizedIncoming === $persisted;
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
        if (! ArticleWordPressPostType::isProductLike($postType)) {
            return false;
        }

        return ! ArticlePostTypeResolver::isTerm($article);
    }

    private function isTaxonomyEntity(SeoArticle $article, string $postType): bool
    {
        if (ArticlePostTypeResolver::isTerm($article)) {
            return true;
        }

        $type = ArticleWordPressPostType::normalizeEditorInput($postType);

        return in_array($type, [
            'category',
            'product_cat',
            SeoProjectTask::POST_TYPE_CATEGORY,
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY,
        ], true);
    }
}
