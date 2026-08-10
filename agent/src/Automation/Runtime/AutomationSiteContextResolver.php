<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Runtime;

use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\SiteContext;
use Omnichannel\Addons\Agent\Automation\Support\CanonicalIds;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;
use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use Throwable;

final class AutomationSiteContextResolver
{
    /**
     * @param  array<string, mixed>  $hints  may include site_id, connection_id, article_id (aliases normalized)
     */
    public function resolve(ActionContext $context, array $hints = []): SiteContext
    {
        $hints = CanonicalIds::normalizeContextAttributes($hints);

        $siteId = CanonicalIds::nullableInt($hints['site_id'] ?? null) ?? $context->siteId;
        $connectionId = CanonicalIds::nullableInt($hints['connection_id'] ?? null) ?? $context->connectionId;
        $articleId = CanonicalIds::nullableInt($hints['article_id'] ?? null);

        if ($siteId === null && $articleId !== null) {
            try {
                $siteId = CanonicalIds::nullableInt(
                    SeoArticle::query()->whereKey($articleId)->value('site_id'),
                );
            } catch (Throwable) {
                // DB may be unavailable in unit tests.
            }
        }

        $siteDomain = null;
        if ($siteId !== null) {
            try {
                $siteDomain = Site::query()->whereKey($siteId)->value('domain');
                $siteDomain = is_string($siteDomain) ? $siteDomain : null;
            } catch (Throwable) {
                $siteDomain = null;
            }
        }

        $connectionHash = null;
        if ($connectionId === null) {
            $current = SeoConnectionContext::current();
            if ($current instanceof SeoDatabaseConnection) {
                $connectionId = (int) $current->id;
                $connectionHash = (string) $current->hash_id;
            }
        } else {
            try {
                $connectionHash = SeoDatabaseConnection::query()->whereKey($connectionId)->value('hash_id');
                $connectionHash = is_string($connectionHash) ? $connectionHash : null;
            } catch (Throwable) {
                $connectionHash = null;
            }
        }

        return new SiteContext(
            teamId: $context->teamId,
            siteId: $siteId,
            siteDomain: $siteDomain,
            connectionId: $connectionId,
            connectionHash: $connectionHash,
            locale: $context->locale,
            timezone: SeoDisplayTimezone::name(),
            wordpressCapabilities: [
                'article_outbound_sync' => true,
                'article_create_draft' => false,
                'article_update_without_publish_status' => false,
                'scheduled_publish_via_laravel_cron' => true,
            ],
            settings: [],
        );
    }
}
