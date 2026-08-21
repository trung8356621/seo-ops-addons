<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

final class KeywordClusterSiteScope
{
    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public static function apply(Builder $query, ?int $siteId): Builder
    {
        if ($siteId !== null && $siteId > 0) {
            return $query->forSite($siteId);
        }

        return $query;
    }

    /**
     * @return list<int>
     */
    public static function keywordIds(?int $siteId): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        return self::apply(Keyword::query(), $siteId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public static function keywordIdSubquery(?int $siteId): QueryBuilder
    {
        $scoped = self::apply(Keyword::query(), $siteId)->select('keywords.id');

        return DB::connection('omi_seo_ai')
            ->query()
            ->fromSub($scoped->getQuery(), 'scoped_site_keywords')
            ->select('id');
    }
}
