<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordTag
{
    public const FOCUS = 'focus';

    public const ERROR = 'error';

    public const SEO_EXCLUDED = 'seo_excluded';

    public const HAS_LINK = 'has_link';

    public const WRITING = 'writing';

    public const PENDING_REVIEW = 'pending_review';

    public const PENDING_PUBLISH = 'pending_publish';

    public const PUBLISHED = 'published';

    public const GROUP_PREFIX = 'group:';

    public static function groupKey(string $key): string
    {
        return self::GROUP_PREFIX.ltrim($key, ':');
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FOCUS,
            self::ERROR,
            self::SEO_EXCLUDED,
            self::HAS_LINK,
            self::WRITING,
            self::PENDING_REVIEW,
            self::PENDING_PUBLISH,
            self::PUBLISHED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function primarySeo(): array
    {
        return [self::SEO_EXCLUDED, self::FOCUS];
    }

    public static function isKnown(string $tag): bool
    {
        return in_array($tag, self::all(), true);
    }

    public static function isGroup(string $tag): bool
    {
        return str_starts_with($tag, self::GROUP_PREFIX);
    }

    public static function groupCode(int $tagId): string
    {
        return self::GROUP_PREFIX.$tagId;
    }

    public static function parseGroupKey(string $tag): ?string
    {
        if (! self::isGroup($tag)) {
            return null;
        }
        $key = trim(substr($tag, strlen(self::GROUP_PREFIX)));

        return $key !== '' && ! ctype_digit($key) ? $key : null;
    }

    public static function parseGroupId(string $tag): ?int
    {
        if (! self::isGroup($tag)) {
            return null;
        }

        $id = (int) substr($tag, strlen(self::GROUP_PREFIX));

        return $id > 0 ? $id : null;
    }

    public static function label(string $tag): string
    {
        return match ($tag) {
            self::FOCUS => __('seo-content-ai::filament.keyword.op_tag_focus'),
            self::ERROR => __('seo-content-ai::filament.keyword.op_tag_error'),
            self::SEO_EXCLUDED => __('seo-content-ai::filament.keyword.op_tag_seo_excluded'),
            self::HAS_LINK => __('seo-content-ai::filament.keyword.op_tag_has_link'),
            self::WRITING => __('seo-content-ai::filament.keyword.op_tag_writing'),
            self::PENDING_REVIEW => __('seo-content-ai::filament.keyword.op_tag_pending_review'),
            self::PENDING_PUBLISH => __('seo-content-ai::filament.keyword.op_tag_pending_publish'),
            self::PUBLISHED => __('seo-content-ai::filament.keyword.op_tag_published'),
            default => $tag,
        };
    }

    public static function color(string $tag): string
    {
        return match ($tag) {
            self::FOCUS, self::PUBLISHED => 'success',
            self::PENDING_REVIEW => 'warning',
            self::ERROR, self::SEO_EXCLUDED => 'danger',
            self::HAS_LINK, self::PENDING_PUBLISH => 'info',
            self::WRITING => 'primary',
            default => 'gray',
        };
    }

    public static function badgeClass(string $tag): string
    {
        $color = match (self::color($tag)) {
            'success' => 'ws-badge--success',
            'warning' => 'ws-badge--warning',
            'danger' => 'ws-badge--danger',
            'info' => 'ws-badge--info',
            'primary' => 'ws-badge--primary',
            default => 'ws-badge--gray',
        };

        return 'ws-badge ws-badge--compact '.$color;
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [];
        foreach (self::all() as $tag) {
            $options[$tag] = self::label($tag);
        }

        return $options;
    }
}
