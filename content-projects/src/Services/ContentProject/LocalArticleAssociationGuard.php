<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Canonical gate: seo_project_tasks.article_id must be local omi_seo_ai.articles.id.
 * Never accept WordPress post IDs or other external namespaces.
 */
final class LocalArticleAssociationGuard
{
    /**
     * Return local articles.id when the candidate exists (optionally same site), else null.
     */
    public static function resolveLocalArticleId(?int $candidateId, ?int $siteId = null): ?int
    {
        $id = (int) ($candidateId ?? 0);
        if ($id <= 0) {
            return null;
        }

        $query = SeoArticle::query()->whereKey($id);
        if ($siteId !== null && $siteId > 0) {
            $query->where('site_id', $siteId);
        }

        return $query->exists() ? $id : null;
    }

    public static function isLocalArticleId(?int $candidateId, ?int $siteId = null): bool
    {
        return self::resolveLocalArticleId($candidateId, $siteId) !== null;
    }
}
