<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

use Omnichannel\Addons\WordPress\Models\WordPressSideEffectAttempt;
use Illuminate\Support\Str;

final class WordPressSideEffectLedger
{
    /**
     * @return int Attempt id (0 if table missing)
     */
    public function begin(
        string $operation,
        WordPressExecutionContext $context,
    ): int {
        try {
            $row = WordPressSideEffectAttempt::query()->create([
                'operation' => $operation,
                'origin' => $context->origin(),
                'correlation_id' => $context->correlationId(),
                'automation_execution_id' => $context instanceof AutomationWordPressContext
                    ? $context->automationExecutionId
                    : null,
                'automation_node_execution_id' => $context instanceof AutomationWordPressContext
                    ? $context->automationNodeExecutionId
                    : null,
                'user_id' => $context instanceof ManualWordPressContext ? $context->userId : null,
                'article_id' => $context->articleId(),
                'site_id' => $context->siteId(),
                'idempotency_key' => $context instanceof AutomationWordPressContext
                    ? $context->idempotencyKey
                    : ($context instanceof ManualWordPressContext ? $context->requestId : null),
                'status' => 'allowed',
                'created_at' => now(),
            ]);

            return (int) $row->id;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function complete(int $attemptId, ?int $remotePostId = null): void
    {
        if ($attemptId <= 0) {
            return;
        }

        try {
            WordPressSideEffectAttempt::query()->whereKey($attemptId)->update([
                'status' => 'completed',
                'remote_post_id' => $remotePostId,
                'completed_at' => now(),
            ]);
        } catch (\Throwable) {
            // ignore ledger failures
        }
    }

    public function fail(int $attemptId, string $reason): void
    {
        if ($attemptId <= 0) {
            return;
        }

        try {
            WordPressSideEffectAttempt::query()->whereKey($attemptId)->update([
                'status' => 'failed',
                'blocked_reason' => mb_substr($reason, 0, 500),
                'completed_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    public function recordBlocked(
        string $operation,
        ?WordPressExecutionContext $context,
        string $reason,
        ?string $correlationId,
    ): void {
        try {
            WordPressSideEffectAttempt::query()->create([
                'operation' => $operation,
                'origin' => $context?->origin() ?? 'missing',
                'correlation_id' => $correlationId ?? (string) Str::uuid(),
                'automation_execution_id' => $context instanceof AutomationWordPressContext
                    ? $context->automationExecutionId
                    : null,
                'automation_node_execution_id' => $context instanceof AutomationWordPressContext
                    ? $context->automationNodeExecutionId
                    : null,
                'user_id' => $context instanceof ManualWordPressContext ? $context->userId : null,
                'article_id' => $context?->articleId(),
                'site_id' => $context?->siteId(),
                'idempotency_key' => $context instanceof AutomationWordPressContext
                    ? $context->idempotencyKey
                    : null,
                'status' => 'blocked',
                'blocked_reason' => mb_substr($reason, 0, 500),
                'created_at' => now(),
                'completed_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }
}
