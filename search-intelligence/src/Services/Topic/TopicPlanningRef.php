<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Compatibility codec for Content Project planner fields named `cluster_ref`.
 *
 * Modern format: topic:{seo_topics.id} — seo_topics.id remains SSOT.
 * Does not encode manual:{...} seeds (those stay planner-local).
 */
final class TopicPlanningRef
{
    public const PREFIX = 'topic:';

    public static function encode(int $topicId): string
    {
        if ($topicId <= 0) {
            return '';
        }

        return self::PREFIX.$topicId;
    }

    public static function decode(string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '' || ! self::isTopicRef($ref)) {
            return null;
        }

        $id = (int) substr($ref, strlen(self::PREFIX));

        return $id > 0 ? $id : null;
    }

    public static function isTopicRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '' || ! str_starts_with($ref, self::PREFIX)) {
            return false;
        }

        $suffix = substr($ref, strlen(self::PREFIX));

        return $suffix !== '' && ctype_digit($suffix) && (int) $suffix > 0;
    }
}
