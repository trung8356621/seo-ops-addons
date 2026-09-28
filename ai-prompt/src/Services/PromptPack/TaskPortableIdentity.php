<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptPack;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

final class TaskPortableIdentity
{
    public function ensure(SeoTask $task): string
    {
        $flowData = is_array($task->flow_data) ? $task->flow_data : [];
        $fromFlow = trim((string) ($flowData['portable_uuid'] ?? ''));
        if ($this->isUuid($fromFlow)) {
            $this->persistColumn($task, $fromFlow);

            return $fromFlow;
        }

        $fromColumn = trim((string) ($task->getAttribute('portable_uuid') ?? ''));
        if ($this->isUuid($fromColumn)) {
            $flowData['portable_uuid'] = $fromColumn;
            $task->flow_data = $flowData;
            $task->save();

            return $fromColumn;
        }

        $uuid = (string) Str::uuid();
        $flowData['portable_uuid'] = $uuid;
        $task->flow_data = $flowData;
        $this->persistColumn($task, $uuid);
        $task->save();

        return $uuid;
    }

    public function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    public function findByUuid(int $userId, string $uuid): ?SeoTask
    {
        $query = SeoTask::query()->where('user_id', $userId);
        try {
            $connection = (new SeoTask())->getConnectionName();
            if (Schema::connection($connection)->hasColumn('seo_tasks', 'portable_uuid')) {
                $byCol = (clone $query)->where('portable_uuid', $uuid)->first();
                if ($byCol instanceof SeoTask) {
                    return $byCol;
                }
            }
        } catch (\Throwable) {
        }

        foreach ($query->get() as $task) {
            $flow = is_array($task->flow_data) ? $task->flow_data : [];
            if (trim((string) ($flow['portable_uuid'] ?? '')) === $uuid) {
                return $task;
            }
        }

        return null;
    }

    private function persistColumn(SeoTask $task, string $uuid): void
    {
        try {
            $connection = $task->getConnectionName();
            if (Schema::connection($connection)->hasColumn($task->getTable(), 'portable_uuid')) {
                $task->setAttribute('portable_uuid', $uuid);
            }
        } catch (\Throwable) {
        }
    }
}
