<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

/**
 * Stable incident identity for content_project.task.failed notifications.
 * Must not use event_uuid. Same task + different run → distinct incident.
 */
final class ContentProjectTaskFailedDedupKey
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): string
    {
        $projectId = max(0, (int) ($payload['project_id'] ?? 0));
        $taskId = max(0, (int) ($payload['task_id'] ?? 0));
        $runId = max(0, (int) ($payload['run_item_id'] ?? $payload['run_id'] ?? 0));
        $error = strtolower(trim((string) ($payload['error_code'] ?? $payload['failure_code'] ?? '')));
        if ($error === '') {
            $error = 'unknown';
        }
        $error = preg_replace('/[^a-z0-9._-]+/', '-', $error) ?: 'unknown';

        return sprintf(
            'content_project.task.failed:project:%d:task:%d:run:%d:error:%s',
            $projectId,
            $taskId,
            $runId,
            $error,
        );
    }
}
