<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemDashboardBucket;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;

/**
 * Central classifier: item có output hợp lệ để audit nhanh.
 *
 * Fail-safe: ambiguous / incomplete → NOT success.
 */
final class ContentProjectAuditSuccessClassifier
{
    public function isAuditSuccess(ContentProjectItemState $state): bool
    {
        if ($state->archiveState !== ContentProjectItemArchiveState::None) {
            return false;
        }

        if ($state->lifecycleState === ContentProjectLifecyclePhase::Archived
            || $state->lifecycleState === ContentProjectLifecyclePhase::Failed
            || $state->lifecycleState === ContentProjectLifecyclePhase::Draft
            || $state->lifecycleState === ContentProjectLifecyclePhase::Generating
        ) {
            return false;
        }

        if ($state->generationState === ContentProjectItemGenerationState::Writing
            || $state->generationState === ContentProjectItemGenerationState::Processing
            || $state->generationState === ContentProjectItemGenerationState::Pending
            || $state->generationState === ContentProjectItemGenerationState::Failed
            || $state->generationState === ContentProjectItemGenerationState::Cancelled
        ) {
            // Published revision vẫn audit-ready dù đang rerun — lifecycle Published wins above.
            if (! $state->hasPublishedRevision
                && $state->publishState !== ContentProjectItemPublishState::Published
            ) {
                return false;
            }
        }

        return match ($state->lifecycleState) {
            ContentProjectLifecyclePhase::Review,
            ContentProjectLifecyclePhase::Approved,
            ContentProjectLifecyclePhase::WaitingPublish,
            ContentProjectLifecyclePhase::Published => true,
            default => false,
        };
    }

    public function isAuditSuccessFromBucket(ContentProjectItemDashboardBucket $bucket): bool
    {
        return match ($bucket) {
            ContentProjectItemDashboardBucket::WaitingReview,
            ContentProjectItemDashboardBucket::Approved,
            ContentProjectItemDashboardBucket::WaitingPublish,
            ContentProjectItemDashboardBucket::Published => true,
            default => false,
        };
    }
}
