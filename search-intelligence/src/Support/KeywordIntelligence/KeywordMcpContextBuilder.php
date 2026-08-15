<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use App\Models\Site;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;

/**
 * Compact keyword MCP payload. Reuses landscape + generation context. No raw keyword dump.
 */
final class KeywordMcpContextBuilder
{
    public const SCHEMA = 'keywords.mcp.v1';

    public const MAX_CLUSTERS = 25;

    public const MAX_GROUPS = 20;

    public function __construct(
        private readonly KeywordClassificationService $classification,
        private readonly KeywordGenerationContextBuilder $generation,
        private readonly KeywordTagQuery $tagQuery,
    ) {}

    /**
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }
     */
    public function build(int $siteId, string $periodKey): array
    {
        $landscape = $this->classification->landscape($siteId);
        $progress = $this->classification->progress($siteId);
        $tags = $this->tagCounts($siteId);
        $groups = $this->groupDistribution($siteId);
        $generation = $this->generation->build($landscape, [
            'site' => (string) (Site::query()->find($siteId)?->domain ?? ''),
            'max_topics' => 30,
            'max_exclusions' => 80,
        ]);

        return $this->fromPrepared($landscape, $tags, $groups, $generation, $periodKey, $siteId, $this->sourceUpdatedAtFrom($landscape, $progress));
    }

    public function sourceUpdatedAt(int $siteId): ?string
    {
        $landscape = $this->classification->landscape($siteId);
        $progress = $this->classification->progress($siteId);

        return $this->sourceUpdatedAtFrom($landscape, $progress);
    }

    /**
     * @param  array<string, mixed>  $landscape
     * @param  array<string, int>  $tags
     * @param  list<array{key: string, label: string, count: int}>  $groups
     * @param  array<string, mixed>  $generation
     * @param  array<string, mixed>  $progress
     * @return array{
     *   metrics: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   context: array<string, mixed>,
     *   source_updated_at: ?string
     * }
     */
    public function fromPrepared(
        array $landscape,
        array $tags,
        array $groups,
        array $generation,
        string $periodKey,
        int $siteId,
        ?string $sourceUpdatedAt,
    ): array {
        $clusters = is_array($landscape['clusters'] ?? null) ? $landscape['clusters'] : [];
        $compactClusters = [];
        $strong = [];
        $weak = [];
        $intents = [];
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $coverage = (string) ($cluster['coverage'] ?? 'unknown');
            $row = [
                'cluster_id' => (string) ($cluster['cluster'] ?? $cluster['cluster_key'] ?? ''),
                'name' => (string) ($cluster['primary'] ?? ''),
                'keyword_count' => (int) ($cluster['usable_keyword_count'] ?? 0),
                'article_count' => (int) ($cluster['target_pages'] ?? $cluster['published'] ?? 0),
                'coverage' => $coverage,
            ];
            $compactClusters[] = $row;
            if (in_array($coverage, ['healthy', 'saturated', 'strong'], true)) {
                $strong[] = $row;
            }
            if (in_array($coverage, ['weak', 'missing'], true)) {
                $weak[] = $row;
            }
            foreach ((array) ($cluster['intent_coverage'] ?? []) as $intent) {
                $key = (string) $intent;
                if ($key === '') {
                    continue;
                }
                $intents[$key] = ($intents[$key] ?? 0) + 1;
            }
        }
        usort($compactClusters, static fn (array $a, array $b): int => $b['keyword_count'] <=> $a['keyword_count']);
        $compactClusters = array_slice($compactClusters, 0, self::MAX_CLUSTERS);
        $strong = array_slice($strong, 0, 10);
        $weak = array_slice($weak, 0, 10);

