<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Idempotent keyset backfill for planning_month, consumed_ideas, and unattributed attributions.
 * Uses PlanningMonthBackfill::resolve() as the sole planning-month SSOT.
 */
final class PlanningDataBackfillService
{
    public const CHUNK = 500;

    /**
     * @return array{
     *   planning_month_stamped: int,
     *   consumed_ideas_inserted: int,
     *   attributions_unattributed: int,
     *   chunks: int
     * }
     */
    public function runAll(): array
    {
        $stats = [
            'planning_month_stamped' => 0,
            'consumed_ideas_inserted' => 0,
            'attributions_unattributed' => 0,
            'chunks' => 0,
        ];

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $result = $this->backfillPlanningMonths();
            $stats['planning_month_stamped'] = $result['updated'];
            $stats['chunks'] += $result['chunks'];
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_consumed_ideas')
            && Schema::connection('omi_seo_ai')->hasTable('seo_content_project_item_origins')) {
            $result = $this->backfillConsumedIdeas();
            $stats['consumed_ideas_inserted'] = $result['inserted'];
            $stats['chunks'] += $result['chunks'];
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_task_planning_attributions')) {
            $result = $this->backfillUnattributedAttributions();
            $stats['attributions_unattributed'] = $result['inserted'];
            $stats['chunks'] += $result['chunks'];
        }

        Log::info('seo_content_project_planning_backfill', $stats);

