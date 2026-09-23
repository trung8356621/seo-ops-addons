<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use Omnichannel\Addons\SiteSync\Models\SeoArticleScoreSource;
use Omnichannel\Addons\SiteSync\Support\SiteSyncWpIdentity;
use App\Models\Site;

final class ArticleScoreSourceReconciler
{
    /**
     * Store scores per source — never merge into a fake single plugin score.
     *
     * @param  list<array<string, mixed>>  $scores
     * @return array{upserted: int}
     */
    public function reconcile(Site $site, array $scores): array
    {
        $upserted = 0;
        $siteId = (int) $site->id;

        foreach ($scores as $row) {
            $source = trim((string) ($row['source'] ?? ''));
            $wpId = (int) ($row['wordpress_id'] ?? 0);
            if ($source === '' || $wpId <= 0) {
                continue;
            }

            $articleId = null;
            $isTerm = array_key_exists('wp_is_term', $row)
                ? (bool) $row['wp_is_term']
                : false;
            $article = SiteSyncWpIdentity::find($siteId, $wpId, $isTerm);
            if ($article !== null) {
                $articleId = (int) $article->id;
            }

            SeoArticleScoreSource::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'wordpress_id' => $wpId,
                    'source' => $source,
                ],
                [
                    'article_id' => $articleId,
                    'score' => isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : null,
                    'raw' => is_array($row['raw'] ?? null) ? $row['raw'] : $row,
                ],
            );
            $upserted++;
        }

        return ['upserted' => $upserted];
    }
}
