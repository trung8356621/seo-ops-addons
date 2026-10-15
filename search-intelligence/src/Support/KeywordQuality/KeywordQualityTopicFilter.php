<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read-time Topic filter. Does not write seo_topic_keywords.
 */
final class KeywordQualityTopicFilter
{
    public function apply(EloquentBuilder|QueryBuilder $query, int $siteId, string $keywordColumn): void
    {
        if ($siteId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return;
        }

        $query->whereNotExists(function (QueryBuilder $sub) use ($siteId, $keywordColumn): void {
            $sub->selectRaw('1')
                ->from('seo_keyword_quality_tags as kq_tags')
                ->join('seo_keyword_quality_policies as kq_policies', function ($join): void {
                    $join->on('kq_policies.site_id', '=', 'kq_tags.site_id')
                        ->on('kq_policies.code', '=', 'kq_tags.code');
                })
                ->where('kq_tags.site_id', $siteId)
                ->where('kq_policies.exclude_from_topic', 1)
                ->whereColumn('kq_tags.keyword_id', $keywordColumn);
        });
    }

    /**
     * @return array<int, true>
     */
    public function excludedIdSet(int $siteId): array
    {
        if ($siteId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return [];
        }

        $ids = DB::connection('omi_seo_ai')
            ->table('seo_keyword_quality_tags as kq_tags')
            ->join('seo_keyword_quality_policies as kq_policies', function ($join): void {
                $join->on('kq_policies.site_id', '=', 'kq_tags.site_id')
                    ->on('kq_policies.code', '=', 'kq_tags.code');
            })
            ->where('kq_tags.site_id', $siteId)
            ->where('kq_policies.exclude_from_topic', 1)
            ->distinct()
            ->pluck('kq_tags.keyword_id');

        $set = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $set[$id] = true;
            }
        }

        return $set;
    }
}
