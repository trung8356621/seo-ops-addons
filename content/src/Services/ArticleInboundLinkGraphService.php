<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Internal-link graph helpers for Link Assistant orphan-page targets.
 * Orphan target = inbound internal count is 0 (keyword_id may be null).
 */
final class ArticleInboundLinkGraphService
{
    public const ORPHAN_SUGGESTION_LIMIT = 8;

    public static function isOrphan(int $inboundInternalCount): bool
    {
        return $inboundInternalCount === 0;
    }

    /**
     * Filter already-ranked internal suggestions to same-site orphan targets.
     * Does not dump the full site orphan set — only relevant suggestion candidates.
     *
     * @param  list<array<string, mixed>>  $internalSuggestions
     * @return list<array<string, mixed>>
     */
    public function pickOrphanSuggestions(array $internalSuggestions, int $currentArticleId, int $limit = self::ORPHAN_SUGGESTION_LIMIT): array
    {
        $candidateIds = [];
        foreach ($internalSuggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $targetId = (int) ($row['target_article_id'] ?? 0);
            if ($targetId <= 0 || $targetId === $currentArticleId) {
                continue;
            }
            $candidateIds[$targetId] = true;
        }

        $orphanIds = $this->orphanTargetIdSet(array_keys($candidateIds));

        return self::selectOrphanRows($internalSuggestions, $currentArticleId, $orphanIds, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $internalSuggestions
     * @param  array<int, true>  $orphanIds
     * @return list<array<string, mixed>>
     */
    public static function selectOrphanRows(
        array $internalSuggestions,
        int $currentArticleId,
        array $orphanIds,
        int $limit = self::ORPHAN_SUGGESTION_LIMIT,
    ): array {
        $picked = [];
        $seen = [];
        foreach ($internalSuggestions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $targetId = (int) ($row['target_article_id'] ?? 0);
            if ($targetId <= 0 || $targetId === $currentArticleId || isset($seen[$targetId])) {
                continue;
            }
            if (! isset($orphanIds[$targetId])) {
                continue;
            }
            $seen[$targetId] = true;
            $picked[] = $row;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param  list<int>  $targetArticleIds
     * @return array<int, true>
     */
    public function orphanTargetIdSet(array $targetArticleIds): array
    {
        $ids = [];
        foreach ($targetArticleIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $inboundCounts = $this->inboundInternalCounts(array_values($ids));
        $orphans = [];
        foreach ($ids as $id) {
            if (self::isOrphan((int) ($inboundCounts[$id] ?? 0))) {
                $orphans[$id] = true;
            }
        }

        return $orphans;
    }

    /**
     * @param  list<int>  $targetArticleIds
     * @return array<int, int> target_article_id => inbound internal count
     */
    public function inboundInternalCounts(array $targetArticleIds): array
    {
        if ($targetArticleIds === []) {
            return [];
        }

        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $internal = SeoLinkMapType::Internal->value;
        $ignored = SeoLinkMapStatus::Ignored->value;

        $rows = SeoLinkMap::query()
            ->whereIn('target_article_id', $targetArticleIds)
            ->where('link_type', $internal)
            ->where('status', '!=', $ignored)
            ->selectRaw('target_article_id, COUNT(*) as inbound_count')
            ->groupBy('target_article_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->target_article_id] = (int) $row->inbound_count;
        }

        return $counts;
    }
}
