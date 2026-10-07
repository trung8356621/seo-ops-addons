<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts\TopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicNaming;

/**
 * LEGACY PROVIDER PATH.
 *
 * Site recluster delegates to TopicClusterEngine.
 * Targeted membership scan delegates to TopicMembershipMatcher (direct lexical
 * match against one label — no discovery, no core fallback, no persistence).
 *
 * Default binding for TopicGroupingProvider. Replace the binding to insert
 * another analyzer without rewriting Topic orchestration.
 */
final class LegacyTopicGroupingProvider implements TopicGroupingProvider
{
    public const KEY = 'legacy-topic-grouping';

    public function __construct(
        private readonly TopicClusterEngine $engine,
        private readonly TopicMembershipMatcher $matcher,
    ) {}

    public function analyze(TopicGroupingInput $input): TopicGroupingProposal
    {
        return match ($input->scope) {
            TopicGroupingScope::TOPIC_MEMBERSHIP_SCAN => $this->analyzeMembershipScan($input),
            TopicGroupingScope::SITE_RECLUSTER => $this->analyzeSiteRecluster($input),
            default => new TopicGroupingProposal(
                [],
                $input->candidates,
                [
                    TopicGroupingProposal::META_PROVIDER => self::KEY,
                    TopicGroupingProposal::META_SCOPE => $input->scope,
                    'unsupported_scope' => true,
                ],
            ),
        };
    }

    private function analyzeSiteRecluster(TopicGroupingInput $input): TopicGroupingProposal
    {
        $seeds = [];
        foreach ($input->seeds as $seed) {
            $seeds[] = [
                'keyword_id' => $seed->keywordRef,
                'phrase' => $seed->text,
                'source' => $seed->source,
                'is_seed' => true,
                'confidence' => $seed->confidence,
            ];
        }

        $eligible = [];
        foreach ($input->candidates as $candidate) {
            $eligible[] = [
                'keyword_id' => $candidate->keywordRef,
                'phrase' => $candidate->text,
                'is_seo_keyword' => false,
            ];
        }

        $lockedTopics = [];
        $inventoryTopics = [];
        foreach ($input->protectedTopics as $topic) {
            if ($topic->topicRef <= 0) {
                continue;
            }
            $row = $this->engineTopicRow($topic);
            if ($topic->locked) {
                $lockedTopics[] = $row;
            } else {
                $inventoryTopics[] = $row;
            }
        }

        /** @var array<int, true> $lockedKeywordIds */
        $lockedKeywordIds = [];
        foreach ($input->lockedKeywordRefs as $keywordRef) {
            if ($keywordRef > 0) {
                $lockedKeywordIds[$keywordRef] = true;
            }
        }

        $engineMetrics = [];
        $clusters = $this->engine
            ->withRules($input->industryMatchRules, $input->globalMatchRules)
            ->cluster($seeds, $eligible, $lockedTopics, $lockedKeywordIds, $inventoryTopics, $engineMetrics);

        return $this->proposalFromClusters(
            $input,
            $clusters,
            $engineMetrics,
            TopicGroupingScope::SITE_RECLUSTER,
        );
    }

    private function analyzeMembershipScan(TopicGroupingInput $input): TopicGroupingProposal
    {
        $anchor = $input->anchor;
        if ($anchor === null || $anchor->topicRef <= 0) {
            return new TopicGroupingProposal(
                [],
                $input->candidates,
                [
                    TopicGroupingProposal::META_PROVIDER => self::KEY,
                    TopicGroupingProposal::META_SCOPE => TopicGroupingScope::TOPIC_MEMBERSHIP_SCAN,
                ],
            );
        }

        $label = TopicNaming::canonicalName($anchor->label) ?: $anchor->label;
        $matcher = $this->matcher->withRules($input->industryMatchRules, $input->globalMatchRules);
        $members = [];
        $unassigned = [];
        foreach ($input->candidates as $candidate) {
            if ($candidate->keywordRef <= 0 || $candidate->text === '') {
                $unassigned[] = $candidate;

                continue;
            }
            if (! $matcher->matches($candidate->text, $label)) {
                $unassigned[] = $candidate;

                continue;
            }
            $members[] = new TopicGroupingMember(
                $candidate->keywordRef,
                $candidate->text,
                0.8,
                [TopicGroupingMember::EVIDENCE_MATCH => 'direct'],
            );
        }

        return new TopicGroupingProposal(
            [new TopicGroupingGroup(
                'topic:'.$anchor->topicRef,
                $label,
                $members,
                $anchor->topicRef,
                [TopicGroupingGroup::META_IS_LOCKED => false],
            )],
            $unassigned,
            [
                TopicGroupingProposal::META_PROVIDER => self::KEY,
                TopicGroupingProposal::META_SCOPE => TopicGroupingScope::TOPIC_MEMBERSHIP_SCAN,
            ],
        );
    }