        $clusteredKeywords = 0;
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $clusteredKeywords += (int) ($cluster['usable_keyword_count'] ?? 0);
        }
        $linked = (int) ($tags[KeywordTag::HAS_LINK] ?? 0);
        $tagTotal = (int) ($tags['_total'] ?? 0);
        $total = max((int) ($landscape['raw_keywords'] ?? 0), $tagTotal);
        $metrics = [
            'total' => $total,
            'focus' => (int) ($tags[KeywordTag::FOCUS] ?? 0),
            'error' => (int) ($tags[KeywordTag::ERROR] ?? 0),
            'excluded' => (int) ($tags[KeywordTag::SEO_EXCLUDED] ?? 0),
            'clusters' => (int) ($landscape['cluster_count'] ?? count($clusters)),
            'unclustered' => max(0, $total - $clusteredKeywords),
            'linked' => $linked,
            'internal_link_coverage' => $total > 0 ? round(min(1, $linked / $total), 3) : 0.0,
        ];
        $sortedGroups = $groups;
        usort($sortedGroups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $sortedGroups = array_slice($sortedGroups, 0, self::MAX_GROUPS);
        $weakGroups = array_values(array_filter(
            $sortedGroups,
            static fn (array $g): bool => (int) $g['count'] > 0 && (int) $g['count'] <= 6,
        ));
        $strongGroups = array_slice($sortedGroups, 0, 5);

        $generationContext = [
            'core_topics' => array_slice((array) ($generation['core_topics'] ?? []), 0, 10),
            'saturated_topics' => array_slice((array) ($generation['saturated_topics'] ?? []), 0, 8),
            'weak_topics' => array_slice((array) ($generation['weak_topics'] ?? []), 0, 10),
            'missing_directions' => array_slice((array) ($generation['missing_directions'] ?? []), 0, 10),
            'intent_gaps' => array_slice((array) ($generation['intent_gaps'] ?? []), 0, 10),
            'group_gaps' => array_slice($weakGroups, 0, 8),
            'existing_canonicals' => array_slice((array) ($generation['existing_canonicals'] ?? []), 0, 40),
            'generation_rules' => $generation['generation_rules'] ?? [],
        ];

        $summary = [
            'tags' => $metrics,
            'groups' => $sortedGroups,
            'intents' => $intents,
            'clusters' => $compactClusters,
            'strong_clusters' => $strong,
            'weak_clusters' => $weak,
            'linked_article_coverage' => $metrics['internal_link_coverage'],
        ];
        $context = [
            'schema' => self::SCHEMA,
            'period' => $periodKey,
            'site_id' => $siteId,
            'generation_context' => $generationContext,
            'gaps' => array_slice($weak, 0, 10),
        ];

        return [
            'metrics' => $metrics,
            'summary' => $summary,
            'context' => $context,
            'source_updated_at' => $sourceUpdatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $landscape
     * @param  array<string, mixed>  $progress
     */
    private function sourceUpdatedAtFrom(array $landscape, array $progress): ?string
    {
        $candidates = [
            is_string($landscape['generated_at'] ?? null) ? (string) $landscape['generated_at'] : null,
            is_string($landscape['classification_freshness'] ?? null) ? (string) $landscape['classification_freshness'] : null,
            is_string($progress['last_activity_at'] ?? null) ? (string) $progress['last_activity_at'] : null,
            is_string($progress['finished_at'] ?? null) ? (string) $progress['finished_at'] : null,
        ];
        $best = null;
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if ($best === null || $candidate > $best) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array<string, int>
     */
    private function tagCounts(int $siteId): array
    {
        $counts = [
            '_total' => Keyword::query()->forSite($siteId)->count(),
        ];
        foreach ([KeywordTag::FOCUS, KeywordTag::ERROR, KeywordTag::SEO_EXCLUDED, KeywordTag::HAS_LINK] as $tag) {
            $query = Keyword::query()->forSite($siteId);
            $counts[$tag] = $this->tagQuery->apply($query, [$tag])->count();
        }

        return $counts;
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function groupDistribution(int $siteId): array
    {
        try {
            $tags = Tag::query()->orderBy('name')->get(['id', 'name']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($tags as $tag) {
            $id = (int) $tag->id;
            $name = trim((string) $tag->name);
            if ($id <= 0 || $name === '') {
                continue;
            }
            $key = KeywordTag::groupCode($id);
            $count = $this->tagQuery->apply(Keyword::query()->forSite($siteId), [$key])->count();
            if ($count <= 0) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $name,
                'count' => $count,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($out, 0, self::MAX_GROUPS);
    }
}
