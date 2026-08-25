<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;

/**
 * Safety checks read-only — trả fail result hoặc null nếu pass.
 */
final class ContentProjectAgentPolicy
{
    public function __construct(
        private readonly ContentProjectCapabilityRegistry $registry,
        private readonly ContentProjectLifecycle $lifecycle,
    ) {}

    /**
     * @param  list<string>  $scopes
     */
    public function assertScopes(array $scopes, string $capability): ?AgentCapabilityResult
    {
        $required = $this->requiredScope($capability);
        if ($required === null) {
            return null;
        }

        if (in_array('content-project:admin', $scopes, true)) {
            return null;
        }

        if (! in_array($required, $scopes, true)) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::PERMISSION_DENIED,
                'Missing required scope: '.$required,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function assertSafeWrite(string $capability, array $input, int $siteId): ?AgentCapabilityResult
    {
        if (! $this->registry->isAgentWriteExposed($capability)) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::CAPABILITY_NOT_ALLOWED,
                'Capability is not exposed for agent write.',
            );
        }

        if ($this->registry->get($capability) === null) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::CAPABILITY_NOT_FOUND,
                'Capability not found.',
            );
        }

        if ($conflict = $this->assertNoRestoreGenerateConflict($input)) {
            return $conflict;
        }

        $projectRef = isset($input['project_ref']) ? (string) $input['project_ref'] : '';
        if ($projectRef === '') {
            return null;
        }

        try {
            $projectId = ContentProjectPublicRef::resolveProjectIdStrict($projectRef);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return null;
        }

        if ((int) ($project->site_id ?? 0) !== $siteId) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::CONTEXT_MISMATCH,
                'Project does not belong to site context.',
                data: [
                    'required' => ['project_ref'],
                    'mismatch' => 'site_ref',
                ],
            );
        }

        if ($capability === 'content_project.archive') {
            return $this->assertArchiveSafe($project);
        }

        if (in_array($capability, ['content_project.publish_now', 'content_project.retry_publish'], true)) {
            return $this->assertPublishSafe($project, $input, $capability);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function assertNoNumericIds(array $input): ?AgentCapabilityResult
    {
        foreach ($this->flattenScalars($input) as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '' && ctype_digit($trimmed)) {
                return AgentCapabilityResult::fail(
                    AgentErrorCodes::INVALID_INPUT,
                    'Numeric IDs are not allowed; use opaque refs.',
                );
            }
        }

        return null;
    }

    private function requiredScope(string $capability): ?string
    {
        if (str_starts_with($capability, 'content_project.get_')
            || str_starts_with($capability, 'content_project.list_')) {
            return 'content-project:read';
        }

        if (str_starts_with($capability, 'keyword_intelligence.get_')
            || str_starts_with($capability, 'keyword_intelligence.list_')) {
            return 'content-project:read';
        }

        if (str_starts_with($capability, 'serp_intelligence.get_')
            || str_starts_with($capability, 'serp_intelligence.list_')) {
            return 'content-project:read';
        }

        if (str_starts_with($capability, 'serp_intelligence.')) {
            return 'content-project:write';
        }

        if (str_starts_with($capability, 'gsc_intelligence.get_')
            || str_starts_with($capability, 'gsc_intelligence.list_')) {
            return 'content-project:read';
        }

        if (str_starts_with($capability, 'seo_audit.')) {
            return 'content-project:read';
        }

        if (str_starts_with($capability, 'domain.run_analysis')) {
            return 'content-project:write';
        }

        if (str_starts_with($capability, 'domain.')) {
            return 'content-project:read';
        }

        if (str_starts_with($capability, 'gsc_intelligence.')) {
            return 'content-project:write';
        }

        if (str_starts_with($capability, 'site.')) {
            return 'content-project:write';
        }

        if (str_starts_with($capability, 'keyword_intelligence.')) {
            return 'content-project:write';
        }

        return match ($capability) {
            'content_project.create', 'content_project.update', 'content_project.add_items',
            'content_project.fill_seo_audit_suggestions', 'content_project.generate_new_content_suggestions',
            'content_project.split_draft',
            'content_project.update_item', 'content_project.restore' => 'content-project:write',
            'content_project.planning_intelligence',
            'content_project.list_projects', 'content_project.get_project', 'content_project.list_items',
            'content_project.get_item', 'content_project.get_status', 'content_project.get_publishing_queue',
            'content_project.get_timeline', 'content_project.get_daily_report', 'content_project.get_site_health',
            'content_project.get_operation' => 'content-project:read',
            'content_project.generate', 'content_project.rerun', 'content_project.rerun_items' => 'content-project:generate',
            'content_project.start_review', 'content_project.approve' => 'content-project:review',
            'content_project.schedule', 'content_project.auto_schedule',
            'content_project.unschedule', 'content_project.move_schedule' => 'content-project:schedule',
            'content_project.publish_now', 'content_project.retry_publish',
            'content_project.skip_publish', 'content_project.cancel_publish' => 'content-project:publish',
            'content_project.archive' => 'content-project:archive',
            default => 'content-project:write',
        };
    }

    private function assertArchiveSafe(SeoProject $project): ?AgentCapabilityResult
    {
        $projectId = (int) $project->getKey();

        $activeWriting = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->active()
            ->where('status', SeoProjectTask::STATUS_WRITING)
            ->exists();

        if ($activeWriting) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::OPERATION_ALREADY_PROCESSING,
                'Cannot archive while AI generation is active.',
            );
        }

        $processingPublish = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->active()
            ->whereIn('publish_queue_status', [
                ContentProjectPublishQueueStatus::Processing->value,
                ContentProjectPublishQueueStatus::Retrying->value,
            ])
            ->exists();

        if ($processingPublish) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::OPERATION_ALREADY_PROCESSING,
                'Cannot archive while publishing is processing.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertPublishSafe(SeoProject $project, array $input, string $capability): ?AgentCapabilityResult
    {
        $itemRefs = $input['item_refs'] ?? [];
        if (! is_array($itemRefs)) {
            $itemRefs = [];
        }

        if ($itemRefs === []) {
            return null;
        }

        foreach ($itemRefs as $ref) {
            try {
                $itemId = ContentProjectPublicRef::resolveItemIdStrict((string) $ref);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $task = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->where('id', $itemId)
                ->first();

            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            $phase = $this->lifecycle->resolvePhase($task);

            if ($capability === 'content_project.publish_now'
                && ! in_array($phase, [
                    ContentProjectLifecyclePhase::Approved,
                    ContentProjectLifecyclePhase::WaitingPublish,
                    ContentProjectLifecyclePhase::Failed,
                ], true)) {
                return AgentCapabilityResult::fail(
                    AgentErrorCodes::APPROVAL_REVIEW_REQUIRED,
                    'Items must be approved before publish.',
                );
            }

            if ($capability === 'content_project.retry_publish') {
                $queueStatus = (string) ($task->publish_queue_status ?? 'none');
                if ($queueStatus === ContentProjectPublishQueueStatus::Published->value
                    || $phase === ContentProjectLifecyclePhase::Published) {
                    return AgentCapabilityResult::fail(
                        AgentErrorCodes::LIFECYCLE_INVALID_TRANSITION,
                        'Cannot retry publish for already published item.',
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertNoRestoreGenerateConflict(array $input): ?AgentCapabilityResult
    {
        $hasRestore = ($input['restore'] ?? false) === true
            || ($input['action'] ?? '') === 'restore';
        $hasGenerate = ($input['generate'] ?? false) === true
            || ($input['action'] ?? '') === 'generate'
            || (isset($input['mode']) && ($input['action'] ?? '') === 'generate');

        if ($hasRestore && $hasGenerate) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::CONFLICTING_ACTIONS,
                'Restore and generate cannot be combined in the same input.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<scalar>
     */
    private function flattenScalars(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (in_array($key, ['project_ref', 'item_ref', 'item_refs'], true)) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $out[] = $item;
                    }
                } else {
                    $out[] = $value;
                }

                continue;
            }

            if (is_scalar($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
