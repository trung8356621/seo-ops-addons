<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\V3;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SiteSync\Support\SiteSyncWpIdentity;

/**
 * Reconcile content-subtype drift (post|page|product) from fresh WP inventory.
 *
 * Same numeric WP id in the term namespace is NOT a collision — do not delete or
 * merge content↔term pairs. Only rewrite content_type / wp_post_type on the
 * content-namespace article.
 */
final class SiteSyncV3ContentTypeDriftRepair
{
    /**
     * @param  list<array{wp_id: int, wp_type: string, local_type: string}>  $typeMismatch
     * @return array{
     *     repaired: list<array{wp_id: int, from: string, to: string, article_id: int}>,
     *     unresolved: list<array{wp_id: int, wp_type: string, local_type: string, reason: string}>
     * }
     */
    public function repairFromInventory(int $siteId, array $typeMismatch): array
    {
        $repaired = [];
        $unresolved = [];

        foreach ($typeMismatch as $row) {
            if (! is_array($row)) {
                continue;
            }
            $wpId = (int) ($row['wp_id'] ?? 0);
            $wpType = strtolower(trim((string) ($row['wp_type'] ?? '')));
            $localType = strtolower(trim((string) ($row['local_type'] ?? '')));

            if ($wpId <= 0 || ! in_array($wpType, ContentType::values(), true)) {
                $unresolved[] = [
                    'wp_id' => $wpId,
                    'wp_type' => $wpType,
                    'local_type' => $localType,
                    'reason' => 'invalid_wp_type',
                ];
                continue;
            }

            if ($wpType === $localType) {
                continue;
            }

            $article = SiteSyncWpIdentity::findContent($siteId, $wpId);
            if ($article === null) {
                $unresolved[] = [
                    'wp_id' => $wpId,
                    'wp_type' => $wpType,
                    'local_type' => $localType,
                    'reason' => 'content_article_missing',
                ];
                continue;
            }

            // Refuse to mutate term-namespace rows even if a buggy caller passed one.
            if (ArticleContentClassification::for($article)->isTerm()) {
                $unresolved[] = [
                    'wp_id' => $wpId,
                    'wp_type' => $wpType,
                    'local_type' => $localType,
                    'reason' => 'refused_term_namespace',
                ];
                continue;
            }

            ArticleContentClassification::persist($article, [
                'content_type' => $wpType,
                'wp_is_term' => false,
                'wp_post_type' => $wpType,
            ]);

            $repaired[] = [
                'wp_id' => $wpId,
                'from' => $localType,
                'to' => $wpType,
                'article_id' => (int) $article->id,
            ];
        }

        return [
            'repaired' => $repaired,
            'unresolved' => $unresolved,
        ];
    }
}
