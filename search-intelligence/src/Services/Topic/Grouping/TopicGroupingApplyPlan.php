<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Deterministic business mutation plan. Not executed until Apply.
 *
 * @phpstan-type TopicAction array{topic_id: int|null, name: string, action: string, member_count: int, group_key: string|null, mean_similarity: float|null, min_similarity: float|null, cohesion: float|null, low_confidence_count: int, warning: string|null}
 * @phpstan-type KeywordAction array{keyword_id: int, text: string, from_topic_id: int|null, to_topic_id: int|null, action: string, similarity: float|null, confidence: float|null, protected: bool}
 */
final class TopicGroupingApplyPlan
{
    /**
     * @param  list<TopicAction>  $topicActions
     * @param  list<KeywordAction>  $keywordActions
     * @param  list<string>  $warnings
     * @param  list<array{topic_id: int, name: string, reason: string}>  $protectedTopics
     * @param  list<array{keyword_id: int, reason: string}>  $protectedKeywords
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $resolvedClusters
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly string $planHash,
        public readonly array $counts,
        public readonly array $topicActions,
        public readonly array $keywordActions,
        public readonly array $protectedTopics,
        public readonly array $protectedKeywords,
        public readonly array $warnings,
        public readonly array $resolvedClusters,
        public readonly string $businessSnapshotHash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_hash' => $this->planHash,
            'business_snapshot_hash' => $this->businessSnapshotHash,
            'counts' => $this->counts,
            'topic_actions' => $this->topicActions,
            'keyword_actions' => $this->keywordActions,
            'protected_topics' => $this->protectedTopics,
            'protected_keywords' => $this->protectedKeywords,
            'warnings' => $this->warnings,
            'resolved_clusters' => $this->resolvedClusters,
        ];
    }
}
