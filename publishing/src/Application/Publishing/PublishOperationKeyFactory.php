<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Stable publish operation / idempotency key for one publish lifecycle.
 * Same key on first attempt, retries, and manual recover.
 */
final class PublishOperationKeyFactory
{
    public const MAX_ATTEMPTS = 4;

    public function forTask(SeoProjectTask $task, ?SeoArticle $article = null): string
    {
        $existing = trim((string) ($task->publish_operation_key ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $itemId = (int) $task->getKey();
        $articleId = (int) ($task->article_id ?? $article?->id ?? 0);
        $revision = $this->revisionFor($task, $article);

        return sprintf(
            'content-project-item:%d:article:%d:publish:%s',
            $itemId,
            $articleId,
            $revision,
        );
    }

    /**
     * New key only when user intentionally starts a fresh publish lifecycle.
     */
    public function mintNew(SeoProjectTask $task, ?SeoArticle $article = null): string
    {
        $itemId = (int) $task->getKey();
        $articleId = (int) ($task->article_id ?? $article?->id ?? 0);
        $revision = substr(hash('sha256', $itemId.'|'.$articleId.'|'.microtime(true).'|'.random_int(1, 999999)), 0, 12);

        return sprintf(
            'content-project-item:%d:article:%d:publish:%s',
            $itemId,
            $articleId,
            $revision,
        );
    }

    private function revisionFor(SeoProjectTask $task, ?SeoArticle $article): string
    {
        $article = $article ?? ($task->relationLoaded('article') ? $task->article : null);
        if ($article instanceof SeoArticle) {
            $bodyHash = substr(hash('sha256', (string) ($article->body ?? '')), 0, 12);
            if ($bodyHash !== '') {
                return $bodyHash;
            }
        }

        $scheduled = $task->scheduled_publish_at?->toIso8601String() ?? 'unscheduled';

        return substr(hash('sha256', (int) $task->getKey().'|'.$scheduled), 0, 12);
    }
}
