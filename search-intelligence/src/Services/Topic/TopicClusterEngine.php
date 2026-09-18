<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Cluster site keywords around Topic seeds, then discover Topics from remainder.
 *
 * Attach is specificity-first (not iteration-order). Unmatched eligible SEO keywords stay
 * site-classified only unless they form a discovered Topic (min size 3, no fake seeds).
 * No Focus⇒Topic. No legacy cluster identity columns.
 */
final class TopicClusterEngine
{
    public const MIN_DISCOVERED_GROUP_SIZE = 3;

    public function __construct(
        private readonly TopicMembershipMatcher $matcher,
        private readonly KeywordNormalizer $normalizer,
        private readonly TopicPhraseResolver $phrases,
    ) {}

    /**
     * @param  list<array{keyword_id: int, phrase: string, source: string, is_seed: true, confidence: float|null}>  $seeds
     * @param  list<array{keyword_id: int, phrase: string, is_seo_keyword: bool}>  $eligible
     * @param  list<array{
     *     topic_id: int,
     *     name: string,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>  $lockedTopics  fully topic-locked Topics only
     * @param  array<int, true>  $lockedKeywordIds  memberships that must stay put
     * @param  list<array{
     *     topic_id: int,
     *     name: string,
     *     is_locked: bool,
     *     accept_attach?: bool,
     *     members?: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>  $inventoryTopics  protected topics with known topic_id.
     *     Manual freeze: accept_attach=false + existing members assigned (excluded from free remainder).
     * @param  array<string, int>  $metrics
     * @return list<array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>
     */
    public function cluster(
        array $seeds,
        array $eligible,
        array $lockedTopics = [],
        array $lockedKeywordIds = [],
        array $inventoryTopics = [],
        array &$metrics = [],
    ): array {
        $metrics = array_merge([
            'members_attached_direct' => 0,
            'members_attached_core_fallback' => 0,
            'discovered_candidates' => 0,
            'discovered_proposals_before_collapse' => 0,
            'discovered_proposals_after_collapse' => 0,
            'discovered_groups_pruned_below_threshold' => 0,
        ], $metrics);

        /** @var list<array{name: string, topic_id: int|null, is_locked: bool, is_protected: bool, accept_attach: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}> $topics */
        $topics = [];
        /** @var array<int, true> $assigned */
        $assigned = [];
        /** @var array<int, true> $inventoryIds */
        $inventoryIds = [];

        foreach ($lockedTopics as $locked) {
            $members = [];
            foreach ($locked['members'] as $member) {
                $keywordId = (int) $member['keyword_id'];
                $assigned[$keywordId] = true;
                $phrase = $member['phrase'] !== ''
                    ? $member['phrase']
                    : $this->phraseFor($keywordId, $eligible, $seeds);
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => $phrase,
                    'source' => (string) $member['source'],
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => (bool) $member['is_locked'],
                ];
            }
            $topicId = (int) $locked['topic_id'];
            $inventoryIds[$topicId] = true;
            $topics[] = [
                'name' => TopicNaming::canonicalName($locked['name']) ?: $locked['name'],
                'topic_id' => $topicId,
                'is_locked' => true,
                'is_protected' => true,
                // Topic lock still allows net-new unlocked matches (existing product rule).
                'accept_attach' => true,
                'members' => $members,
            ];
        }

        foreach ($lockedKeywordIds as $keywordId => $_) {
            $assigned[(int) $keywordId] = true;
        }

