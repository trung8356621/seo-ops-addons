<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;

/**
 * Quota hooks config-driven — không hard-code billing.
 */
final class KeywordIntelligenceQuotaGuard
{
    public function canCreateWorkspace(int $siteId): bool
    {
        $max = (int) config('seo-content-ai.keyword_intelligence.limits.max_workspaces_per_site', 50);
        if ($max <= 0) {
            return true;
        }

        return SeoKeywordWorkspace::query()->where('site_id', $siteId)->count() < $max;
    }

    public function canImport(SeoKeywordWorkspace $workspace, int $newRowCount): bool
    {
        $maxImport = (int) config('seo-content-ai.keyword_intelligence.limits.max_keywords_per_import', 2000);
        if ($maxImport > 0 && $newRowCount > $maxImport) {
            return false;
        }

        $maxWorkspace = (int) config('seo-content-ai.keyword_intelligence.limits.max_keywords_per_workspace', 20000);
        if ($maxWorkspace <= 0) {
            return true;
        }

        return ((int) $workspace->keyword_count) + $newRowCount <= $maxWorkspace;
    }

    public function canConvert(int $clusterCount): bool
    {
        $max = (int) config('seo-content-ai.keyword_intelligence.limits.max_clusters_per_convert', 200);

        return $max <= 0 || $clusterCount <= $max;
    }

    public function requiresConfirmation(int $clusterCount): bool
    {
        $threshold = (int) config('seo-content-ai.keyword_intelligence.limits.convert_confirmation_threshold', 10);

        return $threshold > 0 && $clusterCount > $threshold;
    }
}
