<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\Content\Enums\ArticleImproveScope;

/**
 * Improve capability riêng — không phải Article Writing full generation.
 */
final class ArticleImproveInput
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $bodyMarkdown,
        public readonly string $instruction = '',
        public readonly string $title = '',
        public readonly string $keyword = '',
        public readonly ArticleImproveScope $scope = ArticleImproveScope::Article,
        public readonly ?string $selectedText = null,
        public readonly ?string $sectionId = null,
        public readonly ?string $expectedUpdatedAt = null,
        public readonly ?string $executionToken = null,
    ) {}
}
