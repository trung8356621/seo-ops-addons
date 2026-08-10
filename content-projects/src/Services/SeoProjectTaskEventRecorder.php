<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;

/**
 * Skeleton recorder — Phase 2 không gọi từ write path / observer.
 */
final class SeoProjectTaskEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        ?SeoProjectTask $task,
        string|SeoProjectTaskEventType $event,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $payload = [],
        ?int $runId = null,
        ?int $createdBy = null,
    ): SeoProjectTaskEvent {
        $eventValue = $event instanceof SeoProjectTaskEventType
            ? $event->value
            : trim($event);

        return SeoProjectTaskEvent::query()->create([
            'task_id' => $task?->id,
            'run_id' => $runId !== null && $runId > 0 ? $runId : null,
            'event' => $eventValue,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'payload' => $payload === [] ? null : $payload,
            'created_by' => $createdBy !== null && $createdBy > 0 ? $createdBy : null,
            'created_at' => now(),
        ]);
    }
}
