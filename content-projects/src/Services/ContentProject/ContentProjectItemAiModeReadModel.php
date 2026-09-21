<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;

/**
 * Latest article-content generation mode for Content Project item rows.
 *
 * One batched read of prompt_results + prompt_result_routing_attempts.
 * Does not query per row.
 *
 * Cost class comes from successful routing attempts (attempted + success).
 * Section calls that persisted no attempt rows fall back to that call's
 * own is_free / is_free_candidate — never to SPLIT/SINGLE shape.
 */
final class ContentProjectItemAiModeReadModel
{
    private const GENERATE_KEY = 'article.content.generate';

    private const SECTION_KEY = 'article.content.section.generate';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function apply(array $rows): array
    {
        $taskIds = [];
        foreach ($rows as $row) {
            $taskId = (int) ($row['task_id'] ?? 0);
            if ($taskId > 0) {
                $taskIds[] = $taskId;
            }
        }

        $modes = $this->forTaskIds($taskIds);
        foreach ($rows as $index => $row) {
            $taskId = (int) ($row['task_id'] ?? 0);
            $mode = $modes[$taskId] ?? ContentProjectItemAiModeClassifier::unknown();
            $rows[$index]['ai_mode'] = $mode['mode'];
            $rows[$index]['ai_mode_shape'] = $mode['shape'];
            $rows[$index]['ai_mode_label'] = $mode['label'];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array{mode: string|null, shape: string|null, label: string}>
     */
    public function forTaskIds(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($taskIds === []) {
            return [];
        }

        return $this->resolve($taskIds);
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array{mode: string|null, shape: string|null, label: string}>
     */
    private function resolve(array $taskIds): array
    {
        $connection = $this->connectionName();
        if (! Schema::connection($connection)->hasTable('prompt_results')
            || ! Schema::connection($connection)->hasTable('prompt_result_routing_attempts')
        ) {
            return [];
        }

        $db = DB::connection($connection);
        $latestIds = $db->table('prompt_results')
            ->selectRaw('MAX(id) as id')
            ->whereIn('project_item_id', $taskIds)
            ->where('canonical_prompt_key', self::GENERATE_KEY)
            ->groupBy('project_item_id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($latestIds === []) {
            return [];
        }

        $parents = $db->table('prompt_results')
            ->whereIn('id', $latestIds)
            ->get([
                'id',
                'project_item_id',
                DB::raw("json_extract(input_snapshot, '$.generation_shape') as generation_shape"),
                DB::raw("json_extract(input_snapshot, '$.variables.generation_shape') as variables_generation_shape"),
                DB::raw("json_extract(input_snapshot, '$.sectioned_free_orchestrator') as sectioned_orchestrator"),
                DB::raw("json_extract(input_snapshot, '$.child_prompt_result_ids') as child_prompt_result_ids"),
            ]);

        /** @var array<int, array{id: int, shape: ?string, child_ids: array<int, true>}> $parentByTask */
        $parentByTask = [];
        /** @var array<int, int> $taskByParentId */
        $taskByParentId = [];
        $listedChildIds = [];
        foreach ($parents as $parent) {
            $taskId = (int) ($parent->project_item_id ?? 0);
            $parentId = (int) ($parent->id ?? 0);
            if ($taskId <= 0 || $parentId <= 0) {
                continue;
            }
            $childIds = [];
            foreach ($this->jsonIdList($parent->child_prompt_result_ids ?? null) as $childId) {
                $childIds[$childId] = true;
                $listedChildIds[$childId] = true;
            }
            $parentByTask[$taskId] = [
                'id' => $parentId,
                'shape' => $this->parentShape($parent),
                'child_ids' => $childIds,
            ];
            $taskByParentId[$parentId] = $taskId;
        }

        if ($parentByTask === []) {
            return [];
        }

        $sectionQuery = $db->table('prompt_results')
            ->where('canonical_prompt_key', self::SECTION_KEY)
            ->where(function ($query) use ($taskIds, $listedChildIds): void {
                $query->whereIn('project_item_id', $taskIds);
                if ($listedChildIds !== []) {
                    $query->orWhereIn('id', array_keys($listedChildIds));
                }
            });

        $sections = $sectionQuery->get([
            'id',
            'project_item_id',
            'status',
            DB::raw("json_extract(input_snapshot, '$.parent_prompt_result_id') as parent_prompt_result_id"),
            DB::raw("json_extract(input_snapshot, '$.is_free') as is_free"),
            DB::raw("json_extract(input_snapshot, '$.is_free_candidate') as is_free_candidate"),
        ]);

        /** @var array<int, list<int>> $sectionIdsByTask */
        $sectionIdsByTask = [];
        /** @var array<int, object> $sectionsById */
        $sectionsById = [];
        foreach ($sections as $section) {
            $sectionId = (int) ($section->id ?? 0);
            if ($sectionId <= 0 || ! $this->isSuccessfulResultStatus($section->status ?? null)) {
                continue;
            }
            $taskId = $this->taskForSection($section, $parentByTask, $taskByParentId);
            if ($taskId === null) {
                continue;
            }
            $sectionIdsByTask[$taskId][] = $sectionId;
            $sectionsById[$sectionId] = $section;
        }

        $attemptResultIds = array_values(array_unique(array_merge(
            array_keys($taskByParentId),
            array_keys($sectionsById),
        )));
        $attempts = $attemptResultIds === []
            ? collect()
            : $db->table('prompt_result_routing_attempts')
                ->whereIn('prompt_result_id', $attemptResultIds)
                ->get(['prompt_result_id', 'cost_class', 'state', 'attempted']);

        /** @var array<int, list<string>> $successClassesByResult */
        $successClassesByResult = [];
        /** @var array<int, true> $resultsWithAttempts */
        $resultsWithAttempts = [];
        foreach ($attempts as $attempt) {
            $resultId = (int) ($attempt->prompt_result_id ?? 0);
            if ($resultId <= 0) {
                continue;
            }
            $resultsWithAttempts[$resultId] = true;
            if (! $this->isAttempted($attempt->attempted ?? null) || ! $this->isSuccessfulRouteState($attempt->state ?? null)) {
                continue;
            }
            $costClass = strtolower(trim((string) ($attempt->cost_class ?? '')));
            if (! in_array($costClass, ['free', 'paid'], true)) {
                continue;
            }
            $successClassesByResult[$resultId][] = $costClass;
        }

        $out = [];
        foreach ($parentByTask as $taskId => $parent) {
            $childClasses = [];
            $usedSection = false;
            foreach ($sectionIdsByTask[$taskId] ?? [] as $sectionId) {
                $usedSection = true;
                if (isset($successClassesByResult[$sectionId])) {
                    foreach ($successClassesByResult[$sectionId] as $costClass) {
                        $childClasses[] = $costClass;
                    }
                    continue;
                }
                if (isset($resultsWithAttempts[$sectionId])) {
                    continue;
                }
                $fallback = $this->snapshotCostClass($sectionsById[$sectionId] ?? null);
                if ($fallback !== null) {
                    $childClasses[] = $fallback;
                }
            }

            $classes = $childClasses !== []
                ? $childClasses
                : ($successClassesByResult[$parent['id']] ?? []);

            $shape = $parent['shape'];
            if ($usedSection && $childClasses !== []) {
                $shape = 'sectioned';
            }

            $out[$taskId] = ContentProjectItemAiModeClassifier::classify($classes, $shape);
        }

        return $out;
    }

    private function connectionName(): string
    {
        $name = (new PromptResult())->getConnectionName();

        return is_string($name) && $name !== '' ? $name : 'omi_seo_ai';
    }

    private function parentShape(object $parent): ?string
    {
        $shape = $this->jsonScalar($parent->generation_shape ?? null)
            ?? $this->jsonScalar($parent->variables_generation_shape ?? null);
        if ($shape !== null) {
            return $shape;
        }

        return $this->jsonBool($parent->sectioned_orchestrator ?? null) === true ? 'sectioned' : null;
    }

    /**
     * @param  array<int, array{id: int, shape: ?string, child_ids: array<int, true>}>  $parentByTask
     * @param  array<int, int>  $taskByParentId
     */
    private function taskForSection(object $section, array $parentByTask, array $taskByParentId): ?int
    {
        $sectionId = (int) ($section->id ?? 0);
        $parentId = (int) ($this->jsonScalar($section->parent_prompt_result_id ?? null) ?? 0);
        if ($parentId > 0 && isset($taskByParentId[$parentId])) {
            return $taskByParentId[$parentId];
        }

        $taskId = (int) ($section->project_item_id ?? 0);
        if ($taskId > 0 && isset($parentByTask[$taskId]['child_ids'][$sectionId])) {
            return $taskId;
        }

        foreach ($parentByTask as $candidateTaskId => $parent) {
            if (isset($parent['child_ids'][$sectionId])) {
                return $candidateTaskId;
            }
        }

        return null;
    }

    private function snapshotCostClass(?object $section): ?string
    {
        if ($section === null) {
            return null;
        }

        $isFree = $this->jsonBool($section->is_free ?? null);
        if ($isFree === null) {
            $isFree = $this->jsonBool($section->is_free_candidate ?? null);
        }
        if ($isFree === null) {
            return null;
        }

        return $isFree ? 'free' : 'paid';
    }

    private function isSuccessfulResultStatus(mixed $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['completed', 'success', 'succeeded'], true);
    }

    private function isSuccessfulRouteState(mixed $state): bool
    {
        return in_array(strtoupper(trim((string) $state)), ['SUCCESS', 'SUCCEEDED', 'COMPLETED'], true);
    }

    private function isAttempted(mixed $attempted): bool
    {
        if (is_bool($attempted)) {
            return $attempted;
        }

        $normalized = strtolower(trim((string) $attempted));

        return in_array($normalized, ['1', 'true', 'yes'], true);
    }

    /**
     * @return list<int>
     */
    private function jsonIdList(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $raw = $this->jsonScalar($value) ?? trim((string) $value);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return [];
            }
        }

        $ids = [];
        foreach ($decoded as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $ids[] = $int;
            }
        }

        return $ids;
    }

    private function jsonScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $encoded = json_encode($value);

            return is_string($encoded) ? $encoded : null;
        }

        $text = trim((string) $value);
        if ($text === '' || strtolower($text) === 'null') {
            return null;
        }
        if (str_starts_with($text, '"') && str_ends_with($text, '"')) {
            $decoded = json_decode($text, true);
            if (is_string($decoded) || is_int($decoded) || is_float($decoded)) {
                return trim((string) $decoded);
            }
        }

        return $text;
    }

    private function jsonBool(mixed $value): ?bool
    {
        $scalar = $this->jsonScalar($value);
        if ($scalar === null) {
            return null;
        }

        $normalized = strtolower($scalar);
        if (in_array($normalized, ['1', 'true'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false'], true)) {
            return false;
        }

        return null;
    }
}
