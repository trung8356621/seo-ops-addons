<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * Apply một artifact article_outline vào bản nháp editor (không lưu chính thức).
 *
 * @param  list<int>  $accessibleProjectIds
 */
final class ApplyArticleOutlineArtifactCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $artifactRef,
        public readonly array $accessibleProjectIds,
        public readonly int $userId,
        public readonly bool $confirmDirty = false,
    ) {}

    public function name(): string
    {
        return 'article_ai_history.apply_outline';
    }
}
