<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Cluster site keywords around Topic seeds (core containment + self-topic).
 *
 * No Focus⇒Topic rule. No cluster-key identity. No singleton auto-prune from focus.
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
     * @param  list<array{topic_id: int, name: string, keyword_ids: list<int>, is_locked: bool}>  $lockedTopics
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

        // Preserve locked topics + their locked memberships.
        foreach ($lockedTopics as $locked) {
            $members = [];
            foreach ($locked['keyword_ids'] as $keywordId) {
                $assigned[$keywordId] = true;
                $phrase = $this->phraseFor($keywordId, $eligible, $seeds);
                $members[] = [
                    'keyword_id' => $keywordId,
                    'phrase' => $phrase,
                    'source' => TopicKeywordSource::MANUAL,
                    'is_seed' => false,
                    'confidence' => null,
                    'is_locked' => isset($lockedKeywordIds[$keywordId]),
                ];
            }
            $topics[] = [
                'name' => TopicNaming::canonicalName($locked['name']) ?: $locked['name'],
                'topic_id' => $locked['topic_id'],
                'is_locked' => true,
                'members' => $members,
            ];
        }

        // Seed topics (skip keywords already locked elsewhere).
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
                if ($topic['is_locked'] && ! $this->phraseMatchesTopic($phrase, $topic['name'])) {
                    // Locked topics still accept containment matches for unlocked keywords.
                }
                if ($this->phraseMatchesTopic($phrase, $topic['name'])) {
                    $bestIndex = $index;
                    break;
                }
            }
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
        }

        // Remaining SEO keywords → self-topic (not forced by Focus).
        foreach ($eligible as $row) {
            $keywordId = $row['keyword_id'];
            if (isset($assigned[$keywordId]) || isset($lockedKeywordIds[$keywordId])) {
                continue;
            }
            $core = $this->phrases->deriveCorePhrase($row['phrase']);
            $name = TopicNaming::canonicalName($core !== '' ? $core : $row['phrase'])
                ?: $row['phrase'];
            $topics[] = [
                'name' => $name,
                'topic_id' => null,
                'is_locked' => false,
                'members' => [[
                    'keyword_id' => $keywordId,
                    'phrase' => $row['phrase'],
                    'source' => TopicKeywordSource::RECLUSTER,
                    'is_seed' => false,
                    'confidence' => 0.5,
                    'is_locked' => false,
                ]],
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
