<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * Xem trước (sanitized) nội dung artifact AI trước khi apply.
 *
 * @param  list<int>  $accessibleProjectIds
 */
final class PreviewArticleAiArtifactCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $artifactRef,
        public readonly array $accessibleProjectIds,
    ) {}

    public function name(): string
    {
        return 'article_ai_history.preview';
    }
}
