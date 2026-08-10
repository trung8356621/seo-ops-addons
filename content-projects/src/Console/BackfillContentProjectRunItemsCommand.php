<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\LegacyProjectRunItemMapper;
use Omnichannel\Addons\ContentProjects\Support\ProjectRunIdempotencyKeyGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BackfillContentProjectRunItemsCommand extends Command
{
    protected $signature = 'content-project:backfill-run-items
        {--dry-run : Chỉ thống kê, không ghi DB}
        {--apply : Ghi DB (mặc định khi không có --dry-run; alias tương thích)}
        {--run-id= : Chỉ một seo_project_runs.id}
        {--project-id= : Chỉ runs của project}
        {--chunk=100 : Số run mỗi chunk}
        {--force-inconsistent : Cho phép backfill bổ sung vào run đã có DB items (không overwrite success)}';

    protected $description = 'Backfill seo_project_run_items từ legacy seo_project_runs.items JSON.';

    public function handle(
        LegacyProjectRunItemMapper $mapper,
        ProjectRunIdempotencyKeyGenerator $idempotencyKeys,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun && (bool) $this->option('apply')) {
            $this->warn('--dry-run thắng --apply: không ghi DB.');
        }
        $runId = (int) ($this->option('run-id') ?? 0);
        $projectId = (int) ($this->option('project-id') ?? 0);
        $chunk = max(1, (int) ($this->option('chunk') ?? 100));
        $forceInconsistent = (bool) $this->option('force-inconsistent');

        $stats = [
            'runs_scanned' => 0,
            'runs_skipped_has_db_items' => 0,
            'legacy_items_scanned' => 0,
            'run_items_created' => 0,
            'run_items_updated' => 0,
            'duplicates_collapsed' => 0,
            'missing_task_references' => 0,
            'missing_article_references' => 0,
            'invalid_items' => 0,
            'inconsistent_runs' => 0,
        ];

        $this->info('Backfill seo_project_run_items '.($dryRun ? '(dry-run)' : ''));

        $query = SeoProjectRun::query()->orderBy('id');
        if ($runId > 0) {
            $query->whereKey($runId);
        }
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $query->chunkById($chunk, function ($runs) use (
            $mapper,
            $idempotencyKeys,
            $dryRun,
            $forceInconsistent,
            &$stats,
        ): void {
            foreach ($runs as $run) {
                if (! $run instanceof SeoProjectRun) {
                    continue;
                }

                $stats['runs_scanned']++;
                $hasDb = SeoProjectRunItem::query()->where('run_id', (int) $run->id)->exists();

                if ($hasDb && ! $forceInconsistent) {
                    $stats['runs_skipped_has_db_items']++;

                    continue;
                }

                if ($hasDb && $forceInconsistent) {
                    $stats['inconsistent_runs']++;
                }

                $json = is_array($run->items) ? $run->items : [];
                if ($json === []) {
                    continue;
                }

                /** @var array<string, array{mapped: array<string, mixed>, index: int}> $logical */
                $logical = [];

                foreach ($json as $index => $item) {
                    if (! is_array($item)) {
                        $stats['invalid_items']++;

                        continue;
                    }

                    $stats['legacy_items_scanned']++;
                    $mapped = $mapper->map($item);
                    if ($mapped === null) {
                        $stats['invalid_items']++;

                        continue;
                    }

                    $taskId = $mapped['task_id'];
                    $action = (string) $mapped['action'];

                    if ($taskId !== null && $taskId > 0) {
                        $taskExists = SeoProjectTask::query()->whereKey($taskId)->exists();
                        if (! $taskExists) {
                            $stats['missing_task_references']++;
                            $mapped['input_snapshot']['legacy_task_id'] = $taskId;
                            $mapped['task_id'] = null;
                            $taskId = null;
                        }
                    }

                    if ($mapped['article_id'] !== null && $mapped['article_id'] > 0) {
                        // Article existence optional — chỉ đếm.
                        // Không query articles nếu không cần; giữ ID.
                    }

                    $logicalKey = $taskId !== null && $taskId > 0
                        ? 't:'.$taskId.'|a:'.$action
                        : 'orphan:'.$action.'|'.hash('sha256', json_encode([
                            'run_id' => (int) $run->id,
                            'type' => $mapped['input_snapshot']['type'] ?? null,
                            'source' => $mapped['input_snapshot']['source_content'] ?? null,
                            'post_type' => $mapped['input_snapshot']['post_type'] ?? null,
                            'legacy_task_id' => $mapped['input_snapshot']['legacy_task_id'] ?? null,
                            'index' => (int) $index,
                        ], JSON_THROW_ON_ERROR));

                    if (isset($logical[$logicalKey])) {
                        $stats['duplicates_collapsed']++;
                        $existing = $logical[$logicalKey]['mapped'];
                        $logical[$logicalKey]['mapped'] = $this->preferMapped($existing, $mapped);
                        $logical[$logicalKey]['mapped']['input_snapshot']['duplicate_indexes'] = array_values(array_unique(array_merge(
                            (array) ($existing['input_snapshot']['duplicate_indexes'] ?? []),
                            [(int) $index, (int) $logical[$logicalKey]['index']],
                        )));

                        continue;
                    }

                    $logical[$logicalKey] = [
                        'mapped' => $mapped,
                        'index' => (int) $index,
                    ];
                }

                if ($dryRun) {
                    $stats['run_items_created'] += count($logical);

                    continue;
                }

                DB::connection('omi_seo_ai')->transaction(function () use (
                    $run,
                    $logical,
                    $idempotencyKeys,
                    $forceInconsistent,
                    &$stats,
                ): void {
                    foreach ($logical as $entry) {
                        $mapped = $entry['mapped'];
                        $taskId = $mapped['task_id'];
                        $action = (string) $mapped['action'];

                        $existing = null;
                        if ($taskId !== null && $taskId > 0) {
                            $existing = SeoProjectRunItem::query()
                                ->where('run_id', (int) $run->id)
                                ->where('task_id', $taskId)
                                ->where('action', $action)
                                ->lockForUpdate()
                                ->first();
                        }

                        $version = $idempotencyKeys->contentVersion([
                            'run_id' => (int) $run->id,
                            'task_id' => $taskId ?? 0,
                            'action' => $action,
                            'legacy_index' => (int) $entry['index'],
                            'source' => (string) ($mapped['input_snapshot']['source_content'] ?? ''),
                        ]);
                        $idempotencyKey = $idempotencyKeys->generate(
                            $taskId ?? ('run:'.(int) $run->id.':'.$entry['index']),
                            $action,
                            $version,
                        );

                        if ($existing instanceof SeoProjectRunItem) {
                            if (
                                (string) $existing->status === SeoProjectRunItemStatus::Success->value
                                && ! $forceInconsistent
                            ) {
                                continue;
                            }

                            if (
                                (string) $existing->status === SeoProjectRunItemStatus::Success->value
                                && $forceInconsistent
                            ) {
                                // Không overwrite success bằng legacy yếu hơn.
                                continue;
                            }

                            $existing->fill([
                                'article_id' => $mapped['article_id'] ?? $existing->article_id,
                                'status' => $mapped['status'],
                                'attempt' => max((int) $existing->attempt, (int) $mapped['attempt']),
                                'message' => $mapped['message'] ?? $existing->message,
                                'error_code' => $mapped['error_code'] ?? $existing->error_code,
                                'error_message' => $mapped['error_message'] ?? $existing->error_message,
                                'input_snapshot' => $mapped['input_snapshot'],
                                'output_snapshot' => $mapped['output_snapshot'],
                                'finished_at' => $mapped['finished_at'] ?? $existing->finished_at,
                                'idempotency_key' => $existing->idempotency_key ?: $idempotencyKey,
                            ]);
                            $existing->save();
                            $stats['run_items_updated']++;

                            continue;
                        }

                        SeoProjectRunItem::query()->create([
                            'run_id' => (int) $run->id,
                            'task_id' => $taskId,
                            'article_id' => $mapped['article_id'],
                            'action' => $action,
                            'status' => $mapped['status'],
                            'attempt' => max(1, (int) $mapped['attempt']),
                            'idempotency_key' => $idempotencyKey,
                            'message' => $mapped['message'],
                            'error_code' => $mapped['error_code'],
                            'error_message' => $mapped['error_message'],
                            'input_snapshot' => $mapped['input_snapshot'],
                            'output_snapshot' => $mapped['output_snapshot'],
                            'started_at' => null,
                            'finished_at' => $mapped['finished_at'],
                        ]);
                        $stats['run_items_created']++;
                    }
                });
            }
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(static fn (int $v, string $k): array => [$k, (string) $v])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function preferMapped(array $a, array $b): array
    {
        $rank = static function (array $row): int {
            return match ((string) ($row['status'] ?? '')) {
                'success' => 3,
                'failed' => 2,
                'manual' => 1,
                default => 0,
            };
        };

        return $rank($b) >= $rank($a) ? $b : $a;
    }
}
