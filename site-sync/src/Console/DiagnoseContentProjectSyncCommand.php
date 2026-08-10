<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Console;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3C2 — diagnose sync leftovers; không auto-repair.
 */
final class DiagnoseContentProjectSyncCommand extends Command
{
    protected $signature = 'content-project:diagnose-sync
        {--project-id= : Chỉ một project}
        {--limit=50 : Số mẫu log}';

    protected $description = 'Diagnose duplicate identity / soft-deleted sync replacements trên seo_project_tasks.';

    public function handle(): int
    {
        $projectId = (int) ($this->option('project-id') ?? 0);
        $limit = max(1, (int) ($this->option('limit') ?? 50));

        $stats = [
            'SYNC_DUPLICATE_ACTIVE_IDENTITY' => 0,
            'SYNC_LEGACY_SOFT_DELETED_REPLACEMENT' => 0,
            'SYNC_ARTICLE_SPLIT_ACROSS_TASKS' => 0,
            'SYNC_NULL_SOURCE_KEY_ACTIVE' => 0,
        ];

        $dupQuery = SeoProjectTask::query()
            ->selectRaw('project_id, source_key, COUNT(*) as c')
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->whereNotNull('source_key')
            ->groupBy('project_id', 'source_key')
            ->havingRaw('COUNT(*) > 1');

        if ($projectId > 0) {
            $dupQuery->where('project_id', $projectId);
        }

        foreach ($dupQuery->get() as $row) {
            $stats['SYNC_DUPLICATE_ACTIVE_IDENTITY']++;
            if ($stats['SYNC_DUPLICATE_ACTIVE_IDENTITY'] <= $limit) {
                $this->line(sprintf(
                    '[SYNC_DUPLICATE_ACTIVE_IDENTITY] project=%s source_key=%s count=%s',
                    (string) $row->project_id,
                    (string) $row->source_key,
                    (string) $row->c,
                ));
            }
        }

        $nullKey = SeoProjectTask::query()
            ->whereNull('source_key')
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED);
        if ($projectId > 0) {
            $nullKey->where('project_id', $projectId);
        }
        $stats['SYNC_NULL_SOURCE_KEY_ACTIVE'] = (int) $nullKey->count();

        $softQuery = SeoProjectTask::onlyTrashed()
            ->whereNotNull('source_key')
            ->orderBy('id');
        if ($projectId > 0) {
            $softQuery->where('project_id', $projectId);
        }

        $logged = 0;
        foreach ($softQuery->cursor() as $deleted) {
            if (! $deleted instanceof SeoProjectTask) {
                continue;
            }

            $activeTwin = SeoProjectTask::query()
                ->where('project_id', (int) $deleted->project_id)
                ->where('source_key', (string) $deleted->source_key)
                ->whereNull('archived_at')
                ->exists();

            if (! $activeTwin) {
                continue;
            }

            $stats['SYNC_LEGACY_SOFT_DELETED_REPLACEMENT']++;
            if ($logged < $limit) {
                $this->line(sprintf(
                    '[SYNC_LEGACY_SOFT_DELETED_REPLACEMENT] deleted_task=%d project=%d source_key=%s',
                    (int) $deleted->id,
                    (int) $deleted->project_id,
                    (string) $deleted->source_key,
                ));
                $logged++;
            }
        }

        $articleSplit = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks')
            ->selectRaw('article_id, COUNT(*) as c')
            ->whereNotNull('article_id')
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->groupBy('article_id')
            ->havingRaw('COUNT(*) > 1');
        if ($projectId > 0) {
            $articleSplit->where('project_id', $projectId);
        }
        $stats['SYNC_ARTICLE_SPLIT_ACROSS_TASKS'] = (int) $articleSplit->get()->count();

        $this->newLine();
        $this->table(
            ['Check', 'Count'],
            collect($stats)->map(static fn (int $v, string $k): array => [$k, (string) $v])->values()->all(),
        );

        return self::SUCCESS;
    }
}
