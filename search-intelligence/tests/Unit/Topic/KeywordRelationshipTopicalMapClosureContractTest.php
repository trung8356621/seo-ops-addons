<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordRelationshipAppPage;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\TopicalMapAppPage;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordRelationshipView;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\ListKeywords;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapKeywordRelationshipController;
use Omnichannel\Addons\SearchIntelligence\SearchIntelligenceServiceProvider;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGraphPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keyword Relationship visualization moved into standalone Topical Map React app.
 */
final class KeywordRelationshipTopicalMapClosureContractTest extends TestCase
{
    public function test_relationship_api_uses_gateway_and_presenter_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapKeywordRelationshipController::class))->getFileName()
        );
        self::assertStringContainsString('KeywordRelationshipGateway', $src);
        self::assertStringContainsString('KeywordRelationshipGraphPresenter', $src);
        self::assertStringContainsString('assertCanAccessSite', $src);
        self::assertStringNotContainsString('SeoTopicKeyword::', $src);
        self::assertStringNotContainsString('SeoLinkMap::', $src);
        self::assertStringNotContainsString('SeoGscQueryMapping::', $src);
        self::assertStringNotContainsString('Http::', $src);
    }

    public function test_api_route_registered_under_topical_map_prefix(): void
    {
        $provider = (string) file_get_contents(
            (string) (new ReflectionClass(SearchIntelligenceServiceProvider::class))->getFileName()
        );
        self::assertStringContainsString("prefix('seo/topical-map/api')", $provider);
        self::assertStringContainsString('keywords/{keyword}/relationship', $provider);
        self::assertStringContainsString('TopicalMapKeywordRelationshipController', $provider);
        self::assertStringContainsString('Authenticate::class', $provider);
    }

    public function test_site_and_keyword_shell_modes(): void
    {
        $site = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAppPage::class))->getFileName()
        );
        self::assertStringContainsString("'mode' => 'site'", $site);
        self::assertStringContainsString("slug = 'topical-map'", $site);

        $kw = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipAppPage::class))->getFileName()
        );
        self::assertStringContainsString("slug = 'topical-map/keyword/{keyword}'", $kw);
        self::assertStringContainsString("'mode' => 'keyword-relationship'", $kw);
        self::assertStringContainsString('KeywordRelationshipGateway', $kw);
        self::assertStringContainsString('topical-map-app', $kw);
        self::assertStringContainsString('appUrl', $kw);
    }

    public function test_legacy_relationship_route_redirects_to_standalone(): void
    {
        $pages = KeywordResource::getPages();
        self::assertArrayHasKey('relationships', $pages);

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipView::class))->getFileName()
        );
        self::assertStringContainsString('KeywordRelationshipAppPage::appUrl', $src);
        self::assertStringContainsString('redirect', $src);
        self::assertStringNotContainsString('KeywordRelationshipGraphPresenter', $src);
        self::assertStringNotContainsString('toggleCategory', $src);
    }

    public function test_dictionary_and_topic_item_actions_point_to_new_route(): void
    {
        $listSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ListKeywords::class))->getFileName()
        );
        self::assertStringContainsString('KeywordRelationshipAppPage::appUrl', $listSrc);
        self::assertStringContainsString('openUrlInNewTab', $listSrc);
        self::assertStringNotContainsString("getUrl('relationships'", $listSrc);

        $itemBlade = dirname(__DIR__, 4)
            .'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php';
        self::assertFileExists($itemBlade);
        $itemSrc = (string) file_get_contents($itemBlade);
        self::assertStringContainsString('KeywordRelationshipAppPage::appUrl', $itemSrc);
        self::assertStringContainsString('target="_blank"', $itemSrc);
        self::assertStringContainsString('relationship_action', $itemSrc);
    }

    public function test_react_app_router_and_relationship_page_contracts(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js/topical-map';
        $app = (string) file_get_contents($root.'/App.jsx');
        $entry = (string) file_get_contents($root.'/topical-map-app.jsx');
        $page = (string) file_get_contents($root.'/pages/KeywordRelationshipPage.jsx');
        $site = (string) file_get_contents($root.'/pages/SiteTopicalMapPage.jsx');
        $chrome = (string) file_get_contents($root.'/components/RelationshipChrome.jsx');
        $chart = (string) file_get_contents($root.'/components/RelationshipChart.jsx');
        $filters = (string) file_get_contents($root.'/state/relationshipFilters.js');
        $css = (string) file_get_contents($root.'/styles/topical-map-app.css');

        self::assertStringContainsString('AppRouter', $entry);
        self::assertStringContainsString('keyword-relationship', $app);
        self::assertStringContainsString('SiteTopicalMapPage', $app);
        self::assertStringContainsString('KeywordRelationshipPage', $app);

        self::assertStringContainsString('AppChrome', $site);
        self::assertStringContainsString('mcpMin', $site);
        self::assertStringContainsString('onRendererChange', $site);
        self::assertStringContainsString('onBeginAiAudit', $site);
        self::assertStringNotContainsString('TagFilterControl', $page);
        self::assertStringNotContainsString('mcpMin', $page);
        self::assertStringNotContainsString('onRendererChange', $page);
        self::assertStringNotContainsString('onBeginAiAudit', $page);
        self::assertStringNotContainsString('RelationshipChrome', $site);

        self::assertStringContainsString('DEFAULT_REL_FILTERS', $filters);
        self::assertStringContainsString('topic: true', $filters);
        self::assertStringContainsString('gsc: false', $filters);
        self::assertStringContainsString('internal_link: false', $filters);
        self::assertStringContainsString('planning: false', $filters);
        self::assertStringContainsString('filterGraphByCategories', $filters);
        self::assertStringContainsString('isSectionAvailable', $filters);

        self::assertStringContainsString('tm-rel-filters', $chrome);
        self::assertStringNotContainsString('TagFilterControl', $chrome);
        self::assertStringNotContainsString('tm-mcp-filter', $chrome);
        self::assertStringNotContainsString('tm-renderer', $chrome);
        self::assertStringNotContainsString('onBeginAiAudit', $chrome);

        self::assertStringContainsString('GraphChart', $chart);
        self::assertStringContainsString('tm-rel-overlay', $chart);
        self::assertStringContainsString('ResizeObserver', $chart);
        self::assertStringContainsString('passive: false', $chart);
        self::assertStringContainsString("window.open(url, '_blank', 'noopener,noreferrer')", $chart);
        self::assertStringContainsString('relationshipUrl', $chart);
        self::assertStringContainsString('window.location.href', $chart);

        self::assertStringContainsString('tm-rel-overlay', $css);
        self::assertStringContainsString('100dvh', $css);
        self::assertStringContainsString('overflow: hidden', $css);
    }

    public function test_old_relationship_js_retired_and_root_vite_cleared(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/keyword-relationship-chart.js');
        self::assertStringContainsString('retired', strtolower($js));
        self::assertStringNotContainsString('echarts.init', $js);
        self::assertStringNotContainsString('GraphChart', $js);

        $blade = dirname(__DIR__, 4)
            .'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-relationship.blade.php';
        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringNotContainsString('keyword-relationship-chart.js', $bladeSrc);
        self::assertStringNotContainsString('@vite', $bladeSrc);

        $candidates = [
            dirname(__DIR__, 5).'/omnichannel-client/vite.config.js',
            dirname(__DIR__, 4).'/omnichannel-client/vite.config.js',
            'D:/work/omnichannel-client/vite.config.js',
        ];
        $found = null;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $found = $path;
                break;
            }
        }
        self::assertNotNull($found, 'omnichannel-client vite.config.js not found');
        $vite = (string) file_get_contents((string) $found);
        self::assertStringNotContainsString('keyword-relationship-chart.js', $vite);
        self::assertStringNotContainsString('topical-map-app.jsx', $vite);
    }

    public function test_presenter_defaults_unchanged(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipGraphPresenter::class))->getFileName()
        );
        self::assertStringContainsString("'topic' => true", $src);
        self::assertStringContainsString("'article' => true", $src);
        self::assertStringContainsString("'dna' => true", $src);
        self::assertStringContainsString("'related_keyword' => true", $src);
        self::assertStringContainsString("'gsc' => false", $src);
        self::assertStringContainsString("'internal_link' => false", $src);
        self::assertStringContainsString("'planning' => false", $src);
        self::assertStringContainsString("'symbolSize' => 56", $src);
    }

    public function test_gateway_remains_relationship_boundary(): void
    {
        self::assertTrue(class_exists(KeywordRelationshipGateway::class));
        $gatewaySrc = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipGateway::class))->getFileName()
        );
        self::assertStringContainsString('KeywordRelationshipReadModel', $gatewaySrc);
        self::assertStringContainsString('forKeyword', $gatewaySrc);
    }
}
