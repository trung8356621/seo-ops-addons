<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\TopicalMapAppPage;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\RunsTopicalMapAuditAndTags;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicalMap;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapAuditController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapAuditStatusController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapChildrenController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapNetworkController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapOverviewController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapTagsController;
use Omnichannel\Addons\SearchIntelligence\SearchIntelligenceServiceProvider;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapAccess;
use Omnichannel\Addons\SearchIntelligence\Support\TopicalMapVite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Standalone React Topical Map v1 — build isolation + API boundary + manual AI contracts.
 */
final class TopicalMapReactAppContractTest extends TestCase
{
    public function test_dedicated_package_and_vite_output(): void
    {
        $addonRoot = dirname(__DIR__, 3);
        $package = json_decode((string) file_get_contents($addonRoot.'/package.json'), true);
        self::assertIsArray($package);
        self::assertSame('@omnichannel/topical-map', $package['name'] ?? null);
        self::assertArrayHasKey('echarts', $package['dependencies'] ?? []);
        self::assertArrayHasKey('react', $package['dependencies'] ?? []);

        $vite = (string) file_get_contents($addonRoot.'/vite.config.js');
        self::assertStringContainsString("buildDirectory = 'build-topical-map'", $vite);
        self::assertStringContainsString('resources/js/topical-map/topical-map-app.jsx', $vite);
        self::assertStringContainsString('port: 5175', $vite);

        $resolver = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapVite::class))->getFileName()
        );
        self::assertStringContainsString("BUILD_DIRECTORY = 'build-topical-map'", $resolver);
        self::assertStringContainsString("HOT_FILE = 'hot-topical-map'", $resolver);
    }

    public function test_root_vite_does_not_register_topical_map_entry(): void
    {
        $clientVite = dirname(__DIR__, 5).'/omnichannel-client/vite.config.js';
        if (! is_file($clientVite)) {
            $clientVite = dirname(__DIR__, 4).'/../omnichannel-client/vite.config.js';
        }
        // Junction layout: addons → peer repo; client is sibling of omnichannel-addons.
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
        $src = (string) file_get_contents((string) $found);
        self::assertStringNotContainsString('topical-map-chart.js', $src);
        self::assertStringNotContainsString('topical-map-app.jsx', $src);

        $clientPackage = dirname((string) $found).'/package.json';
        $pkg = json_decode((string) file_get_contents($clientPackage), true);
        self::assertSame('npm --prefix addons/search-intelligence run build', $pkg['scripts']['build:topical-map'] ?? null);
        self::assertSame('npm --prefix addons/search-intelligence run dev', $pkg['scripts']['dev:topical-map'] ?? null);
    }

    public function test_old_chart_js_is_retired_stub(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/topical-map-chart.js');
        self::assertStringContainsString('retired', strtolower($js));
        self::assertStringNotContainsString('echarts.init', $js);
        self::assertStringNotContainsString('openTopicDetail', $js);
    }

    public function test_react_app_owns_viewport_filters_and_dblclick(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js/topical-map';
        $app = (string) file_get_contents($root.'/pages/SiteTopicalMapPage.jsx');
        $css = (string) file_get_contents($root.'/styles/topical-map-app.css');
        $canvas = (string) file_get_contents($root.'/components/ChartCanvas.jsx');
        $filters = (string) file_get_contents($root.'/state/filters.js');
        $options = (string) file_get_contents($root.'/charts/options.js');
        $theme = (string) file_get_contents($root.'/charts/theme.js');
        $chrome = (string) file_get_contents($root.'/components/AppChrome.jsx');

        self::assertStringContainsString('100dvh', $css);
        self::assertStringContainsString('overflow: hidden', $css);
        self::assertStringNotContainsString('tm-sidebar', $css);
        self::assertStringContainsString('tm-chrome', $css);
        self::assertStringContainsString('tm-zoom', $css);
        self::assertStringContainsString('filterTopics', $app);
        self::assertStringContainsString('mcpMin', $app);
        self::assertStringContainsString('mcpMax', $app);
        self::assertStringContainsString('showUntagged', $app);
        self::assertStringContainsString('AppChrome', $app);
        self::assertStringContainsString('networkNeighborhood', $app);
        self::assertStringContainsString('buildOverviewNeighborhood', $app);
        self::assertStringContainsString('neighborhood={networkNeighborhood}', $app);
        self::assertStringNotContainsString('networkFocusedTopicId', $app);
        self::assertStringNotContainsString('focusNetworkTopic', $app);
        self::assertStringNotContainsString('rawFocusedNeighborhood', $app);
        self::assertStringNotContainsString('fetchNetwork', $app);
        self::assertStringContainsString('TagFilterControl', $chrome);
        self::assertStringContainsString('ZoomControls', $chrome);
        self::assertStringContainsString("renderer !== 'treemap'", $chrome);
        self::assertStringContainsString('ai_history_url', $chrome);
        self::assertStringContainsString('aiHistoryUrl', $chrome);
        self::assertStringContainsString('labels.aiHistory', $chrome);
        self::assertStringContainsString("target=\"_blank\"", $chrome);
        self::assertStringContainsString('noopener noreferrer', $chrome);
        self::assertStringContainsString('onBeginAiAudit', $chrome);
        // AI History is a plain <a> — must not call beginAiAudit / runAudit.
        $historyAnchor = '';
        if (preg_match('/historyUrl\s*\?[\s\S]*?<\/a>/', $chrome, $m)) {
            $historyAnchor = $m[0];
        }
        self::assertNotSame('', $historyAnchor, 'AI History anchor block missing');
        self::assertStringContainsString('href={historyUrl}', $historyAnchor);
        self::assertStringContainsString('target="_blank"', $historyAnchor);
        self::assertStringNotContainsString('onBeginAiAudit', $historyAnchor);
        self::assertStringNotContainsString('runAudit', $historyAnchor);
        self::assertStringContainsString('aiHistoryUrl={config.aiHistoryUrl', $app);

        $tagControl = (string) file_get_contents($root.'/components/TagFilterControl.jsx');
        self::assertStringContainsString('tm-tags__popover', $tagControl);
        self::assertStringContainsString('CHIP_LIMIT', $tagControl);
        self::assertStringContainsString('tm-chip--more', $tagControl);
        self::assertStringContainsString('All tags', $tagControl);
        self::assertStringNotContainsString('tagFacets.map', $chrome);
        self::assertStringContainsString('passive: false', $canvas);
        self::assertStringContainsString('applyZoomFactorRef.current', $canvas);
        self::assertStringContainsString("mode === 'treemap'", $canvas);
        self::assertStringContainsString("roam: 'move'", $options);
        self::assertStringNotContainsString('roam: true', $options);
        self::assertStringContainsString('ResizeObserver', $canvas);
        self::assertStringContainsString('zoomIn', $canvas);
        self::assertStringContainsString("window.open(url, '_blank', 'noopener,noreferrer')", $canvas);
        self::assertStringNotContainsString('window.location.href', $canvas);
        self::assertStringContainsString('normalizeMcpRange', $filters);
        self::assertStringContainsString('selected.includes', $filters);
        self::assertStringContainsString('pruneNeighborhoodByAllowedTopics', $filters);
        self::assertStringContainsString("startsWith('dna:')", $filters);
        self::assertStringContainsString('focused_topic', $filters);
        self::assertStringContainsString('pickPrimaryTag', $options);
        self::assertStringContainsString("orient: 'BT'", $options);
        self::assertStringContainsString("nodeType: 'tag'", $options);
        self::assertStringContainsString('Click to open Topic', $options);
        self::assertStringContainsString('buildOverviewNeighborhood', $options);
        self::assertStringContainsString('buildTreemapOption', $options);
        self::assertStringContainsString('TREEMAP_MIN_VISUAL_MCP_WEIGHT', $options);
        self::assertStringContainsString('formatTreemapMcpPercent', $options);
        self::assertStringContainsString('formatTreemapTopicLabel', $options);
        self::assertStringContainsString('formatTreemapTopicLabelRich', $options);
        self::assertStringContainsString('assignTreemapLabelTiers', $options);
        self::assertStringContainsString('treemapLayoutValue', $options);
        self::assertStringContainsString('Topic distribution by MCP share', $options);
        self::assertStringContainsString('Focus Articles:', $options);
        self::assertStringContainsString("overflow: 'truncate'", $options);
        self::assertStringContainsString('fontSize: 18', $options);
        self::assertStringContainsString('fontSize: 12', $options);
        self::assertStringContainsString('nodeClick: false', $options);
        self::assertMatchesRegularExpression('/breadcrumb:\s*\{\s*show:\s*false/', $options);
        self::assertStringContainsString("name: 'DNA'", $options);
        self::assertStringContainsString('layoutAnimation: false', $options);
        self::assertStringContainsString('networkTopicSymbolSize', $options);
        self::assertStringContainsString("nodeType === 'dna'", $options);
        self::assertStringNotContainsString('Back to all Topics', $options);
        self::assertStringNotContainsString('siteNavigable', $options);
        self::assertStringNotContainsString("name: 'keyword'", $options);
        self::assertStringContainsString('TreemapChart', $canvas);
        self::assertStringNotContainsString('SunburstChart', $canvas);
        self::assertStringContainsString("viewRaw === 'sunburst'", $filters);
        self::assertStringContainsString("viewRaw === 'structure'", $filters);
        self::assertStringContainsString("'treemap'", $filters);
        self::assertStringContainsString('scaleDisabled', $chrome);
        self::assertStringContainsString('MCP_SYMBOL_MIN', $theme);
        self::assertStringContainsString('TOPIC_PALETTE', $theme);
        self::assertStringContainsString('Math.sqrt', $theme);
        self::assertStringContainsString('networkTopicSymbolSize', $theme);
        self::assertStringContainsString('NETWORK_TOPIC_SYMBOL_MAX', $theme);
        self::assertStringContainsString('NETWORK_DNA_SYMBOL_SIZE', $theme);
        self::assertStringContainsString('topicStructureLabel', $theme);
        self::assertStringContainsString('STRUCTURE_SYMBOL_SIZE', $theme);
        self::assertStringContainsString('tagColorById', $theme);
        self::assertStringContainsString('mcpToSymbolSize', $theme);
        self::assertStringContainsString('sortKeywordsByWordCount', $theme);
        self::assertStringContainsString('phraseWordCount', $theme);
        self::assertStringContainsString('preferredTagIds', $app);
        self::assertStringContainsString('structurePreferredTagIds', $app);
        self::assertStringNotContainsString('onLoadChildren', $app);
        self::assertStringNotContainsString('fetchTopicChildren', $app);
        self::assertStringContainsString("renderer === 'tree'", $canvas);
        self::assertStringContainsString("renderer === 'network'", $canvas);
        self::assertStringNotContainsString('onNetworkTopicClick', $canvas);
        self::assertStringNotContainsString('networkFocused', $canvas);
        self::assertStringContainsString('Click to open Topic', $options);
        self::assertStringContainsString("nodeType: 'untagged_bucket'", $options);
        self::assertStringContainsString('STRUCTURE_UNTAGGED_BUCKET_KEY', $options);
        self::assertStringContainsString('presentation-only', $options);
    }

    public function test_api_controllers_delegate_to_read_model_and_enforce_access(): void
    {
        foreach ([
            TopicalMapOverviewController::class,
            TopicalMapChildrenController::class,
            TopicalMapNetworkController::class,
            TopicalMapTagsController::class,
            TopicalMapAuditStatusController::class,
        ] as $class) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            self::assertStringContainsString('assertCanAccessSite', $src);
            self::assertStringNotContainsString('->audit(', $src);
        }

        $audit = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditController::class))->getFileName()
        );
        self::assertStringContainsString('assertCanMutateSite', $audit);
        self::assertStringContainsString('->audit(', $audit);
        self::assertStringContainsString('Manual AI Audit', $audit);

        $provider = (string) file_get_contents(
            (string) (new ReflectionClass(SearchIntelligenceServiceProvider::class))->getFileName()
        );
        self::assertStringContainsString("prefix('seo/topical-map/api')", $provider);
        self::assertStringContainsString('TopicalMapAuditController', $provider);
        self::assertStringContainsString('Authenticate::class', $provider);
    }

    public function test_shell_page_and_legacy_redirect(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAppPage::class))->getFileName()
        );
        self::assertStringContainsString("slug = 'topical-map'", $page);
        self::assertStringContainsString('bootstrapConfig', $page);
        self::assertStringContainsString('endpoints', $page);
        self::assertStringContainsString('aiHistoryUrl', $page);
        self::assertStringContainsString('resolveAiHistoryUrl', $page);
        self::assertStringContainsString('draft_ai_history_link', $page);
        self::assertStringNotContainsString('overview()->toArray()', $page);
        self::assertStringNotContainsString('ensureSharedDraft', $page);

        $blade = dirname(__DIR__, 3).'/resources/views/filament/pages/topical-map-app.blade.php';
        $bladeSrc = (string) file_get_contents($blade);
        self::assertStringContainsString('topical-map-app-root', $bladeSrc);
        self::assertStringContainsString('TopicalMapVite', $bladeSrc);
        self::assertStringContainsString('100dvh', $bladeSrc);

        $legacy = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordTopicalMap::class))->getFileName()
        );
        self::assertStringContainsString('TopicalMapAppPage::appUrl', $legacy);
        self::assertStringContainsString('redirect', $legacy);
        self::assertStringNotContainsString('RunsTopicalMapAuditAndTags', $legacy);
        self::assertStringNotContainsString('loadTopicChildren', $legacy);
    }

    public function test_topics_heading_opens_react_app_blank(): void
    {
        $nav = (string) file_get_contents(
            (string) (new ReflectionClass(HasKeywordWorkspaceNavigation::class))->getFileName()
        );
        self::assertStringNotContainsString("'key' => 'topical-map'", $nav);
        self::assertStringNotContainsString('TopicalMapAppPage::appUrl', $nav);

        $page = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters::class))->getFileName()
        );
        self::assertStringContainsString('function getTopicalMapUrl', $page);
        self::assertStringContainsString('TopicalMapAppPage::appUrl', $page);
        self::assertStringContainsString('resolveKeywordWorkspaceSiteId', $page);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('getTopicalMapUrl()', $blade);
        self::assertStringContainsString('topic-index-section-heading__map-link', $blade);
        self::assertStringContainsString('target="_blank"', $blade);
        self::assertStringContainsString('rel="noopener noreferrer"', $blade);
        self::assertStringContainsString('workspace_nav_topical_map', $blade);
        self::assertStringContainsString('heroicon-o-arrow-top-right-on-square', $blade);

        $tabs = dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/components/workspace-tabs.blade.php';
        $tabsSrc = (string) file_get_contents($tabs);
        self::assertStringContainsString("target=\"{{ \$item['target'] }}\"", $tabsSrc);
    }

    public function test_topics_manual_ai_trait_still_present(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName()
        );
        self::assertStringContainsString('beginConfirmAiAudit', $trait);
        self::assertStringContainsString('confirmRunAiAuditAndTags', $trait);

        $access = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAccess::class))->getFileName()
        );
        self::assertStringContainsString('canAccessSite', $access);
        self::assertStringContainsString('NotFoundHttpException', $access);
    }
}
