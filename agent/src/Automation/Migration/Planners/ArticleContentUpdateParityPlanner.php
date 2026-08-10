<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration\Planners;

use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;

/**
 * Shadow planner cho article.content.update — không ghi DB / queue / event.
 *
 * @phpstan-type ArticleState array{
 *   article_id: int,
 *   status?: string,
 *   body?: string,
 *   title?: string,
 *   updated_at?: string|null
 * }
 */
final class ArticleContentUpdateParityPlanner
{
    public function __construct(
        private readonly ArticleContentConflictGuard $conflictGuard = new ArticleContentConflictGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  ArticleState  $articleState  Snapshot đã resolve (không query trong planner)
     * @return array<string, mixed>
     */
    public function plan(array $input, array $articleState): array
    {
        $articleId = (int) ($articleState['article_id'] ?? $input['article_id'] ?? 0);
        $body = (string) ($articleState['body'] ?? '');
        $currentHash = $this->conflictGuard->contentHash($body);
        $newContent = (string) ($input['content'] ?? '');
        $newHash = $this->conflictGuard->contentHash($newContent);
        $title = trim((string) ($input['title'] ?? $articleState['title'] ?? ''));
        $currentTitle = trim((string) ($articleState['title'] ?? ''));

        $conflict = false;
        $expectedHash = $input['expected_content_hash'] ?? null;
        if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($expectedHash, $currentHash)) {
            $conflict = true;
        }

        $noop = ! $conflict && $newHash === $currentHash && ($title === '' || $title === $currentTitle);
        $fields = [];
        if (! $noop && ! $conflict) {
            if ($newHash !== $currentHash) {
                $fields[] = 'content';
            }
            if ($title !== '' && $title !== $currentTitle) {
                $fields[] = 'title';
            }
        }

        return [
            'article_id' => $articleId,
            'status' => (string) ($articleState['status'] ?? 'draft'),
            'content_hash' => $conflict ? $currentHash : ($noop ? $currentHash : $newHash),
            'updated_at' => $articleState['updated_at'] ?? null,
            'noop' => $noop,
            'conflict' => $conflict,
            'changed_fields' => $fields,
            'would_persist' => ! $noop && ! $conflict,
        ];
    }
}
