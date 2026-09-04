<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * SEO Audit Global Domain must follow the same site_id / client-hydrate SoT as Keywords.
 * draft_domain remains an independent Draft-list filter — never a Global Domain surrogate.
 */
final class SeoAuditGlobalDomainF5ContractTest extends TestCase
{
    public function test_seo_audit_is_domain_scoped_not_content_projects_neutral(): void
    {
        $store = (string) file_get_contents(dirname(__DIR__, 3).'/seo/resources/js/domainContextStore.js');

        self::assertStringContainsString('function isDomainNeutralPanelPath(href)', $store);
        // JS regex escapes slashes: \/content-projects\/seo-audit
        self::assertStringContainsString('content-projects\\/seo-audit', $store);
        self::assertTrue(SeoPanelRoutes::isProjectPlannerSeoAuditPath('/seo/content-projects/seo-audit'));
        self::assertTrue(SeoPanelRoutes::isProjectPlannerSeoAuditPath('content-projects/seo-audit'));
    }

    public function test_global_bar_treats_seo_audit_like_keyword_concrete_domain(): void
    {
        $bar = (string) file_get_contents((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName());

        self::assertStringContainsString('isProjectPlannerSeoAudit()', $bar);
        self::assertStringContainsString('resolvePreferredKeywordIntelligenceContext', $bar);
        self::assertStringContainsString('hasExplicitRequestKey', $bar);
    }

    public function test_planner_does_not_canonicalize_missing_site_id_to_first_site(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('function shouldCanonicalizePlannerUrl', $page);
        self::assertStringContainsString("return request()->has('site');", $page);
        self::assertStringNotContainsString(
            'return ! request()->has(DomainContext::SITE_ID_QUERY_KEY)',
            $page,
        );
        self::assertStringContainsString("DomainContext::SITE_ID_QUERY_KEY", $page);
        self::assertSame('site_id', DomainContext::SITE_ID_QUERY_KEY);
    }

    public function test_draft_domain_url_binding_stays_separate_from_global_site_id(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString("#[Url(as: 'draft_domain', except: 'all')]", $page);
        self::assertStringNotContainsString("#[Url(as: 'site_id'", $page);
        self::assertStringContainsString('onDomainContextChanged', $page);
        self::assertStringContainsString('domain-context-changed', $page);
        self::assertStringContainsString('seoGlobalSiteChanged', $page);
    }
}
