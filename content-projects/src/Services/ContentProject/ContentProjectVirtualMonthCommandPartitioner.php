<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use ReflectionClass;

/**
 * Splits virtual-month bulk commands by real project_id (task.project_id SSOT).
 */
final class ContentProjectVirtualMonthCommandPartitioner
{
    /**
     * @param  list<int>  $taskIds
     * @return array<int, list<int>>
     */
    public function partitionTaskIdsByProject(array $taskIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn (int $id): bool => $id > 0)));
        if ($normalized === []) {
            return [];
        }

        $groups = [];
        foreach (SeoProjectTask::query()->whereIn('id', $normalized)->get(['id', 'project_id']) as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $pid = (int) ($task->project_id ?? 0);
            $tid = (int) $task->id;
            if ($pid <= 0 || $tid <= 0) {
                continue;
            }
            $groups[$pid] ??= [];
            $groups[$pid][] = $tid;
        }

        foreach ($groups as $pid => $ids) {
            sort($groups[$pid]);
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function rescopeCommand(object $command, int|string $projectRef, array $taskIds): object
    {
        $ref = new ReflectionClass($command);
        if (! $ref->hasMethod('__construct')) {
            return $command;
        }

        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $command;
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if ($name === 'projectRef' || $name === 'projectId') {
                $args[] = $projectRef;

                continue;
            }
            if ($name === 'itemRefs' || $name === 'taskIds') {
                $args[] = array_values($taskIds);

                continue;
            }

            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $args[] = $prop->getValue($command);

                continue;
            }

            $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        }

        return $ref->newInstance(...$args);
    }
}