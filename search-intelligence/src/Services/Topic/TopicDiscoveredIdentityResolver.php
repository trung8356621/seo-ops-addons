<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Reconcile discovered Topic proposals onto existing topic_id via membership overlap.
 *
 * Applies only to auto proposals with zero is_seed members.
 * Never reuses manual / default-seeded / locked Topics.
 * Never creates fake seeds. Identity is seo_topics.id only — no legacy cluster identity columns.
 */
final class TopicDiscoveredIdentityResolver
{
    private readonly TopicPhraseResolver $phrases;

    public function __construct(?TopicPhraseResolver $phrases = null)
    {
        $this->phrases = $phrases ?? new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
    }

    /**
     * @param  list<array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>  $clusters
     * @param  list<array{
     *     topic_id: int,
     *     name: string,
     *     member_keyword_ids: list<int>,
     *     member_count: int,
     *     is_locked: bool
     * }>  $discoveredInventory  prior auto Topics with zero seed memberships (unlocked only)
     * @return array{
     *     clusters: list<array{
     *         name: string,
     *         topic_id: int|null,
     *         is_locked: bool,
     *         members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     *     }>,
     *     metrics: array{discovered_topics_reused: int, discovered_topics_created: int}
     * }
     */
    public function apply(array $clusters, array $discoveredInventory): array
    {
        $metrics = [
            'discovered_topics_reused' => 0,
            'discovered_topics_created' => 0,
        ];

        if ($discoveredInventory === []) {
            foreach ($clusters as $cluster) {
                if ($this->isDiscoveredProposal($cluster) && $cluster['topic_id'] === null) {
                    $metrics['discovered_topics_created']++;
                }
            }

            return ['clusters' => $clusters, 'metrics' => $metrics];
        }

        /** @var array<int, true> $claimed */
        $claimed = [];
        foreach ($clusters as $cluster) {
            if ($cluster['topic_id'] !== null) {
                $claimed[(int) $cluster['topic_id']] = true;
            }
        }

        /** @var array<int, array{topic_id: int, name: string, member_keyword_ids: list<int>, member_count: int, is_locked: bool}> $inventoryById */
        $inventoryById = [];
        foreach ($discoveredInventory as $row) {
            $topicId = (int) $row['topic_id'];
            if ($topicId <= 0 || $row['is_locked']) {
                continue;
            }
            if (isset($claimed[$topicId])) {
                continue;
            }
            $inventoryById[$topicId] = [
                'topic_id' => $topicId,
                'name' => (string) $row['name'],
                'member_keyword_ids' => array_values(array_map('intval', $row['member_keyword_ids'])),
                'member_count' => (int) $row['member_count'],
                'is_locked' => false,
            ];
        }

        /** @var list<int> $proposalIndexes */
        $proposalIndexes = [];
        foreach ($clusters as $index => $cluster) {
            if ($cluster['topic_id'] !== null) {
                continue;
            }
            if (! $this->isDiscoveredProposal($cluster)) {
                continue;
            }
            $proposalIndexes[] = $index;
        }

        /** @var list<array{proposal_index: int, topic_id: int, intersection: int, ratio: float, prior_count: int}> $pairs */
        $pairs = [];
        foreach ($proposalIndexes as $proposalIndex) {
            $proposalIds = $this->memberKeywordIds($clusters[$proposalIndex]);
            $newSize = count($proposalIds);
            if ($newSize === 0) {
                continue;
            }
            $proposalSet = array_fill_keys($proposalIds, true);

            foreach ($inventoryById as $prior) {
                if (isset($claimed[$prior['topic_id']])) {
                    continue;
                }
                $intersection = 0;
                foreach ($prior['member_keyword_ids'] as $keywordId) {
                    if (isset($proposalSet[$keywordId])) {
                        $intersection++;
                    }
                }
                $threshold = $this->overlapThreshold($newSize, $prior['member_count']);
                if ($intersection < $threshold) {
                    continue;
                }
                $denom = max(1, min($newSize, $prior['member_count']));
                $pairs[] = [
                    'proposal_index' => $proposalIndex,
                    'topic_id' => $prior['topic_id'],
                    'intersection' => $intersection,
                    'ratio' => $intersection / $denom,
                    'prior_count' => $prior['member_count'],
                ];
            }
        }

        usort(
            $pairs,
            static function (array $a, array $b): int {
                if ($a['intersection'] !== $b['intersection']) {
                    return $b['intersection'] <=> $a['intersection'];
                }
                if ($a['ratio'] !== $b['ratio']) {
                    return $b['ratio'] <=> $a['ratio'];
                }
                if ($a['prior_count'] !== $b['prior_count']) {
                    return $b['prior_count'] <=> $a['prior_count'];
                }

                return $a['topic_id'] <=> $b['topic_id'];
            },
        );

        /** @var array<int, true> $assignedProposals */
        $assignedProposals = [];
        /** @var array<int, true> $assignedPriors */
        $assignedPriors = [];

        foreach ($pairs as $pair) {
            $proposalIndex = $pair['proposal_index'];
            $topicId = $pair['topic_id'];
            if (isset($assignedProposals[$proposalIndex]) || isset($assignedPriors[$topicId]) || isset($claimed[$topicId])) {
                continue;
            }
            $clusters[$proposalIndex]['topic_id'] = $topicId;
            $assignedProposals[$proposalIndex] = true;
            $assignedPriors[$topicId] = true;
            $claimed[$topicId] = true;
            $metrics['discovered_topics_reused']++;
        }

        // Exact normalized-name fallback among remaining discovered inventory only.
        foreach ($proposalIndexes as $proposalIndex) {
            if (isset($assignedProposals[$proposalIndex])) {
                continue;
            }
            $nameKey = $this->phrases->normalizedKey($clusters[$proposalIndex]['name']);
            if ($nameKey === '') {
                continue;
            }

            /** @var list<int> $nameMatches */
            $nameMatches = [];
            foreach ($inventoryById as $prior) {
                if (isset($claimed[$prior['topic_id']]) || isset($assignedPriors[$prior['topic_id']])) {
                    continue;
                }
                if ($this->phrases->normalizedKey($prior['name']) === $nameKey) {
                    $nameMatches[] = $prior['topic_id'];
                }
            }
            if ($nameMatches === []) {
                continue;
            }
            sort($nameMatches);
            $reuseId = $nameMatches[0];
            $clusters[$proposalIndex]['topic_id'] = $reuseId;
            $assignedProposals[$proposalIndex] = true;
            $assignedPriors[$reuseId] = true;
            $claimed[$reuseId] = true;
            $metrics['discovered_topics_reused']++;
        }

        foreach ($proposalIndexes as $proposalIndex) {
            if (($clusters[$proposalIndex]['topic_id'] ?? null) === null) {
                $metrics['discovered_topics_created']++;
            }
        }

        return ['clusters' => $clusters, 'metrics' => $metrics];
    }

    public function overlapThreshold(int $newSize, int $priorSize): int
    {
        return max(2, (int) ceil(min($newSize, $priorSize) / 2));
    }

    /**
     * @param  array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }  $cluster
     */
    private function isDiscoveredProposal(array $cluster): bool
    {
        if ($cluster['is_locked'] ?? false) {
            return false;
        }
        foreach ($cluster['members'] as $member) {
            if ($member['is_seed'] ?? false) {
                return false;
            }
        }

        return $cluster['members'] !== [];
    }

    /**
     * @param  array{members: list<array{keyword_id: int}>}  $cluster
     * @return list<int>
     */
    private function memberKeywordIds(array $cluster): array
    {
        $ids = [];
        foreach ($cluster['members'] as $member) {
            $keywordId = (int) $member['keyword_id'];
            if ($keywordId > 0) {
                $ids[$keywordId] = $keywordId;
            }
        }

        return array_values($ids);
    }
}
