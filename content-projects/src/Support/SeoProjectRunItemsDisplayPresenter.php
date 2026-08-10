<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * View-layer helper — Phase 3B: không consolidate theo source text.
 * Chỉ gộp khi cùng run_item_id (hoặc cùng legacy id key).
 */
final class SeoProjectRunItemsDisplayPresenter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function consolidate(array $items): array
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->stableRowKey($item);
            if (! isset($byKey[$key])) {
                $byKey[$key] = $item;
                continue;
            }

            // Cùng run_item_id — giữ bản giàu hơn; khác task_id cùng key không xảy ra.
            $byKey[$key] = $this->preferRicher($byKey[$key], $item);
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function stableRowKey(array $item): string
    {
        $runItemId = (int) ($item['run_item_id'] ?? 0);
        if ($runItemId > 0) {
            return 'ri:'.$runItemId;
        }

        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            return 'id:'.$id;
        }

        $taskId = (int) ($item['task_id'] ?? 0);
        $action = trim((string) ($item['action'] ?? ''));

        return 't:'.$taskId.'|a:'.$action.'|s:'.mb_strtolower(trim((string) ($item['source_content'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function preferRicher(array $a, array $b): array
    {
        $score = static function (array $row): int {
            $s = 0;
            if ((int) ($row['article_id'] ?? 0) > 0) {
                $s += 4;
            }
            if ((string) ($row['status'] ?? '') === 'success') {
                $s += 3;
            }
            if ((string) ($row['status'] ?? '') === 'failed') {
                $s += 1;
            }
            $s += (int) ($row['attempt'] ?? 0);

            return $s;
        };

        return $score($b) >= $score($a) ? $b : $a;
    }
}
