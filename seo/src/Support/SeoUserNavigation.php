<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * User-facing SEO sidebar — WordPress-style modules (presentation only).
 *
 * Top-level items are domain modules (Dự án, Bài viết, …). Nested children use
 * Filament parentItem / childItems. Only 「Hệ thống」 remains a NavigationGroup
 * for low-frequency technical tools.
 */
final class SeoUserNavigation
{
    /** Technical tools group (Operation Center, PG Canary). */
    public const GROUP_SYSTEM = 'Hệ thống';

    public const SORT_DASHBOARD = 1;

    public const SORT_PROJECTS = 10;

    public const SORT_ARTICLES = 20;

    public const SORT_MEDIA = 30;

    public const SORT_KEYWORDS = 40;

    public const SORT_SEO = 50;

    public const SORT_DOMAINS = 60;

    public const SORT_TEAM = 70;

    public const SORT_PROMPTS = 80;

    public const SORT_SETTINGS = 85;

    public const SORT_WORKFLOWS = 90;

    public const SORT_SYSTEM = 100;

    /** @return list<string> */
    public static function groups(): array
    {
        return [
            self::GROUP_SYSTEM,
        ];
    }

    public static function moduleProjects(): string
    {
        return __('seo-content-ai::filament.nav.content_projects');
    }

    public static function moduleArticles(): string
    {
        return __('seo-content-ai::filament.nav.articles');
    }

    public static function moduleKeywords(): string
    {
        return __('seo-content-ai::filament.nav.keywords');
    }

    public static function moduleSeo(): string
    {
        return __('seo-content-ai::filament.nav.module_seo');
    }

    public static function moduleMedia(): string
    {
        return __('seo-content-ai::filament.nav.media_library');
    }
}
