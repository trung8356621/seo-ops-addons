<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Commands;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\Application\Contracts\ArticleAiHistoryCommand;

/**
 * Xoá (tombstone) một artifact AI khỏi lịch sử bài viết.
 *
 * @param  list<int>  $accessibleProjectIds
 */
final class DeleteArticleAiArtifactCommand implements ArticleAiHistoryCommand
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $artifactRef,
        public readonly array $accessibleProjectIds,
        public readonly int $userId,
        public readonly bool $confirmPreviouslyApplied = false,
        public readonly ?string $reason = null,
    ) {}

    public function name(): string
    {
        return 'article_ai_history.delete';
    }
}