        return $stats;
    }

    /**
     * @return array{updated: int, chunks: int}
     */
    public function backfillPlanningMonths(int $chunkSize = self::CHUNK): array
    {
        $updated = 0;
        $chunks = 0;
        $afterId = 0;

        while (true) {
            $rows = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
                ->leftJoin('seo_projects as p', 'p.id', '=', 't.project_id')
                ->whereNull('t.planning_month')
                ->where('t.id', '>', $afterId)
                ->orderBy('t.id')
                ->limit($chunkSize)
                ->get([
                    't.id',
                    't.created_at',
                    't.target_date',
                    'p.month as project_month',
                    'p.status as project_status',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            $chunks++;
            foreach ($rows as $row) {
                $afterId = max($afterId, (int) $row->id);
                $month = PlanningMonthBackfill::resolve([
                    'planning_month' => null,
                    'created_at' => $row->created_at ?? null,
                    'target_date' => $row->target_date ?? null,
                    'project_month' => $row->project_month ?? null,
                    'project_is_draft' => (string) ($row->project_status ?? '') === SeoProject::STATUS_DRAFT,
                ]);

                $updated += DB::connection('omi_seo_ai')->table('seo_project_tasks')
                    ->where('id', (int) $row->id)
                    ->whereNull('planning_month')
                    ->update(['planning_month' => ContentProjectMonthContext::toDateString($month)]);
            }
        }

        return ['updated' => $updated, 'chunks' => $chunks];
    }

    /**
     * @return array{inserted: int, chunks: int}
     */
    public function backfillConsumedIdeas(int $chunkSize = self::CHUNK): array
    {
        $inserted = 0;
        $chunks = 0;
        $afterId = 0;

        while (true) {
            $origins = DB::connection('omi_seo_ai')->table('seo_content_project_item_origins as o')
                ->join('seo_project_tasks as t', 't.id', '=', 'o.project_task_id')
                ->where('o.source_type', SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST)
                ->whereNotNull('t.site_id')
                ->where('t.site_id', '>', 0)
                ->where('o.id', '>', $afterId)
                ->orderBy('o.id')
                ->limit($chunkSize)
                ->get([
                    'o.id',
                    'o.project_task_id',
                    'o.source_finding_ids',
                    'o.reason_codes',
                    'o.created_at',
                    't.site_id',
                    't.keyword',
                    't.source_content',
                ]);

            if ($origins->isEmpty()) {
                break;
            }

            $chunks++;
            foreach ($origins as $origin) {
                $afterId = max($afterId, (int) $origin->id);
                $keywordId = $this->extractKeywordId(
                    $origin->source_finding_ids ?? null,
                    $origin->reason_codes ?? null,
                );
                if ($keywordId <= 0) {
                    continue;
                }

                $siteId = (int) ($origin->site_id ?? 0);
                if ($siteId <= 0) {
                    continue;
                }

                $exists = DB::connection('omi_seo_ai')->table('seo_content_project_consumed_ideas')
                    ->where('site_id', $siteId)
                    ->where('source_type', SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST)
                    ->where('source_ref', (string) $keywordId)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $phrase = trim((string) ($origin->keyword ?? $origin->source_content ?? ''));
                DB::connection('omi_seo_ai')->table('seo_content_project_consumed_ideas')->insert([
                    'site_id' => $siteId,
                    'source_type' => SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
                    'source_ref' => (string) $keywordId,
                    'source_keyword_id' => $keywordId,
                    'phrase_snapshot' => $phrase !== '' ? mb_substr($phrase, 0, 500) : null,
                    'source_article_id' => null,
                    'vocabulary_group' => null,
                    'project_task_id' => (int) $origin->project_task_id,
                    'consumed_at' => $origin->created_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            }
        }

        return ['inserted' => $inserted, 'chunks' => $chunks];
    }

    /**
     * @return array{inserted: int, chunks: int}
     */
    public function backfillUnattributedAttributions(int $chunkSize = self::CHUNK): array
    {
        $inserted = 0;
        $chunks = 0;
        $afterId = 0;

        while (true) {
            $rows = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
                ->leftJoin('seo_content_project_task_planning_attributions as a', 'a.project_task_id', '=', 't.id')
                ->leftJoin('seo_content_project_item_origins as o', 'o.project_task_id', '=', 't.id')
                ->leftJoin('seo_projects as p', 'p.id', '=', 't.project_id')
                ->whereNull('a.id')
                ->whereNotNull('t.site_id')
                ->where('t.site_id', '>', 0)
                ->whereNull('t.archived_at')
                ->whereNull('t.deleted_at')
                ->where('t.id', '>', $afterId)
                ->orderBy('t.id')
                ->limit($chunkSize)
                ->get([
                    't.id',
                    't.site_id',
                    't.planning_month',
                    't.created_at',
                    't.target_date',
                    'p.month as project_month',
                    'p.status as project_status',
                    'o.source_type',
                    'o.planner_run_id',
                    'o.source_finding_ids',
                    'o.reason_codes',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            $chunks++;
            foreach ($rows as $row) {
                $afterId = max($afterId, (int) $row->id);
                $month = PlanningMonthBackfill::resolve([
                    'planning_month' => $row->planning_month ?? null,
                    'created_at' => $row->created_at ?? null,
                    'target_date' => $row->target_date ?? null,
                    'project_month' => $row->project_month ?? null,
                    'project_is_draft' => (string) ($row->project_status ?? '') === SeoProject::STATUS_DRAFT,
                ]);

                $keywordId = $this->extractKeywordId(
                    $row->source_finding_ids ?? null,
                    $row->reason_codes ?? null,
                );

                $exists = DB::connection('omi_seo_ai')->table('seo_content_project_task_planning_attributions')
                    ->where('project_task_id', (int) $row->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::connection('omi_seo_ai')->table('seo_content_project_task_planning_attributions')->insert([
                    'project_task_id' => (int) $row->id,
                    'site_id' => (int) $row->site_id,
                    'planner_run_id' => ((int) ($row->planner_run_id ?? 0)) ?: null,
                    'planning_month' => $month,
                    'source_type' => $row->source_type ?? null,
                    'source_keyword_id' => $keywordId > 0 ? $keywordId : null,
                    'cluster_ref' => null,
                    'cluster_name_snapshot' => null,
                    'dna_phrases' => null,
                    'attribution_status' => 'unattributed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            }
        }

        return ['inserted' => $inserted, 'chunks' => $chunks];
    }

    private function extractKeywordId(mixed $findingsRaw, mixed $codesRaw): int
    {
        $findings = is_string($findingsRaw) ? json_decode($findingsRaw, true) : $findingsRaw;
        if (is_array($findings)) {
            foreach ($findings as $finding) {
                $id = (int) $finding;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        $codes = is_string($codesRaw) ? json_decode($codesRaw, true) : $codesRaw;
        if (is_array($codes)) {
            foreach ($codes as $code) {
                $code = (string) $code;
                if (str_starts_with($code, 'source_keyword_id:')) {
                    $id = (int) substr($code, strlen('source_keyword_id:'));
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        return 0;
    }
}
