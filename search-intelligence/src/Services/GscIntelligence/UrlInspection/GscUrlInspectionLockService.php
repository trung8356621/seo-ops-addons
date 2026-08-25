<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;

/**
 * Short external-operation lock: site + article (no DB txn during Google HTTP).
 */
final class GscUrlInspectionLockService
{
    public function __construct(
        private readonly ContentProjectBusinessLock $businessLock = new ContentProjectBusinessLock,
    ) {}

    public function articleKey(int $siteId, int $articleId): string
    {
        return 'gsc-url-inspect:'.$siteId.':'.$articleId;
    }

    /**
     * @template T
     *
     * @param  callable(?string $ownerToken): T  $callback
     * @return T
     */
    public function withArticleLock(int $siteId, int $articleId, callable $callback, int $waitSeconds = 0): mixed
    {
        return $this->businessLock->withLock(
            $this->articleKey($siteId, $articleId),
            $callback,
            $waitSeconds,
            GscUrlInspectionPolicy::ARTICLE_LOCK_TTL_SECONDS,
        );
    }
}
