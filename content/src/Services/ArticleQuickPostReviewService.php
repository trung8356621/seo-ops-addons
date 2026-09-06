<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewGenerateService;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Thin facade for Edit Article / jobs — canonical path is ProductReviewGenerateService
 * (article.comment.generate Prompt). Legacy post_review_task_id Workflow is retired.
 */
final class ArticleQuickPostReviewService
{
    public function __construct(
        private readonly ProductReviewGenerateService $generator,
    ) {}

    /**
     * @return array{success: bool, message: string, created_count?: int}
     */
    public function runForArticle(SeoArticle $article): array
    {
        return $this->generator->generateForArticle($article);
    }
}
