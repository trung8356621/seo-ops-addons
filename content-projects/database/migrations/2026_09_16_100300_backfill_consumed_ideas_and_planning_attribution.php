<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Deterministic backfill:
 * 1) planning_month on legacy tasks
 * 2) consumed_ideas tombstones from vocabulary_suggest origins
 * 3) unattributed planning attributions when cluster cannot be proven
 *
 * Safe to re-run (insert ignore / whereNull only).
 */
return new class extends Migration
{
    public function up(): void
    {
        $stats = [
            'planning_month_stamped' => 0,
            'consumed_ideas_inserted' => 0,
            'attributions_unattributed' => 0,
        ];

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $stats['planning_month_stamped'] = $this->backfillPlanningMonths();
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_consumed_ideas')
            && Schema::connection('omi_seo_ai')->hasTable('seo_content_project_item_origins')) {
            $stats['consumed_ideas_inserted'] = $this->backfillConsumedIdeas();
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_task_planning_attributions')) {
            $stats['attributions_unattributed'] = $this->backfillUnattributedAttributions();
        }

        Log::info('seo_content_project_planning_backfill', $stats);
    }

    public function down(): void
    {
        // Non-destructive: leave backfilled rows (tombstones/attributions are product history).
    }

    private function backfillPlanningMonths(): int
    {
        $updated = 0;
        $rows = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->leftJoin('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.planning_month')
            ->orderBy('t.id')
            ->limit(5000)
            ->get([
                't.id',
                't.created_at',
                't.target_date',
                'p.month as project_month',
                'p.status as project_status',
            ]);

        foreach ($rows as $row) {
            $isDraft = (string) ($row->project_status ?? '') === 'draft';
            $month = ContentProjectMonthContext::parseOrNull($row->planning_month ?? null);
            if ($month === null && ! $isDraft) {
                $month = ContentProjectMonthContext::parseOrNull($row->project_month ?? null);
            }
            if ($month === null) {
                $month = ContentProjectMonthContext::parseOrNull($row->target_date ?? null);
            }
            if ($month === null) {
                $month = ContentProjectMonthContext::parseOrNull($row->created_at ?? null);
            }
            if ($month === null) {
                $month = '1970-01';
            }

            $updated += DB::connection('omi_seo_ai')->table('seo_project_tasks')
                ->where('id', (int) $row->id)
                ->whereNull('planning_month')
                ->update(['planning_month' => ContentProjectMonthContext::toDateString($month)]);
        }

        return $updated;
    }

    private function backfillConsumedIdeas(): int
    {
        $inserted = 0;
        $origins = DB::connection('omi_seo_ai')->table('seo_content_project_item_origins as o')
            ->join('seo_project_tasks as t', 't.id', '=', 'o.project_task_id')
            ->where('o.source_type', SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST)
            ->whereNotNull('t.site_id')
            ->where('t.site_id', '>', 0)
            ->orderBy('o.id')
            ->limit(5000)
            ->get([
                'o.project_task_id',
                'o.source_finding_ids',
                'o.reason_codes',
                'o.created_at',
                't.site_id',
                't.keyword',
                't.source_content',
            ]);

        foreach ($origins as $origin) {
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

        return $inserted;
    }

    private function backfillUnattributedAttributions(): int
    {
        $inserted = 0;
        $rows = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->leftJoin('seo_content_project_task_planning_attributions as a', 'a.project_task_id', '=', 't.id')
            ->leftJoin('seo_content_project_item_origins as o', 'o.project_task_id', '=', 't.id')
            ->whereNull('a.id')
            ->whereNotNull('t.site_id')
            ->where('t.site_id', '>', 0)
            ->whereNull('t.archived_at')
            ->whereNull('t.deleted_at')
            ->orderBy('t.id')
            ->limit(5000)
            ->get([
                't.id',
                't.site_id',
                't.planning_month',
                't.created_at',
                't.target_date',
                'o.source_type',
                'o.planner_run_id',
                'o.source_finding_ids',
                'o.reason_codes',
            ]);

        foreach ($rows as $row) {
            $month = ContentProjectMonthContext::parseOrNull($row->planning_month ?? null)
                ?? ContentProjectMonthContext::parseOrNull($row->target_date ?? null)
                ?? ContentProjectMonthContext::parseOrNull($row->created_at ?? null)
                ?? '1970-01';

            $keywordId = $this->extractKeywordId(
                $row->source_finding_ids ?? null,
                $row->reason_codes ?? null,
            );

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
                // Do not invent cluster from title — leave unattributed.
                'attribution_status' => 'unattributed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        return $inserted;
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
};
