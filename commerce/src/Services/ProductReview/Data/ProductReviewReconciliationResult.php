<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview\Data;

final class ProductReviewReconciliationResult
{
    /**
     * @param  list<int>  $queuedReviewIds
     * @param  list<int>  $skippedAlreadyScheduled
     * @param  list<int>  $skippedAlreadyPublished
     * @param  list<int>  $skippedAlreadyProcessing
     * @param  list<int>  $waitingForArticle
     * @param  list<int>  $invalid
     * @param  list<int>  $foundReviewIds
     */
    public function __construct(
        public readonly int $articleId,
        public readonly array $foundReviewIds = [],
        public readonly array $queuedReviewIds = [],
        public readonly array $skippedAlreadyScheduled = [],
        public readonly array $skippedAlreadyPublished = [],
        public readonly array $skippedAlreadyProcessing = [],
        public readonly array $waitingForArticle = [],
        public readonly array $invalid = [],
        public readonly string $outcome = 'OK',
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'article_id' => $this->articleId,
            'found_count' => count($this->foundReviewIds),
            'found_review_ids' => $this->foundReviewIds,
            'queued_count' => count($this->queuedReviewIds),
            'queued_review_ids' => $this->queuedReviewIds,
            'skipped_already_scheduled' => $this->skippedAlreadyScheduled,
            'skipped_already_published' => $this->skippedAlreadyPublished,
            'skipped_already_processing' => $this->skippedAlreadyProcessing,
            'waiting_for_article' => $this->waitingForArticle,
            'invalid' => $this->invalid,
            'outcome' => $this->outcome,
            'message' => $this->message,
        ];
    }
}
