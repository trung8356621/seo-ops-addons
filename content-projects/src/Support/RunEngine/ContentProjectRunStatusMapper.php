<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\RunEngine;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRunSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;

/**
 * Single place mapping semantic engine states ↔ legacy DB strings.
 * Do not scatter string status mapping elsewhere.
 */
final class ContentProjectRunStatusMapper
{
    public function runFromDb(?string $status): ContentProjectRunSemanticStatus
    {
        return match (trim((string) $status)) {
            SeoProjectRun::STATUS_STOPPING, 'stopping' => ContentProjectRunSemanticStatus::Stopping,
            SeoProjectRun::STATUS_CANCELLED, 'cancelled' => ContentProjectRunSemanticStatus::Cancelled,
            SeoProjectRun::STATUS_COMPLETED, 'completed' => ContentProjectRunSemanticStatus::Completed,
            SeoProjectRun::STATUS_FAILED, 'failed' => ContentProjectRunSemanticStatus::Failed,
            'pending' => ContentProjectRunSemanticStatus::Pending,
            default => ContentProjectRunSemanticStatus::Running,
        };
    }

    public function runToDb(ContentProjectRunSemanticStatus $status): string
    {
        return match ($status) {
            ContentProjectRunSemanticStatus::Pending => SeoProjectRun::STATUS_RUNNING,
            ContentProjectRunSemanticStatus::Running => SeoProjectRun::STATUS_RUNNING,
            ContentProjectRunSemanticStatus::Stopping => SeoProjectRun::STATUS_STOPPING,
            ContentProjectRunSemanticStatus::Cancelled => SeoProjectRun::STATUS_CANCELLED,
            ContentProjectRunSemanticStatus::Completed => SeoProjectRun::STATUS_COMPLETED,
            ContentProjectRunSemanticStatus::Failed => SeoProjectRun::STATUS_FAILED,
        };
    }

    public function articleFromDb(?string $status, ?string $errorMessage = null): ContentProjectArticleSemanticStatus
    {
        $normalized = trim((string) $status);
        if ($normalized === SeoProjectRunItemStatus::Failed->value
            && $this->errorLooksCancelled($errorMessage)
        ) {
            return ContentProjectArticleSemanticStatus::Cancelled;
        }

        return match ($normalized) {
            SeoProjectRunItemStatus::Processing->value => ContentProjectArticleSemanticStatus::Running,
            SeoProjectRunItemStatus::Success->value => ContentProjectArticleSemanticStatus::Completed,
            SeoProjectRunItemStatus::Failed->value => ContentProjectArticleSemanticStatus::Failed,
            SeoProjectRunItemStatus::Skipped->value => ContentProjectArticleSemanticStatus::Skipped,
            SeoProjectRunItemStatus::Manual->value => ContentProjectArticleSemanticStatus::Skipped,
            default => ContentProjectArticleSemanticStatus::Pending,
        };
    }

    public function articleToDb(ContentProjectArticleSemanticStatus $status): string
    {
        return match ($status) {
            ContentProjectArticleSemanticStatus::Pending => SeoProjectRunItemStatus::Pending->value,
            ContentProjectArticleSemanticStatus::Running => SeoProjectRunItemStatus::Processing->value,
            ContentProjectArticleSemanticStatus::Completed => SeoProjectRunItemStatus::Success->value,
            ContentProjectArticleSemanticStatus::Failed => SeoProjectRunItemStatus::Failed->value,
            ContentProjectArticleSemanticStatus::Cancelled => SeoProjectRunItemStatus::Failed->value,
            ContentProjectArticleSemanticStatus::Skipped => SeoProjectRunItemStatus::Skipped->value,
        };
    }

    public function cancelledArticleErrorMessage(): string
    {
        return 'Cancelled by user.';
    }

    public function errorLooksCancelled(?string $errorMessage): bool
    {
        $message = trim((string) $errorMessage);
        if ($message === '') {
            return false;
        }

        return str_contains(mb_strtolower($message), 'cancelled by user');
    }
}
