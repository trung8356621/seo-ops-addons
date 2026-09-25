<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\TopicalMapSeoAuditEntryPoint;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\TopicalMapAppPage;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapThemeAndSeoAuditContractTest extends TestCase
{
    public function test_bootstrap_uses_canonical_site_scoped_seo_audit_url_with_access_fallback(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAppPage::class))->getFileName(),
        );

        self::assertStringContainsString("'seoAuditUrl' => app(TopicalMapSeoAuditEntryPoint::class)->resolveUrl(\$siteId)", $page);
        self::assertStringContainsString('topical_map_seo_audit', $page);

        $entryPoint = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapSeoAuditEntryPoint::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectSeoAuditPlanner::canAccess()', $entryPoint);
        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl()', $entryPoint);
        self::assertStringContainsString('DomainContextResolver::class', $entryPoint);
        self::assertStringContainsString('appendSiteToUrl(', $entryPoint);
        self::assertMatchesRegularExpression(
            '/public function resolveUrl[\s\S]*?catch \(Throwable\)[\s\S]*?return null;/',
            $entryPoint,
        );
        self::assertSame(
            '/seo/content-projects/seo-audit?site_id=37',
            (new DomainContextResolver())->appendSiteToUrl('/seo/content-projects/seo-audit', 37),
        );
    }

    public function test_chrome_has_safe_navigation_and_locally_scoped_persistent_theme(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js/topical-map';
        $app = (string) file_get_contents($root.'/pages/SiteTopicalMapPage.jsx');
        $chrome = (string) file_get_contents($root.'/components/AppChrome.jsx');
        $themeState = (string) file_get_contents($root.'/state/theme.js');
        $canvas = (string) file_get_contents($root.'/components/ChartCanvas.jsx');
        $css = (string) file_get_contents($root.'/styles/topical-map-app.css');

        self::assertStringContainsString('seoAuditUrl={config.seoAuditUrl || null}', $app);
        self::assertStringContainsString('data-theme={theme}', $app);
        self::assertStringContainsString('theme={theme}', $app);
        self::assertStringContainsString('persistTopicalMapTheme(next)', $app);

        $seoAnchor = '';
        if (preg_match('/\{seoAuditUrl \? \([\s\S]*?<\/a>\s*\) : null\}/', $chrome, $match)) {
            $seoAnchor = $match[0];
        }
        self::assertNotSame('', $seoAnchor, 'SEO Audit anchor block missing');
        self::assertStringContainsString('href={seoAuditUrl}', $seoAnchor);
        self::assertStringContainsString('target="_blank"', $seoAnchor);
        self::assertStringContainsString('rel="noopener noreferrer"', $seoAnchor);
        self::assertStringNotContainsString('onBeginAiAudit', $seoAnchor);
        self::assertStringNotContainsString('runAudit', $seoAnchor);

        self::assertStringContainsString("seo-ops.topical-map.theme", $themeState);
        self::assertStringContainsString("readStorage('theme')", $themeState);
        self::assertStringContainsString("classList.contains('dark')", $themeState);
        self::assertStringContainsString("matchMedia('(prefers-color-scheme: dark)')", $themeState);
        self::assertStringContainsString('window.localStorage.setItem(TOPICAL_MAP_THEME_KEY, theme)', $themeState);

        self::assertStringContainsString(".tm-app[data-theme='dark']", $css);
        foreach (['--tm-bg:', '--tm-surface:', '--tm-border:', '--tm-text:', '--tm-control-bg:'] as $token) {
            self::assertStringContainsString($token, $css);
        }
        self::assertStringNotContainsString('html.dark', $css);

        self::assertStringContainsString('lastThemeRef', $canvas);
        self::assertStringContainsString('zoom: zoomRef.current', $canvas);
        self::assertStringContainsString('option.series[0].center = centerRef.current', $canvas);
        self::assertStringContainsString('buildTreemapOption(overview, { theme })', $canvas);
    }

    public function test_light_and_dark_chart_options_change_presentation_not_geometry(): void
    {
        $chartsDir = dirname(__DIR__, 3).'/resources/js/topical-map/charts';
        $script = <<<'JS'
import { buildTreeOption, buildTreemapOption, buildNetworkOption } from './options.js';

const topics = [
  { id: 7, name: 'Bags', mcp: 42, dna_count: 2, article_count: 3, keyword_count: 5, tags: [{ id: 3, name: 'Products' }] },
  { id: 8, name: 'Cases', mcp: 18, dna_count: 1, article_count: 2, keyword_count: 4, tags: [] },
];
const overview = {
  site_domain: 'example.test',
  topics,
  tag_facets: [{ id: 3, name: 'Products', topic_count: 1 }],
};
const neighborhood = {
  nodes: [
    { id: 'site:1', category: 'site', name: 'example.test', x: 0, y: 0 },
    { id: 'topic:7', category: 'topic', name: 'Bags\n42%', x: 120, y: 40, mcp: 42, symbolSizeHint: 14 },
    { id: 'dna:7:a', category: 'dna', name: 'travel bag', x: 180, y: 65, topic_id: 7, parentSymbolSize: 14, symbolSizeHint: 10 },
  ],
  links: [
    { source: 'site:1', target: 'topic:7' },
    { source: 'topic:7', target: 'dna:7:a' },
  ],
  max_mcp: 42,
  showing_topics: 1,
};

const treeGeometry = (option) => ({
  layout: (({ type, id, left, right, top, bottom, symbol, orient, zoom, roam, scaleLimit }) => ({ type, id, left, right, top, bottom, symbol, orient, zoom, roam, scaleLimit }))(option.series[0]),
  data: JSON.parse(JSON.stringify(option.series[0].data, (key, value) => ['itemStyle', 'lineStyle', 'label', 'emphasis'].includes(key) ? undefined : value)),
});
const treemapGeometry = (option) => ({
  layout: (({ type, id, width, height, top, left, right, bottom, roam, nodeClick, squareRatio }) => ({ type, id, width, height, top, left, right, bottom, roam, nodeClick, squareRatio }))(option.series[0]),
  data: option.series[0].data.map(({ name, value, topicId, nodeType, mcp }) => ({ name, value, topicId, nodeType, mcp })),
});
const networkGeometry = (option) => ({
  layout: (({ type, id, layout, nodeScaleRatio, zoom, roam, scaleLimit }) => ({ type, id, layout, nodeScaleRatio, zoom, roam, scaleLimit }))(option.series[0]),
  data: option.series[0].data.map(({ id, x, y, symbolSize, nodeType, topicId, label }) => ({ id, x, y, symbolSize, nodeType, topicId, label: { show: label?.show, position: label?.position } })),
  links: option.series[0].links.map(({ source, target, lineStyle }) => ({ source, target, width: lineStyle?.width, curveness: lineStyle?.curveness })),
});
const same = (a, b, label) => {
  if (JSON.stringify(a) !== JSON.stringify(b)) throw new Error(label + ' geometry changed');
};

const treeLight = buildTreeOption(overview, { theme: 'light' });
const treeDark = buildTreeOption(overview, { theme: 'dark' });
const treemapLight = buildTreemapOption(overview, { theme: 'light' });
const treemapDark = buildTreemapOption(overview, { theme: 'dark' });
const networkLight = buildNetworkOption(neighborhood, { theme: 'light', zoom: 2 });
const networkDark = buildNetworkOption(neighborhood, { theme: 'dark', zoom: 2 });

same(treeGeometry(treeLight), treeGeometry(treeDark), 'tree');
same(treemapGeometry(treemapLight), treemapGeometry(treemapDark), 'treemap');
same(networkGeometry(networkLight), networkGeometry(networkDark), 'network');

if (treeLight.backgroundColor === treeDark.backgroundColor) throw new Error('tree background did not switch');
if (treemapLight.series[0].data[0].itemStyle.color === treemapDark.series[0].data[0].itemStyle.color) throw new Error('node palette did not switch');
if (networkLight.tooltip.backgroundColor === networkDark.tooltip.backgroundColor) throw new Error('tooltip did not switch');
if (!String(networkDark.series[0].links[0].lineStyle.color).includes('148,163,184')) throw new Error('dark edge color missing');

console.log('theme presentation contract ok');
JS;

        $tmp = $chartsDir.'/_theme_contract_smoke.mjs';
        file_put_contents($tmp, $script);
        try {
            $output = [];
            $code = 0;
            exec('node '.escapeshellarg($tmp).' 2>&1', $output, $code);
            self::assertSame(0, $code, implode("\n", $output));
            self::assertStringContainsString('theme presentation contract ok', implode("\n", $output));
        } finally {
            @unlink($tmp);
        }
    }
}