    /**
     * @param  array{
     *     topic_id: int,
     *     name: string,
     *     is_locked: bool,
     *     accept_attach: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }  $topic
     * @return array{
     *     topic_id: int,
     *     name: string,
     *     is_locked: bool,
     *     accept_attach: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }
     */
    private function engineTopicRow(TopicGroupingProtectedTopic $topic): array
    {
        $members = [];
        foreach ($topic->members as $member) {
            $members[] = [
                'keyword_id' => $member->keywordRef,
                'phrase' => $member->text,
                'source' => $member->source,
                'is_seed' => $member->isSeed,
                'confidence' => $member->confidence,
                'is_locked' => $member->isLocked,
            ];
        }

        return [
            'topic_id' => $topic->topicRef,
            'name' => $topic->label,
            'is_locked' => $topic->locked,
            'accept_attach' => $topic->locked ? true : $topic->acceptAttach,
            'members' => $members,
        ];
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $clusters
     * @param  array<string, int>  $engineMetrics
     */
    private function proposalFromClusters(
        TopicGroupingInput $input,
        array $clusters,
        array $engineMetrics,
        string $scope,
    ): TopicGroupingProposal {
        /** @var array<int, true> $grouped */
        $grouped = [];
        /** @var array<string, true> $usedKeys */
        $usedKeys = [];
        $discoveredIndex = 0;
        $groups = [];

        foreach ($clusters as $cluster) {
            $members = [];
            foreach ($cluster['members'] as $member) {
                $keywordRef = (int) $member['keyword_id'];
                if ($keywordRef > 0) {
                    $grouped[$keywordRef] = true;
                }
                $confidence = $member['confidence'];
                $members[] = new TopicGroupingMember(
                    $keywordRef,
                    (string) $member['phrase'],
                    $confidence !== null ? (float) $confidence : null,
                    [
                        TopicGroupingMember::EVIDENCE_SOURCE => (string) $member['source'],
                        TopicGroupingMember::EVIDENCE_IS_SEED => (bool) $member['is_seed'],
                        TopicGroupingMember::EVIDENCE_IS_LOCKED => (bool) $member['is_locked'],
                    ],
                );
            }

            $topicRef = $cluster['topic_id'] !== null ? (int) $cluster['topic_id'] : null;
            if ($topicRef !== null && $topicRef <= 0) {
                $topicRef = null;
            }

            $groups[] = new TopicGroupingGroup(
                $this->groupKey($cluster, $topicRef, $discoveredIndex, $usedKeys),
                (string) $cluster['name'],
                $members,
                $topicRef,
                [TopicGroupingGroup::META_IS_LOCKED => (bool) $cluster['is_locked']],
            );
        }

        /** @var array<int, true> $locked */
        $locked = [];
        foreach ($input->lockedKeywordRefs as $keywordRef) {
            if ($keywordRef > 0) {
                $locked[$keywordRef] = true;
            }
        }

        $unassigned = [];
        foreach ($input->candidates as $candidate) {
            if (isset($grouped[$candidate->keywordRef]) || isset($locked[$candidate->keywordRef])) {
                continue;
            }
            $unassigned[] = $candidate;
        }

        return new TopicGroupingProposal(
            $groups,
            $unassigned,
            [
                TopicGroupingProposal::META_PROVIDER => self::KEY,
                TopicGroupingProposal::META_SCOPE => $scope,
                TopicGroupingProposal::META_ENGINE_METRICS => $engineMetrics,
            ],
        );
    }

    /**
     * @param  array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}  $cluster
     * @param  array<string, true>  $usedKeys
     */
    private function groupKey(array $cluster, ?int $topicRef, int &$discoveredIndex, array &$usedKeys): string
    {
        if ($topicRef !== null) {
            $key = 'topic:'.$topicRef;
        } else {
            $seedRef = null;
            foreach ($cluster['members'] as $member) {
                if (($member['is_seed'] ?? false) === true) {
                    $seedRef = (int) $member['keyword_id'];
                    break;
                }
            }
            if ($seedRef !== null && $seedRef > 0) {
                $key = 'seed:'.$seedRef;
            } else {
                $key = 'discovered:'.$discoveredIndex;
                $discoveredIndex++;
            }
        }

        if (isset($usedKeys[$key])) {
            $suffix = 2;
            while (isset($usedKeys[$key.':'.$suffix])) {
                $suffix++;
            }
            $key .= ':'.$suffix;
        }
        $usedKeys[$key] = true;

        return $key;
    }
}
