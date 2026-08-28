<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpTokenEstimator;

final class ClusterIndexMcpPreviewSummary
{
    public const TARGET_COVERAGE_PERCENT = 87.0;

    public function __construct(
        private readonly SiteMcpClusterTopicalProfileBuilder $profileBuilder,
        private readonly McpTokenEstimator $tokenEstimator,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @return array{
     *     cluster_count: int,
     *     coverage_percent: float,
     *     estimated_tokens: int,
     *     total_topics: int
     * }
     */
    public function summarize(int $siteId, ?array $languageVariants = null): array
    {
        if ($siteId <= 0) {
            return [
                'cluster_count' => 0,
                'coverage_percent' => 0.0,
                'estimated_tokens' => 0,
                'total_topics' => 0,
            ];
        }

        $profile = $this->profileBuilder->build($siteId);
        $topics = is_array($profile['topics'] ?? null) ? $profile['topics'] : [];
        if ($topics === []) {
            return [
                'cluster_count' => 0,
                'coverage_percent' => 0.0,
                'estimated_tokens' => 0,
                'total_topics' => 0,
            ];
        }

        if ($languageVariants !== null && $languageVariants !== []) {
            $allowedKeys = [];
            foreach (app(KeywordClusterQuery::class)->clusterAggregates($siteId, 500, $languageVariants) as $row) {
                $key = trim((string) ($row->cluster_key ?? ''));
                if ($key !== '') {
                    $allowedKeys[$key] = true;
                }
            }
            $topics = array_values(array_filter(
                $topics,
                static function (array $topic) use ($allowedKeys): bool {
                    $key = trim((string) ($topic['cluster_key'] ?? $topic['id'] ?? $topic['name'] ?? ''));

                    return $key !== '' && isset($allowedKeys[$key]);
                },
            ));
        }

        if ($topics === []) {
            return [
                'cluster_count' => 0,
                'coverage_percent' => 0.0,
                'estimated_tokens' => 0,
                'total_topics' => 0,
            ];
        }

        $selected = $this->selectPreviewSubset($topics);
        $coverage = round(array_sum(array_map(
            static fn (array $topic): float => (float) ($topic['weight'] ?? 0),
            $selected,
        )), 1);

        $lines = SiteMcpClusterTopicalProfileBuilder::compactLines(['topics' => $selected]);
        $tokens = $this->tokenEstimator->estimate(implode("\n", $lines))['estimated_tokens'];

        return [
            'cluster_count' => count($selected),
            'coverage_percent' => $coverage,
            'estimated_tokens' => $tokens,
            'total_topics' => count($topics),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     * @return list<array<string, mixed>>
     */
    public function selectPreviewSubset(array $topics, float $targetPercent = self::TARGET_COVERAGE_PERCENT): array
    {
        $pinned = [];
        $discovered = [];
        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $state = (string) ($topic['state'] ?? 'active');
            $source = (string) ($topic['source'] ?? SeoTopicClusterMeta::SOURCE_AUTO);
            $priority = trim((string) ($topic['priority'] ?? ''));
            if ($state === 'planned' || $source === SeoTopicClusterMeta::SOURCE_MANUAL || $priority !== '') {
                $pinned[] = $topic;

                continue;
            }
            $discovered[] = $topic;
        }

        usort($discovered, static function (array $a, array $b): int {
            $byWeight = ((float) ($b['weight'] ?? 0)) <=> ((float) ($a['weight'] ?? 0));
            if ($byWeight !== 0) {
                return $byWeight;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $selected = $pinned;
        $coverage = array_sum(array_map(
            static fn (array $topic): float => (float) ($topic['weight'] ?? 0),
            $selected,
        ));

        foreach ($discovered as $topic) {
            if ($coverage >= $targetPercent) {
                break;
            }
            $selected[] = $topic;
            $coverage += (float) ($topic['weight'] ?? 0);
        }

        return array_values($selected);
    }
}
