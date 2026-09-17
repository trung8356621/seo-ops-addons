<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

/**
 * Batch Topic tag metrics (intent / coverage / Auto|Manual) from Topic Core tables.
 *
 * @phpstan-type TopicTagMetrics array{
 *     intent: string,
 *     coverage: string,
 *     canonical_source: string,
 *     intent_diversity: int,
 *     dna_branch_count: int,
 *     intent_counts: array<string, int>
 * }
 */
final class TopicTagMetricsResolver
{
    public function __construct(
        private readonly TopicCoverageCalculator $coverage = new TopicCoverageCalculator,
    ) {}

    /**
     * @param  list<int>  $topicIds
     * @param  array<int, int>  $articleCountsByTopic  topic_id => article_count
     * @return array<int, TopicTagMetrics>
     */
    public function forTopics(int $siteId, array $topicIds, array $articleCountsByTopic = []): array
    {
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        $empty = [
            'intent' => '',
            'coverage' => 'unknown',
            'canonical_source' => TopicSource::AUTO,
            'intent_diversity' => 0,
            'dna_branch_count' => 0,
            'intent_counts' => [],
        ];
        /** @var array<int, TopicTagMetrics> $out */
        $out = [];
        foreach ($topicIds as $topicId) {
            $out[$topicId] = $empty;
        }
        if ($siteId <= 0 || $topicIds === []) {
            return $out;
        }

        /** @var array<int, string> $topicSourceById */
        $topicSourceById = SeoTopic::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $topicIds)
            ->get(['id', 'source'])
            ->mapWithKeys(static fn (SeoTopic $topic): array => [
                (int) $topic->id => TopicSource::normalize($topic->source ?? null),
            ])
            ->all();

        $memberships = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'keyword_id', 'source', 'is_seed']);

        /** @var array<int, list<int>> $keywordIdsByTopic */
        $keywordIdsByTopic = [];
        /** @var list<int> $allKeywordIds */
        $allKeywordIds = [];

        foreach ($memberships as $row) {
            $topicId = (int) $row->topic_id;
            $keywordId = (int) $row->keyword_id;
            if ($topicId <= 0 || $keywordId <= 0) {
                continue;
            }
            $keywordIdsByTopic[$topicId][] = $keywordId;
            $allKeywordIds[] = $keywordId;
        }

        $allKeywordIds = array_values(array_unique($allKeywordIds));
        /** @var array<int, string> $intentByKeyword */
        $intentByKeyword = [];
        if ($allKeywordIds !== []) {
            $intentRows = SeoSiteKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('keyword_id', $allKeywordIds)
                ->whereNotNull('seo_intent')
                ->get(['keyword_id', 'seo_intent']);
            foreach ($intentRows as $row) {
                $intent = strtolower(trim((string) $row->seo_intent));
                if ($intent !== '') {
                    $intentByKeyword[(int) $row->keyword_id] = $intent;
                }
            }
        }

        /** @var array<int, array<string, true>> $dnaValuesByTopic */
        $dnaValuesByTopic = [];
        $dnaRows = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->whereIn('topic_id', $topicIds)
            ->get(['topic_id', 'value']);
        foreach ($dnaRows as $row) {
            $value = trim((string) $row->value);
            if ($value === '') {
                continue;
            }
            $dnaValuesByTopic[(int) $row->topic_id][mb_strtolower($value)] = true;
        }

        foreach ($topicIds as $topicId) {
            $keywordIds = array_values(array_unique($keywordIdsByTopic[$topicId] ?? []));
            $keywordCount = count($keywordIds);
            $intentCounts = [];
            foreach ($keywordIds as $keywordId) {
                $intent = $intentByKeyword[$keywordId] ?? '';
                if ($intent === '') {
                    continue;
                }
                $intentCounts[$intent] = (int) ($intentCounts[$intent] ?? 0) + 1;
            }
            $intentDiversity = count($intentCounts);
            $dominantIntent = '';
            if ($intentCounts !== []) {
                arsort($intentCounts);
                $dominantIntent = (string) array_key_first($intentCounts);
            }
            $dnaBranchCount = count($dnaValuesByTopic[$topicId] ?? []);
            $articleCount = (int) ($articleCountsByTopic[$topicId] ?? 0);
            $canonicalSource = ($topicSourceById[$topicId] ?? TopicSource::AUTO) === TopicSource::MANUAL
                ? TopicSource::MANUAL
                : TopicSource::AUTO;

            $out[$topicId] = [
                'intent' => $dominantIntent,
                'coverage' => $this->coverage->coverage(
                    $keywordCount,
                    $articleCount,
                    $dnaBranchCount,
                    $intentDiversity,
                ),
                'canonical_source' => $canonicalSource,
                'intent_diversity' => $intentDiversity,
                'dna_branch_count' => $dnaBranchCount,
                'intent_counts' => $intentCounts,
            ];
        }

        return $out;
    }
}
