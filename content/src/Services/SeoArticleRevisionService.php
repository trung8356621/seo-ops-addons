<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Illuminate\Support\Collection;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

final class SeoArticleRevisionService
{
    private const MAX_REVISIONS_PER_ARTICLE = 15;

    public function __construct(
        private readonly WordPressArticleContentService $wordPressContent,
    ) {}

    /**
     * @param  array<string, mixed>  $seoMeta
     */
    /**
     * @param  array<string, mixed>  $seoMeta
     * @param  bool  $force  true = luôn snapshot (pipeline rollback), bỏ qua rule WP-owned history
     */
    public function captureAfterSave(
        SeoArticle $article,
        string $title,
        string $content,
        array $seoMeta,
        ?int $userId = null,
        bool $force = false,
    ): ?SeoArticleRevision {
        // Sau khi bài đã có trên WordPress: lịch sử chỉnh sửa do WP Revision quản lý.
        // Pipeline rerun vẫn force snapshot tạm để rollback AI.
        if (! $force && (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
            return null;
        }

        $revision = SeoArticleRevision::query()->create([
            'article_id' => (int) $article->id,
            'user_id' => $userId,
            'title' => $title !== '' ? $title : null,
            'content' => $content !== '' ? $content : null,
            'seo_meta' => $seoMeta !== [] ? $seoMeta : null,
        ]);

        $this->pruneOldRevisions((int) $article->id);

        return $revision;
    }

    /**
     * @return Collection<int, SeoArticleRevision>
     */
    public function listForArticle(int $articleId): Collection
    {
        return SeoArticleRevision::query()
            ->with('user:id,name')
            ->where('article_id', $articleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function findForArticle(int $articleId, int $revisionId): ?SeoArticleRevision
    {
        return SeoArticleRevision::query()
            ->where('article_id', $articleId)
            ->whereKey($revisionId)
            ->first();
    }

    public function countForArticle(int $articleId): int
    {
        return (int) SeoArticleRevision::query()
            ->where('article_id', $articleId)
            ->count();
    }

    public function clearAllForArticle(int $articleId): int
    {
        return SeoArticleRevision::query()
            ->where('article_id', $articleId)
            ->delete();
    }

    public function restoreRevisionToArticle(SeoArticle $article, SeoArticleRevision $revision): SeoArticle
    {
        app(ArticleEditorSessionService::class)
            ->assertNoActiveEditorSession($article, 'revision_restore');

        $seoMeta = is_array($revision->seo_meta) ? $revision->seo_meta : [];
        $title = trim((string) ($revision->title ?? ''));
        $content = (string) ($revision->content ?? '');

        $updates = [
            'title' => $title !== '' ? $title : $article->title,
            'body' => $content !== '' ? $content : null,
        ];

        if (is_array($seoMeta['editor_document'] ?? null)) {
            $updates['editor_document'] = $seoMeta['editor_document'];
            $updates['editor_document_schema_version'] = (int) ($seoMeta['editor_document_schema_version'] ?? 1);
            $updates['editor_document_hash'] = (string) ($seoMeta['editor_document_hash'] ?? '');
            $updates['editor_document_status'] = \Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentSchema::STATUS_CURRENT;
            $updates['editor_document_updated_at'] = now();
        } else {
            // Legacy revision: body restored → mark JSON stale for re-ingest.
            try {
                app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
                    ->invalidateForLegacyBodyWrite($article, 'revision_restore_html_only');
                if ($article->isDirty('editor_document_status')) {
                    $updates['editor_document_status'] = $article->editor_document_status;
                }
            } catch (\Throwable) {
                // best-effort
            }
        }

        if (array_key_exists('seo_score', $seoMeta) && $seoMeta['seo_score'] !== null) {
            $updates['seo_score'] = (float) $seoMeta['seo_score'];
        }

        $article->update($updates);
        $this->persistSeoMetaFromRevision($article, $seoMeta);

        return $article->fresh();
    }

    /**
     * @return array{title: string, content: string, seo_meta: array<string, mixed>}
     */
    public function buildArticleCompareSnapshot(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');

        $title = trim((string) ($article->title ?? ''));
        $content = trim((string) ($article->body ?? ''));
        if ($content === '') {
            $content = trim($this->wordPressContent->resolveEditorHtml($article));
        }

        return [
            'title' => $title,
            'content' => $content,
            'seo_meta' => $this->resolveSeoMetaSnapshot($article),
        ];
    }

    /**
     * @return array{title: string, content: string, seo_meta: array<string, mixed>, id: int, created_at: string|null}
     */
    public function buildRevisionComparePayload(SeoArticleRevision $revision): array
    {
        return [
            'id' => (int) $revision->id,
            'title' => (string) ($revision->title ?? ''),
            'content' => (string) ($revision->content ?? ''),
            'seo_meta' => is_array($revision->seo_meta) ? $revision->seo_meta : [],
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $seoMeta
     */
    private function persistSeoMetaFromRevision(SeoArticle $article, array $seoMeta): void
    {
        $metaDescription = trim((string) ($seoMeta['meta_description'] ?? ''));
        $focusKeyword = trim((string) ($seoMeta['focus_keyword'] ?? ''));

        $article->articleMetas()->where('meta_key', 'seo_title')->delete();

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            if ($metaDescription === '') {
                $article->articleMetas()->where('meta_key', $key)->delete();

                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $metaDescription],
            );
        }

        $slug = trim((string) ($seoMeta['slug'] ?? ''));
        if ($slug !== '') {
            $article->update(['slug' => $slug]);
        }

        $siteId = (int) ($article->site_id ?? 0);
        $userId = (int) (auth()->id() ?? 0);
        if ($siteId > 0 && $userId > 0) {
            \Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach::syncMainKeyword(
                $article,
                $siteId,
                $userId,
                $focusKeyword,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSeoMetaSnapshot(SeoArticle $article): array
    {
        $metas = $article->articleMetas;

        return [
            'seo_title' => trim((string) ($article->title ?? '')),
            'meta_description' => trim((string) (
                $metas->firstWhere('meta_key', 'seo_meta_description')?->meta_value
                ?? $metas->firstWhere('meta_key', 'meta_description')?->meta_value
                ?? ''
            )),
            'focus_keyword' => app(\Omnichannel\Addons\Seo\Services\SeoAnalyzerService::class)
                ->resolveFocusKeywordForArticle($article) ?? '',
            'seo_score' => $article->seoProfile?->seo_score !== null ? (float) $article->seoProfile->seo_score : null,
            'slug' => trim((string) ($article->slug ?? '')),
        ];
    }

    private function pruneOldRevisions(int $articleId): void
    {
        $keepIds = SeoArticleRevision::query()
            ->where('article_id', $articleId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_REVISIONS_PER_ARTICLE)
            ->pluck('id');

        if ($keepIds->isEmpty()) {
            return;
        }

        SeoArticleRevision::query()
            ->where('article_id', $articleId)
            ->whereNotIn('id', $keepIds->all())
            ->delete();
    }
}
