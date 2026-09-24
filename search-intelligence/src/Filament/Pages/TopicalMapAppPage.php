<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Standalone React Topical Map shell — auth/tenancy only; visualization is React-owned.
 *
 * Route: /seo/topical-map?site={id}
 */
final class TopicalMapAppPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $slug = 'topical-map';

    protected static string $view = 'search-intelligence::filament.pages.topical-map-app';

    protected static bool $shouldRegisterNavigation = false;

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
        return (string) __('seo-content-ai::filament.keyword.topical_map_title');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(): void
    {
        $access = app(TopicalMapAccess::class);
        $access->assertCanAccessApp();

        $requested = request()->integer('site_id');
        if ($requested <= 0) {
            $requested = request()->integer('site');
        }
        $this->siteId = $access->resolveSiteId($requested > 0 ? $requested : null);

        if ($this->siteId <= 0) {
            return;
        }

        if ($requested !== $this->siteId) {
            $this->redirect(
                app(DomainContextResolver::class)->appendSiteToUrl(
                    static::getUrl(),
                    $this->siteId,
                ),
                navigate: false,
            );
        }
    }

    /**
     * Canonical app URL with site query (for Keywords nav _blank entry).
     */
    public static function appUrl(?int $siteId = null): string
    {
        $resolved = $siteId !== null && $siteId > 0
            ? $siteId
            : (int) (SeoAccessControl::globalSiteId() ?? 0);

        $url = static::getUrl();
        if ($resolved <= 0) {
            return $url;
        }

        return app(DomainContextResolver::class)->appendSiteToUrl($url, $resolved);
    }

    /**
     * Bootstrap config injected into React mount (non-sensitive).
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

        $topicsUrl = KeywordResource::getUrl('clusters');
        if ($siteId > 0) {
            $topicsUrl = app(DomainContextResolver::class)->appendSiteToUrl($topicsUrl, $siteId);
        }

        return [
            'siteId' => $siteId,
            'siteDomain' => $domain,
            'locale' => $locale,
            'csrfToken' => csrf_token(),
            'canMutate' => $access->canMutateSite($siteId),
            'topicsPageUrl' => $topicsUrl,
            'topicDetailUrlTemplate' => $topicDetailTemplate,
            'endpoints' => [
                'overview' => url('/seo/topical-map/api/overview'),
                'topicChildren' => url('/seo/topical-map/api/topics/{topic}/children'),
                'network' => url('/seo/topical-map/api/network'),
                'tags' => url('/seo/topical-map/api/tags'),
                'auditStatus' => url('/seo/topical-map/api/audit-status'),
                'runAudit' => url('/seo/topical-map/api/audit'),
            ],
            'labels' => [
                'title' => (string) __('seo-content-ai::filament.keyword.topical_map_title'),
                'tags' => (string) __('seo-content-ai::filament.keyword.topical_map_tags_label'),
                'tagsAll' => (string) __('seo-content-ai::filament.keyword.topical_map_tags_all'),
                'untagged' => (string) __('seo-content-ai::filament.keyword.topical_map_tags_untagged'),
                'tree' => (string) __('seo-content-ai::filament.keyword.topical_map_mode_tree'),
                'network' => (string) __('seo-content-ai::filament.keyword.topical_map_mode_network'),
                'treemap' => (string) __('seo-content-ai::filament.keyword.topical_map_mode_treemap'),
                'empty' => (string) __('seo-content-ai::filament.keyword.topical_map_empty'),
                'needSite' => (string) __('seo-content-ai::filament.keyword.topical_map_need_site'),
                'aiAction' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_action'),
                'aiCurrent' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_current'),
                'aiStale' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_stale'),
                'aiRunning' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_running'),
                'aiDisabled' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_disabled'),
                'aiModalTitle' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_modal_title'),
                'aiSite' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_site'),
                'aiAssignedKw' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_assigned_kw'),
                'aiUnassignedKw' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_unassigned_kw'),
                'aiExisting' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_existing'),
                'aiTopicsTagged' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_topics_tagged'),
                'aiUntagged' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_untagged'),
                'aiCostNotice' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_cost_notice'),
                'aiRun' => (string) __('seo-content-ai::filament.keyword.ai_audit_tags_run'),
                'cancel' => (string) __('seo-content-ai::filament.keyword.topic_recluster_cancel'),
                'openAudit' => (string) __('seo-content-ai::filament.keyword.topical_map_open_audit'),
                'closeAudit' => (string) __('seo-content-ai::filament.keyword.topical_map_close_audit'),
                'auditSummary' => (string) __('seo-content-ai::filament.keyword.topical_map_audit_summary'),
                'auditFindings' => (string) __('seo-content-ai::filament.keyword.topical_map_audit_findings'),
                'auditOpportunities' => (string) __('seo-content-ai::filament.keyword.topical_map_audit_opportunities'),
                'auditActions' => (string) __('seo-content-ai::filament.keyword.topical_map_audit_actions'),
                'openTopics' => (string) __('seo-content-ai::filament.keyword.workspace_nav_two'),
            ],
        ];
    }
}
