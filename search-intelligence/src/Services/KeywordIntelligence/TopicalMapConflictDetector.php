<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordClusterStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordTopicClusterRelationship;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicClusterLink;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicCandidate;

/**
 * Phát hiện conflict trên cây TopicCandidate + tập cluster bị loại khỏi map — risk theo
 * thang info|warning|high|blocking. Dedupe theo fingerprint để UI không lặp cùng 1 cảnh báo.
 */
final class TopicalMapConflictDetector
{
    /** @var list<string> */
    private const INFO_EXCLUSION_REASONS = ['manual_preserved', 'draft_assignment_preserved'];

    /**
     * Workspace adapter for approve gate / builder finalize.
     *
     * @return list<array<string, mixed>>
     */
    public function detectForWorkspace(SeoKeywordWorkspace $workspace): array
    {
        $conflicts = [];

        $multiPrimary = SeoTopicClusterLink::query()
            ->selectRaw('cluster_id, COUNT(*) as c')
            ->where('relationship', KeywordTopicClusterRelationship::Primary->value)
            ->whereIn('topic_id', SeoKiTopic::query()->where('workspace_id', $workspace->id)->select('id'))
            ->groupBy('cluster_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('cluster_id');

        foreach ($multiPrimary as $clusterId) {
            $conflicts[] = [
                'code' => 'cluster_multiple_primary_topics',
                'type' => 'multiple_primary_topics',
                'cluster_ref' => KeywordIntelligencePublicRef::cluster((int) $clusterId),
                'blocking' => true,
                'risk' => 'blocking',
                'message' => 'Cluster has multiple primary topics.',
                'fingerprint' => hash('sha256', 'multi_primary|'.$clusterId),
            ];
        }

        $approvedWithoutTopic = SeoKeywordCluster::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', KeywordClusterStatus::Approved->value)
            ->whereNull('topic_id')
            ->limit(50)
            ->get();

        foreach ($approvedWithoutTopic as $cluster) {
            $conflicts[] = [
                'code' => 'cluster_without_primary_topic',
                'type' => 'approved_cluster_without_topic',
                'cluster_ref' => $cluster->public_ref,
                'blocking' => true,
                'risk' => 'blocking',
                'message' => 'Approved cluster missing primary topic.',
                'fingerprint' => hash('sha256', 'no_topic|'.$cluster->id),
            ];
        }

        $maxDepth = max(1, (int) (function_exists('config') ? config('seo-content-ai.keyword_intelligence.topical_map.max_depth', 4) : 4));
        $tooDeep = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->where('depth', '>', $maxDepth)
            ->limit(50)
            ->get();

        foreach ($tooDeep as $topic) {
            $conflicts[] = [
                'code' => 'topic_depth_overflow',
                'type' => 'topic_depth_exceeded',
                'topic_ref' => $topic->public_ref,
                'blocking' => true,
                'risk' => 'blocking',
                'message' => 'Topic exceeds max depth.',
                'fingerprint' => hash('sha256', 'depth|'.$topic->id),
            ];
        }

        return $conflicts;
    }

    /**
     * @param  list<TopicCandidate>|SeoKeywordWorkspace  $candidates
     * @param  list<array{cluster_ref: string, reason: string}>  $excludedClusters
     * @param  list<array{code: string, severity: string, message: string, candidate_ref: string|null}>  $hierarchyIssues
     * @return list<array<string, mixed>>
     */
    public function detect(
        array|SeoKeywordWorkspace $candidates,
        array $excludedClusters = [],
        array $hierarchyIssues = [],
        bool $includedReviewedClusters = false,
    ): array {
        if ($candidates instanceof SeoKeywordWorkspace) {
            return $this->detectForWorkspace($candidates);
        }

        $conflicts = [];
        $seen = [];

        foreach ($hierarchyIssues as $issue) {
            $this->push($conflicts, $seen, 'hierarchy_'.$issue['code'], $this->mapSeverity($issue['severity']), $issue['message'], ['candidate_ref' => $issue['candidate_ref'] ?? null]);
        }

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof TopicCandidate) {
                continue;
            }
            $type = $candidate->topicType instanceof \BackedEnum
                ? $candidate->topicType->value
                : (string) $candidate->topicType;
            if ($type !== 'pillar') {
                continue;
            }

            if ($this->countDescendantClusters($candidate, $candidates) === 0) {
                $this->push($conflicts, $seen, 'empty_pillar', 'info', "Pillar \"{$candidate->name}\" has no clusters attached.", ['candidate_ref' => $candidate->candidateRef]);
            }
        }

        $this->detectDuplicateNames($candidates, $conflicts, $seen);

        if ($includedReviewedClusters) {
            $this->push($conflicts, $seen, 'reviewed_clusters_included', 'warning', 'Map includes reviewed-only clusters — not convert-ready.', []);
        }

        foreach ($excludedClusters as $excluded) {
            $reason = (string) ($excluded['reason'] ?? 'unknown');
            $risk = in_array($reason, self::INFO_EXCLUSION_REASONS, true) ? 'info' : 'warning';
            $this->push(
                $conflicts,
                $seen,
                'cluster_excluded:'.$reason,
                $risk,
                "Cluster {$excluded['cluster_ref']} excluded from this build ({$reason}).",
                ['cluster_ref' => $excluded['cluster_ref'], 'reason' => $reason],
            );
        }

        return $conflicts;
    }

    /**
     * @param  list<TopicCandidate>  $candidates
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, bool>  $seen
     */
    private function detectDuplicateNames(array $candidates, array &$conflicts, array &$seen): void
    {
        $namesByParent = [];
        foreach ($candidates as $candidate) {
            $parentKey = $candidate->parentCandidateRef ?? '__root__';
            $key = mb_strtolower(trim($candidate->name));

            if (isset($namesByParent[$parentKey][$key])) {
                $this->push($conflicts, $seen, 'duplicate_topic_name', 'warning', "Duplicate topic name \"{$candidate->name}\" under the same parent.", ['candidate_ref' => $candidate->candidateRef]);
            }

            $namesByParent[$parentKey][$key] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, bool>  $seen
     * @param  array<string, mixed>  $context
     */
    private function push(array &$conflicts, array &$seen, string $code, string $risk, string $message, array $context): void
    {
        $fingerprint = hash('sha256', $code.'|'.(json_encode($context) ?: ''));
        if (isset($seen[$fingerprint])) {
            return;
        }
        $seen[$fingerprint] = true;

        $conflicts[] = [
            'code' => $code,
            'risk' => $risk,
            'message' => $message,
            'context' => $context,
            'fingerprint' => $fingerprint,
        ];
    }

    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'blocking' => 'blocking',
            'high' => 'high',
            'warning' => 'warning',
            default => 'info',
        };
    }

    /**
     * @param  list<TopicCandidate>  $all
     */
    private function countDescendantClusters(TopicCandidate $candidate, array $all): int
    {
        $count = count($candidate->clusterRefs);
        foreach ($all as $other) {
            if ($other->parentCandidateRef === $candidate->candidateRef) {
                $count += $this->countDescendantClusters($other, $all);
            }
        }

        return $count;
    }
}
