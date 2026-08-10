<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

/**
 * Cô lập luồng keywords legacy: gate `allowsContentKeywordPersistence` vẫn chỉ mở trong domain resync
 * cho SeoAnalyzerService; save/debug/link list dùng ArticleLinkContextMapService + keyword_meta EAV.
 */
final class KeywordSyncIsolation
{
    private static int $domainResyncDepth = 0;

    public static function allowsAutomaticContentSync(): bool
    {
        return true;
    }

    public static function allowsKeywordObserverSync(): bool
    {
        return false;
    }

    public static function allowsDomainLinkListSync(): bool
    {
        return true;
    }

    public static function allowsContentKeywordPersistence(): bool
    {
        return self::$domainResyncDepth > 0;
    }

    public static function allowsDomainResync(): bool
    {
        return true;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function runWithinDomainResync(callable $callback): mixed
    {
        self::$domainResyncDepth++;

        try {
            return $callback();
        } finally {
            self::$domainResyncDepth--;
        }
    }
}
