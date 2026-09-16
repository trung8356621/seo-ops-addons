<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTopicHistory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Writes Topic History when a New Content plan is successfully queued.
 * Idempotent per (planner_run_id, topic_ref).
 */
final class TopicHistoryWriter
{
    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return list<SeoContentProjectTopicHistory>
     */
    public function recordForPlannerRun(
        int $siteId,
        string $planningMonth,
        int $plannerRunId,
        array $noteItems,
    ): array {
        if ($siteId <= 0 || $plannerRunId <= 0) {
            return [];
        }
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_topic_histories')) {
            return [];
        }

        $month = ContentProjectMonthContext::normalize($planningMonth);
        $monthDate = ContentProjectMonthContext::toDateString($month);
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        $written = [];

        foreach ($items as $item) {
            $topicRef = trim((string) ($item['cluster_ref'] ?? ''));
            if ($topicRef === '') {
                continue;
            }
            $count = max(0, (int) ($item['target_dna_count'] ?? 0));
            if ($count <= 0) {
                continue;
            }

            $name = trim((string) ($item['cluster_name_snapshot'] ?? ''));
            if ($name === '') {
                $seed = trim((string) ($item['seed_text'] ?? ''));
                $name = $seed !== '' ? $seed : $topicRef;
            }

            $row = SeoContentProjectTopicHistory::query()->firstOrCreate(
                [
                    'planner_run_id' => $plannerRunId,
                    'topic_ref' => $topicRef,
                ],
                [
                    'site_id' => $siteId,
                    'planning_month' => $monthDate,
                    'topic_name' => mb_substr($name, 0, 500),
                    'planned_article_count' => $count,
                ],
            );
            $written[] = $row;
        }

        return $written;
    }
}
