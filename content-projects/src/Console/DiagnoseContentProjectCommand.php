<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DiagnoseContentProjectCommand extends Command
{
    protected $signature = 'content-project:diagnose
        {--project-id= : Lọc project}
        {--json : Output JSON}
        {--strict : Exit 1 nếu có critical issue}';

    protected $description = 'Diagnose Content Project integrity (source_key, duplicates, archive, runs, consolidation).';

    public function handle(): int
    {
        $projectId = (int) ($this->option('project-id') ?? 0);
        $issues = $this->collectIssues($projectId > 0 ? $projectId : null);

        $critical = array_filter($issues, static fn (array $i): bool => ($i['severity'] ?? '') === 'critical');

        if ($this->option('json')) {
            $this->line(json_encode([
                'issues' => $issues,
                'critical_count' => count($critical),
                'ok' => $critical === [],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Code', 'Severity', 'Count', 'Detail'],
                array_map(static fn (array $i): array => [
                    (string) $i['code'],
                    (string) $i['severity'],
                    (string) $i['count'],
                    (string) ($i['detail'] ?? ''),
                ], $issues),
            );
            $this->info($critical === [] ? 'No critical issues.' : 'Critical issues: '.count($critical));
        }

        if ($this->option('strict') && $critical !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{code: string, severity: string, count: int, detail?: string}>
     */
    private function collectIssues(?int $projectId): array
    {
        $issues = [];

        $taskQ = SeoProjectTask::withTrashed();
        if ($projectId !== null) {
            $taskQ->where('project_id', $projectId);
        }

        $issues[] = ['code' => 'TOTAL_TASKS', 'severity' => 'info', 'count' => (int) (clone $taskQ)->count()];
        $issues[] = ['code' => 'ACTIVE_TASKS', 'severity' => 'info', 'count' => (int) SeoProjectTask::query()->when($projectId, fn ($q) => $q->where('project_id', $projectId))->active()->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)->count()];
        $issues[] = ['code' => 'ARCHIVED_TASKS', 'severity' => 'info', 'count' => (int) SeoProjectTask::query()->when($projectId, fn ($q) => $q->where('project_id', $projectId))->archived()->count()];
        $issues[] = ['code' => 'SOFT_DELETED_TASKS', 'severity' => 'info', 'count' => (int) SeoProjectTask::onlyTrashed()->when($projectId, fn ($q) => $q->where('project_id', $projectId))->count()];

        $nullKeys = (int) SeoProjectTask::withTrashed()->when($projectId, fn ($q) => $q->where('project_id', $projectId))->whereNull('source_key')->count();
        $issues[] = [
            'code' => 'NULL_SOURCE_KEY',
            'severity' => $nullKeys > 0 ? 'critical' : 'info',
            'count' => $nullKeys,
        ];

        $dupQ = SeoProjectTask::withTrashed()
            ->selectRaw('project_id, source_key, COUNT(*) as c')
            ->whereNotNull('source_key')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->groupBy('project_id', 'source_key')
            ->havingRaw('COUNT(*) > 1');
        $dupCount = (int) $dupQ->get()->count();
        $issues[] = [
            'code' => 'DUPLICATE_SOURCE_KEY',
            'severity' => $dupCount > 0 ? 'critical' : 'info',
            'count' => $dupCount,
        ];

        $activeDup = SeoProjectTask::query()
            ->selectRaw('project_id, source_key, COUNT(*) as c')
            ->whereNotNull('source_key')
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->groupBy('project_id', 'source_key')
            ->havingRaw('COUNT(*) > 1');
        $issues[] = [
            'code' => 'DUPLICATE_ACTIVE_IDENTITY',
            'severity' => ($c = (int) $activeDup->get()->count()) > 0 ? 'critical' : 'info',
            'count' => $c,
        ];

        $articleSplit = DB::connection('omi_seo_ai')->table('seo_project_tasks')
            ->selectRaw('article_id, COUNT(*) as c')
            ->whereNotNull('article_id')
            ->whereNull('deleted_at')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->groupBy('article_id')
            ->havingRaw('COUNT(*) > 1');
        $issues[] = [
            'code' => 'MULTIPLE_TASKS_SAME_ARTICLE',
            'severity' => ($c = (int) $articleSplit->get()->count()) > 0 ? 'warning' : 'info',
            'count' => $c,
        ];

        $missingTask = (int) SeoProjectRunItem::query()
            ->whereNotNull('task_id')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('seo_project_tasks')
                    ->whereColumn('seo_project_tasks.id', 'seo_project_run_items.task_id');
            })
            ->count();
        $issues[] = [
            'code' => 'RUN_ITEM_MISSING_TASK',
            'severity' => $missingTask > 0 ? 'warning' : 'info',
            'count' => $missingTask,
        ];

        $missingEvents = (int) SeoProjectTaskEvent::query()
            ->whereNotNull('task_id')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('seo_project_tasks')
                    ->whereColumn('seo_project_tasks.id', 'seo_project_task_events.task_id');
            })
            ->count();
        $issues[] = [
            'code' => 'EVENT_MISSING_TASK',
            'severity' => $missingEvents > 0 ? 'warning' : 'info',
            'count' => $missingEvents,
        ];

        $runsWithDb = (int) SeoProjectRun::query()->whereHas('runItems')->count();
        $legacyOnly = (int) SeoProjectRun::query()
            ->whereNotNull('items')
            ->whereDoesntHave('runItems')
            ->count();
        $mixed = (int) SeoProjectRun::query()
            ->whereHas('runItems')
            ->whereNotNull('items')
            ->count();
        $issues[] = ['code' => 'RUNS_WITH_DB_ITEMS', 'severity' => 'info', 'count' => $runsWithDb];
        $issues[] = [
            'code' => 'RUNS_LEGACY_JSON_ONLY',
            'severity' => $legacyOnly > 0 ? 'critical' : 'info',
            'count' => $legacyOnly,
            'detail' => 'Run content-project:backfill-run-items',
        ];
        $issues[] = ['code' => 'RUNS_MIXED_DB_JSON', 'severity' => $mixed > 0 ? 'warning' : 'info', 'count' => $mixed];

        $mirrorNoTask = (int) SeoContentArchiveItem::query()->whereNull('task_id')->count();
        $issues[] = [
            'code' => 'ARCHIVE_MIRROR_NO_TASK_ID',
            'severity' => $mirrorNoTask > 0 ? 'warning' : 'info',
            'count' => $mirrorNoTask,
        ];

        $archivedNoMirror = 0;
        $q = SeoProjectTask::query()->archived()->whereNotNull('article_id');
        if ($projectId !== null) {
            $q->where('project_id', $projectId);
        }
        foreach ($q->cursor() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $exists = SeoContentArchiveItem::query()
                ->where(function ($inner) use ($task): void {
                    $inner->where('task_id', (int) $task->id)
                        ->orWhere('article_id', (int) $task->article_id);
                })
                ->exists();
            if (! $exists) {
                $archivedNoMirror++;
            }
        }
        $issues[] = [
            'code' => 'ARCHIVED_TASK_MISSING_MIRROR',
            'severity' => $archivedNoMirror > 0 ? 'warning' : 'info',
            'count' => $archivedNoMirror,
        ];

        $activeWithMirror = 0;
        foreach (SeoContentArchiveItem::query()->whereNotNull('task_id')->cursor() as $mirror) {
            $task = SeoProjectTask::query()->find((int) $mirror->task_id);
            if ($task instanceof SeoProjectTask && $task->archived_at === null) {
                $activeWithMirror++;
            }
        }
        $issues[] = [
            'code' => 'ACTIVE_TASK_WITH_ARCHIVE_MIRROR',
            'severity' => $activeWithMirror > 0 ? 'warning' : 'info',
            'count' => $activeWithMirror,
        ];

        $flagActive = 0;
        $flagged = SeoArticle::query()
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('seo_content_archive_items')
                    ->whereColumn('seo_content_archive_items.article_id', 'articles.id');
            });
        foreach ($flagged->cursor() as $article) {
            $hasActive = SeoProjectTask::query()
                ->active()
                ->where('article_id', (int) $article->id)
                ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
                ->exists();
            $hasArchived = SeoProjectTask::query()
                ->archived()
                ->where('article_id', (int) $article->id)
                ->exists();
            if ($hasActive && ! $hasArchived) {
                $flagActive++;
            }
        }
        $issues[] = [
            'code' => 'ARTICLE_FLAG_ACTIVE_TASK_MISMATCH',
            'severity' => $flagActive > 0 ? 'warning' : 'info',
            'count' => $flagActive,
        ];

        $consolidated = (int) SeoProjectRun::query()
            ->whereNotNull('consolidated_into_run_id')
            ->count();
        $issues[] = ['code' => 'CONSOLIDATED_RUNS', 'severity' => 'info', 'count' => $consolidated];

        return $issues;
    }
}
