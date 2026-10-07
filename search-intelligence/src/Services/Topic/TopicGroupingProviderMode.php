<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\LegacyTopicGroupingProvider;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\SemanticHttpTopicGroupingProvider;

/** Centralized Topic grouping provider selection (config only). */
final class TopicGroupingProviderMode
{
    public const LEGACY = 'legacy';

    public const SEMANTIC_HTTP = 'semantic_http';

    public static function current(): string
    {
        return self::normalize((string) config('semantic.topic_provider', self::LEGACY));
    }

    /** Normalize a provider key (explicit run/job override or config). */
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            self::SEMANTIC_HTTP, SemanticHttpTopicGroupingProvider::KEY => self::SEMANTIC_HTTP,
            default => self::LEGACY,
        };
    }

    public static function isSemanticProvider(string $value): bool
    {
        return self::normalize($value) === self::SEMANTIC_HTTP;
    }

    public static function isSemanticHttp(): bool
    {
        return self::current() === self::SEMANTIC_HTTP;
    }

    public static function isLegacy(): bool
    {
        return self::current() === self::LEGACY;
    }

    public static function providerKey(): string
    {
        return self::isSemanticHttp()
            ? SemanticHttpTopicGroupingProvider::KEY
            : LegacyTopicGroupingProvider::KEY;
    }
}
