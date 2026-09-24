<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Standalone React Keyword Relationship shell — auth/tenancy only.
 *
 * Route: /seo/topical-map/keyword/{keyword}?site={id}
 * Same Vite bundle as {@see TopicalMapAppPage}.
 */
final class KeywordRelationshipAppPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $slug = 'topical-map/keyword/{keyword}';

    protected static string $view = 'search-intelligence::filament.pages.topical-map-app';

    protected static bool $shouldRegisterNavigation = false;

    public int $keyword = 0;

    public int $siteId = 0;

    public static function canAccess(array $parameters = []): bool
    {
        return app(TopicalMapAccess::class)->canAccessApp();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('seo-content-ai::filament.keyword.relationship_title');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(int|string $keyword): void
    {
        $access = app(TopicalMapAccess::class);
        $access->assertCanAccessApp();

        $this->keyword = (int) $keyword;
        abort_unless($this->keyword > 0, 404);

        $requested = request()->integer('site_id');
        if ($requested <= 0) {
            $requested = request()->integer('site');
        }
        $this->siteId = $access->resolveSiteId($requested > 0 ? $requested : null);
        abort_unless($this->siteId > 0, 404);
        $access->assertCanAccessSite($this->siteId);

        $relationship = app(KeywordRelationshipGateway::class)->forKeyword($this->siteId, $this->keyword);
        abort_unless($relationship !== null, 404);

        if ($requested > 0 && $requested !== $this->siteId) {
            $this->redirect(static::appUrl($this->keyword, $this->siteId), navigate: false);
        }
    }

    /**
     * Canonical Keyword Relationship app URL.
     */
    public static function appUrl(int $keywordId, ?int $siteId = null): string
    {
        $keywordId = max(0, $keywordId);
        $resolved = $siteId !== null && $siteId > 0
            ? $siteId
            : (int) (SeoAccessControl::globalSiteId() ?? 0);

        $url = static::getUrl(['keyword' => max(1, $keywordId)]);
        if ($resolved <= 0) {
            return $url;
        }

        return app(DomainContextResolver::class)->appendSiteToUrl($url, $resolved);
    }

    /**
     * Bootstrap config for React Keyword Relationship mode.
     *
     * @return array<string, mixed>
     */
    public function bootstrapConfig(): array
    {
        $access = app(TopicalMapAccess::class);
        $siteId = $this->siteId;
        $domain = $access->siteDomain($siteId);
        $locale = app()->getLocale();

        $topicDetailTemplate = str_replace(
            '999999001',
            '{topic}',
            KeywordResource::getUrl('cluster', ['topic' => 999999001]),
        );

        $articleEditTemplate = '';
        if (class_exists(ArticleResource::class)) {
            try {
                $articleEditTemplate = str_replace(
                    '999999002',
                    '{article}',
                    ArticleResource::getUrl('edit', ['record' => 999999002]),
                );
            } catch (\Throwable) {
                $articleEditTemplate = '';
            }
        }

        $dictionaryUrl = KeywordResource::getUrl('index');
        $topicalMapUrl = TopicalMapAppPage::appUrl($siteId > 0 ? $siteId : null);
        if ($siteId > 0) {
            $dictionaryUrl = app(DomainContextResolver::class)->appendSiteToUrl($dictionaryUrl, $siteId);
        }

        $relationship = app(KeywordRelationshipGateway::class)->forKeyword($siteId, $this->keyword);
        $phrase = '';
        $mcpExcluded = false;
        $topicId = 0;
        $topicName = '';
        if ($relationship !== null) {
            $payload = $relationship->toArray();
            $kw = is_array($payload['keyword'] ?? null) ? $payload['keyword'] : [];
            $phrase = (string) ($kw['phrase'] ?? '');
            $mcpExcluded = ($kw['mcp_excluded'] ?? false) === true;
            $topic = is_array($payload['topics'][0] ?? null) ? $payload['topics'][0] : [];
            $topicId = (int) ($topic['id'] ?? 0);
            $topicName = (string) ($topic['name'] ?? '');
        }

        return [
            'mode' => 'keyword-relationship',
            'siteId' => $siteId,
            'siteDomain' => $domain,
            'keywordId' => $this->keyword,
            'keywordPhrase' => $phrase,
            'mcpExcluded' => $mcpExcluded,
            'topicId' => $topicId,
            'topicName' => $topicName,
            'locale' => $locale,
            'csrfToken' => csrf_token(),
            'canMutate' => false,
            'dictionaryUrl' => $dictionaryUrl,
            'topicalMapUrl' => $topicalMapUrl,
            'topicDetailUrlTemplate' => $topicDetailTemplate,
            'articleEditUrlTemplate' => $articleEditTemplate,
            'relationshipUrlTemplate' => (function () use ($siteId): string {
                $template = str_replace(
                    '999999003',
                    '{keyword}',
                    self::getUrl(['keyword' => 999999003]),
                );
                if ($siteId > 0) {
                    return app(DomainContextResolver::class)->appendSiteToUrl($template, $siteId);
                }

                return $template;
            })(),
            'endpoints' => [
                'relationship' => url('/seo/topical-map/api/keywords/{keyword}/relationship'),
            ],
            'labels' => [
                'title' => (string) __('seo-content-ai::filament.keyword.relationship_title'),
                'openKeywords' => (string) __('seo-content-ai::filament.keyword.relationship_open_dictionary'),
                'openTopic' => (string) __('seo-content-ai::filament.keyword.workspace_nav_two'),
                'openTopicalMap' => (string) __('seo-content-ai::filament.keyword.topical_map_title'),
                'filterTopic' => (string) __('seo-content-ai::filament.keyword.relationship_filter_topic'),
                'filterArticle' => (string) __('seo-content-ai::filament.keyword.relationship_filter_article'),
                'filterDna' => (string) __('seo-content-ai::filament.keyword.relationship_filter_dna'),
                'filterRelated' => (string) __('seo-content-ai::filament.keyword.relationship_filter_related'),
                'filterGsc' => (string) __('seo-content-ai::filament.keyword.relationship_filter_gsc'),
                'filterLinks' => (string) __('seo-content-ai::filament.keyword.relationship_filter_links'),
                'filterPlanning' => (string) __('seo-content-ai::filament.keyword.relationship_filter_planning'),
                'empty' => (string) __('seo-content-ai::filament.keyword.relationship_empty'),
                'needSite' => (string) __('seo-content-ai::filament.keyword.topical_map_need_site'),
                'mcpExcluded' => 'MCP Excluded',
                'issueFocusMissing' => 'Missing Focus Article',
                'unavailableSection' => 'No data available',
                'noFocusArticle' => 'No Focus Article available',
                'details' => (string) __('seo-content-ai::filament.keyword.relationship_side_panel'),
                'closeDetails' => (string) __('seo-content-ai::filament.keyword.topical_map_close_audit'),
            ],
        ];
    }
}
