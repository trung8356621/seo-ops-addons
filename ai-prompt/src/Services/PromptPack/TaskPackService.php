<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptPack;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

final class TaskPackService
{
    public const MAX_TASKS = 200;

    public const MAX_NAME = 255;

    public function __construct(
        private readonly TaskPortableIdentity $taskIdentity = new TaskPortableIdentity(),
        private readonly PromptPortableIdentity $promptIdentity = new PromptPortableIdentity(),
    ) {}

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function export(int $userId, array $ids = [], bool $includeInactive = true): array
    {
        $query = SeoTask::query()->where('user_id', $userId);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        $tasks = [];
        foreach ($query->orderBy('id')->get() as $task) {
            $tasks[] = $this->serialize($task, $userId);
        }

        return $tasks;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(SeoTask $task, int $userId): array
    {
        $flowData = is_array($task->flow_data) ? $task->flow_data : [];
        $portableUuid = $this->taskIdentity->ensure($task);

        $portableFlow = $this->portableizeFlowData($flowData, $userId);
        $portableFlow['portable_uuid'] = $portableUuid;

        return [
            'portable_uuid' => $portableUuid,
            'name' => (string) ($task->name ?? ''),
            'description' => $task->description !== null ? (string) $task->description : null,
            'is_active' => (bool) $task->is_active,
            'output_type' => in_array((string) $task->output_type, ['text', 'image', 'video'], true)
                ? (string) $task->output_type
                : 'text',
            'flow_data' => $portableFlow,
        ];
    }

    /**
     * @param  list<mixed>  $tasksRaw
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    public function plan(array $tasksRaw, int $userId, string $mode = 'update', array &$warnings = []): array
    {
        $planned = [];
        foreach ($tasksRaw as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $planned[] = $this->planOne($raw, $userId, $index, $mode, $warnings);
        }

        return $planned;
    }

    /**
     * @param  list<array<string, mixed>>  $tasksPlan
     * @param  array<int, string>  $overrides
     */
    public function apply(array $tasksPlan, int $userId, array $overrides = []): int
    {
        $applied = 0;
        foreach ($tasksPlan as $index => $row) {
            $action = $overrides[$index] ?? (string) ($row['action'] ?? 'skip');
            if ($action === 'skip' || ($row['blocked'] ?? false) === true) {
                continue;
            }

            $record = is_array($row['normalized'] ?? null) ? $row['normalized'] : [];
            $existingId = $row['existing_id'] ?? null;

            if ($action === 'update' && $existingId !== null) {
                $task = SeoTask::query()->where('user_id', $userId)->whereKey((int) $existingId)->first();
                if ($task instanceof SeoTask) {
                    $this->fill($task, $record, $userId);
                    $task->save();
                    $applied++;
                    continue;
                }
            }

            $task = new SeoTask();
            if ($action === 'copy') {
                $record['name'] = $this->uniqueCopyName($userId, (string) $record['name']);
            }
            $this->fill($task, $record, $userId);
            $task->save();
            $applied++;
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function planOne(array $raw, int $userId, int $index, string $mode, array &$warnings): array
    {
        $uuid = trim((string) ($raw['portable_uuid'] ?? ''));
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            $warnings[] = 'Workflow #'.($index + 1).' has an invalid name.';

            return ['index' => $index, 'action' => 'skip', 'blocked' => true, 'reason' => 'invalid_name'];
        }

        $flowData = is_array($raw['flow_data'] ?? null) ? $raw['flow_data'] : [];
        $normalized = [
            'portable_uuid' => $this->taskIdentity->isUuid($uuid) ? $uuid : '',
            'name' => $name,
            'description' => isset($raw['description']) ? (string) $raw['description'] : null,
            'is_active' => (bool) ($raw['is_active'] ?? true),
            'output_type' => in_array((string) ($raw['output_type'] ?? ''), ['text', 'image', 'video'], true)
                ? (string) $raw['output_type']
                : null,
            'flow_data' => $flowData,
        ];

        $existing = null;
        if ($normalized['portable_uuid'] !== '') {
            $existing = $this->taskIdentity->findByUuid($userId, $normalized['portable_uuid']);
        }

        $action = 'create';
        $conflict = null;
        if ($existing instanceof SeoTask) {
            $action = in_array($mode, ['update', 'copy', 'skip'], true) ? $mode : 'update';
            $conflict = 'portable_uuid';
        } else {
            $byName = SeoTask::query()->where('user_id', $userId)->where('name', $name)->first();
            if ($byName instanceof SeoTask) {
                $conflict = 'name';
                $action = $mode === 'update' ? 'update' : 'copy';
                $existing = $byName;
            }
        }

        return [
            'index' => $index,
            'name' => $name,
            'portable_uuid' => $normalized['portable_uuid'],
            'action' => $action,
            'conflict' => $conflict,
            'blocked' => false,
            'existing_id' => $existing instanceof SeoTask ? (int) $existing->id : null,
            'normalized' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function fill(SeoTask $task, array $record, int $userId): void
    {
        $task->user_id = $userId;
        $task->name = (string) $record['name'];
        $task->description = $record['description'];
        $task->is_active = (bool) $record['is_active'];

        $flow = is_array($record['flow_data'] ?? null) ? $record['flow_data'] : [];
        $uuid = trim((string) ($record['portable_uuid'] ?? ''));
        if ($uuid === '' || ! $this->taskIdentity->isUuid($uuid)) {
            $uuid = (string) \Illuminate\Support\Str::uuid();
        }
        $flow['portable_uuid'] = $uuid;

        // Resolve prompt references in flow nodes to local destination prompt IDs
        $flow = $this->resolveLocalFlowReferences($flow, $userId);
        $task->flow_data = $flow;
        $metadata = app(\Omnichannel\Addons\AiPrompt\Services\TaskFlowPromptMetadataService::class);
        $task->output_type = is_string($record['output_type'] ?? null)
            ? $record['output_type']
            : $metadata->outputType($flow);
        $metadata->markFlowPrompts($flow);

        try {
            $connection = $task->getConnectionName();
            if (\Illuminate\Support\Facades\Schema::connection($connection)->hasColumn('seo_tasks', 'portable_uuid')) {
                $task->setAttribute('portable_uuid', $uuid);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private function portableizeFlowData(array $flow, int $userId): array
    {
        $clean = $flow;
        unset($clean['task_test_results'], $clean['test_results'], $clean['runtime_state']);

        $nodes = is_array($clean['nodes'] ?? null) ? $clean['nodes'] : [];
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $promptId = $data['promptId'] ?? $data['prompt_id'] ?? null;
            if ($promptId !== null && (int) $promptId > 0) {
                $prompt = SeoPrompt::query()
                    ->where('user_id', $userId)
                    ->whereKey((int) $promptId)
                    ->first();
                if ($prompt instanceof SeoPrompt) {
                    $data['prompt_ref'] = [
                        'portable_uuid' => $this->promptIdentity->ensure($prompt),
                        'name' => (string) $prompt->name,
                    ];
                }
            }
            // Strip installation-local prompt IDs from export
            unset($data['promptId'], $data['prompt_id']);
            $nodes[$i]['data'] = $data;
        }

        $clean['nodes'] = $nodes;

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private function resolveLocalFlowReferences(array $flow, int $userId): array
    {
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $promptRef = is_array($data['prompt_ref'] ?? null) ? $data['prompt_ref'] : null;

            if ($promptRef !== null) {
                $uuid = trim((string) ($promptRef['portable_uuid'] ?? ''));
                $name = trim((string) ($promptRef['name'] ?? ''));
                $prompt = null;

                if ($uuid !== '') {
                    $prompt = $this->findPromptByUuid($userId, $uuid);
                }
                if ($prompt === null && $name !== '') {
                    $prompt = SeoPrompt::query()->where('user_id', $userId)->where('name', $name)->first();
                }

                if ($prompt instanceof SeoPrompt) {
                    $data['promptId'] = (int) $prompt->id;
                    $data['prompt_id'] = (int) $prompt->id;
                }
            }

            $nodes[$i]['data'] = $data;
        }

        $flow['nodes'] = $nodes;

        return $flow;
    }

    private function findPromptByUuid(int $userId, string $uuid): ?SeoPrompt
    {
        $query = SeoPrompt::query()->withTrashed()->where('user_id', $userId);
        try {
            $byCol = (clone $query)->where('portable_uuid', $uuid)->first();
            if ($byCol instanceof SeoPrompt) {
                return $byCol;
            }
        } catch (\Throwable) {
        }

        foreach ($query->get() as $p) {
            $settings = is_array($p->settings) ? $p->settings : [];
            if (trim((string) ($settings['portable_uuid'] ?? '')) === $uuid) {
                return $p;
            }
        }

        return null;
    }

    private function uniqueCopyName(int $userId, string $name): string
    {
        $base = mb_substr($name.' (copy)', 0, self::MAX_NAME);
        $candidate = $base;
        $i = 2;
        while (SeoTask::query()->where('user_id', $userId)->where('name', $candidate)->exists()) {
            $candidate = mb_substr($base.' '.$i, 0, self::MAX_NAME);
            $i++;
        }

        return $candidate;
    }
}
