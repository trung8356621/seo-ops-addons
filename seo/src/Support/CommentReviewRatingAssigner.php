<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Gán sao khi prompt không trả star_ranking: chu kỳ 5, 5, 4 (2×5 sao, 1×4 sao).
 */
final class CommentReviewRatingAssigner
{
    public function resolve(?int $explicitRating, int $index): ?int
    {
        if ($explicitRating !== null) {
            return max(1, min(5, $explicitRating));
        }

        return match ($index % 3) {
            2 => 4,
            default => 5,
        };
    }
}
