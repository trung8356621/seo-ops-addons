<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Single registry of Article core fields required for a valid WordPress → SEO Ops sync.
 *
 * Sourced from real schema + SyncDomainContentService / Site Sync import paths — not invented.
 * SEO/optional fields (meta_title, focus_keyword, seo_score, featured image, AI) are excluded.
 *
 * @phpstan-type RequiredField array{
 *   key: string,
 *   label: string,
 *   how_to_check: string,
 *   storage: 'column'|'meta'|'relation',
 *   column?: string,
 *   meta_key?: string,
 *   relation?: string
 * }
 */
final class ArticleRequiredDataRegistry
{
    public const SEVERITY_GREEN = 'green';

    public const SEVERITY_YELLOW = 'yellow';

    public const SEVERITY_RED = 'red';

    public const MISSING_YELLOW_MAX = 500;

    /**
     * @return list<RequiredField>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'source_id',
                'label' => 'WordPress ID',
                'how_to_check' => 'wordpress_article_links.wp_post_id present and > 0',
                'storage' => 'relation',
                'relation' => 'wordpress_article_links.wp_post_id',
            ],
            [
                'key' => 'title',
                'label' => 'Title',
                'how_to_check' => 'articles.title not null and not whitespace-only',
                'storage' => 'column',
                'column' => 'title',
            ],
            [
                'key' => 'slug',
                'label' => 'Slug',
                'how_to_check' => 'articles.slug not null/empty/whitespace-only (independent of permalink)',
                'storage' => 'column',
                'column' => 'slug',
            ],
            [
                'key' => 'permalink',
                'label' => 'Permalink',
                'how_to_check' => 'article_meta.wp_permalink present and not whitespace-only (stored URL, not derived UI)',
                'storage' => 'meta',
                'meta_key' => 'wp_permalink',
            ],
            [
                'key' => 'content_type',
                'label' => 'Content type',
                'how_to_check' => 'article_meta.content_type in post|page|product',
                'storage' => 'meta',
                'meta_key' => 'content_type',
            ],
            [
                'key' => 'wp_post_type',
                'label' => 'WP post type',
                'how_to_check' => 'article_meta.wp_post_type present and not whitespace-only',
                'storage' => 'meta',
                'meta_key' => 'wp_post_type',
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'how_to_check' => 'articles.status not null and not whitespace-only',
                'storage' => 'column',
                'column' => 'status',
            ],
        ];
    }

    /**
     * @return RequiredField|null
     */
    public static function get(string $key): ?array
    {
        foreach (self::all() as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_map(
            static fn (array $field): string => $field['key'],
            self::all(),
        ));
    }

    public static function severityForMissing(int $missing): string
    {
        if ($missing <= 0) {
            return self::SEVERITY_GREEN;
        }

        if ($missing <= self::MISSING_YELLOW_MAX) {
            return self::SEVERITY_YELLOW;
        }

        return self::SEVERITY_RED;
    }

    /**
     * Inspected but NOT registered as core-required (runtime does not treat absence as structural failure).
     *
     * @return list<string>
     */
    public static function explicitlyNotRequired(): array
    {
        return [
            'published_at', // drafts valid without publish time
            'language', // column default; not used as sync structural gate
            'wp_entity', // legacy — replaced by wp_is_term
            'meta_title',
            'meta_description',
            'focus_keyword',
            'seo_score',
            'featured_image',
        ];
    }
}
