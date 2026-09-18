<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;

/**
 * Counts for code-defined Topic filter shortcuts on the Tags tab.
 * Does NOT materialize rows into seo_topic_tags.
 */
final class TopicBuiltinShortcutStats
{
    public function __construct(
        private readonly TopicLinkedArticleCounter $articleCounter = new TopicLinkedArticleCounter,
        private readonly TopicTagMetricsResolver $metrics = new TopicTagMetricsResolver,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     group: string,
     *     label: string,
     *     topic_count: int,
     *     filter: array{intent?: string, coverage?: string, source?: string}
     * }>
     */
    public function forSite(int $siteId): array
    {
        $defs = [
            [
                'key' => 'intent_commercial',
                'group' => 'intent',
                'label_key' => 'seo-content-ai::filament.keyword.intent_commercial',
                'filter' => ['intent' => 'commercial'],
            ],
            [
                'key' => 'intent_informational',
                'group' => 'intent',
                'label_key' => 'seo-content-ai::filament.keyword.intent_informational',
                'filter' => ['intent' => 'informational'],
            ],
            [
                'key' => 'coverage_strong',
                'group' => 'coverage',
                'label_key' => 'seo-content-ai::filament.keyword.topic_shortcut_coverage_strong',
                'filter' => ['coverage' => 'strong'],
            ],
            [
                'key' => 'coverage_medium',
                'group' => 'coverage',
                'label_key' => 'seo-content-ai::filament.keyword.topic_shortcut_coverage_medium',
                'filter' => ['coverage' => 'medium'],
            ],
            [
                'key' => 'coverage_weak',
                'group' => 'coverage',
                'label_key' => 'seo-content-ai::filament.keyword.topic_shortcut_coverage_weak',
                'filter' => ['coverage' => 'weak'],
            ],
            [
                'key' => 'source_auto',
                'group' => 'source',
                'label_key' => 'seo-content-ai::filament.keyword.topic_tag_auto',
                'filter' => ['source' => TopicSource::AUTO],
            ],
            [
                'key' => 'source_manual',
                'group' => 'source',
                'label_key' => 'seo-content-ai::filament.keyword.topic_tag_manual',
                'filter' => ['source' => TopicSource::MANUAL],
            ],
        ];

        /** @var array<string, int> $counts */
        $counts = array_fill_keys(array_column($defs, 'key'), 0);

        if ($siteId <= 0 || ! TopicReclusterService::tablesReady()) {
            return $this->format($defs, $counts);
        }

        $topics = SeoTopic::query()
            ->where('site_id', $siteId)
            ->get(['id', 'source']);
        if ($topics->isEmpty()) {
            return $this->format($defs, $counts);
        }

        $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $articleCounts = $this->articleCounter->countForTopics($siteId, $topicIds);
        $metrics = $this->metrics->forTopics($siteId, $topicIds, $articleCounts);

        foreach ($topics as $topic) {
            $topicId = (int) $topic->id;
            $row = $metrics[$topicId] ?? [];
            $intent = strtolower((string) ($row['intent'] ?? ''));
            $coverage = strtolower((string) ($row['coverage'] ?? ''));
            $source = TopicSource::normalize((string) ($topic->source ?? ''));

            if ($intent === 'commercial') {
                $counts['intent_commercial']++;
            }
            if ($intent === 'informational') {
                $counts['intent_informational']++;
            }
            if ($coverage === 'strong') {
                $counts['coverage_strong']++;
            }
            if ($coverage === 'medium') {
                $counts['coverage_medium']++;
            }
            if ($coverage === 'weak') {
                $counts['coverage_weak']++;
            }
            if ($source === TopicSource::AUTO) {
                $counts['source_auto']++;
            }
            if ($source === TopicSource::MANUAL) {
                $counts['source_manual']++;
            }
        }

        return $this->format($defs, $counts);
    }

    /**
     * @param  list<array{key: string, group: string, label_key: string, filter: array<string, string>}>  $defs
     * @param  array<string, int>  $counts
     * @return list<array{key: string, group: string, label: string, topic_count: int, filter: array<string, string>}>
     */
    private function format(array $defs, array $counts): array
    {
        $out = [];
        foreach ($defs as $def) {
            $out[] = [
                'key' => $def['key'],
                'group' => $def['group'],
                'label' => (string) __($def['label_key']),
                'topic_count' => (int) ($counts[$def['key']] ?? 0),
                'filter' => $def['filter'],
            ];
        }

        return $out;
    }
}
