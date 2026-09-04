<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use App\Models\Site;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Soft monthly content recommendation per site (NOT writer capacity / pack max).
 */
final class SiteMonthlyContentTargetService
{
    public const META_KEY = 'monthly_content_target';

    public function defaultTarget(): int
    {
        return ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;
    }

    public function forSite(Site|int $site): int
    {
        $model = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if (! $model instanceof Site) {
            return $this->defaultTarget();
        }

        $raw = trim((string) ($model->getMeta(self::META_KEY) ?? ''));
        if ($raw === '' || ! is_numeric($raw)) {
            // JSON wrapper compat if stored via putJson historically.
            $json = SiteSyncSiteMeta::getJson($model, self::META_KEY);
            if (is_array($json) && isset($json['target']) && is_numeric($json['target'])) {
                return max(1, (int) $json['target']);
            }

            return $this->defaultTarget();
        }

        return max(1, (int) $raw);
    }

    public function setForSite(Site $site, int $target): void
    {
        SiteSyncSiteMeta::put($site, self::META_KEY, (string) max(1, $target));
    }
}
