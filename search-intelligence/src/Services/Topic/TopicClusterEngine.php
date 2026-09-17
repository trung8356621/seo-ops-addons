<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Cluster site keywords around Topic seeds (core containment).
 *
 * Unmatched eligible SEO keywords stay site-classified only — no singleton Topic.
 * No Focus⇒Topic rule. No cluster-key identity.
 */
final class TopicClusterEngine
{
    public function __construct(
        private readonly TopicPhraseResolver $phrases,
        private readonly KeywordNormalizer $normalizer,
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
    ): array {
        /** @var list<array{name: string, topic_id: int|null, is_locked: bool, members: list<array{keyword_id: int, phrase: string, source: string, is_seed: bool, confidence: float|null, is_locked: bool}>}> $topics */
        $topics = [];
        /** @var array<int, true> $assigned */
        $assigned = [];

        // Preserve fully topic-locked Topics + their memberships.
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
            $topics[] = [
                'name' => TopicNaming::canonicalName($locked['name']) ?: $locked['name'],
                'topic_id' => $locked['topic_id'],
                'is_locked' => true,
                'members' => $members,
            ];
        }

        // Locked memberships (membership-lock only) stay put — do not seed a new Topic.
        foreach ($lockedKeywordIds as $keywordId => $_) {
            $assigned[(int) $keywordId] = true;
        }

        // Seed topics (skip keywords already locked / assigned elsewhere).
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

        // Attach eligible SEO keywords by core containment (shortest topic core first).
        usort(
            $topics,
            function (array $a, array $b): int {
                $aLen = mb_strlen($this->normalizer->normalize($a['name'])['folded_text'] ?? '');
                $bLen = mb_strlen($this->normalizer->normalize($b['name'])['folded_text'] ?? '');

                return $aLen <=> $bLen;
            },
        );

        foreach ($eligible as $row) {
            $keywordId = $row['keyword_id'];
            if (isset($assigned[$keywordId]) || isset($lockedKeywordIds[$keywordId])) {
                continue;
            }
            $phrase = $row['phrase'];
            $bestIndex = null;
            foreach ($topics as $index => $topic) {
                if ($this->phraseMatchesTopic($phrase, $topic['name'])) {
                    $bestIndex = $index;
                    break;
                }
            }
            if ($bestIndex === null) {
                // Seed-only Topics: unmatched eligible SEO keywords stay in seo_site_keywords only.
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
        }

        return $topics;
    }

    private function phraseMatchesTopic(string $phrase, string $topicName): bool
    {
        if ($this->phrases->containsCanonicalCore($phrase, $topicName)) {
            return true;
        }

        return $this->phrases->containsCanonicalCoreForTopic($phrase, $topicName);
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
