<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskCanonicalResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3C3 repair: backfill source_key, merge duplicates, archive mirrors, purge orphans.
 */
final class SeoProjectTaskRepairService
{
    public function __construct(
        private readonly ProjectTaskSourceKeyGenerator $sourceKeys,
        private readonly SeoProjectTaskCanonicalResolver $canonicalResolver,
        private readonly SeoProjectRunItemMergeService $runItemMerger,
    ) {}

    /**
     * @return array<string, int|list<mixed>>
     */
    public function repair(
        bool $apply,
        ?int $projectId = null,
        ?int $taskId = null,
        bool $includeArchive = true,
        bool $repairRunItems = true,
        bool $repairArchive = true,
        bool $purgeSyncOrphans = true,
    ): array {
        $stats = [
            'tasks_scanned' => 0,
            'source_keys_backfilled' => 0,
            'duplicate_groups_found' => 0,
            'canonical_tasks_selected' => 0,
            'tasks_merged' => 0,
            'tasks_soft_deleted' => 0,
            'tasks_force_deleted' => 0,
            'article_links_moved' => 0,
            'run_items_relinked' => 0,
            'events_relinked' => 0,
            'prompt_links_relinked' => 0,
            'archive_mirrors_repaired' => 0,
            'invalid_groups_skipped' => 0,
            'manual_review_required' => 0,
            'manual_groups' => [],
        ];

        $this->backfillSourceKeys($apply, $projectId, $taskId, $stats);
        $this->repairDuplicateGroups($apply, $projectId, $taskId, $purgeSyncOrphans, $stats);

        if ($repairArchive) {
            $this->repairArchiveMirrors($apply, $projectId, $stats);
        }

        if ($repairRunItems) {
            $this->ensureLegacyRunsBackfilledNote($stats);
        }

        return $stats;
    }

    /**
     * @param  array<string, int|list<mixed>>  $stats
     */
    private function backfillSourceKeys(bool $apply, ?int $projectId, ?int $taskId, array &$stats): void
    {
        $query = SeoProjectTask::withTrashed()->orderBy('id');
        if ($projectId !== null && $projectId > 0) {
            $query->where('project_id', $projectId);
        }
        if ($taskId !== null && $taskId > 0) {
            $query->whereKey($taskId);
        }

        $query->chunkById(200, function ($tasks) use ($apply, &$stats): void {
            foreach ($tasks as $task) {
                if (! $task instanceof SeoProjectTask) {
                    continue;
                }
                $stats['tasks_scanned'] = (int) $stats['tasks_scanned'] + 1;

                if ($task->source_key !== null && trim((string) $task->source_key) !== '') {
                    continue;
                }

                $source = trim((string) ($task->source_content ?? ''));
                if ($source === '') {
                    $stats['manual_review_required'] = (int) $stats['manual_review_required'] + 1;
                    /** @var list<mixed> $manual */
                    $manual = $stats['manual_groups'];
                    $manual[] = ['task_id' => (int) $task->id, 'reason' => 'empty_source_content'];
                    $stats['manual_groups'] = $manual;
                    continue;
                }

                $key = $this->sourceKeys->generate(
                    (int) $task->project_id,
                    (string) $task->type,
                    $task->post_type !== null ? (string) $task->post_type : null,
                    $source,
                );

                if (! $apply) {
                    $stats['source_keys_backfilled'] = (int) $stats['source_keys_backfilled'] + 1;
                    continue;
                }

                $task->forceFill(['source_key' => $key])->save();
                $stats['source_keys_backfilled'] = (int) $stats['source_keys_backfilled'] + 1;
            }
        });
    }

