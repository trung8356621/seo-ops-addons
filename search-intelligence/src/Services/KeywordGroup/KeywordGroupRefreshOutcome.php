<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * Process-local result of a synchronous RefreshKeywordGroupsJob.
 * dispatchSync does not return the job handle value.
 */
final class KeywordGroupRefreshOutcome
{
    private static ?KeywordGroupRefreshResult $result = null;

    public static function clear(): void
    {
        self::$result = null;
    }

    public static function store(KeywordGroupRefreshResult $result): void
    {
        self::$result = $result;
    }

    public static function result(): ?KeywordGroupRefreshResult
    {
        return self::$result;
    }
}
