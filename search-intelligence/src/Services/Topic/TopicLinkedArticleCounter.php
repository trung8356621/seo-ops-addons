<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

/**
 * Site-scoped DISTINCT Focus Article counts for Topic / Keyword Landscape.
 *
 * Canonical Topic/Topical Map `article_count` =
 * DISTINCT Focus Articles of eligible member Keywords (not linkMap sources, not edges).
 *
 * NEVER aggregate by keyword_id alone — always require site_id.
 */
final class TopicLinkedArticleCounter
{
    /**
     * Distinct Focus Articles for member keywords of one Topic on this site.
     *
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds  MCP-quarantined (or other) keyword ids to omit
     */
    public function countForTopic(int $siteId, int $topicId, array|null $excludeKeywordIds = null): int
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return 0;
        }

        $exclude = $this->normalizeExcludeMap($excludeKeywordIds);

        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && ! isset($exclude[$id]))
            ->values()
            ->all();

        return $this->countForKeywords($siteId, $keywordIds);
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     */
    public function countForKeywords(int $siteId, array $keywordIds, array|null $excludeKeywordIds = null): int
    {
        $exclude = $this->normalizeExcludeMap($excludeKeywordIds);
        $keywordIds = array_values(array_filter(
            array_map('intval', $keywordIds),
            static fn (int $id): bool => $id > 0 && ! isset($exclude[$id]),
        ));
        if ($siteId <= 0 || $keywordIds === []) {
            return 0;
        }

        return count($this->focusArticleIdsByKeyword($siteId, $keywordIds));
    }

    /**
     * Batch counts for many topics on one site (no N+1).
     *
     * @param  list<int>  $topicIds
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     * @return array<int, int> topic_id => distinct focus article count
     */
    public function countForTopics(int $siteId, array $topicIds, array|null $excludeKeywordIds = null): array
    {
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        $out = array_fill_keys($topicIds, 0);
        if ($siteId <= 0 || $topicIds === []) {
            return $out;
        }

        $exclude = $this->normalizeExcludeMap($excludeKeywordIds);

        $memberships = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'keyword_id']);

        /** @var array<int, list<int>> $byTopic */
        $byTopic = [];
        /** @var list<int> $allKeywordIds */
        $allKeywordIds = [];
        foreach ($memberships as $row) {
            $topicId = (int) $row->topic_id;
            $keywordId = (int) $row->keyword_id;
            if ($keywordId <= 0 || isset($exclude[$keywordId])) {
                continue;
            }
            $byTopic[$topicId][] = $keywordId;
            $allKeywordIds[] = $keywordId;
        }
        $allKeywordIds = array_values(array_unique($allKeywordIds));
        if ($allKeywordIds === []) {
            return $out;
        }

        $focusByKeyword = $this->focusArticleIdMap($siteId, $allKeywordIds);

        foreach ($byTopic as $topicId => $keywordIds) {
            /** @var array<int, true> $articles */
            $articles = [];
            foreach ($keywordIds as $keywordId) {
                $articleId = $focusByKeyword[$keywordId] ?? null;
                if ($articleId !== null && $articleId > 0) {
                    $articles[$articleId] = true;
                }
            }
            $out[$topicId] = count($articles);
        }

        return $out;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return list<int> distinct focus article ids
     */
    private function focusArticleIdsByKeyword(int $siteId, array $keywordIds): array
    {
        $map = $this->focusArticleIdMap($siteId, $keywordIds);

        return array_values(array_unique(array_filter(
            array_values($map),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Resolve Focus Article id per keyword (site-scoped meta preferred; legacy global only if same site).
     *
     * @param  list<int>  $keywordIds
     * @return array<int, int> keyword_id => article_id
     */
    private function focusArticleIdMap(int $siteId, array $keywordIds): array
    {
        if ($siteId <= 0 || $keywordIds === []) {
            return [];
        }

        $siteKey = KeywordMetaKey::siteMainArticleId($siteId);
        $legacyKey = KeywordMetaKey::MainArticleId->value;

        $rows = DB::connection('omi_seo_ai')
            ->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->whereIn('meta_key', [$siteKey, $legacyKey])
            ->get(['keyword_id', 'meta_key', 'meta_value']);

        /** @var array<int, int> $scoped */
        $scoped = [];
        /** @var array<int, int> $legacy */
        $legacy = [];
        foreach ($rows as $row) {
            $keywordId = (int) ($row->keyword_id ?? 0);
            $articleId = (int) ($row->meta_value ?? 0);
            if ($keywordId <= 0 || $articleId <= 0) {
                continue;
            }
            $metaKey = (string) ($row->meta_key ?? '');
            if ($metaKey === $siteKey) {
                $scoped[$keywordId] = $articleId;
            } elseif ($metaKey === $legacyKey) {
                $legacy[$keywordId] = $articleId;
            }
        }

        /** @var array<int, int> $candidateByKeyword */
        $candidateByKeyword = [];
        foreach ($keywordIds as $keywordId) {
            if (isset($scoped[$keywordId])) {
                $candidateByKeyword[$keywordId] = $scoped[$keywordId];
            } elseif (isset($legacy[$keywordId])) {
                $candidateByKeyword[$keywordId] = $legacy[$keywordId];
            }
        }

        if ($candidateByKeyword === []) {
            return [];
        }

        $validArticleIds = DB::connection('omi_seo_ai')
            ->table('articles')
            ->where('site_id', $siteId)
            ->whereNull('deleted_at')
            ->whereIn('id', array_values(array_unique($candidateByKeyword)))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $validSet = array_fill_keys($validArticleIds, true);

        $out = [];
        foreach ($candidateByKeyword as $keywordId => $articleId) {
            if (isset($validSet[$articleId])) {
                $out[$keywordId] = $articleId;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, true>|list<int>|null  $excludeKeywordIds
     * @return array<int, true>
     */
    private function normalizeExcludeMap(array|null $excludeKeywordIds): array
    {
        if ($excludeKeywordIds === null || $excludeKeywordIds === []) {
            return [];
        }

        $out = [];
        foreach ($excludeKeywordIds as $key => $value) {
            if (is_int($key) && $value === true) {
                if ($key > 0) {
                    $out[$key] = true;
                }

                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        return $out;
    }
}
