<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Illuminate\Support\Facades\Log;

/**
 * Merge/relink run items when collapsing duplicate tasks (Phase 3C3).
 */
final class SeoProjectRunItemMergeService
{
    /**
     * Relink all run items from $fromTaskId to $toTaskId, merging conflicts on (run_id, task_id, action).
     *
     * @return array{relinked: int, merged: int, dropped: int}
     */
    public function relinkTask(int $fromTaskId, int $toTaskId): array
    {
        $stats = ['relinked' => 0, 'merged' => 0, 'dropped' => 0];

        if ($fromTaskId <= 0 || $toTaskId <= 0 || $fromTaskId === $toTaskId) {
            return $stats;
        }

        $items = SeoProjectRunItem::query()
            ->where('task_id', $fromTaskId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }

            $existing = SeoProjectRunItem::query()
                ->where('run_id', (int) $item->run_id)
                ->where('task_id', $toTaskId)
                ->where('action', (string) $item->action)
                ->lockForUpdate()
                ->first();

            if (! $existing instanceof SeoProjectRunItem) {
                $item->task_id = $toTaskId;
                $item->save();
                $stats['relinked']++;
                continue;
            }

            $keeper = $this->preferItem($existing, $item);
            $loser = $keeper->is($existing) ? $item : $existing;

            if (! $keeper->is($existing)) {
                // Swap identity: copy richer data onto existing unique row, delete incoming.
                $existing->fill([
                    'status' => $keeper->status,
                    'attempt' => max((int) $existing->attempt, (int) $keeper->attempt),
                    'message' => $keeper->message ?? $existing->message,
                    'error_code' => $keeper->error_code ?? $existing->error_code,
                    'error_message' => $keeper->error_message ?? $existing->error_message,
                    'input_snapshot' => $keeper->input_snapshot ?? $existing->input_snapshot,
                    'output_snapshot' => $keeper->output_snapshot ?? $existing->output_snapshot,
                    'article_id' => $keeper->article_id ?? $existing->article_id,
                    'started_at' => $keeper->started_at ?? $existing->started_at,
                    'finished_at' => $keeper->finished_at ?? $existing->finished_at,
                    'idempotency_key' => $existing->idempotency_key ?: $keeper->idempotency_key,
                ]);
                $existing->save();
            } else {
                $existing->attempt = max((int) $existing->attempt, (int) $item->attempt);
                if ((int) ($existing->article_id ?? 0) <= 0 && (int) ($item->article_id ?? 0) > 0) {
                    $existing->article_id = $item->article_id;
                }
                $existing->save();
            }

            Log::info('seo.project_run_item.merge', [
                'kept_id' => (int) $existing->id,
                'dropped_id' => (int) $loser->id,
                'from_task_id' => $fromTaskId,
                'to_task_id' => $toTaskId,
            ]);

            if (! $loser->is($existing)) {
                $loser->delete();
            }
            $stats['merged']++;
            $stats['dropped']++;
        }

        return $stats;
    }

    /**
     * Relink all run items from $fromRunId onto $toRunId (consolidation).
     *
     * @return array{relinked: int, merged: int, dropped: int}
     */
    public function relinkRun(int $fromRunId, int $toRunId): array
    {
        $stats = ['relinked' => 0, 'merged' => 0, 'dropped' => 0];

        if ($fromRunId <= 0 || $toRunId <= 0 || $fromRunId === $toRunId) {
            return $stats;
        }

        $items = SeoProjectRunItem::query()
            ->where('run_id', $fromRunId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if (! $item instanceof SeoProjectRunItem) {
                continue;
            }

            $taskId = (int) ($item->task_id ?? 0);
            $action = (string) $item->action;

            $existing = null;
            if ($taskId > 0 && $action !== '') {
                $existing = SeoProjectRunItem::query()
                    ->where('run_id', $toRunId)
                    ->where('task_id', $taskId)
                    ->where('action', $action)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $existing instanceof SeoProjectRunItem) {
                $item->run_id = $toRunId;
                $item->save();
                $stats['relinked']++;
                continue;
            }

            $keeper = $this->preferItem($existing, $item);
            $loser = $keeper->is($existing) ? $item : $existing;

            if (! $keeper->is($existing)) {
                $existing->fill([
                    'status' => $keeper->status,
                    'attempt' => max((int) $existing->attempt, (int) $keeper->attempt),
                    'message' => $keeper->message ?? $existing->message,
                    'error_code' => $keeper->error_code ?? $existing->error_code,
                    'error_message' => $keeper->error_message ?? $existing->error_message,
                    'input_snapshot' => $keeper->input_snapshot ?? $existing->input_snapshot,
                    'output_snapshot' => $keeper->output_snapshot ?? $existing->output_snapshot,
                    'article_id' => $keeper->article_id ?? $existing->article_id,
                    'started_at' => $keeper->started_at ?? $existing->started_at,
                    'finished_at' => $keeper->finished_at ?? $existing->finished_at,
                    'idempotency_key' => $existing->idempotency_key ?: $keeper->idempotency_key,
                ]);
                $existing->save();
            } else {
                $existing->attempt = max((int) $existing->attempt, (int) $item->attempt);
                if ((int) ($existing->article_id ?? 0) <= 0 && (int) ($item->article_id ?? 0) > 0) {
                    $existing->article_id = $item->article_id;
                }
                $existing->save();
            }

            Log::info('seo.project_run_item.merge_run', [
                'kept_id' => (int) $existing->id,
                'dropped_id' => (int) $loser->id,
                'from_run_id' => $fromRunId,
                'to_run_id' => $toRunId,
            ]);

            if (! $loser->is($existing)) {
                $loser->delete();
            }
            $stats['merged']++;
            $stats['dropped']++;
        }

        return $stats;
    }

    private function preferItem(SeoProjectRunItem $a, SeoProjectRunItem $b): SeoProjectRunItem
    {
        $score = static function (SeoProjectRunItem $item): int {
            $status = (string) $item->status;
            $s = match ($status) {
                SeoProjectRunItemStatus::Success->value => 100,
                SeoProjectRunItemStatus::Failed->value => 50,
                SeoProjectRunItemStatus::Processing->value => 40,
                SeoProjectRunItemStatus::Pending->value => 20,
                SeoProjectRunItemStatus::Skipped->value => 30,
                default => 10,
            };
            $s += (int) $item->attempt;
            if ((int) ($item->article_id ?? 0) > 0) {
                $s += 15;
            }
            if (is_array($item->output_snapshot) && $item->output_snapshot !== []) {
                $s += 10;
            }

            return $s;
        };

        return $score($b) > $score($a) ? $b : $a;
    }
}
