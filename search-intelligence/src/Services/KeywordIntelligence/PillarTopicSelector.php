<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterType;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Chọn pillar topic từ tập cluster đủ điều kiện — nhóm theo entity (chủ đề chính rút ra
 * từ tên cluster) chứ KHÔNG nhóm theo intent bucket đơn thuần (bug của TopicalMapBuilder
 * bản cũ, vốn biến MỌI cluster thành 1 trong vài pillar theo intent). Score xếp hạng pillar
 * kết hợp độ đa dạng cluster trong nhóm + relevance/opportunity trung bình + bonus khi cluster
 * đã được đánh dấu cluster_type=pillar — KHÔNG rank chỉ theo total_search_volume.
 */
final class PillarTopicSelector
{
    /** @var list<string> */
    private const STOPWORDS = [
        'la', 'gi', 'cua', 'va', 'cho', 'nhung', 'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'and', 'or',
    ];

    /**
     * @param  Collection<int, SeoKeywordCluster>  $clusters
     * @return array{
     *   groups: list<array{entity: string, clusters: list<SeoKeywordCluster>, score: float}>,
     *   pillar_groups: list<array{entity: string, clusters: list<SeoKeywordCluster>, score: float}>,
     *   overflow_group: array{entity: string, clusters: list<SeoKeywordCluster>, score: float}|null
     * }
     */
    public function select(Collection $clusters, int $maxPillars): array
    {
        $maxPillars = max(1, $maxPillars);
        $groups = $this->groupByEntity($clusters);

        usort($groups, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $pillarGroups = array_slice($groups, 0, $maxPillars);
        $overflow = array_slice($groups, $maxPillars);

        $overflowGroup = null;
        if ($overflow !== []) {
            $overflowClusters = [];
            foreach ($overflow as $group) {
                array_push($overflowClusters, ...$group['clusters']);
            }
            $overflowGroup = [
                'entity' => 'additional-topics',
                'clusters' => $overflowClusters,
                'score' => 0.0,
            ];
        }

        return [
            'groups' => $groups,
            'pillar_groups' => $pillarGroups,
            'overflow_group' => $overflowGroup,
        ];
    }

    public function entityKey(SeoKeywordCluster $cluster): string
    {
        $tokens = $this->tokenize($cluster);
        $entity = $tokens[0] ?? '';

        return $entity !== '' ? $entity : ('cluster-'.$cluster->id);
    }

    /**
     * Token "phụ" của cluster khác với entity của pillar — dùng để chia subtopic bên trong
     * 1 pillar. Fallback về search_intent rồi funnel_stage khi tên không có token thứ 2.
     */
    public function secondaryKey(SeoKeywordCluster $cluster, string $entity): string
    {
        $tokens = $this->tokenize($cluster);
        foreach ($tokens as $token) {
            if ($token !== $entity) {
                return $token;
            }
        }

        $intent = $cluster->search_intent instanceof KeywordSearchIntent ? $cluster->search_intent->value : null;
        if ($intent !== null && $intent !== KeywordSearchIntent::Unknown->value) {
            return $intent;
        }

        $funnel = $cluster->funnel_stage instanceof KeywordFunnelStage ? $cluster->funnel_stage->value : null;

        return $funnel ?? 'general';
    }

    /**
     * @param  Collection<int, SeoKeywordCluster>  $clusters
     * @return list<array{entity: string, clusters: list<SeoKeywordCluster>, score: float}>
     */
    private function groupByEntity(Collection $clusters): array
    {
        $buckets = [];
        foreach ($clusters as $cluster) {
            $entity = $this->entityKey($cluster);
            $buckets[$entity][] = $cluster;
        }

        $groups = [];
        foreach ($buckets as $entity => $members) {
            $groups[] = [
                'entity' => $entity,
                'clusters' => $members,
                'score' => $this->scoreGroup($members),
            ];
        }

        return $groups;
    }

    /**
     * @param  list<SeoKeywordCluster>  $members
     */
    private function scoreGroup(array $members): float
    {
        $count = count($members);
        $relevanceSum = 0.0;
        $opportunitySum = 0.0;
        $pillarBonus = 0.0;

        foreach ($members as $cluster) {
            $relevanceSum += (float) ($cluster->relevance_score ?? 50);
            $opportunitySum += (float) ($cluster->opportunity_score ?? 50);
            if ($cluster->cluster_type === KeywordClusterType::Pillar) {
                $pillarBonus = 20.0;
            }
        }

        $avgRelevance = $count > 0 ? $relevanceSum / $count : 50.0;
        $avgOpportunity = $count > 0 ? $opportunitySum / $count : 50.0;

        // Diversity (số cluster cùng chủ đề) là tín hiệu cấu trúc — cố ý KHÔNG dùng
        // total_search_volume để tránh rank pillar chỉ theo volume.
        return ($count * 10.0) + ($avgRelevance * 0.3) + ($avgOpportunity * 0.3) + $pillarBonus;
    }

    /**
     * @return list<string>
     */
    private function tokenize(SeoKeywordCluster $cluster): array
    {
        $source = trim((string) ($cluster->name !== '' && $cluster->name !== null ? $cluster->name : $cluster->slug));
        $normalized = Str::of($source)->lower()->ascii()->toString();

        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/u', $normalized) ?: [],
            static fn (string $token): bool => $token !== '' && ! in_array($token, self::STOPWORDS, true),
        ));
    }
}
