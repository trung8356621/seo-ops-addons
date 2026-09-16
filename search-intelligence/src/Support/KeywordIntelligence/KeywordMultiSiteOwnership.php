<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared multi-site ownership check for keyword lifecycle cleanup.
 * Global classification / hard-delete must not run while another site still owns the keyword.
 */
final class KeywordMultiSiteOwnership
{
    /**
     * True when keyword still has site meta or article link ownership on any site other than $excludeSiteId.
     */
    public static function isSharedWithOtherSites(int $keywordId, int $excludeSiteId): bool
    {
        if ($keywordId <= 0) {
            return false;
        }

        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $metas = DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', 'like', 'site.%')
                ->pluck('meta_key');

            foreach ($metas as $key) {
                if (preg_match('/^site\.(\d+)\./', (string) $key, $m) !== 1) {
                    continue;
                }
                if ((int) $m[1] !== $excludeSiteId && (int) $m[1] > 0) {
                    return true;
                }
            }
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')
            && Schema::connection('omi_seo_ai')->hasTable('articles')) {
            $hasOtherLinks = DB::connection('omi_seo_ai')->table('seo_link_maps as lm')
                ->join('articles as a', 'a.id', '=', 'lm.source_article_id')
                ->where('lm.keyword_id', $keywordId)
                ->where('a.site_id', '!=', $excludeSiteId)
                ->exists();
            if ($hasOtherLinks) {
                return true;
            }
        }

        return false;
    }
}