    /**
     * @param  array<string, int|list<mixed>>  $stats
     */
    private function repairDuplicateGroups(
        bool $apply,
        ?int $projectId,
        ?int $taskId,
        bool $purgeSyncOrphans,
        array &$stats,
    ): void {
        $base = SeoProjectTask::withTrashed()
            ->whereNotNull('source_key')
            ->selectRaw('project_id, source_key, COUNT(*) as c')
            ->groupBy('project_id', 'source_key')
            ->havingRaw('COUNT(*) > 1');

        if ($projectId !== null && $projectId > 0) {
            $base->where('project_id', $projectId);
        }

        $groups = $base->get();
        foreach ($groups as $group) {
            $stats['duplicate_groups_found'] = (int) $stats['duplicate_groups_found'] + 1;

            $tasks = SeoProjectTask::withTrashed()
                ->where('project_id', (int) $group->project_id)
                ->where('source_key', (string) $group->source_key)
                ->orderBy('id')
                ->get();

            if ($taskId !== null && $taskId > 0 && ! $tasks->contains(static fn (SeoProjectTask $t): bool => (int) $t->id === $taskId)) {
                continue;
            }

            $resolved = $this->canonicalResolver->resolve($tasks);
            if ($resolved['manual_review_required'] || $resolved['canonical_task_id'] === null) {
                $stats['invalid_groups_skipped'] = (int) $stats['invalid_groups_skipped'] + 1;
                $stats['manual_review_required'] = (int) $stats['manual_review_required'] + 1;
                /** @var list<mixed> $manual */
                $manual = $stats['manual_groups'];
                $manual[] = [
                    'project_id' => (int) $group->project_id,
                    'source_key' => (string) $group->source_key,
                    'reason' => $resolved['reason'],
                    'classification' => $resolved['classification'],
                    'task_ids' => $resolved['duplicate_task_ids'],
                ];
                $stats['manual_groups'] = $manual;
                continue;
            }

            $stats['canonical_tasks_selected'] = (int) $stats['canonical_tasks_selected'] + 1;
            $canonicalId = (int) $resolved['canonical_task_id'];

            if (! $apply) {
                $stats['tasks_merged'] = (int) $stats['tasks_merged'] + count($resolved['duplicate_task_ids']);
                continue;
            }

            DB::connection('omi_seo_ai')->transaction(function () use (
                $canonicalId,
                $resolved,
                $purgeSyncOrphans,
                &$stats,
            ): void {
                /** @var SeoProjectTask|null $canonical */
                $canonical = SeoProjectTask::withTrashed()->whereKey($canonicalId)->lockForUpdate()->first();
                if (! $canonical instanceof SeoProjectTask) {
                    return;
                }

                foreach ($resolved['duplicate_task_ids'] as $dupId) {
                    /** @var SeoProjectTask|null $dup */
                    $dup = SeoProjectTask::withTrashed()->whereKey($dupId)->lockForUpdate()->first();
                    if (! $dup instanceof SeoProjectTask) {
                        continue;
                    }

                    $this->mergeMetadata($canonical, $dup);
                    $this->relinkReferences((int) $dup->id, (int) $canonical->id, $stats);

                    $wasTrashed = $dup->trashed();
                    if ($purgeSyncOrphans && $wasTrashed) {
                        $dup->forceDelete();
                        $stats['tasks_force_deleted'] = (int) $stats['tasks_force_deleted'] + 1;
                    } elseif (! $wasTrashed) {
                        $dup->delete();
                        $stats['tasks_soft_deleted'] = (int) $stats['tasks_soft_deleted'] + 1;
                        if ($purgeSyncOrphans) {
                            $dup->forceDelete();
                            $stats['tasks_force_deleted'] = (int) $stats['tasks_force_deleted'] + 1;
                            $stats['tasks_soft_deleted'] = (int) $stats['tasks_soft_deleted'] - 1;
                        }
                    } elseif ($purgeSyncOrphans) {
                        $dup->forceDelete();
                        $stats['tasks_force_deleted'] = (int) $stats['tasks_force_deleted'] + 1;
                    }

                    $stats['tasks_merged'] = (int) $stats['tasks_merged'] + 1;
                    Log::info('seo.project_task.repair_merge', [
                        'canonical_task_id' => $canonicalId,
                        'duplicate_task_id' => $dupId,
                        'reason' => $resolved['reason'],
                    ]);
                }

                $canonical->save();
            });
        }
    }

    private function mergeMetadata(SeoProjectTask $canonical, SeoProjectTask $dup): void
    {
        $fill = [];
        foreach (['rewrite_mode', 'rewrite_notes', 'description', 'loai_san_pham', 'target_date'] as $field) {
            $cur = $canonical->{$field};
            $incoming = $dup->{$field};
            if (($cur === null || $cur === '') && $incoming !== null && $incoming !== '') {
                $fill[$field] = $incoming;
            }
        }

        if ((int) ($canonical->article_id ?? 0) <= 0 && (int) ($dup->article_id ?? 0) > 0) {
            $fill['article_id'] = (int) $dup->article_id;
        }

        if ($canonical->connected_at === null && $dup->connected_at !== null) {
            $fill['connected_at'] = $dup->connected_at;
        }
        if ($canonical->completed_at === null && $dup->completed_at !== null) {
            $fill['completed_at'] = $dup->completed_at;
        }

        $canonRank = $this->statusStrength((string) $canonical->status);
        $dupRank = $this->statusStrength((string) $dup->status);
        if ($dupRank > $canonRank && $canonical->archived_at === null && $dup->archived_at === null) {
            $fill['status'] = (string) $dup->status;
        }

        if ($fill !== []) {
            $canonical->forceFill($fill);
        }
    }

    private function statusStrength(string $status): int
    {
        return match ($status) {
            SeoProjectTask::STATUS_COMPLETED => 100,
            SeoProjectTask::STATUS_REVIEWING => 80,
            SeoProjectTask::STATUS_WRITING, 'processing' => 70,
            SeoProjectTask::STATUS_PENDING => 50,
            SeoProjectTask::STATUS_FAILED => 40,
            default => 0,
        };
    }

