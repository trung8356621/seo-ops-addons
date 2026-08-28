<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Short Main Service panel uses id "seo-main" (path /seo/...).
 * Hash/secondary panel keeps id "seo" (path /seo/{connection_hash}/...).
 * Route-name checks must accept both.
 *
 * Sidebar active state should call the dedicated is*Nav*() helpers — never
 * broad path wildcards like seo/content-projects*.
 */
final class SeoPanelRoutes
{
    /**
     * @param  string  ...$patterns  Prefer filament.seo.* patterns; seo-main aliases are added automatically.
     */
    public static function is(string ...$patterns): bool
    {
        $current = request()->route()?->getName();

        if (! is_string($current) || $current === '') {
            return false;
        }

        return self::matches($current, ...$patterns);
    }

    /**
     * Testable matcher: compare a concrete route name against patterns.
     *
     * @param  string  ...$patterns  Prefer filament.seo.* patterns.
     */
    public static function matches(string $currentRoute, string ...$patterns): bool
    {
        foreach (self::expand($patterns) as $pattern) {
            if (Str::is($pattern, $currentRoute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $expanded = [];

        foreach ($patterns as $pattern) {
            $expanded[] = $pattern;

            if (str_starts_with($pattern, 'filament.seo-main.')) {
                continue;
            }

            if (str_starts_with($pattern, 'filament.seo.')) {
                $expanded[] = 'filament.seo-main.'.substr($pattern, strlen('filament.seo.'));
            }
        }

        return array_values(array_unique($expanded));
    }

    // ─── Dự án ───────────────────────────────────────────────────────────

    public static function isProjectsModule(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.resources.content-projects.*',
            'filament.seo.pages.content-projects.*',
            'filament.seo.pages.publishing-queue',
        );
    }

    /** List + project detail/workspace (not create / planner / publishing queue). */
    public static function isProjectsListNav(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.resources.content-projects.index',
            'filament.seo.resources.content-projects.view',
            'filament.seo.resources.content-projects.edit',
            'filament.seo.resources.content-projects.archive',
            'filament.seo.resources.content-projects.archive-preview',
            'filament.seo.resources.content-projects.run-history',
            'filament.seo.resources.content-projects.view-run',
            'filament.seo.resources.content-projects.view-run-step',
        );
    }

    public static function isProjectsCreateNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.resources.content-projects.create');
    }

    public static function isProjectPlannerNav(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.pages.content-projects.seo-audit',
            'filament.seo.pages.content-projects.planner-runs',
            'filament.seo.pages.content-projects.new-content',
            'filament.seo.pages.content-projects.ai-history',
        );
    }

    public static function isPublishingQueueNav(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.pages.publishing-queue',
            'filament.seo.resources.content-projects.publishing-queue',
        );
    }

    // ─── Bài viết ────────────────────────────────────────────────────────

    public static function isArticlesModule(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.resources.articles.*',
            'filament.seo.pages.articles.*',
        );
    }

    /**
     * Categories shares articles.index — only the intentional `tab` discriminator
     * is read (site_id / filters ignored). Other article routes never match.
     */
    public static function isArticlesCategoriesNav(?string $route = null, ?string $tab = null): bool
    {
        if (! self::check($route, 'filament.seo.resources.articles.index')) {
            return false;
        }

        return self::resolveArticlesIndexTab($tab) === 'categories';
    }

    /** All-posts entry: index (non-categories) + editor/detail/queue/trash/…. */
    public static function isArticlesListNav(?string $route = null, ?string $tab = null): bool
    {
        if (self::isArticlesCategoriesNav($route, $tab)) {
            return false;
        }

        return self::isArticlesModule($route);
    }

    // ─── Từ khóa ─────────────────────────────────────────────────────────

    public static function isKeywordsModule(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.resources.keywords.*',
            'filament.seo.pages.keywords.*',
        );
    }

    public static function isKeywordsDictionaryNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.resources.keywords.index');
    }

    public static function isKeywordsFocusNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.resources.keywords.focus');
    }

    public static function isKeywordsClustersNav(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.resources.keywords.clusters',
            'filament.seo.resources.keywords.cluster',
            'filament.seo.resources.keywords.workspace-2',
        );
    }

    public static function isKeywordsCannibalizationNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.resources.keywords.cannibalization');
    }

    public static function isKeywordsBrokenLinksNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.resources.keywords.anchor-audit');
    }

    // ─── SEO ─────────────────────────────────────────────────────────────

    public static function isSeoModule(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.pages.performance-hub',
            'filament.seo.pages.mcp-intelligence',
            'filament.seo.pages.social',
        );
    }

    public static function isSeoPerformanceNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.pages.performance-hub');
    }

    public static function isMcpIntelligenceNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.pages.mcp-intelligence');
    }

    public static function isSocialNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.pages.social');
    }

    // ─── Hệ thống ────────────────────────────────────────────────────────

    public static function isSystemModule(?string $route = null): bool
    {
        return self::check($route,
            'filament.seo.pages.content-operations',
            'filament.seo.pages.product-gallery-canary',
        );
    }

    public static function isOperationCenterNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.pages.content-operations');
    }

    public static function isPgCanaryNav(?string $route = null): bool
    {
        return self::check($route, 'filament.seo.pages.product-gallery-canary');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function shortLoginUrl(array $parameters = []): string
    {
        if (Route::has('filament.seo-main.auth.login')) {
            return route('filament.seo-main.auth.login', $parameters);
        }

        return url('/seo/login');
    }

    private static function check(?string $route, string ...$patterns): bool
    {
        if ($route !== null) {
            return self::matches($route, ...$patterns);
        }

        return self::is(...$patterns);
    }

    private static function resolveArticlesIndexTab(?string $tab = null): string
    {
        if (is_string($tab) && $tab !== '') {
            return $tab;
        }

        if (! app()->bound('request')) {
            return 'posts';
        }

        $queryTab = request()->query('tab');

        return is_string($queryTab) && $queryTab !== '' ? $queryTab : 'posts';
    }
}
