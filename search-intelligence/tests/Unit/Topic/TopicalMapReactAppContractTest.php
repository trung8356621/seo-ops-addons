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
        self::assertStringContainsString("'Inter'", $css);
        self::assertStringNotContainsString('tm-sidebar', $css);
        self::assertStringContainsString('tm-chrome', $css);
        self::assertStringContainsString('tm-zoom', $css);
        self::assertStringContainsString('filterTopics', $app);
        self::assertStringContainsString('mcpMin', $app);
        self::assertStringContainsString('mcpMax', $app);
        self::assertStringContainsString('showUntagged', $app);
        self::assertStringContainsString('AppChrome', $app);
        self::assertStringContainsString('networkNeighborhood', $app);
        self::assertStringContainsString('fullNetworkNeighborhood', $app);
        self::assertStringContainsString('buildOverviewNeighborhood', $app);
        self::assertStringContainsString('buildFocusedNetworkNeighborhood', $app);
        self::assertStringContainsString('neighborhood={networkNeighborhood}', $app);
        self::assertStringNotContainsString('networkFocusedTopicId', $app);
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
        self::assertStringContainsString('requestAnimationFrame', $canvas);
        self::assertStringContainsString('cancelAnimationFrame', $canvas);
        self::assertStringContainsString('pendingZoomFactorRef', $canvas);
        self::assertStringContainsString('zoomRef.current', $canvas);
        self::assertStringNotContainsString('readSeriesZoom', $canvas);
        // Wheel/applyZoom uses zoomRef; getOption only for infrequent roam sync.
        self::assertSame(1, substr_count($canvas, 'getOption()'));
        self::assertStringContainsString('syncRoamFromEvent', $canvas);
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
        self::assertStringContainsString('getTreemapTypographyRich', $options);
        self::assertStringContainsString('buildStructureTypographyPatch', $options);
        self::assertStringContainsString('buildNetworkTypographyPatch', $options);
        self::assertStringContainsString('CHART_FONT_TOOLTIP', $options);
        self::assertStringContainsString('CHART_FONT_FAMILY', $theme);
        self::assertStringContainsString('CHART_FONT_FAMILY', $options);
        self::assertStringContainsString('fontSize: 18', $theme);
        self::assertStringContainsString('getChartTypographyBand', $theme);
        self::assertStringContainsString('CHART_FONT_MAX_ZOOM', $theme);
        self::assertStringContainsString('typographyBandRef', $canvas);
        self::assertStringContainsString('applyTypographyBandIfNeeded', $canvas);
        self::assertStringContainsString('buildNetworkTypographyPatch', $canvas);
        self::assertStringContainsString('nodeClick: false', $options);
        self::assertMatchesRegularExpression('/breadcrumb:\s*\{\s*show:\s*false/', $options);
        self::assertStringContainsString("name: 'DNA'", $options);
        self::assertStringContainsString("layout: 'none'", $options);
        self::assertStringNotContainsString("layout: 'force'", $options);
        self::assertStringNotContainsString('layoutAnimation:', $options);
        self::assertDoesNotMatchRegularExpression('/\bforce:\s*\{/', $options);
        self::assertStringContainsString('assignNetworkFixedCoordinates', $options);
        self::assertStringContainsString('networkLayout', $options);
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
        self::assertStringContainsString('NETWORK_DNA_SYMBOL_MIN', $theme);
        self::assertStringContainsString('NETWORK_DNA_SYMBOL_MAX', $theme);
        self::assertStringContainsString('networkDnaSymbolSize', $theme);
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
        self::assertStringContainsString('focusNetworkTopic', $canvas);
        self::assertStringContainsString('openTopicBlank', $canvas);
        self::assertStringContainsString('clearNetworkFocus', $canvas);
        self::assertStringContainsString('NETWORK_CLICK_DELAY_MS', $canvas);
        self::assertStringContainsString('Click to open Topic', $options);
        self::assertStringContainsString('Single click: Focus', $options);
        self::assertStringContainsString('Double click: Open Topic', $options);
        self::assertStringContainsString('Back to full Network', $options);
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
        self::assertStringContainsString('fonts.googleapis.com', $bladeSrc);
        self::assertStringContainsString('family=Inter:wght@400;500;600;700', $bladeSrc);

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

    public function test_network_fixed_layout_and_zoom_perf_contracts(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js/topical-map';
        $options = (string) file_get_contents($root.'/charts/options.js');
        $layout = (string) file_get_contents($root.'/charts/networkLayout.js');
        $canvas = (string) file_get_contents($root.'/components/ChartCanvas.jsx');
        $theme = (string) file_get_contents($root.'/charts/theme.js');
        $app = (string) file_get_contents($root.'/pages/SiteTopicalMapPage.jsx');

        self::assertStringContainsString("layout: 'none'", $options);
        self::assertStringNotContainsString("layout: 'force'", $options);
        self::assertDoesNotMatchRegularExpression('/\bforce:\s*\{/', $options);
        self::assertStringNotContainsString('blur:', $options);
        self::assertStringContainsString("focus: 'none'", $options);
        self::assertStringNotContainsString("focus: 'adjacency'", $options);
        self::assertStringContainsString('NETWORK_MAX_DNA_NODES', $options);
        self::assertStringContainsString('NETWORK_MAX_DNA_NODES = 1500', $theme);
        self::assertStringContainsString('networkTopicSymbolSize', $options);
        self::assertStringContainsString('networkDnaSymbolSize', $options);
        self::assertStringContainsString('buildFocusedNetworkNeighborhood', $options);
        self::assertStringContainsString('assignFocusedNetworkCoordinates', $options);
        self::assertStringContainsString('compareTopicsForNetworkLayout', $layout);
        self::assertStringContainsString('assignNetworkFixedCoordinates', $layout);
        self::assertStringContainsString('assignFocusedNetworkCoordinates', $layout);
        self::assertStringContainsString('dnaSatelliteRadius', $layout);
        self::assertStringContainsString('DNA_ORBIT_BASE', $layout);
        self::assertStringContainsString('DNA_ORBIT_RADIUS_SCALE', $layout);
        self::assertStringContainsString('placeDnaInOrganicCluster', $layout);
        self::assertStringContainsString('DNA_GOLDEN_ANGLE', $layout);
        self::assertStringContainsString('labelPositionFromAngle', $layout);
        self::assertStringContainsString('resolveDnaLayoutMetrics', $layout);
        self::assertStringContainsString('DNA_AIR_GAP_MIN', $layout);
        self::assertStringContainsString('DNA_LABEL_CLEARANCE', $layout);
        self::assertStringContainsString('DNA_CLUSTER_SPREAD', $layout);
        self::assertStringContainsString('clusterInner', $layout);
        self::assertStringNotContainsString('placeDnaAroundTopic', $layout);
        self::assertStringContainsString('planTopicRingCapacities', $layout);
        self::assertStringContainsString('NETWORK_DNA_SYMBOL_FOCUS_MAX', $theme);
        self::assertStringContainsString('NETWORK_TOPIC_SYMBOL_MAX = 60', $theme);
        self::assertStringContainsString('dnaNetworkLabel', $options);
        self::assertStringContainsString('hideOverlap', $options);
        self::assertStringContainsString('silent: true', $options);
        self::assertStringContainsString("position: 'bottom'", $options);
        // Network series default is outside-bottom; Treemap may still use inside*.
        if (preg_match('/export function buildNetworkTypographyPatch\([\s\S]*?\n\}/', $options, $m)) {
            self::assertStringContainsString("position: 'bottom'", $m[0]);
            self::assertStringContainsString('hideOverlap', $m[0]);
            self::assertStringNotContainsString("'inside'", $m[0]);
        } else {
            self::fail('buildNetworkTypographyPatch not found');
        }
        self::assertStringContainsString('requestAnimationFrame', $canvas);
        self::assertStringContainsString('cancelAnimationFrame', $canvas);
        self::assertSame(1, substr_count($canvas, 'getOption()'));
        self::assertStringNotContainsString('readSeriesZoom', $canvas);
        self::assertStringContainsString("renderer === 'treemap'", $canvas);
        self::assertStringContainsString('focusNetworkTopic', $canvas);
        self::assertStringContainsString('openTopicBlank', $canvas);
        self::assertStringContainsString('[siteId, filteredTopics, config.siteDomain]', $app);
        self::assertStringContainsString('fullNetworkNeighborhood', $app);
        self::assertStringContainsString('buildFocusedNetworkNeighborhood', $app);

        $theme = (string) file_get_contents($root.'/charts/theme.js');
        self::assertStringContainsString("z < 0.75", $theme);
        self::assertStringContainsString("z < 1.5", $theme);
        self::assertStringContainsString("z < 2.5", $theme);
        self::assertStringContainsString('getStructureTypographyBand', $theme);
        self::assertStringContainsString('fontSize: 20', $theme);
        self::assertStringContainsString('width: 220', $theme);
        self::assertStringContainsString("z < 3.5", $theme);
        self::assertStringContainsString('getStructureTypographyBand', $canvas);

        $optionsSrc = (string) file_get_contents($root.'/charts/options.js');
        if (preg_match('/function buildStructureTopicNode\([\s\S]*?\n\}/', $optionsSrc, $m)) {
            self::assertStringNotContainsString('fontSize:', $m[0], 'Topic node must not hard-code fontSize');
            self::assertDoesNotMatchRegularExpression('/^\s*label:\s*\{/m', $m[0], 'Topic node must not set per-node label');
        } else {
            self::fail('buildStructureTopicNode not found');
        }
        if (preg_match('/function buildStructureTagNode\([\s\S]*?\n\}/', $optionsSrc, $m)) {
            self::assertStringNotContainsString('fontSize:', $m[0]);
            self::assertDoesNotMatchRegularExpression('/^\s*label:\s*\{/m', $m[0]);
        }
        self::assertStringContainsString('topic: 16', $theme);

        $node = trim((string) shell_exec('node -v 2>&1'));
        if ($node === '' || ! str_starts_with($node, 'v')) {
            self::markTestSkipped('node unavailable for deterministic layout check');
        }

        $script = <<<'JS'
import {
  buildOverviewNeighborhood,
  buildFocusedNetworkNeighborhood,
  buildNetworkOption,
} from './options.js';
import {
  networkTopicSymbolSize,
  networkDnaSymbolSize,
  NETWORK_TOPIC_SYMBOL_MIN,
  NETWORK_TOPIC_SYMBOL_MAX,
  NETWORK_DNA_SYMBOL_MIN,
  NETWORK_DNA_SYMBOL_MAX,
  NETWORK_DNA_SYMBOL_FOCUS_MIN,
  NETWORK_DNA_SYMBOL_FOCUS_MAX,
  NETWORK_MAX_DNA_NODES,
} from './theme.js';
import {
  resolveDnaLayoutMetrics,
  DNA_AIR_GAP_MIN,
} from './networkLayout.js';

const topics = [];
for (let i = 1; i <= 12; i += 1) {
  const dna = [];
  for (let d = 0; d < (i % 5) + 2; d += 1) {
    dna.push({ phrase: `dna-${i}-${d}`, weight: 1 });
  }
  topics.push({
    id: i,
    name: `Topic ${String.fromCharCode(65 + (12 - i))}`,
    mcp: (13 - i) * 3.5,
    dna,
    dna_count: dna.length,
    article_count: i,
    keyword_count: i * 2,
    tags: [],
  });
}

const a = buildOverviewNeighborhood(7, topics, { siteDomain: 'example.test' });
const b = buildOverviewNeighborhood(7, topics, { siteDomain: 'example.test' });
const site = a.nodes.find((n) => n.category === 'site');
if (!site || site.x !== 0 || site.y !== 0) throw new Error('site not at origin');
const topicsA = a.nodes.filter((n) => n.category === 'topic');
const dnaA = a.nodes.filter((n) => n.category === 'dna');
if (topicsA.length !== 12) throw new Error('topic count');
if (dnaA.length !== a.showing_dna) throw new Error('dna count mismatch');
if (a.showing_dna > NETWORK_MAX_DNA_NODES) throw new Error('over cap');
for (const n of a.nodes) {
  if (!Number.isFinite(n.x) || !Number.isFinite(n.y)) throw new Error('missing coords '+n.id);
}
for (let i = 0; i < a.nodes.length; i += 1) {
  if (a.nodes[i].x !== b.nodes[i].x || a.nodes[i].y !== b.nodes[i].y) {
    throw new Error('non-deterministic '+a.nodes[i].id);
  }
}

const maxMcp = Math.max(...topics.map((t) => t.mcp));
const largeTopic = topicsA.find((t) => Number(String(t.id).replace(/^topic:/, '')) === 1);
const smallTopic = topicsA.find((t) => Number(String(t.id).replace(/^topic:/, '')) === 12);
if (!largeTopic || !smallTopic) throw new Error('missing sized topics');
const largeSize = networkTopicSymbolSize(largeTopic.mcp, maxMcp);
const smallSize = networkTopicSymbolSize(smallTopic.mcp, maxMcp);
if (largeSize <= smallSize) throw new Error('topic MCP size hierarchy broken');
if (largeSize > NETWORK_TOPIC_SYMBOL_MAX || smallSize < NETWORK_TOPIC_SYMBOL_MIN) {
  throw new Error('topic size out of range');
}

const largeDna = dnaA.filter((d) => Number(d.topic_id) === 1);
const smallDna = dnaA.filter((d) => Number(d.topic_id) === 12);
if (largeDna.length === 0 || smallDna.length === 0) throw new Error('missing dna samples');
const largeDnaSize = networkDnaSymbolSize(largeSize);
const smallDnaSize = networkDnaSymbolSize(smallSize);
if (largeDnaSize < smallDnaSize) throw new Error('dna should scale with parent');
if (largeDnaSize < NETWORK_DNA_SYMBOL_MIN || largeDnaSize > NETWORK_DNA_SYMBOL_MAX) {
  throw new Error('dna size out of range '+largeDnaSize);
}
if (largeDnaSize >= largeSize) throw new Error('dna must stay smaller than topic');

const largeMetrics = resolveDnaLayoutMetrics(largeSize, largeDna.length, 'overview');
const smallMetrics = resolveDnaLayoutMetrics(smallSize, smallDna.length, 'overview');
const largeOrbit = largeMetrics.clusterOuter;
const smallOrbit = smallMetrics.clusterOuter;
if (largeOrbit <= smallOrbit) throw new Error('large topic cluster must be bigger');
if (largeMetrics.airGap < DNA_AIR_GAP_MIN.overview) {
  throw new Error('overview airGap below hard min');
}

const largeRadii = largeDna.map((d) => Math.hypot(d.x - largeTopic.x, d.y - largeTopic.y));
for (const dist of largeRadii) {
  if (dist < largeMetrics.clusterInner - 0.5) {
    throw new Error('dna inside clusterInner '+dist);
  }
  // Ellipse stretch may push past circular clusterOuter along the long axis.
  if (dist > largeMetrics.clusterOuter * 1.4 + 1) {
    throw new Error('dna outside cluster outer bound '+dist);
  }
}
// Organic cluster: radii must vary (not equal-radius ring/arc).
const rMin = Math.min(...largeRadii);
const rMax = Math.max(...largeRadii);
if (largeDna.length >= 3 && (rMax - rMin) < 4) {
  throw new Error('dna radii too uniform — looks like a ring');
}
if (largeTopic.labelPosition !== 'bottom') {
  throw new Error('topic label must be bottom');
}
if (largeDna.some((d) => d.labelPosition !== 'left' && d.labelPosition !== 'right')) {
  throw new Error('dna labels must be left/right');
}

const focused = buildFocusedNetworkNeighborhood(a, 1);
if (!focused) throw new Error('focused null');
if (focused.nodes.filter((n) => n.category === 'topic').length !== 1) {
  throw new Error('focused topic count');
}
if (focused.nodes.some((n) => n.category === 'topic' && Number(String(n.id).replace(/^topic:/, '')) !== 1)) {
  throw new Error('wrong focused topic');
}
if (focused.nodes.some((n) => n.category === 'dna' && Number(n.topic_id) !== 1)) {
  throw new Error('unrelated dna in focus');
}
if (!focused.nodes.some((n) => n.category === 'site')) throw new Error('focused missing site');
const fTopic = focused.nodes.find((n) => n.category === 'topic');
if (!fTopic || fTopic.x !== 0 || fTopic.y !== 0) throw new Error('focused topic not centered');
if (fTopic.labelPosition !== 'bottom') throw new Error('focused topic label must be bottom');
const fSite = focused.nodes.find((n) => n.category === 'site');
if (!fSite || fSite.y <= 0) throw new Error('focused site not below topic');
const fDna = focused.nodes.filter((n) => n.category === 'dna');
const focusMetrics = resolveDnaLayoutMetrics(largeSize, fDna.length, 'focus');
const fRadii = fDna.map((d) => Math.hypot(d.x, d.y));
for (const d of fDna) {
  const dist = Math.hypot(d.x, d.y);
  if (dist < focusMetrics.clusterInner - 0.5) {
    throw new Error('focused dna too close to topic');
  }
  if (Number(d.symbolSizeHint) < NETWORK_DNA_SYMBOL_FOCUS_MIN) {
    throw new Error('focused dna size too small '+d.symbolSizeHint);
  }
  if (Number(d.symbolSizeHint) > NETWORK_DNA_SYMBOL_FOCUS_MAX) {
    throw new Error('focused dna size too large '+d.symbolSizeHint);
  }
}
if (focusMetrics.clusterOuter <= largeOrbit) {
  throw new Error('focus cluster must be larger than overview');
}
if (focusMetrics.childSize <= largeDnaSize) {
  throw new Error('focus dna must be larger than overview dna');
}
if (fDna.length >= 3) {
  const frMin = Math.min(...fRadii);
  const frMax = Math.max(...fRadii);
  if ((frMax - frMin) < 4) {
    throw new Error('focused dna radii too uniform');
  }
}
const aAgain = buildOverviewNeighborhood(7, topics, { siteDomain: 'example.test' });
if (aAgain.nodes.find((n) => n.id === largeTopic.id).x !== largeTopic.x) {
  throw new Error('full neighborhood mutated by focus build');
}

const missing = buildFocusedNetworkNeighborhood(a, 99999);
if (missing !== null) throw new Error('missing topic should return null');

const opt = buildNetworkOption(a, { siteDomain: 'example.test' });
if (opt.series[0].layout !== 'none') throw new Error('layout');
if (opt.series[0].force) throw new Error('force present');
if (opt.series[0].blur) throw new Error('blur present');
if (opt.series[0].emphasis?.focus !== 'none') throw new Error('emphasis focus must be none');
if (opt.series[0].label?.position !== 'bottom') throw new Error('series label default bottom');
if (!opt.series[0].labelLayout?.hideOverlap) throw new Error('hideOverlap required');
if (opt.series[0].label?.silent !== true) throw new Error('labels must be silent');
const topicOpt = opt.series[0].data.find((d) => d.nodeType === 'topic');
if (!topicOpt?.label || topicOpt.label.position === 'inside') {
  throw new Error('topic option label must be outside');
}
if (topicOpt.label.position !== 'bottom') {
  throw new Error('topic option label must be bottom');
}
const dnaOpt = opt.series[0].data.filter((d) => d.nodeType === 'dna');
if (dnaOpt.some((d) => !d.label?.show)) {
  throw new Error('overview dna labels should show (hideOverlap cleans)');
}
if (dnaOpt.some((d) => d.label?.position !== 'left' && d.label?.position !== 'right')) {
  throw new Error('dna option labels must be left/right');
}

const dnaOptSizes = dnaOpt.map((d) => d.symbolSize);
if (dnaOptSizes.some((s) => s < NETWORK_DNA_SYMBOL_MIN || s > NETWORK_DNA_SYMBOL_MAX)) {
  throw new Error('option dna size out of range');
}

const focusedOpt = buildNetworkOption(focused, { siteDomain: 'example.test', focused: true });
if (focusedOpt.series[0].layout !== 'none') throw new Error('focused layout');
if (focusedOpt.series[0].force) throw new Error('focused force');
if (focusedOpt.series[0].labelLayout?.hideOverlap) {
  throw new Error('focused mode must not aggressively hideOverlap');
}
const fTopicOpt = focusedOpt.series[0].data.find((d) => d.nodeType === 'topic');
if (fTopicOpt?.label?.position !== 'bottom') throw new Error('focused option label bottom');
const fDnaOpt = focusedOpt.series[0].data.filter((d) => d.nodeType === 'dna');
if (fDnaOpt.some((d) => !d.label?.show)) {
  throw new Error('focused dna labels must show');
}
const fDnaOptSizes = fDnaOpt.map((d) => d.symbolSize);
if (fDnaOptSizes.some((s) => s < NETWORK_DNA_SYMBOL_FOCUS_MIN || s > NETWORK_DNA_SYMBOL_FOCUS_MAX)) {
  throw new Error('focused option dna size out of range');
}

console.log(JSON.stringify({
  topics: topicsA.length,
  dna: dnaA.length,
  total: a.nodes.length,
  truncated: a.dna_truncated,
  maxDna: NETWORK_MAX_DNA_NODES,
  largeSize,
  smallSize,
  largeDnaSize,
  smallDnaSize,
  largeOrbit: Math.round(largeOrbit),
  smallOrbit: Math.round(smallOrbit),
  largeAirGap: Math.round(largeMetrics.airGap),
  radiusSpread: Math.round(rMax - rMin),
  focusOrbit: Math.round(focusMetrics.clusterOuter),
  focusChild: focusMetrics.childSize,
  focusedNodes: focused.nodes.length,
  focusedDna: focused.showing_dna,
}));
JS;

        $chartsDir = $root.'/charts';
        $tmp = $chartsDir.'/_network_layout_smoke.mjs';
        file_put_contents($tmp, $script);
        try {
            $out = [];
            $code = 0;
            exec('node '.escapeshellarg($tmp).' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            $payload = json_decode(implode("\n", $out), true);
            self::assertIsArray($payload);
            self::assertSame(12, $payload['topics']);
            self::assertGreaterThan(0, $payload['dna']);
            self::assertFalse($payload['truncated']);
            self::assertSame(1500, $payload['maxDna']);
            self::assertGreaterThan(0, $payload['smallSize']);
            self::assertGreaterThan($payload['smallSize'], $payload['largeSize']);
            self::assertGreaterThanOrEqual($payload['smallDnaSize'], $payload['largeDnaSize']);
            self::assertGreaterThan($payload['smallOrbit'], $payload['largeOrbit']);
            self::assertSame($payload['focusedDna'], $payload['focusedNodes'] - 2);
        } finally {
            @unlink($tmp);
        }
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
