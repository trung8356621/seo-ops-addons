<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;

/**
 * Site authority for bindArticleAfterExternal.
 *
 * Invariant: article.site_id === task.site_id.
 * Project.site_id is legacy metadata only — never overrides task.site_id.
 */
final class ContentProjectBindArticleAuthority
{
    /**
     * Task site first; project site only when task.site_id is missing/zero.
     */
    public static function resolveSiteId(int $taskSiteId, int $projectSiteId = 0): int
    {
        if ($taskSiteId > 0) {
            return $taskSiteId;
        }

        return $projectSiteId > 0 ? $projectSiteId : 0;
    }

    /**
     * Evaluate a candidate article id against local existence + task site.
     *
     * @param  ?int  $localArticleSiteId  site_id when the id exists in local articles; null when missing
     * @return array{
     *     ok: bool,
     *     error_code: string|null,
     *     message: string|null,
     *     article_id: int|null,
     *     debug?: array{article_id: int, article_site_id: int, task_site_id: int}
     * }
     */
    public static function evaluateLocalCandidate(
        int $candidateArticleId,
        int $taskSiteId,
        ?int $localArticleSiteId,
    ): array {
        if ($candidateArticleId <= 0) {
            return [
                'ok' => false,
                'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                'message' => 'Thiếu article_id sau workflow.',
                'article_id' => null,
            ];
        }

        if ($localArticleSiteId === null) {
            return [
                'ok' => false,
                'error_code' => ContentProjectErrorCode::ArticleRelationMissing->value,
                'message' => 'article_id không phải local articles.id — từ chối bind.',
                'article_id' => null,
            ];
        }

        $articleSiteId = (int) $localArticleSiteId;
        if ($taskSiteId > 0 && $articleSiteId !== $taskSiteId) {
            return [
                'ok' => false,
                'error_code' => ContentProjectErrorCode::ArticleSiteMismatch->value,
                'message' => sprintf(
                    'article_site_mismatch: article_id=%d article_site_id=%d task_site_id=%d',
                    $candidateArticleId,
                    $articleSiteId,
                    $taskSiteId,
                ),
                'article_id' => null,
                'debug' => [
                    'article_id' => $candidateArticleId,
                    'article_site_id' => $articleSiteId,
                    'task_site_id' => $taskSiteId,
                ],
            ];
        }

        return [
            'ok' => true,
            'error_code' => null,
            'message' => null,
            'article_id' => $candidateArticleId,
        ];
    }
}
