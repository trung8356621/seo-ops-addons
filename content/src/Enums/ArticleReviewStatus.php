<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Enums;

/**
 * Trạng thái review của bài viết (cột `articles.review_status`, connection `omi_seo_ai`).
 */
enum ArticleReviewStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
