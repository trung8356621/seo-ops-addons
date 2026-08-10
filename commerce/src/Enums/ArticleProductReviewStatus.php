<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Enums;

/**
 * Local pending Product Review lifecycle.
 * WordPress is source of truth after status = reviewed.
 */
enum ArticleProductReviewStatus: string
{
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Reviewed = 'reviewed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @deprecated Legacy — remapped by migration */
    case Draft = 'draft';
    /** @deprecated Legacy — remapped by migration */
    case PendingArticle = 'pending_article';
    /** @deprecated Legacy — remapped by migration */
    case PendingPublish = 'pending_publish';
    /** @deprecated Legacy — remapped by migration */
    case Scheduled = 'scheduled';
    /** @deprecated Legacy — remapped by migration */
    case Publishing = 'publishing';
    /** @deprecated Legacy — remapped by migration */
    case Published = 'published';
    /** @deprecated Legacy — remapped by migration */
    case FailedDispatch = 'failed_dispatch';

    public function isPendingSync(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Failed,
            self::Draft,
            self::PendingArticle,
            self::PendingPublish,
            self::Scheduled,
            self::FailedDispatch,
        ], true);
    }

    public function isReviewed(): bool
    {
        return $this === self::Reviewed || $this === self::Published;
    }

    public function isPublishable(): bool
    {
        return $this->isPendingSync();
    }

    public function normalize(): self
    {
        return match ($this) {
            self::Draft, self::PendingArticle, self::PendingPublish, self::Scheduled => self::Pending,
            self::Publishing => self::Syncing,
            self::Published => self::Reviewed,
            self::FailedDispatch => self::Failed,
            default => $this,
        };
    }
}
