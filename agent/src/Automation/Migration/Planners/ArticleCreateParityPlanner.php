<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration\Planners;

/**
 * Shadow planner cho article.create — không ghi DB, không queue, không event.
 *
 * @phpstan-type ExistingState array{article_id: int, site_id: int, status?: string, post_type?: string}
 */
final class ArticleCreateParityPlanner
{
    /**
     * @param  array<string, mixed>  $input
     * @param  ExistingState|null  $existingByOrigin  Resolved ngoài planner (caller/bridge); null = chưa có
     * @return array<string, mixed>
     */
    public function plan(array $input, ?array $existingByOrigin = null): array
    {
        $siteId = (int) ($input['site_id'] ?? 0);
        $postType = trim((string) ($input['post_type'] ?? 'article')) ?: 'article';

        if ($existingByOrigin !== null && (int) ($existingByOrigin['article_id'] ?? 0) > 0) {
            return [
                'article_id' => (int) $existingByOrigin['article_id'],
                'site_id' => (int) ($existingByOrigin['site_id'] ?? $siteId),
                'status' => (string) ($existingByOrigin['status'] ?? 'draft'),
                'post_type' => (string) ($existingByOrigin['post_type'] ?? $postType),
                'deduplicated' => true,
                'origin_attached' => true,
                'changed_fields' => [],
            ];
        }

        return [
            'article_id' => null,
            'site_id' => $siteId > 0 ? $siteId : null,
            'status' => 'draft',
            'post_type' => $postType,
            'deduplicated' => false,
            'would_create' => true,
            'origin_attached' => false,
            'changed_fields' => ['article'],
        ];
    }
}
