<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Illuminate\Console\Command;

final class BackfillPromptResultLinksCommand extends Command
{
    protected $signature = 'seo-content-ai:backfill-prompt-result-links
        {--article-id= : Chỉ backfill cho 1 article_id}
        {--run-id= : Chỉ backfill cho 1 project_run_id}
        {--chunk=200 : Kích thước chunk khi quét dữ liệu}
        {--dry-run : Chỉ thống kê, không ghi dữ liệu}';

    protected $description = 'Backfill bảng seo_prompt_result_links từ run items và input_snapshot.';

    public function handle(PromptResultLinkService $linkService): int
    {
        $articleId = (int) ($this->option('article-id') ?? 0);
        $runId = (int) ($this->option('run-id') ?? 0);
        $chunk = max(50, (int) ($this->option('chunk') ?? 200));
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Bắt đầu backfill seo_prompt_result_links ...');
        $this->line('Filter: article_id='.($articleId > 0 ? $articleId : 'ALL')
            .', run_id='.($runId > 0 ? $runId : 'ALL')
            .', dry_run='.($dryRun ? 'yes' : 'no'));

        $stats = [
            'workflow_links' => 0,
            'snapshot_links' => 0,
            'errors' => 0,
        ];

        try {
            $this->backfillFromWorkflowRuns($linkService, $articleId, $runId, $chunk, $dryRun, $stats);
            $this->backfillFromPromptSnapshots($linkService, $articleId, $chunk, $dryRun, $stats);
        } catch (\Throwable $exception) {
            $this->error('Backfill thất bại: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Hoàn tất backfill.');
        $this->table(
            ['Nguồn', 'Số link xử lý'],
            [
                ['Workflow runs', (string) $stats['workflow_links']],
                ['Input snapshot', (string) $stats['snapshot_links']],
                ['Errors', (string) $stats['errors']],
            ],
        );

        if ($dryRun) {
            $this->warn('Đang chạy dry-run, chưa ghi dữ liệu. Chạy lại không kèm --dry-run để áp dụng.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function backfillFromWorkflowRuns(
        PromptResultLinkService $linkService,
        int $articleId,
        int $runId,
        int $chunk,
        bool $dryRun,
        array &$stats,
    ): void {
        $query = SeoProjectRun::query()->orderBy('id');
        if ($runId > 0) {
            $query->whereKey($runId);
        }

        $query->chunkById($chunk, function ($runs) use ($linkService, $articleId, $dryRun, &$stats): void {
            foreach ($runs as $run) {
                /** @var SeoProjectRun $run */
                $items = is_array($run->items) ? $run->items : [];

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $itemArticleId = (int) ($item['article_id'] ?? 0);
                    if ($itemArticleId <= 0) {
                        continue;
                    }

                    if ($articleId > 0 && $itemArticleId !== $articleId) {
                        continue;
                    }

                    $taskId = (int) ($item['task_id'] ?? 0);
                    $steps = is_array($item['steps'] ?? null) ? $item['steps'] : [];

                    foreach ($steps as $step) {
                        if (! is_array($step) || (int) ($step['result_id'] ?? 0) <= 0) {
                            continue;
                        }

                        $stats['workflow_links']++;
                        if ($dryRun) {
                            continue;
                        }

                        try {
                            $linkService->linkFromWorkflowStep(
                                step: $step,
                                articleId: $itemArticleId,
                                runId: (int) $run->id,
                                taskId: $taskId,
                                source: 'workflow_run',
                            );
                        } catch (\Throwable) {
                            $stats['errors']++;
                        }
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function backfillFromPromptSnapshots(
        PromptResultLinkService $linkService,
        int $articleId,
        int $chunk,
        bool $dryRun,
        array &$stats,
    ): void {
        $query = PromptResult::query()->orderBy('id');

        if ($articleId > 0) {
            $query->where(function ($builder) use ($articleId): void {
                $builder
                    ->where('input_snapshot->article_id', (string) $articleId)
                    ->orWhere('input_snapshot->article_id', $articleId)
                    ->orWhere('input_snapshot->variables->article_id', (string) $articleId)
                    ->orWhere('input_snapshot->variables->article_id', $articleId);
            });
        }

        $query->chunkById($chunk, function ($results) use ($linkService, $articleId, $dryRun, &$stats): void {
            foreach ($results as $result) {
                /** @var PromptResult $result */
                $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];

                $snapshotArticleId = (int) ($snapshot['article_id'] ?? ($snapshot['variables']['article_id'] ?? 0));
                if ($snapshotArticleId <= 0) {
                    continue;
                }

                if ($articleId > 0 && $snapshotArticleId !== $articleId) {
                    continue;
                }

                $stats['snapshot_links']++;
                if ($dryRun) {
                    continue;
                }

                try {
                    $linkService->linkPromptResult(
                        promptResultId: (int) $result->id,
                        articleId: $snapshotArticleId,
                        source: 'snapshot_inferred',
                        meta: [
                            'status' => (string) $result->status,
                        ],
                    );
                } catch (\Throwable) {
                    $stats['errors']++;
                }
            }
        });
    }
}
