<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Support\ArticleContentGenerationBadge;

/**
 * Content Project item list AI MODE — same SSOT as editor FREE badge.
 *
 * {@see ArticleContentGenerationBadge}: content-body PromptResult snapshot via
 * seo_prompt_result_links (article_id), then shape + route_cost stamps.
 * Shape never decides FREE vs PAID. No per-row queries.
 */
final class ContentProjectItemAiModeReadModel
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function apply(array $rows): array
    {
        $articleIds = [];
        foreach ($rows as $row) {
            $articleId = (int) ($row['article_id'] ?? 0);
            if ($articleId > 0) {
                $articleIds[] = $articleId;
            }
        }

        $byArticle = ArticleContentGenerationBadge::forArticleIds($articleIds);
        foreach ($rows as $index => $row) {
            $articleId = (int) ($row['article_id'] ?? 0);
            $badge = $byArticle[$articleId] ?? ArticleContentGenerationBadge::empty();
            $mode = $this->fromBadge($badge);
            $rows[$index]['ai_mode'] = $mode['mode'];
            $rows[$index]['ai_mode_shape'] = $mode['shape'];
            $rows[$index]['ai_mode_label'] = $mode['label'];
        }

        return $rows;
    }

    /**
     * @param  array{
     *     show_free_badge: bool,
     *     route_cost: string|null,
     *     generation_shape: string|null,
     *     generation_shape_source: string|null
     * }  $badge
     * @return array{mode: string|null, shape: string|null, label: string}
     */
    private function fromBadge(array $badge): array
    {
        $cost = strtolower(trim((string) ($badge['route_cost'] ?? '')));
        if (! in_array($cost, ['free', 'paid'], true)) {
            return ContentProjectItemAiModeClassifier::unknown();
        }

        return ContentProjectItemAiModeClassifier::classify(
            [$cost],
            isset($badge['generation_shape']) ? (string) $badge['generation_shape'] : null,
        );
    }
}
