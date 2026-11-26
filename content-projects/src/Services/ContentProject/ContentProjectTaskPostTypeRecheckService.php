<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleMetaMap;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pre-generation post_type recheck for Content Project items.
 *
 * Rewrite/Improve: resolve from linked Article and persist when task.post_type
 * is null or diverges. Create: keep planner-chosen post_type unchanged.
 *
 * Must run before Content Length Policy / Prompt Variables resolution.
 */
final class ContentProjectTaskPostTypeRecheckService
{
    public const ERROR_MISSING_ARTICLE = 'Thiếu Article gốc để xác định post_type (rewrite/improve).';

    public const ERROR_SITE_MISMATCH = 'Article gốc không thuộc cùng site với Content Project item.';

    public const ERROR_UNRESOLVED = 'Không xác định được post_type từ Article gốc (thiếu content_type/wp_post_type).';

    /**
     * Recheck + conditional persist. Returns the same task instance with
     * in-memory post_type synchronized for the current worker.
     *
     * @throws InvalidArgumentException when rewrite/improve cannot classify
     */
    public function recheckAndPersist(SeoProjectTask $task): SeoProjectTask
    {
        $type = SeoProjectTask::normalizeType((string) ($task->type ?? ''));
        if ($type === SeoProjectTask::TYPE_CREATE) {
            return $task;
        }

        if (! in_array($type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true)) {
            return $task;
        }

        $taskId = (int) ($task->getKey() ?? 0);
        if ($taskId <= 0) {
            throw new InvalidArgumentException(
                ContentProjectErrorCode::TaskNotFound->value.': '.self::ERROR_MISSING_ARTICLE,
            );
        }

        $canonical = $this->resolveCanonicalFromLinkedArticle($task);

        return DB::connection($task->getConnectionName())->transaction(function () use ($task, $taskId, $canonical): SeoProjectTask {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
            if (! $locked instanceof SeoProjectTask) {
                throw new InvalidArgumentException(
                    ContentProjectErrorCode::TaskNotFound->value.': Content Project item #'.$taskId.' không tồn tại.',
                );
            }

            $storedRaw = trim((string) ($locked->getAttributes()['post_type'] ?? ''));
            if ($storedRaw !== '' && SeoProjectTask::normalizePostType($storedRaw) === $canonical) {
                // Equivalent (incl. legacy article ≡ post) — no DB write.
                $task->forceFill(['post_type' => $storedRaw]);

                return $task;
            }

            $affected = SeoProjectTask::query()
                ->whereKey($taskId)
                ->where(static function ($query) use ($canonical): void {
                    $query->whereNull('post_type')
                        ->orWhere('post_type', '')
                        ->orWhere('post_type', '!=', $canonical);
                })
                ->update(['post_type' => $canonical]);

            // Concurrent worker may have written the same value — still sync memory.
            if ($affected === 0) {
                $fresh = trim((string) (SeoProjectTask::query()->whereKey($taskId)->value('post_type') ?? ''));
                $task->forceFill([
                    'post_type' => $fresh !== ''
                        ? SeoProjectTask::normalizePostType($fresh)
                        : $canonical,
                ]);

                return $task;
            }

            $task->forceFill(['post_type' => $canonical]);

            return $task;
        });
    }

    /**
     * Display / read-model effective type. Never throws; null → UI "—".
     * Create: planned task type. Rewrite/Improve: Article-resolved type.
     */
    public function resolveEffectiveForDisplay(SeoProjectTask $task, ?SeoArticle $article = null): ?string
    {
        $type = SeoProjectTask::normalizeType((string) ($task->type ?? ''));

        if ($type === SeoProjectTask::TYPE_CREATE) {
            $raw = trim((string) ($task->getAttributes()['post_type'] ?? ''));

            return $raw !== '' ? SeoProjectTask::normalizePostType($raw) : null;
        }

        if (! in_array($type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true)) {
            return null;
        }

        $resolvedArticle = $article;
        if (! $resolvedArticle instanceof SeoArticle) {
            if ($task->relationLoaded('article') && $task->article instanceof SeoArticle) {
                $resolvedArticle = $task->article;
            }
        }

        if (! $resolvedArticle instanceof SeoArticle) {
            return null;
        }

        $siteId = (int) ($task->site_id ?? 0);
        $articleSiteId = (int) ($resolvedArticle->site_id ?? 0);
        if ($siteId > 0 && $articleSiteId > 0 && $siteId !== $articleSiteId) {
            return null;
        }

        if (! $this->hasClassificationEvidence($resolvedArticle)) {
            return null;
        }

        return SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($resolvedArticle));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resolveCanonicalFromLinkedArticle(SeoProjectTask $task): string
    {
        $articleId = (int) ($task->article_id ?? 0);
        $siteId = (int) ($task->site_id ?? 0);

        if ($articleId <= 0) {
            throw new InvalidArgumentException(
                ContentProjectErrorCode::ArticleRelationMissing->value.': '.self::ERROR_MISSING_ARTICLE,
            );
        }

        $localId = LocalArticleAssociationGuard::resolveLocalArticleId(
            $articleId,
            $siteId > 0 ? $siteId : null,
        );

        if ($localId === null) {
            // Distinguish missing vs site mismatch when article exists on another site.
            $existsAnywhere = SeoArticle::query()->whereKey($articleId)->exists();
            if ($existsAnywhere && $siteId > 0) {
                throw new InvalidArgumentException(
                    ContentProjectErrorCode::ArticleSiteMismatch->value.': '.self::ERROR_SITE_MISMATCH,
                );
            }

            throw new InvalidArgumentException(
                ContentProjectErrorCode::ArticleRelationMissing->value.': '.self::ERROR_MISSING_ARTICLE,
            );
        }

        $article = SeoArticle::query()->with('articleMetas')->find($localId);
        if (! $article instanceof SeoArticle) {
            throw new InvalidArgumentException(
                ContentProjectErrorCode::ArticleRelationMissing->value.': '.self::ERROR_MISSING_ARTICLE,
            );
        }

        if (! $this->hasClassificationEvidence($article)) {
            throw new InvalidArgumentException(
                ContentProjectErrorCode::ArticlePostTypeUnresolved->value.': '.self::ERROR_UNRESOLVED,
            );
        }

        return SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($article));
    }

    private function hasClassificationEvidence(SeoArticle $article): bool
    {
        $map = ArticleMetaMap::for($article);
        $contentType = trim((string) ($map->get(ArticleContentClassification::META_CONTENT_TYPE) ?? ''));
        $wpPostType = trim((string) ($map->get(ArticleContentClassification::META_WP_POST_TYPE) ?? ''));

        return $contentType !== '' || $wpPostType !== '';
    }
}
