<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;

/**
 * Canonical ownership partition for manual archived workspace GC.
 *
 * Active ownership = current non-archived Content Project task (see ContentProjectArticleMembership).
 * Historical archive snapshot / archived / soft-deleted tasks do not count.
 */
final class ContentProjectWorkspaceArticleOwnershipGuard
{
    public function __construct(
        private readonly ContentProjectArticleMembership $membership,
    ) {}

    /**
     * @param  list<int|string>  $historicalArticleIds
     * @return array{
     *     considered: list<int>,
     *     safe: list<int>,
     *     skipped_reused: list<int>,
     * }
     */
    public function partitionHistoricalArticles(array $historicalArticleIds): array
    {
        $considered = [];
        foreach ($historicalArticleIds as $rawId) {
            $articleId = (int) $rawId;
            if ($articleId > 0) {
                $considered[$articleId] = $articleId;
            }
        }
        $considered = array_values($considered);

        $safe = [];
        $skippedReused = [];

        foreach ($considered as $articleId) {
            if ($this->membership->belongsToActiveContentProject($articleId)) {
                $skippedReused[] = $articleId;
                continue;
            }

            $safe[] = $articleId;
        }

        return [
            'considered' => $considered,
            'safe' => $safe,
            'skipped_reused' => $skippedReused,
        ];
    }
}
