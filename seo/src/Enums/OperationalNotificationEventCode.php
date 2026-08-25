<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

/**
 * Canonical operational notification event codes.
 * Publishing codes reserved for Publishing Queue auto-retry patch reuse.
 */
enum OperationalNotificationEventCode: string
{
    // Publishing (shared contract — do not rename)
    case PublishingStuck = 'publishing.stuck';
    case PublishingRetryStarted = 'publishing.retry_started';
    case PublishingRetrySucceeded = 'publishing.retry_succeeded';
    case PublishingRetryExhausted = 'publishing.retry_exhausted';
    case PublishingReconciled = 'publishing.reconciled';
    case PublishingDeliveryWorkerStalled = 'publishing.delivery_worker_stalled';

    // Prompt
    case PromptContractInvalid = 'prompt.contract_invalid';

    // Generation
    case GenerationBatchPartialFailed = 'generation.batch_partial_failed';
    case GenerationBatchFailed = 'generation.batch_failed';
    case GenerationStuck = 'generation.stuck';
    case GenerationRecovered = 'generation.recovered';
    case GenerationRetryExhausted = 'generation.retry_exhausted';

    // Runner health
    case RunnerUnhealthy = 'runner.unhealthy';
    case RunnerRecovered = 'runner.recovered';

    // AI runtime health
    case AiModelDegraded = 'ai.model_degraded';
    case AiModelUnavailable = 'ai.model_unavailable';
    case AiConnectionLocked = 'ai.connection_locked';
    case AiConnectionBudgetLimited = 'ai.connection_budget_limited';
    case AiHealthRecovered = 'ai.health_recovered';

    // WordPress
    case WordpressConnectionFailed = 'wordpress.connection_failed';
    case WordpressConnectionRecovered = 'wordpress.connection_recovered';
    case WordpressCapabilityMissing = 'wordpress.capability_missing';
    case WordpressCallbackRejected = 'wordpress.callback_rejected';

    // Review
    case ReviewItemsAssigned = 'review.items_assigned';

    // Site Sync
    case SiteSyncPartialFailed = 'site_sync.partial_failed';
    case SiteSyncStuck = 'site_sync.stuck';
    case SiteSyncFailed = 'site_sync.failed';
    case SiteSyncRecovered = 'site_sync.recovered';

    // Article Index Health
    case ArticleIndexDropped = 'article.index_dropped';

    public function module(): string
    {
        return explode('.', $this->value, 2)[0] ?? 'system';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }
}
