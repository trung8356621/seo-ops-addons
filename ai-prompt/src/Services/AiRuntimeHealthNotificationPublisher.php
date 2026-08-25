<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\AiRuntimeHealthState;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiRuntimeHealthStatus;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use App\Models\ApiConnection;

final class AiRuntimeHealthNotificationPublisher
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
        private readonly OperationalNotificationRecipientResolver $recipients,
        private readonly OperationalNotificationDeepLinks $links,
    ) {}

    public function onHealthTransition(
        int $userId,
        AiRuntimeHealthState $row,
        AiRuntimeHealthStatus $previous,
        AiRuntimeHealthStatus $current,
        AiFailureDecision $decision,
        ?RoutedAiCandidate $candidate = null,
    ): void {
        if ($userId <= 0) {
            return;
        }

        if ($row->subject_type === AiRuntimeHealthState::SUBJECT_CONNECTION) {
            $this->publishConnectionTransition($userId, $row, $current, $decision);

            return;
        }

        if ($row->subject_type === AiRuntimeHealthState::SUBJECT_MODEL) {
            $this->publishModelTransition($userId, $row, $current, $decision, $candidate);
        }
    }

    public function onHealthRecovered(
        int $userId,
        AiRuntimeHealthState $row,
        AiRuntimeHealthStatus $previous,
        AiRuntimeHealthStatus $current,
        ?RoutedAiCandidate $candidate = null,
    ): void {
        unset($candidate);
        if ($userId <= 0 || $current !== AiRuntimeHealthStatus::Healthy) {
            return;
        }

        if (! in_array($previous, [
            AiRuntimeHealthStatus::Degraded,
            AiRuntimeHealthStatus::Unavailable,
            AiRuntimeHealthStatus::BudgetLimited,
            AiRuntimeHealthStatus::ConnectionLocked,
        ], true)) {
            return;
        }

        $subjectKey = $row->subject_type.':'.$row->subject_id;
        $dedup = sprintf('ai-health-recovered:%d:%s', $userId, $subjectKey);

        $this->notifications->resolve(
            dedupKey: $dedup,
            recoveryTitle: 'AI health recovered',
            recoveryMessage: 'Runtime health returned to healthy.',
            recoveryEventCode: OperationalNotificationEventCode::AiHealthRecovered,
            recoveryRecipients: $this->recipients->forRunnerHealth($userId),
            emitRecovery: true,
            recoveryContext: [
                'user_id' => $userId,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
            ],
            recoveryActionUrl: $this->links->aiCenterHealth(),
        );
    }

    private function publishConnectionTransition(
        int $userId,
        AiRuntimeHealthState $row,
        AiRuntimeHealthStatus $current,
        AiFailureDecision $decision,
    ): void {
        $connection = ApiConnection::query()->find($row->subject_id);
        $name = $connection !== null ? (string) $connection->name : 'Connection #'.$row->subject_id;
        $dedup = sprintf('ai-connection-health:%d:%d:%s', $userId, (int) $row->subject_id, $current->value);

        if ($current === AiRuntimeHealthStatus::ConnectionLocked) {
            $this->notifications->notify(
                eventCode: OperationalNotificationEventCode::AiConnectionLocked,
                severity: NotificationSeverity::Warning,
                recipients: $this->recipients->forRunnerHealth($userId),
                title: $name.' connection disabled by runtime health',
                message: 'Invalid API credentials detected. Update the API key, then manually enable the connection.',
                context: [
                    'user_id' => $userId,
                    'connection_id' => (int) $row->subject_id,
                    'failure_class' => $decision->category->value,
                ],
                actionUrl: $this->links->aiConnectionEdit((int) $row->subject_id),
                actions: [
                    ['label' => 'Manage API connection', 'url' => $this->links->aiConnectionEdit((int) $row->subject_id), 'name' => 'manage_connection'],
                    ['label' => 'Open AI Health', 'url' => $this->links->aiCenterHealth(), 'name' => 'open_health'],
                ],
                dedupKey: $dedup,
                groupKey: $dedup,
                resolvable: true,
            );

            return;
        }

        if ($current === AiRuntimeHealthStatus::BudgetLimited) {
            $this->notifications->notify(
                eventCode: OperationalNotificationEventCode::AiConnectionBudgetLimited,
                severity: NotificationSeverity::Warning,
                recipients: $this->recipients->forRunnerHealth($userId),
                title: $name.' paid routes paused',
                message: 'Current balance cannot satisfy paid AI requests. Free routes remain available.',
                context: [
                    'user_id' => $userId,
                    'connection_id' => (int) $row->subject_id,
                    'failure_class' => $decision->category->value,
                ],
                actionUrl: $this->links->aiCenterHealth(),
                actions: [
                    ['label' => 'Open AI Health', 'url' => $this->links->aiCenterHealth(), 'name' => 'open_health'],
                ],
                dedupKey: $dedup,
                groupKey: $dedup,
                resolvable: true,
            );
        }
    }

    private function publishModelTransition(
        int $userId,
        AiRuntimeHealthState $row,
        AiRuntimeHealthStatus $current,
        AiFailureDecision $decision,
        ?RoutedAiCandidate $candidate,
    ): void {
        $model = SeoAiModel::query()->find($row->subject_id);
        $label = $model !== null ? (string) ($model->display_name ?: $model->raw_model_name) : 'Model #'.$row->subject_id;
        $dedup = sprintf('ai-model-health:%d:%d:%s', $userId, (int) $row->subject_id, $current->value);

        if ($current === AiRuntimeHealthStatus::Degraded) {
            $this->notifications->notify(
                eventCode: OperationalNotificationEventCode::AiModelDegraded,
                severity: NotificationSeverity::Warning,
                recipients: $this->recipients->forRunnerHealth($userId),
                title: 'AI model is repeatedly failing',
                message: sprintf(
                    '%s has failed %d consecutive attempts. Last issue: %s.',
                    $label,
                    (int) $row->consecutive_failures,
                    $decision->category->value,
                ),
                context: [
                    'user_id' => $userId,
                    'model_id' => (int) $row->subject_id,
                    'connection_id' => $candidate?->connection->id,
                ],
                actionUrl: $this->links->aiCenterHealth(),
                actions: [
                    ['label' => 'Review model routing', 'url' => $this->links->aiCenterHealth(), 'name' => 'review_routing'],
                ],
                dedupKey: $dedup,
                groupKey: $dedup,
                resolvable: true,
            );

            return;
        }

        if ($current === AiRuntimeHealthStatus::Unavailable) {
            $this->notifications->notify(
                eventCode: OperationalNotificationEventCode::AiModelUnavailable,
                severity: NotificationSeverity::Warning,
                recipients: $this->recipients->forRunnerHealth($userId),
                title: 'AI model unavailable',
                message: sprintf('%s is unavailable. Last issue: %s.', $label, $decision->category->value),
                context: [
                    'user_id' => $userId,
                    'model_id' => (int) $row->subject_id,
                ],
                actionUrl: $this->links->aiCenterHealth(),
                dedupKey: $dedup,
                groupKey: $dedup,
                resolvable: true,
            );
        }
    }
}
