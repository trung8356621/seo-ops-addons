<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications\Publishers;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class PromptContractNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    /**
     * @param  array<string, mixed>  $failedStep
     */
    public function notifyFromFailedStep(
        SeoProject $project,
        array $failedStep,
        ?int $runId = null,
        ?int $taskId = null,
    ): void {
        if (! $this->isContractFailure($failedStep)) {
            return;
        }

        $promptId = (int) ($failedStep['prompt_id'] ?? $failedStep['promptId'] ?? 0);
        $hook = (string) ($failedStep['hook'] ?? $failedStep['hook_key'] ?? $failedStep['title'] ?? 'unknown');
        $errorCode = (string) ($failedStep['failure_category'] ?? $failedStep['error_code'] ?? PromptHookFailureCode::InvalidInput->value);
        $promptName = trim((string) ($failedStep['prompt_name'] ?? $failedStep['title'] ?? 'Prompt'));
        $detail = trim((string) ($failedStep['message'] ?? $failedStep['error_message'] ?? ''));

        $tenantId = $this->recipients->tenantOwnerIdForProject($project);
        $dedup = sprintf(
            'prompt-contract:%d:%d:%s:%s',
            $tenantId,
            max(0, $promptId),
            $this->slug($hook),
            $this->slug($errorCode),
        );

        $inputKey = $this->extractUnknownInputKey($detail);
        $body = $inputKey !== null
            ? sprintf(
                'Prompt “%s” không tương thích với hook. Item thất bại vì input [%s] không được hỗ trợ.',
                $promptName !== '' ? $promptName : 'Prompt',
                $inputKey,
            )
            : sprintf(
                'Prompt “%s” không tương thích với hook (%s). %s',
                $promptName !== '' ? $promptName : 'Prompt',
                $errorCode,
                $detail !== '' ? $detail : 'Kiểm tra contract/binding.',
            );

        $actions = [];
        $primary = null;
        if ($promptId > 0) {
            $primary = $this->links->promptEdit($promptId);
            $actions[] = ['label' => 'Mở Prompt', 'url' => $primary, 'name' => 'open_prompt'];
        }
        $failedUrl = $this->links->contentProjectFailed((int) $project->getKey());
        $actions[] = ['label' => 'Xem item bị ảnh hưởng', 'url' => $failedUrl, 'name' => 'open_failed'];
        if ($runId !== null && $runId > 0) {
            $actions[] = [
                'label' => 'Mở Operation',
                'url' => $this->links->operationsCenter($runId),
                'name' => 'open_operation',
            ];
        }

        $severity = NotificationSeverity::Danger;
        $affectedProjects = [(int) $project->getKey()];

        $this->notifications->notify(
            eventCode: OperationalNotificationEventCode::PromptContractInvalid,
            severity: $severity,
            recipients: $this->recipients->forPromptOrSystemError($tenantId),
            title: 'Lỗi contract Prompt',
            message: $body,
            context: [
                'tenant_id' => $tenantId,
                'project_id' => (int) $project->getKey(),
                'prompt_id' => $promptId > 0 ? $promptId : null,
                'hook' => $hook,
                'error_code' => $errorCode,
                'affected_item_count' => 1,
                'affected_project_ids' => $affectedProjects,
                'latest_operation_id' => $runId,
                'project_item_id' => $taskId,
                'source' => 'generation_failed_step',
            ],
            actionUrl: $primary ?? $failedUrl,
            actions: $actions,
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function resolveAfterSuccessfulPrompt(int $tenantId, int $promptId, string $hook, string $errorCode = 'INVALID_INPUT'): void
    {
        $dedup = sprintf(
            'prompt-contract:%d:%d:%s:%s',
            $tenantId,
            max(0, $promptId),
            $this->slug($hook),
            $this->slug($errorCode),
        );

        $this->notifications->resolve($dedup);
    }

    /**
     * @param  array<string, mixed>  $failedStep
     */
    public function isContractFailure(array $failedStep): bool
    {
        $category = strtoupper((string) ($failedStep['failure_category'] ?? $failedStep['error_code'] ?? ''));
        if (in_array($category, [
            PromptHookFailureCode::InvalidInput->value,
            'DEFINITION_INVALID',
            'DEFINITION_NOT_FOUND',
            'UNKNOWN_HOOK',
            'BINDING_INVALID',
        ], true)) {
            return true;
        }

        $message = (string) ($failedStep['message'] ?? $failedStep['error_message'] ?? '');

        return str_contains($message, 'Unknown input key')
            || str_contains($message, 'INVALID_INPUT')
            || str_contains($message, 'does not accept input');
    }

    private function extractUnknownInputKey(string $detail): ?string
    {
        if (preg_match('/Unknown input key \[([^\]]+)\]/', $detail, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? 'unknown');

        return $slug !== '' ? $slug : 'unknown';
    }
}
