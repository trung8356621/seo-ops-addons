<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;

/**
 * Canonical multi-dimension item state (Batch D read model).
 */
final class ContentProjectItemState
{
    /**
     * @param  list<ContentProjectItemAction>  $availableActions
     */
    public function __construct(
        public readonly ContentProjectLifecyclePhase $lifecycleState,
        public readonly ContentProjectItemGenerationState $generationState,
        public readonly ContentProjectItemReviewState $reviewState,
        public readonly ContentProjectItemPublishState $publishState,
        public readonly ContentProjectItemExecutionState $executionState,
        public readonly ContentProjectItemArchiveState $archiveState,
        public readonly array $availableActions,
        public readonly ?string $blockingReason,
        public readonly ?string $currentError,
        public readonly ContentProjectItemErrorSource $currentErrorSource,
        public readonly bool $hasPublishedRevision,
        public readonly bool $latestPublishAttemptFailed = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lifecycle_state' => $this->lifecycleState->value,
            'generation_state' => $this->generationState->value,
            'review_state' => $this->reviewState->value,
            'publish_state' => $this->publishState->value,
            'execution_state' => $this->executionState->value,
            'archive_state' => $this->archiveState->value,
            'available_actions' => array_map(
                static fn (ContentProjectItemAction $a): string => $a->value,
                $this->availableActions,
            ),
            'blocking_reason' => $this->blockingReason,
            'current_error' => $this->currentError,
            'current_error_source' => $this->currentErrorSource->value,
            'has_published_revision' => $this->hasPublishedRevision,
            'latest_publish_attempt_failed' => $this->latestPublishAttemptFailed,
        ];
    }
}
