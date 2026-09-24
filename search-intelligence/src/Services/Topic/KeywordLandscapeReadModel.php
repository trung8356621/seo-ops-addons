<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\SkipKeywordFromMcpService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;

/**
 * Canonical site-level Keyword Landscape (Keyword MCP type-1).
 *
 * SSOT for: Keyword MCP snapshots, domain.keyword_landscape, SEO Audit cluster suggestions.
 * Does not change MCP/DNA algorithms — only consolidates Topic Core reads.
 *
 * McpExcluded keywords are filtered here so they never contribute to MCP %, DNA,
 * coverage, or landscape_json consumers (Discover New Topics, SEO Audit, etc.).
 */
final class KeywordLandscapeReadModel
{
    public const DNA_LIMIT = 30;

    public function __construct(
        private readonly TopicLinkedArticleCounter $articleCounter,
        private readonly TopicTagMetricsResolver $tagMetrics = new TopicTagMetricsResolver,
        private readonly TopicTopicalShareCalculator $shareCalculator = new TopicTopicalShareCalculator,
        private readonly ?SkipKeywordFromMcpService $mcpSkip = null,
    ) {}

    private function mcpSkip(): SkipKeywordFromMcpService
    {
        return $this->mcpSkip ?? app(SkipKeywordFromMcpService::class);
    }

    public function forSite(int $siteId, bool $includeDna = true): KeywordLandscape
    {
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return new KeywordLandscape($siteId, [], null);
        }

        $topics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->orderBy('id')
            ->get(['id', 'site_id', 'name', 'status', 'updated_at']);

        if ($topics->isEmpty()) {
            return new KeywordLandscape($siteId, [], null);
        }

        /** @var list<int> $topicIds */
        $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $excludedKeywordIds = $this->mcpExcludedKeywordIdsForTopics($siteId, $topicIds);
        $articleCounts = $this->articleCounter->countForTopics($siteId, $topicIds, $excludedKeywordIds);
        $shares = $this->shareCalculator->percentages($articleCounts);
        $tagMetrics = $this->tagMetrics->forTopics($siteId, $topicIds, $articleCounts, $excludedKeywordIds);
        $dnaByTopic = $includeDna ? $this->loadDnaByTopic($siteId, $topicIds, $excludedKeywordIds) : [];

        $sourceUpdatedAt = null;
        $rows = [];
        foreach ($topics as $topic) {
            $topicId = (int) $topic->id;
            $name = trim((string) $topic->name);
            if ($name === '') {
                continue;
            }

            $articleCount = (int) ($articleCounts[$topicId] ?? 0);
            $mcp = round((float) ($shares[$topicId] ?? 0.0), 1);
            $tags = $tagMetrics[$topicId] ?? [];
            $dna = $dnaByTopic[$topicId] ?? [];
            $dnaCount = $includeDna
                ? count($dna)
                : (int) ($tags['dna_branch_count'] ?? 0);
            $updatedAt = $topic->updated_at?->toIso8601String();
            if (is_string($updatedAt) && $updatedAt !== '') {
                if ($sourceUpdatedAt === null || strcmp($updatedAt, $sourceUpdatedAt) > 0) {
                    $sourceUpdatedAt = $updatedAt;
                }
            }

            $rows[] = new KeywordLandscapeTopic(
                id: $topicId,
                name: $name,
                mcp: $mcp,
                dnaCount: $dnaCount,
                articleCount: $articleCount,
                hasFocusArticle: $articleCount > 0,
                coverage: (string) ($tags['coverage'] ?? 'unknown'),
                status: (string) ($topic->status ?? ''),
                dna: $dna,
                updatedAt: $updatedAt,
            );
        }

        usort($rows, static function (KeywordLandscapeTopic $a, KeywordLandscapeTopic $b): int {
            $byShare = $a->mcp <=> $b->mcp;
            if ($byShare !== 0) {
                return $byShare;
            }
            $byName = strcmp(mb_strtolower($a->name, 'UTF-8'), mb_strtolower($b->name, 'UTF-8'));
            if ($byName !== 0) {
                return $byName;
            }

            return $a->id <=> $b->id;
        });

        return new KeywordLandscape($siteId, array_values($rows), $sourceUpdatedAt);
    }

    public function findTopic(int $siteId, int $topicId, bool $includeDna = true): ?KeywordLandscapeTopic
    {
        if ($siteId <= 0 || $topicId <= 0) {
            return null;
        }

        $landscape = $this->forSite($siteId, $includeDna);

        return $landscape->findById($topicId);
    }

    public function sourceUpdatedAt(int $siteId): ?string
    {
        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return null;
        }

        $max = SeoTopic::query()
            ->where('site_id', $siteId)
            ->max('updated_at');

        if ($max === null || $max === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse((string) $max)->toIso8601String();
        } catch (\Throwable) {
            return is_string($max) ? $max : null;
        }
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, true>
     */
    private function mcpExcludedKeywordIdsForTopics(int $siteId, array $topicIds): array
    {
        if ($siteId <= 0 || $topicIds === []) {
            return [];
        }

        $keywordIds = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $this->mcpSkip()->skippedKeywordIdMap($keywordIds);
    }

    /**
     * @param  list<int>  $topicIds
     * @param  array<int, true>  $excludeKeywordIds
     * @return array<int, list<array{phrase: string, weight: int}>>
     */
    private function loadDnaByTopic(int $siteId, array $topicIds, array $excludeKeywordIds = []): array
    {
        if ($siteId <= 0 || $topicIds === [] || ! $this->topicDnaTableReady()) {
            return [];
        }

        $rows = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'keyword_id', 'value']);

        /** @var array<int, array<string, array{phrase: string, weight: int}>> $byTopic */
        $byTopic = [];
        foreach ($rows as $row) {
            $keywordId = (int) ($row->keyword_id ?? 0);
            if ($keywordId > 0 && isset($excludeKeywordIds[$keywordId])) {
                continue;
            }
            $topicId = (int) $row->topic_id;
            $phrase = $this->displayPhrase((string) ($row->value ?? ''));
            if ($phrase === '') {
                continue;
            }
            $key = mb_strtolower($phrase, 'UTF-8');
            if ($key === '') {
                continue;
            }
            if (! isset($byTopic[$topicId][$key])) {
                $byTopic[$topicId][$key] = [
                    'phrase' => $phrase,
                    'weight' => 0,
                ];
            }
            $byTopic[$topicId][$key]['weight']++;
        }

        $out = [];
        foreach ($byTopic as $topicId => $byKey) {
            $list = array_values($byKey);
            usort(
                $list,
                static function (array $a, array $b): int {
                    $byWeight = ((int) $b['weight']) <=> ((int) $a['weight']);
                    if ($byWeight !== 0) {
                        return $byWeight;
                    }

                    return strcmp(
                        mb_strtolower((string) $a['phrase'], 'UTF-8'),
                        mb_strtolower((string) $b['phrase'], 'UTF-8'),
                    );
                },
            );
            $list = array_slice($list, 0, self::DNA_LIMIT);
            foreach ($list as &$item) {
                if ((int) ($item['weight'] ?? 0) < 1) {
                    $item['weight'] = 1;
                }
            }
            unset($item);
            $out[$topicId] = $list;
        }

        return $out;
    }

    private function displayPhrase(string $phrase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $phrase) ?? '');
    }

    private function topicDnaTableReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna');
    }
}
