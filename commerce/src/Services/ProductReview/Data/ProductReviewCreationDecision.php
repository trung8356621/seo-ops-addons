<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview\Data;

/**
 * Shared decision for creating local product reviews.
 *
 * @phpstan-type PolicyArray array{
 *     allowed: bool,
 *     reason: string|null,
 *     target_count: int,
 *     missing_count: int,
 *     recommended_action: string,
 *     wordpress_real_review_count: int,
 *     wordpress_generated_review_count: int,
 *     local_pending_count: int
 * }
 */
final class ProductReviewCreationDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly int $targetCount,
        public readonly int $missingCount,
        public readonly string $recommendedAction,
        public readonly int $wordpressRealReviewCount = 0,
        public readonly int $wordpressGeneratedReviewCount = 0,
        public readonly int $localPendingCount = 0,
    ) {}

    /**
     * @return array{
     *     allowed: bool,
     *     reason: string|null,
     *     target_count: int,
     *     missing_count: int,
     *     recommended_action: string,
     *     wordpress_real_review_count: int,
     *     wordpress_generated_review_count: int,
     *     local_pending_count: int
     * }
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'target_count' => $this->targetCount,
            'missing_count' => $this->missingCount,
            'recommended_action' => $this->recommendedAction,
            'wordpress_real_review_count' => $this->wordpressRealReviewCount,
            'wordpress_generated_review_count' => $this->wordpressGeneratedReviewCount,
            'local_pending_count' => $this->localPendingCount,
        ];
    }
}
