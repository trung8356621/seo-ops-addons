<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

/**
 * Centralized URL Inspection operational policy (no Settings UI in v1).
 */
final class GscUrlInspectionPolicy
{
    /** Conservative default batch size (API ~2000/day/property). */
    public const DEFAULT_BATCH_LIMIT = 25;

    public const MAX_BATCH_LIMIT = 100;

    public const MIN_BATCH_LIMIT = 1;

    /** Short lock while one URL is being inspected. */
    public const ARTICLE_LOCK_TTL_SECONDS = 120;

    public const HTTP_TIMEOUT_SECONDS = 30;

    public const MAX_TRANSIENT_ATTEMPTS = 3;

    public static function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_BATCH_LIMIT;
        }

        return max(self::MIN_BATCH_LIMIT, min(self::MAX_BATCH_LIMIT, $limit));
    }

    public static function sourceKey(): string
    {
        return 'gsc_url_inspection';
    }
}
