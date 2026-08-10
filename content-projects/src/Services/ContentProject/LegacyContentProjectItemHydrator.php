<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;

final class LegacyContentProjectItemHydrator
{
    public function __construct(
        private readonly ArticleGenerationInputResolver $generationInput,
        private readonly ArticleOutlineResolver $outlineResolver,
    ) {}

    /**
     * @return array{project_id:int,dry_run:bool,items:list<array<string,mixed>>,totals:array<string,int>}
     */
    public function inspectProject(int $projectId, bool $apply = false): array
    {
        $project = SeoProject::query()->findOrFail($projectId);
        $items = [];
        $totals = [
            'items' => 0,
            'legacy' => 0,
            'canonical' => 0,
            'repairable' => 0,
            'mutated' => 0,
            'blocked' => 0,
        ];

        SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use ($apply, &$items, &$totals): void {
                foreach ($tasks as $task) {
                    if (! $task instanceof SeoProjectTask) {
                        continue;
                    }

                    $report = $this->inspectTask($task);
                    $totals['items']++;
                    $totals[$report['legacy'] ? 'legacy' : 'canonical']++;
                    if ($report['repairable']) {
                        $totals['repairable']++;
                    }
                    if (! $report['can_generate_after_repair']) {
                        $totals['blocked']++;
                    }

                    if ($apply && $report['repairable']) {
                        DB::connection('omi_seo_ai')->transaction(function () use ($task, &$report): void {
                            $report['mutations'] = $this->repairTask($task);
                        });
                        $totals['mutated']++;
                    }

                    $items[] = $report;
                }
            });

        return [
            'project_id' => (int) $project->id,
            'dry_run' => ! $apply,
            'items' => $items,
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectTask(SeoProjectTask $task): array
    {
        $task->loadMissing('article');
        $article = $task->article;
        $articleId = (int) ($task->article_id ?? 0);
        $type = SeoProjectTask::normalizeType($task->type);
        $missing = [];
        $detected = [];
        $hydrated = [];
        $proposed = [];

        $hasCanonicalOutline = false;
        if ($article !== null) {
            $article->loadMissing('articleMetas');
            $outline = trim((string) $this->outlineResolver->resolveMarkdown($article));
            $hasCanonicalOutline = $outline !== '' && $this->generationInput->isValidArtifact($outline);
            if ($outline !== '' && ! $hasCanonicalOutline) {
                $detected[] = 'legacy_or_plain_outline_meta';
            }
        }

        $hasRunOutline = false;
        if ($article !== null) {
            try {
                $source = $this->generationInput->resolveForArticle($article);
                $hasRunOutline = trim($source->rawArtifact) !== '';
                $hydrated[] = $source->sourceType;
            } catch (\Throwable) {
                $hasRunOutline = false;
            }
        }

        if ($type === SeoProjectTask::TYPE_REWRITE && $articleId <= 0) {
            $missing[] = 'source_article';
        }
        if ($type === SeoProjectTask::TYPE_REWRITE && ! $hasCanonicalOutline && ! $hasRunOutline) {
            $missing[] = 'canonical_outline_artifact';
            $missing[] = 'writing_instructions_artifact';
            $proposed[] = 'rerun_from_start_generate_outline_first';
        }

        $stale = $this->latestStaleFailure($task);
        if ($stale !== null) {
            $detected[] = 'stale_failed_execution';
            $proposed[] = 'clear_transient_failed_generation_state';
        }

        $legacy = $detected !== [] || ($type === SeoProjectTask::TYPE_REWRITE && ! $hasCanonicalOutline);
        $repairable = $stale !== null || $legacy;
        $canGenerate = ! in_array('source_article', $missing, true);

        return [
            'item_id' => (int) $task->id,
            'article_id' => $articleId,
            'item_type' => $type,
            'status' => (string) $task->status,
            'legacy' => $legacy,
            'canonical_status' => $legacy ? 'legacy_or_incomplete' : 'canonical',
            'missing_context_artifacts' => array_values(array_unique($missing)),
            'detected_legacy_fields' => array_values(array_unique($detected)),
            'hydrated_artifacts' => array_values(array_unique($hydrated)),
            'stale_execution' => $stale,
            'proposed_repair' => array_values(array_unique($proposed)),
            'repairable' => $repairable,
            'can_generate_after_repair' => $canGenerate,
        ];
    }

    /**
     * @return list<string>
     */
    private function repairTask(SeoProjectTask $task): array
    {
        $mutations = [];
        $previousExecutionId = (int) (SeoProjectRunItem::query()
            ->where('task_id', (int) $task->id)
            ->orderByDesc('id')
            ->value('run_id') ?? 0);

        if (in_array((string) $task->status, [
            SeoProjectTask::STATUS_FAILED,
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_PROCESSING,
        ], true)) {
            $task->status = SeoProjectTask::STATUS_PENDING;
            $task->save();
            $mutations[] = 'task_status_pending';
        }

        $cleared = SeoProjectRunItem::query()
            ->where('task_id', (int) $task->id)
            ->whereIn('status', [
                SeoProjectRunItemStatus::Pending->value,
                SeoProjectRunItemStatus::Processing->value,
            ])
            ->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => 'Legacy generation state cleared; ready for clean restart.',
                'error_message' => null,
                'finished_at' => now(),
            ]);

        if ($cleared > 0) {
            $mutations[] = 'transient_run_items_cleared:'.$cleared;
        }

        RuntimeLogger::info('content_project.legacy_repair_item', [
            'project_id' => (int) ($task->project_id ?? 0),
            'item_id' => (int) $task->id,
            'article_id' => (int) ($task->article_id ?? 0),
            'previous_execution_id' => $previousExecutionId > 0 ? $previousExecutionId : null,
            'new_execution_id' => null,
            'item_type' => SeoProjectTask::normalizeType($task->type),
            'detected_legacy_fields' => $mutations,
            'missing_artifacts' => [],
            'hydrated_artifacts' => [],
            'rerun_from_step' => 'start',
            'result' => 'repaired',
        ]);

        return $mutations;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestStaleFailure(SeoProjectTask $task): ?array
    {
        $item = SeoProjectRunItem::query()
            ->where('task_id', (int) $task->id)
            ->whereIn('status', [
                SeoProjectRunItemStatus::Failed->value,
                SeoProjectRunItemStatus::Processing->value,
                SeoProjectRunItemStatus::Pending->value,
            ])
            ->orderByDesc('id')
            ->first(['id', 'run_id', 'status', 'action', 'error_message']);

        if (! $item instanceof SeoProjectRunItem) {
            return null;
        }

        if (ContentProjectExecutionStatus::isActive((string) $item->status)) {
            return [
                'run_item_id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) $item->status,
                'action' => (string) $item->action,
            ];
        }

        $run = SeoProjectRun::query()->find((int) $item->run_id);
        if ($run instanceof SeoProjectRun && (string) $run->status === SeoProjectRun::STATUS_FAILED) {
            return [
                'run_item_id' => (int) $item->id,
                'run_id' => (int) $item->run_id,
                'status' => (string) $item->status,
                'action' => (string) $item->action,
                'error' => (string) ($item->error_message ?? ''),
            ];
        }

        return null;
    }
}
