<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
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
