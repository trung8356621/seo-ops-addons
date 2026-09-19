<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\ContentProjectTaskFailedDedupKey;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

final class NotificationSendHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        if (! Schema::hasTable('notifications')) {
            return AutomationActionResult::failure('NOTIFICATIONS_UNAVAILABLE', 'notifications table missing.');
        }

        $message = trim((string) ($input['message'] ?? $settings['message'] ?? ''));
        if ($message === '') {
            $message = sprintf(
                'Automation [%s] for event [%s]',
                $context->rule?->code,
                $context->businessEvent->event_name,
            );
        }

        $title = trim((string) ($input['title'] ?? $settings['title'] ?? 'Automation'));
        $userIds = $this->resolveUserIds($context, $input);
        if ($userIds === []) {
            return AutomationActionResult::failure('NO_RECIPIENT', 'No notification recipient resolved.');
        }

        if ($this->shouldUseOperationalDedup($context, $input, $settings)) {
            return $this->sendOperational($context, $input, $title, $message, $userIds);
        }

        return $this->sendRaw($title, $message, $userIds);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     */
    private function shouldUseOperationalDedup(
        AutomationActionContext $context,
        array $input,
        array $settings,
    ): bool {
        if (($input['operational_dedup'] ?? $settings['operational_dedup'] ?? false) === true) {
            return true;
        }

        return (string) $context->businessEvent->event_name
            === BusinessEventName::ContentProjectTaskFailed->value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $userIds
     */
    private function sendOperational(
        AutomationActionContext $context,
        array $input,
        string $title,
        string $message,
        array $userIds,
    ): AutomationActionResult {
        $payload = is_array($context->businessEvent->payload) ? $context->businessEvent->payload : [];
        foreach (['project_id', 'task_id', 'run_id', 'run_item_id', 'error_code'] as $key) {
            if (! array_key_exists($key, $payload) && array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $payload[$key] = $input[$key];
            }
        }
        if (! isset($payload['project_id']) && $context->projectId !== null) {
            $payload['project_id'] = (int) $context->projectId;
        }

        $dedupKey = trim((string) ($input['dedup_key'] ?? ''));
        if ($dedupKey === '') {
            $dedupKey = ContentProjectTaskFailedDedupKey::fromPayload($payload);
        }

        $users = [];
        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if ($user instanceof User) {
                $users[] = $user;
            }
        }
        if ($users === []) {
            return AutomationActionResult::failure('NO_RECIPIENT', 'Recipients not found.');
        }

        $ops = app(OperationalNotificationService::class);
        if (! $ops->tableReady()) {
            return $this->sendRaw($title, $message, $userIds);
        }

        $result = $ops->notify(
            eventCode: OperationalNotificationEventCode::ContentProjectTaskFailed,
            severity: NotificationSeverity::Danger,
            recipients: $users,
            title: $title !== '' ? $title : 'Content project task failed',
            message: $message,
            context: [
                'project_id' => (int) ($payload['project_id'] ?? 0) ?: null,
                'task_id' => (int) ($payload['task_id'] ?? 0) ?: null,
                'run_id' => (int) ($payload['run_id'] ?? 0) ?: null,
                'run_item_id' => (int) ($payload['run_item_id'] ?? 0) ?: null,
                'error_code' => $payload['error_code'] ?? null,
            ],
            dedupKey: $dedupKey,
        );

        $created = (int) ($result['created'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        if ($created <= 0 && $updated <= 0) {
            return AutomationActionResult::failure('NO_RECIPIENT', 'Operational notification was not recorded.');
        }

        return AutomationActionResult::success(
            output: [
                'delivered' => true,
                'channel' => 'database',
                'recipient_count' => count($users),
                'created' => $created,
                'updated' => $updated,
                'dedup_key' => $dedupKey,
            ],
            message: $updated > 0 ? 'Notification incident updated.' : 'Notification delivered.',
        );
    }

    /**
     * @param  list<int>  $userIds
     */
    private function sendRaw(string $title, string $message, array $userIds): AutomationActionResult
    {
        $delivered = 0;
        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if (! $user instanceof User) {
                continue;
            }

            Notification::make()
                ->title($title)
                ->body($message)
                ->sendToDatabase($user);
            $delivered++;
        }

        if ($delivered <= 0) {
            return AutomationActionResult::failure('NO_RECIPIENT', 'Recipients not found.');
        }

        return AutomationActionResult::success(
            output: [
                'delivered' => true,
                'channel' => 'database',
                'recipient_count' => $delivered,
            ],
            message: 'Notification delivered.',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function resolveUserIds(AutomationActionContext $context, array $input): array
    {
        $ids = [];

        $explicit = (int) ($input['user_id'] ?? 0);
        if ($explicit > 0) {
            $ids[] = $explicit;
        }

        $actorId = (int) ($context->actorId ?? 0);
        if ($actorId > 0) {
            $ids[] = $actorId;
        }

        $projectId = (int) ($input['project_id'] ?? $context->projectId ?? 0);
        if ($projectId > 0) {
            $project = SeoProject::query()->find($projectId);
            if ($project instanceof SeoProject && (int) ($project->user_id ?? 0) > 0) {
                $ids[] = (int) $project->user_id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