        foreach ($inventoryTopics as $inventory) {
            $topicId = (int) $inventory['topic_id'];
            if ($topicId <= 0 || isset($inventoryIds[$topicId])) {
                continue;
            }
            $inventoryIds[$topicId] = true;
            $members = [];
            foreach ($inventory['members'] ?? [] as $member) {
                $keywordId = (int) $member['keyword_id'];
                if ($keywordId <= 0 || isset($assigned[$keywordId])) {
                    continue;
                }
                $assigned[$keywordId] = true;
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => (string) ($member['phrase'] !== '' ? $member['phrase'] : $this->phraseFor($keywordId, $eligible, $seeds)),
                    'source' => (string) $member['source'],
                    'is_seed' => (bool) $member['is_seed'],
                    'confidence' => $member['confidence'],
                    'is_locked' => (bool) $member['is_locked'],
                ];
            }
            $topics[] = [
                'name' => TopicNaming::canonicalName($inventory['name']) ?: $inventory['name'],
                'topic_id' => $topicId,
                'is_locked' => (bool) $inventory['is_locked'],
                'is_protected' => true,
                'accept_attach' => (bool) ($inventory['accept_attach'] ?? true),
                'members' => $members,
            ];
        }

        foreach ($seeds as $seed) {
            $keywordId = $seed['keyword_id'];
            if (isset($assigned[$keywordId])) {
                continue;
            }
            $name = TopicNaming::canonicalName($seed['phrase']) ?: $seed['phrase'];
            $topics[] = [
                'name' => $name,
                'topic_id' => null,
                'is_locked' => false,
                'is_protected' => true,
                'accept_attach' => true,
                'members' => [[
                    'keyword_id' => $keywordId,
                    'phrase' => $seed['phrase'],
                    'source' => $seed['source'],
                    'is_seed' => true,
                    'confidence' => $seed['confidence'],
                    'is_locked' => false,
                ]],
            ];
            $assigned[$keywordId] = true;
        }

        // Phase 2B-1: evaluate every eligible Topic, pick most specific direct match.
        foreach ($eligible as $row) {
            $keywordId = $row['keyword_id'];
            if (isset($assigned[$keywordId]) || isset($lockedKeywordIds[$keywordId])) {
                continue;
            }
            $phrase = $row['phrase'];
            $bestIndex = $this->pickDirectAttachIndex($phrase, $topics);
            if ($bestIndex === null) {
                continue;
            }
            $topics[$bestIndex]['members'][] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'source' => TopicKeywordSource::RECLUSTER,
                'is_seed' => false,
                'confidence' => 0.8,
                'is_locked' => false,
            ];
            $assigned[$keywordId] = true;
            $metrics['members_attached_direct']++;
        }

        // Phase 2B-1: conservative unique core-aware fallback (no guess on ties).
        foreach ($eligible as $row) {
            $keywordId = $row['keyword_id'];
            if (isset($assigned[$keywordId]) || isset($lockedKeywordIds[$keywordId])) {
                continue;
            }
            $phrase = $row['phrase'];
            $bestIndex = $this->pickCoreFallbackIndex($phrase, $topics);
            if ($bestIndex === null) {
                continue;
            }
            $topics[$bestIndex]['members'][] = [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'source' => TopicKeywordSource::RECLUSTER,
                'is_seed' => false,
                'confidence' => 0.7,
                'is_locked' => false,
            ];
            $assigned[$keywordId] = true;
            $metrics['members_attached_core_fallback']++;
        }

        /** @var list<array{keyword_id: int, phrase: string}> $remainder */
        $remainder = [];
        foreach ($eligible as $row) {
            $keywordId = $row['keyword_id'];
            if (isset($assigned[$keywordId]) || isset($lockedKeywordIds[$keywordId])) {
                continue;
            }
            $remainder[] = [
                'keyword_id' => $keywordId,
                'phrase' => $row['phrase'],
            ];
        }
        $metrics['discovered_candidates'] = count($remainder);

        $discovered = $this->discoverFromRemainder($remainder, $metrics);
        foreach ($discovered as $proposal) {
            $topics[] = $proposal;
        }

        return array_map(
            static function (array $topic): array {
                unset($topic['is_protected'], $topic['accept_attach']);

                return $topic;
            },
            $topics,
        );
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, is_protected: bool, accept_attach?: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $topics
     */
    private function pickDirectAttachIndex(string $phrase, array $topics): ?int
    {
        /** @var list<int> $matches */
        $matches = [];
        foreach ($topics as $index => $topic) {
            if (($topic['accept_attach'] ?? true) === false) {
                continue;
            }
            if ($this->matcher->matches($phrase, $topic['name'])) {
                $matches[] = $index;
            }
        }
        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        usort(
            $matches,
            fn (int $a, int $b): int => $this->compareTopicSpecificity($topics[$b], $topics[$a]),
        );

        return $matches[0];
    }

    /**
     * @param  list<array{name: string, topic_id: int|null, is_locked: bool, is_protected: bool, accept_attach?: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $topics
     */
    private function pickCoreFallbackIndex(string $phrase, array $topics): ?int
    {
        /** @var list<int> $matches */
        $matches = [];
        foreach ($topics as $index => $topic) {
            if (($topic['accept_attach'] ?? true) === false) {
                continue;
            }
            if ($this->coreFallbackMatches($phrase, $topic['name'])) {
                $matches[] = $index;
            }
        }
        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            fn (int $a, int $b): int => $this->compareTopicSpecificity($topics[$b], $topics[$a]),
        );

        if (count($matches) >= 2
            && $this->compareTopicSpecificity($topics[$matches[0]], $topics[$matches[1]]) === 0
        ) {
            // Ambiguous — do not guess.
            return null;
        }

        return $matches[0];
    }

    /**
     * @param  array{name: string, topic_id: int|null, is_locked: bool, is_protected: bool, members: list<mixed>}  $a
     * @param  array{name: string, topic_id: int|null, is_locked: bool, is_protected: bool, members: list<mixed>}  $b
     */
    private function compareTopicSpecificity(array $a, array $b): int
    {
        $aTokens = count($this->phrases->significantTokens($a['name']));
        $bTokens = count($this->phrases->significantTokens($b['name']));
        if ($aTokens !== $bTokens) {
            return $aTokens <=> $bTokens;
        }

        $aLen = mb_strlen($this->phrases->normalizedKey($a['name']));
        $bLen = mb_strlen($this->phrases->normalizedKey($b['name']));
        if ($aLen !== $bLen) {
            return $aLen <=> $bLen;
        }

        $aProtected = ($a['is_protected'] ?? false) ? 1 : 0;
        $bProtected = ($b['is_protected'] ?? false) ? 1 : 0;
        if ($aProtected !== $bProtected) {
            return $aProtected <=> $bProtected;
        }

        $aId = $a['topic_id'] ?? PHP_INT_MAX;
        $bId = $b['topic_id'] ?? PHP_INT_MAX;
        if ($aId === null) {
            $aId = PHP_INT_MAX;
        }
        if ($bId === null) {
            $bId = PHP_INT_MAX;
        }
        if ($aId !== $bId) {
            // Lower topic_id wins (stable). Inverted for DESC callers: compare a vs b ascending here.
            return $bId <=> $aId;
        }

        return strcmp($this->phrases->normalizedKey($a['name']), $this->phrases->normalizedKey($b['name']));
    }

    private function coreFallbackMatches(string $keywordPhrase, string $topicName): bool
    {
        // Intent is not a hard gate here: product/service umbrella Topics may absorb
        // product-only keywords when the topic product core is contiguous in the keyword.
        // Two-token product cores (e.g. túi xách / balo laptop) are too broad for fallback.
        $productTokens = $this->topicProductTokens($topicName);
        if (count($productTokens) < 3) {
            return false;
        }
        if ($this->phrases->isGenericSingletonCore($productTokens)) {
            return false;
        }
        // Raw Vietnamese "máy" (machine) must not match sewing "may" Topics via fold.
        // Do NOT treat ASCII "may" as máy — check the original candidate phrase only.
        if ($this->containsRawMayMachineWord($keywordPhrase)) {
            return false;
        }

        $keywordTokens = $this->phrases->significantTokens($keywordPhrase);

        return $this->phrases->containsContiguousTokenPhrase($keywordTokens, $productTokens);
    }

    /**
     * True when the original phrase contains the accented word "máy" (machine),
     * as a Unicode word token — not folded "may" (sewing).
     */
    private function containsRawMayMachineWord(string $phrase): bool
    {
        return preg_match('/(?<![\p{L}\p{N}_])máy(?![\p{L}\p{N}_])/ui', $phrase) === 1;
    }

    /**
     * Strip leading service markers so product core can reverse-match keywords.
     *
     * @return list<string>
     */
    private function topicProductTokens(string $topicName): array
    {
        $tokens = $this->phrases->significantTokens($topicName);
        if (count($tokens) >= 2 && ($tokens[0] ?? '') === 'xuong' && ($tokens[1] ?? '') === 'may') {
            return array_values(array_slice($tokens, 2));
        }
        if (($tokens[0] ?? '') === 'may') {
            return array_values(array_slice($tokens, 1));
        }

        return $tokens;
    }

    /**
     * Discover Topics from unassigned remainder via shortest-hub containment.
     *
     * preferredClusterCore is used only for same-core collapse AFTER hub groups form —
     * never as display name or identity SSOT.
     *
     * @param  list<array{keyword_id: int, phrase: string}>  $remainder
     * @param  array<string, int>  $metrics
     * @return list<array{
     *     name: string,
     *     topic_id: int|null,
     *     is_locked: bool,
     *     is_protected: bool,
     *     members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>
     * }>
     */
    private function discoverFromRemainder(array $remainder, array &$metrics): array
    {
        if ($remainder === []) {
            return [];
        }

        /** @var list<array{keyword_id: int, phrase: string}> $candidates */
        $candidates = array_values($remainder);
        usort(
            $candidates,
            function (array $a, array $b): int {
                $aCount = count($this->phrases->significantTokens($a['phrase']));
                $bCount = count($this->phrases->significantTokens($b['phrase']));
                if ($aCount !== $bCount) {
                    return $aCount <=> $bCount;
                }
                $cmp = strcmp(
                    $this->phrases->normalizedKey($a['phrase']),
                    $this->phrases->normalizedKey($b['phrase']),
                );
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a['keyword_id'] <=> $b['keyword_id'];
            },
        );

        /** @var array<int, true> $assigned */
        $assigned = [];
        /** @var list<list<array{keyword_id: int, phrase: string}>> $rawGroups */
        $rawGroups = [];

        foreach ($candidates as $hub) {
            $hubId = (int) $hub['keyword_id'];
            if (isset($assigned[$hubId])) {
                continue;
            }
            $hubTokens = $this->phrases->significantTokens($hub['phrase']);
            if (count($hubTokens) < 2 || $this->phrases->isGenericSingletonCore($hubTokens)) {
                continue;
            }

            $members = [$hub];
            foreach ($candidates as $other) {
                $otherId = (int) $other['keyword_id'];
                if ($otherId === $hubId || isset($assigned[$otherId])) {
                    continue;
                }
                if ($this->phrases->containsCanonicalCoreForTopic($other['phrase'], $hub['phrase'])) {
                    $members[] = $other;
                }
            }

            if (count($members) < self::MIN_DISCOVERED_GROUP_SIZE) {
                continue;
            }

            foreach ($members as $member) {
                $assigned[(int) $member['keyword_id']] = true;
            }
            $rawGroups[] = $members;
        }

        $metrics['discovered_proposals_before_collapse'] = count($rawGroups);

        // Same-core collapse among discovered groups only (grouping hint, not display name).
        /** @var array<string, list<array{keyword_id: int, phrase: string}>> $collapsed */
        $collapsed = [];
        foreach ($rawGroups as $members) {
            $hubPhrase = $members[0]['phrase'];
            $groupingCore = $this->phrases->preferredClusterCore($hubPhrase);
            if ($groupingCore === '') {
                $groupingCore = $hubPhrase;
            }
            $coreKey = $this->phrases->normalizedKey($groupingCore);
            if ($coreKey === '') {
                $coreKey = $this->phrases->normalizedKey($hubPhrase);
            }
            if (! isset($collapsed[$coreKey])) {
                $collapsed[$coreKey] = [];
            }
            foreach ($members as $member) {
                $collapsed[$coreKey][(int) $member['keyword_id']] = $member;
            }
        }

        $pruned = 0;
        /** @var list<list<array{keyword_id: int, phrase: string}>> $eligibleGroups */
        $eligibleGroups = [];
        foreach ($collapsed as $membersById) {
            $members = array_values($membersById);
            if (count($members) < self::MIN_DISCOVERED_GROUP_SIZE) {
                $pruned++;

                continue;
            }
            $eligibleGroups[] = $members;
        }

        // Also count hubs that never reached threshold as pruned attempts.
        $unassignedTried = 0;
        foreach ($candidates as $hub) {
            if (isset($assigned[(int) $hub['keyword_id']])) {
                continue;
            }
            $hubTokens = $this->phrases->significantTokens($hub['phrase']);
            if (count($hubTokens) >= 2 && ! $this->phrases->isGenericSingletonCore($hubTokens)) {
                $unassignedTried++;
            }
        }
        $metrics['discovered_groups_pruned_below_threshold'] = $pruned + $unassignedTried;
        $metrics['discovered_proposals_after_collapse'] = count($eligibleGroups);

        /** @var list<array{name: string, topic_id: int|null, is_locked: bool, is_protected: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}> $proposals */
        $proposals = [];
        foreach ($eligibleGroups as $members) {
            $withCore = [];
            foreach ($members as $member) {
                $groupingCore = $this->phrases->preferredClusterCore($member['phrase']);
                $withCore[] = [
                    'keyword_id' => (int) $member['keyword_id'],
                    'phrase' => $member['phrase'],
                    'grouping_core' => $groupingCore !== '' ? $groupingCore : $member['phrase'],
                ];
            }
            $displayName = $this->pickDiscoveredDisplayName(
                $withCore,
                $this->phrases->normalizedKey($withCore[0]['grouping_core'] ?? $withCore[0]['phrase']),
            );
            if ($displayName === '') {
                continue;
            }
            $proposalMembers = [];
            foreach ($members as $member) {
                $proposalMembers[] = [
                    'keyword_id' => (int) $member['keyword_id'],
                    'phrase' => $member['phrase'],
                    'source' => TopicKeywordSource::RECLUSTER,
                    'is_seed' => false,
                    'confidence' => 0.75,
                    'is_locked' => false,
                ];
            }
            $proposals[] = [
                'name' => TopicNaming::canonicalName($displayName) ?: $displayName,
                'topic_id' => null,
                'is_locked' => false,
                'is_protected' => false,
                'members' => $proposalMembers,
            ];
        }

        usort(
            $proposals,
            function (array $a, array $b): int {
                $byName = strcmp($this->phrases->normalizedKey($a['name']), $this->phrases->normalizedKey($b['name']));
                if ($byName !== 0) {
                    return $byName;
                }

                return count($b['members']) <=> count($a['members']);
            },
        );

        return $proposals;
    }

    /**
     * Display name MUST be a real member Dictionary phrase — never a truncated core.
     *
     * @param  list<array{keyword_id: int, phrase: string, grouping_core: string}>  $members
     */
    private function pickDiscoveredDisplayName(array $members, string $coreKey): string
    {
        if ($members === []) {
            return '';
        }

        $exact = [];
        foreach ($members as $member) {
            if ($this->phrases->normalizedKey($member['phrase']) === $coreKey) {
                $exact[] = $member;
            }
        }
        if ($exact !== []) {
            return $this->pickShortestLexicalMember($exact);
        }

        $representing = [];
        foreach ($members as $member) {
            $groupingCore = $member['grouping_core'];
            if ($groupingCore !== ''
                && $this->phrases->containsCanonicalCoreForTopic($member['phrase'], $groupingCore)
            ) {
                $representing[] = $member;
            }
        }
        if ($representing === []) {
            $representing = $members;
        }

        return $this->pickShortestLexicalMember($representing);
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $members
     */
    private function pickShortestLexicalMember(array $members): string
    {
        usort(
            $members,
            function (array $a, array $b): int {
                $aKey = $this->phrases->normalizedKey($a['phrase']);
                $bKey = $this->phrases->normalizedKey($b['phrase']);
                $aLen = mb_strlen($aKey);
                $bLen = mb_strlen($bKey);
                if ($aLen !== $bLen) {
                    return $aLen <=> $bLen;
                }
                $cmp = strcmp($aKey, $bKey);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a['keyword_id'] <=> $b['keyword_id'];
            },
        );

        return $members[0]['phrase'] ?? '';
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string, is_seo_keyword?: bool}>  $eligible
     * @param  list<array{keyword_id: int, phrase: string}>  $seeds
     */
    private function phraseFor(int $keywordId, array $eligible, array $seeds): string
    {
        foreach ($eligible as $row) {
            if ($row['keyword_id'] === $keywordId) {
                return $row['phrase'];
            }
        }
        foreach ($seeds as $row) {
            if ($row['keyword_id'] === $keywordId) {
                return $row['phrase'];
            }
        }

        return '';
    }
}
