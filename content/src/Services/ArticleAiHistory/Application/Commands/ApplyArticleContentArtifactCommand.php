<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * Apply article_content via Versioned Save (parse + revision + canonical persist).
 *
 * @param  list<int>  $accessibleProjectIds
 */
final class ApplyArticleContentArtifactCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $artifactRef,
        public readonly array $accessibleProjectIds,
        public readonly int $userId,
        public readonly bool $confirmDirty = false,
        public readonly ?string $editorHtml = null,
        public readonly int|string|null $expectedDocumentVersion = null,
    ) {}

    public function name(): string
    {
        return 'article_ai_history.apply_content';
    }
}
