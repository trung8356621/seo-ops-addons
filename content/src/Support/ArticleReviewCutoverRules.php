<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;

/**
 * Batch C cutover — classify rows before dropping articles.is_reviewed.
 *
 * @phpstan-type CutoverDecision array{
 *     rule: string,
 *     action: 'preserve'|'set_approved'|'set_draft',
 *     target_status: string|null,
 * }
 */
final class ArticleReviewCutoverRules
{
    public const RULE_VALID_APPROVED = 'A_valid_approved_preserve';

    public const RULE_VALID_ARCHIVED = 'B_valid_archived_preserve';

    public const RULE_MIRROR_TO_APPROVED = 'C_null_invalid_mirror_true_to_approved';

    public const RULE_MIRROR_TO_DRAFT = 'D_null_invalid_mirror_false_to_draft';

    public const RULE_CONFLICT_TO_APPROVED = 'E_draft_pending_mirror_true_to_approved';

    public const RULE_ARCHIVED_MIRROR_TRUE = 'F_archived_mirror_true_preserve';

    /**
     * @return CutoverDecision
     */
    public static function decide(?string $reviewStatus, bool $isReviewed): array
    {
        $stored = ArticleReviewStatus::tryFromString($reviewStatus);

        if ($stored === ArticleReviewStatus::Approved) {
            return [
                'rule' => self::RULE_VALID_APPROVED,
                'action' => 'preserve',
                'target_status' => ArticleReviewStatus::Approved->value,
            ];
        }

        if ($stored === ArticleReviewStatus::Archived) {
            return [
                'rule' => $isReviewed ? self::RULE_ARCHIVED_MIRROR_TRUE : self::RULE_VALID_ARCHIVED,
                'action' => 'preserve',
                'target_status' => ArticleReviewStatus::Archived->value,
            ];
        }

        if ($stored === ArticleReviewStatus::Draft || $stored === ArticleReviewStatus::PendingReview) {
            if ($isReviewed) {
                return [
                    'rule' => self::RULE_CONFLICT_TO_APPROVED,
                    'action' => 'set_approved',
                    'target_status' => ArticleReviewStatus::Approved->value,
                ];
            }

            return [
                'rule' => 'preserve_'.$stored->value,
                'action' => 'preserve',
                'target_status' => $stored->value,
            ];
        }

        if ($isReviewed) {
            return [
                'rule' => self::RULE_MIRROR_TO_APPROVED,
                'action' => 'set_approved',
                'target_status' => ArticleReviewStatus::Approved->value,
            ];
        }

        return [
            'rule' => self::RULE_MIRROR_TO_DRAFT,
            'action' => 'set_draft',
            'target_status' => ArticleReviewStatus::Draft->value,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function emptyStats(): array
    {
        return [
            self::RULE_VALID_APPROVED => 0,
            self::RULE_VALID_ARCHIVED => 0,
            self::RULE_MIRROR_TO_APPROVED => 0,
            self::RULE_MIRROR_TO_DRAFT => 0,
            self::RULE_CONFLICT_TO_APPROVED => 0,
            self::RULE_ARCHIVED_MIRROR_TRUE => 0,
            'preserve_other' => 0,
            'scanned' => 0,
            'updated' => 0,
        ];
    }
}
