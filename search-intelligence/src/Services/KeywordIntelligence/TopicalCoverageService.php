<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;

/**
 * Coverage metrics per-topic + aggregate cho 1 lần build topical map. "authority_score"
 * KHÔNG phải chỉ số SEO ngoài (Domain Authority...) — là proxy nội bộ tính từ
 * relevance/opportunity/coverage ratio đã có sẵn trong hệ thống (authority_score_source =
 * internal_proxy). Topic có confidence thấp (ít dữ liệu/ít cluster) KHÔNG được gắn nhãn
 * coverage_status=full dù coverage_ratio cao — tránh báo cáo sai "đã phủ đầy đủ".
 */
final class TopicalCoverageService
{
    public const AUTHORITY_SCORE_SOURCE = 'internal_proxy';

    private const LOW_CONFIDENCE_THRESHOLD = 0.5;

    private const FULL_COVERAGE_RATIO = 0.8;

    private const PARTIAL_COVERAGE_RATIO = 0.4;

    /**
     * @param  list<array{
     *   topic_ref: string,
     *   topic_type: string,
     *   name: string,
     *   clusters: list<array{
     *     cluster_ref: string,
     *     keyword_count: int,
     *     relevance_score: float|null,
     *     opportunity_score: float|null,
     *     has_target_article: bool
     *   }>
     * }>  $topics
     * @return array{topics: list<array<string, mixed>>, aggregate: array<string, mixed>}
     */
    /**
     * Workspace adapter used by TopicalMapBuilder / Filament summary.
     *
     * @return array{
     *   cluster_count: int,
     *   approved_cluster_count: int,
     *   existing_article_count: int,
     *   coverage_score: float,
     *   gap_score: float,
     *   authority_score_source: string
     * }
     */
    public function summarize(SeoKeywordWorkspace $workspace): array
    {
        $clusterCount = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', '!=', KeywordClusterStatus::Excluded->value)
            ->count();

        $approvedClusterCount = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', KeywordClusterStatus::Approved->value)
            ->count();

        $existingArticleCount = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('target_article_ref')
            ->where('target_article_ref', '!=', '')
            ->count();

        $coverageScore = $clusterCount > 0
            ? round(min(100.0, ($approvedClusterCount / $clusterCount) * 100), 2)
            : 0.0;

        return [
            'cluster_count' => $clusterCount,
            'approved_cluster_count' => $approvedClusterCount,
            'existing_article_count' => $existingArticleCount,
            'coverage_score' => $coverageScore,
            'gap_score' => round(max(0.0, 100.0 - $coverageScore), 2),
            'authority_score_source' => self::AUTHORITY_SCORE_SOURCE,
        ];
    }

    public function calculate(array $topics): array
    {
        $topicResults = [];
        $totalClusters = 0;
        $totalCovered = 0;
        $authoritySum = 0.0;
        $lowConfidenceCount = 0;
        $fullCoverageCount = 0;

        foreach ($topics as $topic) {
            $result = $this->calculateTopic($topic);
            $topicResults[] = $result;

            $totalClusters += $result['cluster_count'];
            $totalCovered += $result['covered_cluster_count'];
            $authoritySum += $result['authority_score'];

            if ($result['coverage_status'] === 'low_confidence') {
                $lowConfidenceCount++;
            }
            if ($result['coverage_status'] === 'full') {
                $fullCoverageCount++;
            }
        }

        $topicCount = count($topicResults);

        return [
            'topics' => $topicResults,
            'aggregate' => [
                'topic_count' => $topicCount,
                'avg_authority_score' => $topicCount > 0 ? round($authoritySum / $topicCount, 2) : 0.0,
                'low_confidence_topic_count' => $lowConfidenceCount,
                'full_coverage_topic_count' => $fullCoverageCount,
                'overall_coverage_ratio' => $totalClusters > 0 ? round($totalCovered / $totalClusters, 4) : 0.0,
                'authority_score_source' => self::AUTHORITY_SCORE_SOURCE,
            ],
        ];
    }

    /**
     * @param  array{topic_ref: string, topic_type: string, name: string, clusters: list<array<string, mixed>>}  $topic
     * @return array<string, mixed>
     */
    private function calculateTopic(array $topic): array
    {
        $clusters = $topic['clusters'] ?? [];
        $clusterCount = count($clusters);
        $keywordCount = 0;
        $coveredCount = 0;
        $relevanceSum = 0.0;
        $opportunitySum = 0.0;
        $scoredCount = 0;

        foreach ($clusters as $cluster) {
            $keywordCount += (int) ($cluster['keyword_count'] ?? 0);
            if (! empty($cluster['has_target_article'])) {
                $coveredCount++;
            }

            $relevance = $cluster['relevance_score'] ?? null;
            $opportunity = $cluster['opportunity_score'] ?? null;
            if ($relevance !== null && $opportunity !== null) {
                $relevanceSum += (float) $relevance;
                $opportunitySum += (float) $opportunity;
                $scoredCount++;
            }
        }

        $avgRelevance = $scoredCount > 0 ? $relevanceSum / $scoredCount : 50.0;
        $avgOpportunity = $scoredCount > 0 ? $opportunitySum / $scoredCount : 50.0;
        $dataCompleteness = $clusterCount > 0 ? $scoredCount / $clusterCount : 0.0;
        $coverageRatio = $clusterCount > 0 ? $coveredCount / $clusterCount : 0.0;

        $confidence = min(0.95, 0.4 + ($dataCompleteness * 0.3) + (min($clusterCount, 5) * 0.06));
        $authorityScore = $this->clamp(($avgRelevance * 0.5) + ($avgOpportunity * 0.3) + ($coverageRatio * 100 * 0.2));

        $status = match (true) {
            $clusterCount === 0 => 'empty',
            $confidence < self::LOW_CONFIDENCE_THRESHOLD => 'low_confidence',
            $coverageRatio >= self::FULL_COVERAGE_RATIO => 'full',
            $coverageRatio >= self::PARTIAL_COVERAGE_RATIO => 'partial',
            default => 'emerging',
        };

        return [
            'topic_ref' => (string) ($topic['topic_ref'] ?? ''),
            'topic_type' => (string) ($topic['topic_type'] ?? ''),
            'name' => (string) ($topic['name'] ?? ''),
            'cluster_count' => $clusterCount,
            'keyword_count' => $keywordCount,
            'covered_cluster_count' => $coveredCount,
            'coverage_ratio' => round($coverageRatio, 4),
            'avg_relevance_score' => round($avgRelevance, 2),
            'avg_opportunity_score' => round($avgOpportunity, 2),
            'authority_score' => round($authorityScore, 2),
            'authority_score_source' => self::AUTHORITY_SCORE_SOURCE,
            'confidence' => round($confidence, 2),
            'coverage_status' => $status,
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
