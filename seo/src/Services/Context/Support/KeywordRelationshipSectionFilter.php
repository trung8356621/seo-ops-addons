<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Support;

use InvalidArgumentException;

/**
 * Allowlisted section projection for keywords.relationship.
 * Applied after view selection; does not change gateway loading.
 */
final class KeywordRelationshipSectionFilter
{
    /** @var list<string> */
    public const ALLOWLIST = [
        'keyword',
        'topics',
        'focus_articles',
        'related_keywords',
        'internal_links',
        'gsc',
        'meta',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $sections
     * @return array<string, mixed>
     */
    public static function apply(array $data, ?array $sections): array
    {
        if ($sections === null) {
            return $data;
        }
        if ($sections === []) {
            throw new InvalidArgumentException('sections must not be empty when provided.');
        }

        $allowed = [];
        foreach ($sections as $section) {
            if (! in_array($section, self::ALLOWLIST, true)) {
                throw new InvalidArgumentException(
                    'Invalid keywords.relationship section: '.$section
                );
            }
            $allowed[$section] = true;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && isset($allowed[$key])) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
