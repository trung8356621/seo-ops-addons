<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Canonical machine-readable contracts for Topical Map AI Audit output.
 */
final class TopicalMapAuditContracts
{
    public const MAX_TAG_TAXONOMY = 20;

    /** @var list<string> */
    public const FINDING_TYPES = [
        'strong_coverage',
        'weak_coverage',
        'coverage_gap',
        'over_concentration',
        'structural_gap',
        'search_opportunity',
        'potential_overlap',
        'internal_link_gap',
        'data_quality',
    ];

    /** @var list<string> */
    public const SEVERITIES = [
        'low',
        'medium',
        'high',
    ];

    /** @var list<string> */
    public const ACTION_TYPES = [
        'expand_topic',
        'improve_coverage',
        'investigate_new_topic',
        'consolidate_overlap',
        'review_gsc_opportunity',
        'review_internal_links',
        'review_structure',
        'no_action',
    ];

    public static function topicRef(int $topicId): string
    {
        return 'topic:'.$topicId;
    }

    public static function topicIdFromRef(?string $topicRef): ?int
    {
        $raw = trim((string) $topicRef);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^topic:(\d+)$/', $raw, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    public static function isAllowedFindingType(string $type): bool
    {
        return in_array($type, self::FINDING_TYPES, true);
    }

    public static function isAllowedSeverity(string $severity): bool
    {
        return in_array($severity, self::SEVERITIES, true);
    }

    public static function isAllowedActionType(string $actionType): bool
    {
        return in_array($actionType, self::ACTION_TYPES, true);
    }
}
