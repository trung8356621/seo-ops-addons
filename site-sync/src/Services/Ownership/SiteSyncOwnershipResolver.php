<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Ownership;

use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

/**
 * Field ownership + effective value resolution.
 * Manual > Provider > Workspace. Sources stored separately — never destroy lower sources.
 */
final class SiteSyncOwnershipResolver
{
    /** @var list<string> */
    public const WP_AUTHORITATIVE = [
        'wordpress_id', 'post_type', 'status', 'title', 'body', 'excerpt',
        'permalink', 'taxonomy', 'featured_image', 'provider_seo_metadata',
        'provider_keyword', 'provider_score', 'modified_at',
    ];

    /** @var list<string> */
    public const MANUAL_AUTHORITATIVE = [
        'tone', 'cta', 'contact_override', 'short_description_override',
        'manual_links', 'link_exclusions', 'manual_keyword', 'workspace_notes',
    ];

    /** @var list<string> */
    public const WORKSPACE_FALLBACK = [
        'workspace_keyword', 'workspace_score', 'link_health_fallback',
        'http_404_fallback', 'redirect_fallback',
    ];

    public function ownerFor(string $field): string
    {
        if (in_array($field, self::MANUAL_AUTHORITATIVE, true)) {
            return SiteSyncSchema::SOURCE_MANUAL;
        }
        if (in_array($field, self::WORKSPACE_FALLBACK, true)) {
            return SiteSyncSchema::SOURCE_WORKSPACE;
        }

        return SiteSyncSchema::SOURCE_WORDPRESS;
    }

    /**
     * @param  array<string, array{source: string, value: mixed, locked?: bool}>  $candidates
     * @return array{source: string, value: mixed}|null
     */
    public function resolveEffective(array $candidates): ?array
    {
        $bySource = [];
        foreach ($candidates as $row) {
            $source = (string) ($row['source'] ?? '');
            if ($source === '') {
                continue;
            }
            if (($row['locked'] ?? false) === true && $source === SiteSyncSchema::SOURCE_MANUAL) {
                return ['source' => $source, 'value' => $row['value'] ?? null];
            }
            $bySource[$source] = $row;
        }

        foreach (SiteSyncSchema::KEYWORD_PRIORITY as $source) {
            if (isset($bySource[$source])) {
                return [
                    'source' => $source,
                    'value' => $bySource[$source]['value'] ?? null,
                ];
            }
        }

        $first = reset($bySource);
        if ($first === false) {
            return null;
        }

        return [
            'source' => (string) ($first['source'] ?? SiteSyncSchema::SOURCE_PROVIDER),
            'value' => $first['value'] ?? null,
        ];
    }

    public function mayOverwrite(string $existingSource, bool $existingLocked, string $incomingSource): bool
    {
        if ($existingLocked || $existingSource === SiteSyncSchema::SOURCE_MANUAL) {
            return false;
        }

        $rank = static function (string $source): int {
            $idx = array_search($source, SiteSyncSchema::KEYWORD_PRIORITY, true);

            return $idx === false ? 99 : (int) $idx;
        };

        return $rank($incomingSource) <= $rank($existingSource);
    }

    public function isStale(?string $incomingModifiedAt, ?string $currentModifiedAt): bool
    {
        if ($incomingModifiedAt === null || $incomingModifiedAt === '' || $currentModifiedAt === null || $currentModifiedAt === '') {
            return false;
        }

        $incoming = strtotime($incomingModifiedAt);
        $current = strtotime($currentModifiedAt);
        if ($incoming === false || $current === false) {
            return false;
        }

        return $incoming < $current;
    }
}
