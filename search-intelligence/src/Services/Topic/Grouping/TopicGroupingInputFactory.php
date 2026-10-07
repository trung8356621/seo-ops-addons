<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Maps current Topic array shapes onto the grouping contract.
 * Does not analyze and does not persist.
 */
final class TopicGroupingInputFactory
{
    /**
     * @param  list<array{keyword_id: int, phrase: string, source: string, confidence: float|null}>  $seedRows
     * @param  list<array{keyword_id: int, phrase: string}>  $eligibleRows
     * @param  list<array{topic_id: int, name: string, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $lockedTopics
     * @param  array<int, true>  $lockedKeywordIds
     * @param  list<array{topic_id: int, name: string, is_locked: bool, accept_attach?: bool, members?: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}>  $inventoryTopics
     * @param  array<string, mixed>  $industryMatchRules
     * @param  array<string, mixed>  $globalMatchRules
     */
    public static function siteRecluster(
        int $siteRef,
        array $seedRows,
        array $eligibleRows,
        array $lockedTopics,
        array $lockedKeywordIds,
        array $inventoryTopics,
        array $industryMatchRules = [],
        array $globalMatchRules = [],
        ?string $language = null,
    ): TopicGroupingInput {
        $seeds = [];
        foreach ($seedRows as $seed) {
            $keywordRef = (int) $seed['keyword_id'];
            if ($keywordRef <= 0) {
                continue;
            }
            $seeds[] = new TopicGroupingSeed(
                $keywordRef,
                (string) $seed['phrase'],
                (string) $seed['source'],
                $seed['confidence'] !== null ? (float) $seed['confidence'] : null,
            );
        }

        $protected = [];
        foreach ($lockedTopics as $topic) {
            $protected[] = self::protectedTopic($topic, true, true);
        }
        foreach ($inventoryTopics as $topic) {
            $protected[] = self::protectedTopic(
                $topic,
                (bool) ($topic['is_locked'] ?? false),
                (bool) ($topic['accept_attach'] ?? true),
            );
        }

        $lockedRefs = [];
        foreach ($lockedKeywordIds as $keywordId => $_) {
            $id = (int) $keywordId;
            if ($id > 0) {
                $lockedRefs[] = $id;
            }
        }

        return new TopicGroupingInput(
            siteRef: $siteRef,
            language: $language,
            scope: TopicGroupingScope::SITE_RECLUSTER,
            candidates: self::candidates($eligibleRows),
            seeds: $seeds,
            protectedTopics: $protected,
            lockedKeywordRefs: $lockedRefs,
            industryMatchRules: $industryMatchRules,
            globalMatchRules: $globalMatchRules,
        );
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $eligibleRows
     * @param  array<string, mixed>  $industryMatchRules
     * @param  array<string, mixed>  $globalMatchRules
     */
    public static function topicMembershipScan(
        int $siteRef,
        int $topicRef,
        string $label,
        array $eligibleRows,
        array $industryMatchRules = [],
        array $globalMatchRules = [],
        ?string $language = null,
    ): TopicGroupingInput {
        return new TopicGroupingInput(
            siteRef: $siteRef,
            language: $language,
            scope: TopicGroupingScope::TOPIC_MEMBERSHIP_SCAN,
            candidates: self::candidates($eligibleRows),
            anchor: new TopicGroupingAnchor($topicRef, $label),
            industryMatchRules: $industryMatchRules,
            globalMatchRules: $globalMatchRules,
        );
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $rows
     * @return list<TopicGroupingCandidate>
     */
    private static function candidates(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $keywordRef = (int) ($row['keyword_id'] ?? 0);
            if ($keywordRef <= 0) {
                continue;
            }
            $candidates[] = new TopicGroupingCandidate($keywordRef, (string) ($row['phrase'] ?? ''));
        }

        return $candidates;
    }

    /**
     * @param  array{topic_id: int, name: string, members?: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}  $row
     */
    private static function protectedTopic(array $row, bool $locked, bool $acceptAttach): TopicGroupingProtectedTopic
    {
        $members = [];
        foreach ($row['members'] ?? [] as $member) {
            $members[] = new TopicGroupingProtectedMember(
                (int) ($member['keyword_id'] ?? 0),
                (string) ($member['phrase'] ?? ''),
                (string) ($member['source'] ?? ''),
                (bool) ($member['is_seed'] ?? false),
                isset($member['confidence']) && $member['confidence'] !== null ? (float) $member['confidence'] : null,
                (bool) ($member['is_locked'] ?? false),
            );
        }

        return new TopicGroupingProtectedTopic(
            (int) $row['topic_id'],
            (string) $row['name'],
            $locked,
            $acceptAttach,
            $members,
        );
    }
}
