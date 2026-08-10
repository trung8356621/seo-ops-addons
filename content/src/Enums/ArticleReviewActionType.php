<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Enums;

/**
 * Loại hành động trong workflow review bài viết (bảng `seo_article_reviews`).
 */
enum ArticleReviewActionType: string
{
    case SubmitReview = 'submit_review';
    case Approve = 'approve';
    case Archive = 'archive';
    case RequestChanges = 'request_changes';
    case Reopen = 'reopen';
    case Unapprove = 'unapprove';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
