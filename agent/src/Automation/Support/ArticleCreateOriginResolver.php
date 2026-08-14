<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;

/**
 * Source-identity idempotency cho article.create — không dedup theo title.
 */
final class ArticleCreateOriginResolver
{
    public const ORIGIN_SEO_PROJECT_TASK = 'seo_project_task';

    public const META_ORIGIN_TYPE = 'automation_origin_type';

    public const META_ORIGIN_ID = 'automation_origin_id';

    /**
     * @return array{article_id: int, site_id: int, post_type: string, status: string, deduplicated: true}|null
     */
    public function findExisting(
        ?string $originType,
        ?int $originId,
        int $siteId,
        string $postType,
    ): ?array {
        $originType = $originType !== null ? trim($originType) : '';
        $originId = $originId !== null && $originId > 0 ? $originId : null;

        if ($originType === '' || $originId === null || $siteId <= 0) {
            return null;
        }

        if ($originType === self::ORIGIN_SEO_PROJECT_TASK) {
            $task = SeoProjectTask::query()->find($originId);
            if ($task instanceof SeoProjectTask) {
                $articleId = (int) ($task->article_id ?? 0);
                if ($articleId > 0) {
                    $article = SeoArticle::query()->find($articleId);
                    if ($article instanceof SeoArticle && (int) ($article->site_id ?? 0) === $siteId) {
                        return [
                            'article_id' => $articleId,
                            'site_id' => $siteId,
                            'post_type' => $postType !== '' ? $postType : (string) ($article->type ?? 'article'),
                            'status' => (string) ($article->status ?? 'draft'),
                            'deduplicated' => true,
                        ];
                    }
                }
            }

            $metaArticleId = $this->findByOriginMeta($originType, $originId, $siteId);
            if ($metaArticleId !== null) {
                $article = SeoArticle::query()->find($metaArticleId);
                if ($article instanceof SeoArticle) {
                    if ($task instanceof SeoProjectTask) {
                        $this->attachToProjectTaskIfNeeded($originId, $metaArticleId);
                    }

                    return [
                        'article_id' => $metaArticleId,
                        'site_id' => $siteId,
                        'post_type' => $postType !== '' ? $postType : (string) ($article->type ?? 'article'),
                        'status' => (string) ($article->status ?? 'draft'),
                        'deduplicated' => true,
                    ];
                }
            }

            return null;
        }

        $articleId = $this->findByOriginMeta($originType, $originId, $siteId);
        if ($articleId === null) {
            return null;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return null;
        }

        return [
            'article_id' => $articleId,
            'site_id' => $siteId,
            'post_type' => $postType !== '' ? $postType : (string) ($article->type ?? 'article'),
            'status' => (string) ($article->status ?? 'draft'),
            'deduplicated' => true,
        ];
    }

    public function persistOriginMeta(SeoArticle $article, string $originType, int $originId): void
    {
        if ($originType === '' || $originId <= 0) {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_ORIGIN_TYPE],
            ['meta_value' => $originType],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_ORIGIN_ID],
            ['meta_value' => (string) $originId],
        );
    }

    public function attachToProjectTaskIfNeeded(int $originId, int $articleId): void
    {
        $task = SeoProjectTask::query()->find($originId);
        if (! $task instanceof SeoProjectTask) {
            return;
        }

        $existing = (int) ($task->article_id ?? 0);
        if ($existing > 0 && $existing !== $articleId) {
            // Stale missing / non-local article_id (WP post id, restore) — allow rebind.
            $existingStillAlive = LocalArticleAssociationGuard::isLocalArticleId($existing);
            if ($existingStillAlive) {
                return;
            }
        }

        if ($existing === $articleId) {
            return;
        }

        $payload = ['article_id' => $articleId];
        if ($task->connected_at === null) {
            $payload['connected_at'] = now();
        }

        SeoProjectTask::query()->whereKey($originId)->update($payload);
    }

    private function findByOriginMeta(string $originType, int $originId, int $siteId): ?int
    {
        $typeHits = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereHas('articleMetas', static function ($query) use ($originType): void {
                $query->where('meta_key', self::META_ORIGIN_TYPE)
                    ->where('meta_value', $originType);
            })
            ->whereHas('articleMetas', static function ($query) use ($originId): void {
                $query->where('meta_key', self::META_ORIGIN_ID)
                    ->where('meta_value', (string) $originId);
            })
            ->orderByDesc('id')
            ->value('id');

        $id = (int) ($typeHits ?? 0);

        return $id > 0 ? $id : null;
    }
}
