<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Typed workflow artifacts — PromptResult audit rows are NOT domain artifacts.
 */
enum WorkflowArtifactType: string
{
    case ArticleOutline = 'article_outline';
    case ArticleContent = 'article_content';
    case ProductGallery = 'product_gallery';
    case ProductReview = 'product_review';

    public function isDomainWriteBodySource(): bool
    {
        return $this === self::ArticleContent;
    }
}
