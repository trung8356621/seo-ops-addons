<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Site-scoped Topical Share from Topic Core article assignment counts.
 *
 * share(topic) = topic_article_count / SUM(site topic article counts) × 100
 * denominator 0 → 0.0
 *
 * Topic Core only — no retired MCP topical-profile builders.
 */
final class TopicTopicalShareCalculator
{
    /**
     * @param  array<int, int>  $articleCountsByTopicId  topic_id => article_count (one site only)
     * @return array<int, float> topic_id => percentage 0..100
     */
    public function percentages(array $articleCountsByTopicId): array
    {
        $denominator = 0;
        foreach ($articleCountsByTopicId as $count) {
            $denominator += max(0, (int) $count);
        }

        $out = [];
        foreach ($articleCountsByTopicId as $topicId => $count) {
            $topicId = (int) $topicId;
            if ($topicId <= 0) {
                continue;
            }
            if ($denominator <= 0) {
                $out[$topicId] = 0.0;

                continue;
            }
            $out[$topicId] = ((float) max(0, (int) $count) / (float) $denominator) * 100.0;
        }

        return $out;
    }

    /**
     * Display helper: 18%, 7.4%, 0%.
     */
    public static function formatPercent(float $share): string
    {
        $rounded = round($share, 1);
        if (abs($rounded - round($rounded)) < 0.05) {
            return ((int) round($rounded)).'%';
        }

        return number_format($rounded, 1, '.', '').'%';
    }
}
