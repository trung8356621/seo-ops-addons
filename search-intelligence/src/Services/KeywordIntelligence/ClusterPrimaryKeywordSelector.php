<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Chọn primary keyword cho 1 cluster: manual override luôn thắng, sau đó ưu tiên
 * priority/relevance/business_value. Search volume CHỈ dùng làm tie-breaker cuối cùng,
 * không bao giờ là tiêu chí chính (tránh cluster bị volume-only chi phối).
 */
final class ClusterPrimaryKeywordSelector
{
    /**
     * @param  Collection<int, SeoKiKeyword>|list<SeoKiKeyword>  $keywords
     */
    public function select(Collection|array $keywords): SeoKiKeyword
    {
        $list = $keywords instanceof Collection ? $keywords->values()->all() : array_values($keywords);

        if ($list === []) {
            throw new InvalidArgumentException('Cannot select a primary keyword from an empty list.');
        }

        foreach ($list as $keyword) {
            if ($this->isManualPrimary($keyword)) {
                return $keyword;
            }
        }

        usort($list, function (SeoKiKeyword $a, SeoKiKeyword $b): int {
            $priorityA = $this->priorityValue($a);
            $priorityB = $this->priorityValue($b);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $relevanceA = (float) ($a->relevance_score ?? 0);
            $relevanceB = (float) ($b->relevance_score ?? 0);
            if ($relevanceA !== $relevanceB) {
                return $relevanceB <=> $relevanceA;
            }

            $businessA = (float) ($a->business_value_score ?? 0);
            $businessB = (float) ($b->business_value_score ?? 0);
            if ($businessA !== $businessB) {
                return $businessB <=> $businessA;
            }

            // Tie-breaker cuối cùng, KHÔNG dùng volume làm tiêu chí chính.
            return ((int) ($b->search_volume ?? 0)) <=> ((int) ($a->search_volume ?? 0));
        });

        return $list[0];
    }

    private function isManualPrimary(SeoKiKeyword $keyword): bool
    {
        if (! (bool) $keyword->is_primary) {
            return false;
        }

        $fieldSources = (array) ($keyword->field_sources ?? []);

        return ($fieldSources['is_primary'] ?? null) === 'manual';
    }

    private function priorityValue(SeoKiKeyword $keyword): float
    {
        return (float) ($keyword->priority_score ?? $keyword->total_score ?? 0);
    }
}
