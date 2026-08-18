<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Support;

use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\LocalArticleSaveTimer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ActionSupport
{
    /**
     * Same-process reentrancy depth for article write mutex.
     * SessionService wraps persist → UpdateArticleContentAction must not re-acquire.
     *
     * @var array<int, int>
     */
    private static array $articleWriteDepth = [];

    public static function assertMutable(ActionContext $context): ?ActionResult
    {
        if (self::isSystemOrigin($context)) {
            return null;
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return ActionResult::failure('forbidden', 'Actor is not allowed to mutate SEO content.');
        }

        return null;
    }

    public static function isSystemOrigin(ActionContext $context): bool
    {
        $origin = strtolower($context->origin);

        return str_starts_with($origin, 'system.')
            || str_starts_with($origin, 'foundation.')
            || $origin === 'system'
            || $origin === 'automation.test';
    }

    public static function findArticle(int $articleId): ?SeoArticle
    {
        if ($articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()->find($articleId);

        return $article instanceof SeoArticle ? $article : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function articleEvent(
        string $eventKey,
        ActionContext $context,
        int $articleId,
        array $payload = [],
    ): EventEnvelope {
        return EventEnvelope::make(
            eventKey: $eventKey,
            entity: ['type' => 'article', 'id' => $articleId],
            context: [
                'correlation_id' => $context->correlationId,
                'causation_id' => $context->causationId,
                'origin' => $context->origin,
                'actor_id' => $context->actorId,
                'team_id' => $context->teamId,
                'site_id' => $context->siteId,
                'connection_id' => $context->connectionId,
            ],
            payload: $payload,
        );
    }

    /**
     * Short request-serialization mutex for canonical article writes.
     * Key: article-write:{id} — NOT editor session lock, NOT automation/job lock.
     *
     * Reentrant in the same PHP process so SessionService → Action nested calls work.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withArticleLock(int $articleId, callable $callback, int $waitSeconds = 5): mixed
    {
        if ($articleId <= 0) {
            return $callback();
        }

        if ((self::$articleWriteDepth[$articleId] ?? 0) > 0) {
            self::$articleWriteDepth[$articleId]++;
            try {
                return $callback();
            } finally {
                self::$articleWriteDepth[$articleId]--;
                if (self::$articleWriteDepth[$articleId] <= 0) {
                    unset(self::$articleWriteDepth[$articleId]);
                }
            }
        }

        $lock = Cache::lock(self::articleWriteLockKey($articleId), 30);

        try {
            $waitStarted = hrtime(true);

            return $lock->block(max(1, $waitSeconds), function () use ($articleId, $callback, $waitStarted): mixed {
                LocalArticleSaveTimer::log(
                    $articleId,
                    'articleLock.wait',
                    (int) round((hrtime(true) - $waitStarted) / 1_000_000),
                );
                self::$articleWriteDepth[$articleId] = 1;
                try {
                    return LocalArticleSaveTimer::measure($articleId, 'articleLock.held', $callback);
                } finally {
                    unset(self::$articleWriteDepth[$articleId]);
                }
            });
        } catch (LockTimeoutException $exception) {
            LocalArticleSaveTimer::log(
                $articleId,
                'articleLock.wait_timeout',
                (int) round((hrtime(true) - $waitStarted) / 1_000_000),
            );
            throw new \RuntimeException('article_write_busy', 0, $exception);
        }
    }

    public static function articleWriteLockKey(int $articleId): string
    {
        return 'article-write:'.$articleId;
    }
}
