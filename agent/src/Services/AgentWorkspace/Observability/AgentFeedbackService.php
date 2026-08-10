<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentFeedback;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Illuminate\Support\Str;

final class AgentFeedbackService
{
    public function __construct(
        private readonly ?AgentReviewService $reviews = null,
        private readonly ?AgentObservabilityEventBus $bus = null,
        private readonly AgentObservabilityRedactor $redactor = new AgentObservabilityRedactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function submit(
        AgentWorkspaceContext $context,
        int $messageId,
        int $conversationId,
        bool $useful,
        ?string $reason = null,
        ?string $comment = null,
        ?string $traceId = null,
    ): array {
        if ($reason !== null && ! in_array($reason, AgentReviewService::NEGATIVE_REASONS, true)) {
            return ['ok' => false, 'code' => 'invalid_reason'];
        }

        $safeComment = $comment !== null
            ? mb_substr((string) ($this->redactor->redact(['c' => $comment])['c'] ?? ''), 0, 500)
            : null;

        $row = SeoAgentFeedback::query()->create([
            'hash_id' => 'afb_'.Str::lower((string) Str::ulid()),
            'trace_id' => $traceId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'actor_user_id' => $context->actorUserId,
            'site_id' => $context->siteId,
            'useful' => $useful,
            'reason' => $useful ? null : $reason,
            'comment' => $safeComment,
        ]);

        $this->bus?->dispatch([
            'event_type' => 'feedback.recorded',
            'trace_id' => $traceId ?? 'none',
            'attributes' => ['useful' => $useful, 'reason' => $reason],
            'severity' => 'info',
        ]);

        if (! $useful) {
            $this->reviews?->enqueue(
                reason: 'user_negative_feedback',
                severity: $reason === 'unsafe' ? 'high' : 'warning',
                payload: [
                    'feedback_hash_id' => $row->hash_id,
                    'message_id' => $messageId,
                    'reason' => $reason,
                ],
                traceId: $traceId,
                siteId: $context->siteId,
                createdBy: $context->actorUserId,
            );
        }

        return [
            'ok' => true,
            'code' => 'feedback_recorded',
            'feedback_hash_id' => $row->hash_id,
            'auto_retry' => false,
            'auto_model_change' => false,
        ];
    }
}