    /**
     * @param  array<string, int|list<mixed>>  $stats
     */
    private function relinkReferences(int $fromTaskId, int $toTaskId, array &$stats): void
    {
        $merge = $this->runItemMerger->relinkTask($fromTaskId, $toTaskId);
        $stats['run_items_relinked'] = (int) $stats['run_items_relinked'] + $merge['relinked'] + $merge['merged'];

        $events = SeoProjectTaskEvent::query()->where('task_id', $fromTaskId)->update(['task_id' => $toTaskId]);
        $stats['events_relinked'] = (int) $stats['events_relinked'] + (int) $events;

        $links = SeoPromptResultLink::query()->where('project_task_id', $fromTaskId)->update(['project_task_id' => $toTaskId]);
        $stats['prompt_links_relinked'] = (int) $stats['prompt_links_relinked'] + (int) $links;

        $mirrors = SeoContentArchiveItem::query()->where('task_id', $fromTaskId)->update(['task_id' => $toTaskId]);
        if ($mirrors > 0) {
            $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + (int) $mirrors;
        }

        // Clear article_id on duplicate before delete so unique article constraints don't fight.
        SeoProjectTask::withTrashed()->whereKey($fromTaskId)->update(['article_id' => null]);
        $stats['article_links_moved'] = (int) $stats['article_links_moved'] + 1;
    }

    /**
     * @param  array<string, int|list<mixed>>  $stats
     */
    private function repairArchiveMirrors(bool $apply, ?int $projectId, array &$stats): void
    {
        $archived = SeoProjectTask::query()->archived()->orderBy('id');
        if ($projectId !== null && $projectId > 0) {
            $archived->where('project_id', $projectId);
        }

        foreach ($archived->cursor() as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId <= 0) {
                continue;
            }

            $mirror = SeoContentArchiveItem::query()
                ->where(function ($q) use ($task, $articleId): void {
                    $q->where('task_id', (int) $task->id)
                        ->orWhere('article_id', $articleId);
                })
                ->first();

            if ($mirror instanceof SeoContentArchiveItem) {
                if (! $apply) {
                    if ((int) ($mirror->task_id ?? 0) !== (int) $task->id) {
                        $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + 1;
                    }
                    continue;
                }

                $mirror->forceFill([
                    'task_id' => (int) $task->id,
                    'from_project_id' => (int) $task->project_id,
                    'archived_at' => $mirror->archived_at ?? $task->archived_at ?? now(),
                ])->save();

                $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + 1;
                continue;
            }

            if (! $apply) {
                $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + 1;
                continue;
            }

            SeoContentArchiveItem::query()->updateOrCreate(
                ['article_id' => $articleId],
                [
                    'site_id' => (int) ($task->site_id ?? 0),
                    'task_id' => (int) $task->id,
                    'from_project_id' => (int) $task->project_id,
                    'archived_at' => $task->archived_at ?? now(),
                    'source_content' => mb_substr((string) $task->source_content, 0, 500),
                    'task_type' => (string) $task->type,
                ],
            );
            $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + 1;
        }

        // Active task + mirror: remove mirror when article no longer has archived tasks.
        $mirrors = SeoContentArchiveItem::query()->whereNotNull('task_id')->orderBy('id');
        foreach ($mirrors->cursor() as $mirror) {
            if (! $mirror instanceof SeoContentArchiveItem) {
                continue;
            }
            $task = SeoProjectTask::query()->whereKey((int) $mirror->task_id)->first();
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            if ($task->archived_at !== null) {
                continue;
            }

            if (! $apply) {
                $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + 1;
                continue;
            }

            $articleId = (int) ($mirror->article_id ?? 0);
            $stillArchived = $articleId > 0
                && SeoProjectTask::query()
                    ->archived()
                    ->where('article_id', $articleId)
                    ->exists();

            // Archive row is the flag; keep it if another archived task still owns the article.
            if (! $stillArchived) {
                $mirror->delete();
            }
            $stats['archive_mirrors_repaired'] = (int) $stats['archive_mirrors_repaired'] + 1;
        }
    }

    /**
     * @param  array<string, int|list<mixed>>  $stats
     */
    private function ensureLegacyRunsBackfilledNote(array &$stats): void
    {
        $legacyOnly = (int) SeoProjectRun::query()
            ->whereNotNull('items')
            ->whereDoesntHave('runItems')
            ->count();

        if ($legacyOnly > 0) {
            $stats['manual_review_required'] = (int) $stats['manual_review_required'] + $legacyOnly;
            /** @var list<mixed> $manual */
            $manual = $stats['manual_groups'];
            $manual[] = [
                'reason' => 'legacy_json_runs_need_backfill',
                'count' => $legacyOnly,
                'hint' => 'php artisan content-project:backfill-run-items --apply',
            ];
            $stats['manual_groups'] = $manual;
        }
    }
}
