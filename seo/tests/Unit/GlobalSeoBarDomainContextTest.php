<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ListArticles;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\ContentProjectQueueHealthWidget;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\ListKeywords;
use Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence;
use Omnichannel\Addons\Seo\Livewire\Concerns\RefreshesOnDomainContextChanged;
use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class GlobalSeoBarDomainContextTest extends TestCase
{
    public function test_domain_switch_does_not_full_page_redirect(): void
    {
        $updated = $this->methodSource(GlobalSeoBar::class, 'updatedDomainKey');
        $dispatch = $this->methodSource(GlobalSeoBar::class, 'dispatchDomainContextChanged');
        $classSource = (string) file_get_contents((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName());

        $this->assertStringNotContainsString('$this->redirect', $updated);
        $this->assertStringNotContainsString('window.location', $updated);
        $this->assertStringContainsString('dispatchDomainContextChanged', $updated);
        $this->assertStringContainsString('domain-context-changed', $dispatch);
        $this->assertStringContainsString('seoGlobalSiteChanged', $dispatch);
        $this->assertStringNotContainsString('restoreGlobalSiteFromStorage', $classSource);
    }

    public function test_bar_no_longer_auto_selects_first_site_from_session(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName());

        $this->assertStringNotContainsString('restoreGlobalSiteFromStorage', $source);
        $this->assertStringContainsString('forgetLegacyGlobalSitePersistence', $source);
        $this->assertStringContainsString('DomainContextResolver', $source);
    }

    public function test_access_control_drops_session_cookie_as_source_of_truth(): void
    {
        $source = $this->methodSource(SeoAccessControl::class, 'globalSiteId');

        $this->assertStringNotContainsString("session('seo_global_site_id')", $source);
        $this->assertStringContainsString('domainContext()', $source);

        $setSource = $this->methodSource(SeoAccessControl::class, 'setGlobalSiteId');
        $this->assertStringNotContainsString("session(['seo_global_site_id'", $setSource);
        $this->assertStringContainsString('forgetLegacyGlobalSitePersistence', $setSource);
    }

    public function test_content_projects_list_refreshes_without_resetting_month(): void
    {
        $this->assertContains(RefreshesOnDomainContextChanged::class, class_uses(ListSeoProjects::class) ?: []);

        $traitSource = (string) file_get_contents((string) (new ReflectionClass(RefreshesOnDomainContextChanged::class))->getFileName());
        $this->assertStringContainsString('resetPage', $traitSource);
        $this->assertStringNotContainsString('planningMonth', $traitSource);

        $listSource = (string) file_get_contents((string) (new ReflectionClass(ListSeoProjects::class))->getFileName());
        $this->assertStringContainsString('planningMonth', $listSource);
        $this->assertStringContainsString('applyGlobalSiteScopeToProjectQuery', $listSource);
    }

    public function test_articles_list_and_archive_listen_for_domain_context(): void
    {
        $this->assertContains(RefreshesOnDomainContextChanged::class, class_uses(ListArticles::class) ?: []);
        $this->assertContains(RefreshesOnDomainContextChanged::class, class_uses(ContentProjectArchive::class) ?: []);
        $this->assertContains(RefreshesOnDomainContextChanged::class, class_uses(ContentProjectQueueHealthWidget::class) ?: []);
    }

    public function test_keyword_and_mcp_pages_follow_global_domain_without_local_selector(): void
    {
        $nav = (string) file_get_contents(dirname(__DIR__, 3).'/search-intelligence/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php');
        $this->assertStringContainsString('domain-context-changed', $nav);
        $this->assertStringContainsString('SeoAccessControl::globalSiteId', $nav);
        $this->assertStringNotContainsString('setGlobalSiteId(null)', $nav);

        $mcp = (string) file_get_contents((string) (new ReflectionClass(McpIntelligence::class))->getFileName());
        $this->assertStringContainsString('syncSiteFromGlobalContext', $mcp);
        $this->assertStringContainsString('onDomainContextChanged', $mcp);

        $this->assertContains(HasKeywordWorkspaceNavigation::class, class_uses(ListKeywords::class) ?: []);
        $this->assertContains(HasKeywordWorkspaceNavigation::class, class_uses(KeywordTopicClusters::class) ?: []);
    }

    public function test_project_record_binding_still_skips_global_filter(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'getRecordRouteBindingEloquentQuery');
        $source = $this->readMethodSource($method);

        $this->assertStringNotContainsString('applyGlobalSiteScopeToProjectQuery', $source);
        $this->assertStringContainsString('applyAccessibleSiteScope', $source);
    }

    public function test_js_store_is_tab_scoped_and_ignores_storage_events(): void
    {
        $store = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/domainContextStore.js');
        $boot = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/domain-context.js');

        $this->assertStringContainsString("seo_ops.active_domain", $store);
        $this->assertStringContainsString("seo_ops.last_domain", $store);
        $this->assertStringContainsString('urlDomain', $store);
        $this->assertStringContainsString('sessionDomain', $store);
        $this->assertStringContainsString('lastDomain', $store);
        $this->assertStringContainsString('replaceState', $boot);
        $this->assertStringContainsString('HEADER_KEY', $boot);
        $this->assertStringContainsString('X-Seo-Domain-Context', $store);
        $this->assertStringNotContainsString("addEventListener('storage'", $boot);
        $this->assertStringNotContainsString('location.reload', $boot);
        $this->assertStringNotContainsString('window.location.href =', $boot);
    }

    private function methodSource(string $class, string $method): string
    {
        return $this->readMethodSource(new ReflectionMethod($class, $method));
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
