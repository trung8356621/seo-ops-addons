<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Providers\GscCannibalizationSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Providers\GscOpportunitiesSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Providers\GscPerformanceSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Providers\KeywordsRelationshipSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Support\KeywordRelationshipSectionFilter;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextLoader;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use Omnichannel\Addons\Seo\Services\Mcp\Catalog\SeoMcpRouterCatalog;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestBuilder;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestMarkdownPresenter;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpPartDefinition;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpPartReadSpec;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpReadRequest;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterDefinition;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterReader;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry;
use Omnichannel\Addons\Seo\Services\Mcp\Support\McpSizeHint;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class McpRouterArchitectureContractTest extends TestCase
{
    public function test_canonical_routers_and_part_mappings(): void
    {
        $registry = $this->routerRegistry();
        self::assertSame(
            ['site', 'content', 'seo', 'publishing', 'keywords', 'gsc'],
            $registry->keys(),
        );

        $expected = [
            'site' => ['health' => ContextSliceKey::SITE_HEALTH, 'sync' => ContextSliceKey::SITE_SYNC],
            'content' => ['inventory' => ContextSliceKey::CONTENT_INVENTORY, 'distribution' => ContextSliceKey::CONTENT_DISTRIBUTION],
            'seo' => ['findings' => ContextSliceKey::SEO_FINDINGS, 'internal_links' => ContextSliceKey::SEO_INTERNAL_LINKS],
            'publishing' => ['status' => ContextSliceKey::PUBLISHING_STATUS],
            'keywords' => ['landscape' => ContextSliceKey::KEYWORDS_LANDSCAPE, 'relationship' => ContextSliceKey::KEYWORDS_RELATIONSHIP],
            'gsc' => ['performance' => ContextSliceKey::GSC_PERFORMANCE, 'opportunities' => ContextSliceKey::GSC_OPPORTUNITIES, 'cannibalization' => ContextSliceKey::GSC_CANNIBALIZATION],
        ];

        foreach ($expected as $routerKey => $parts) {
            $router = $registry->router($routerKey);
            foreach ($parts as $partKey => $contextKey) {
                self::assertSame($contextKey, $router->part($partKey)?->contextKey);
                self::assertTrue($registry->contextRegistry()->has($contextKey));
            }
        }
    }

    public function test_unknown_router_and_part_rejected(): void
    {
        $registry = $this->routerRegistry();
        $reader = new McpRouterReader($registry);

        try {
            $registry->router('planning');
            self::fail('Expected unknown router rejection');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown MCP router', $e->getMessage());
        }

        try {
            $reader->read(new McpReadRequest(1, 'site', [
                'not_a_part' => new McpPartReadSpec,
            ]));
            self::fail('Expected unknown part rejection');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown MCP part', $e->getMessage());
        }
    }

    public function test_duplicate_router_and_part_keys_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate MCP part key');
        new McpRouterDefinition(
            key: 'site',
            title: 'Site',
            description: 'x',
            whenToUse: 'y',
            scope: 'site',
            parts: [
                new McpPartDefinition('health', ContextSliceKey::SITE_HEALTH, 'when', McpSizeHint::Small),
                new McpPartDefinition('health', ContextSliceKey::SITE_SYNC, 'when', McpSizeHint::Small),
            ],
        );
    }

    public function test_duplicate_router_key_rejected_at_registry(): void
    {
        $context = $this->stubContextRegistry();
        $router = new McpRouterDefinition(
            key: 'site',
            title: 'Site',
            description: 'x',
            whenToUse: 'y',
            scope: 'site',
            parts: [
                new McpPartDefinition('health', ContextSliceKey::SITE_HEALTH, 'when', McpSizeHint::Small),
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate MCP router key');
        new McpRouterRegistry([$router, $router], $context);
    }

    public function test_manifest_inherits_context_metadata_without_php_classes(): void
    {
        $registry = $this->routerRegistry();
        $manifest = $registry->manifest();

        self::assertSame(McpManifestBuilder::SCHEMA, $manifest['schema']);
        self::assertCount(6, $manifest['routers']);

        $keywords = null;
        foreach ($manifest['routers'] as $router) {
            self::assertNotSame('', $router['description'] ?? '');
            self::assertNotSame('', $router['when_to_use'] ?? '');
            if (($router['key'] ?? '') === 'keywords') {
                $keywords = $router;
            }
        }
        self::assertIsArray($keywords);

        $relationship = null;
        foreach ($keywords['parts'] as $part) {
            if (($part['key'] ?? '') === 'relationship') {
                $relationship = $part;
            }
        }
        self::assertIsArray($relationship);
        self::assertSame(ContextSliceKey::KEYWORDS_RELATIONSHIP, $relationship['context_key']);
        self::assertSame(['summary', 'standard', 'detail'], $relationship['views']);
        self::assertSame('standard', $relationship['default_view']);
        self::assertSame(['keyword_ref'], $relationship['required_parameters']);
        self::assertContains('keyword_id', $relationship['optional_parameters']);
        self::assertContains('sections', $relationship['optional_parameters']);
        self::assertNotSame('', $relationship['when_to_use']);
        self::assertSame('medium', $relationship['size_hint']);

        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SliceProvider', $encoded);
        self::assertStringNotContainsString('Omnichannel\\Addons', $encoded);
        self::assertStringNotContainsString('::class', $encoded);
    }

    public function test_markdown_readme_is_compact_and_high_signal(): void
    {
        $md = (new McpManifestMarkdownPresenter)->present($this->routerRegistry());
        self::assertStringContainsString('# SEO MCP', $md);
        self::assertStringContainsString('## keywords', $md);
        self::assertStringContainsString('- landscape', $md);
        self::assertStringContainsString('- relationship', $md);
        self::assertStringContainsString('When to use:', $md);
        self::assertStringNotContainsString('"data"', $md);
        self::assertStringNotContainsString('ai_lines', $md);
        self::assertStringNotContainsString(ContextSliceKey::KEYWORDS_LANDSCAPE.' payload', $md);
    }

    public function test_selective_reads_only_return_requested_parts(): void
    {
        $reader = new McpRouterReader($this->routerRegistry());

        $one = $reader->read(new McpReadRequest(7, 'site', [
            'health' => new McpPartReadSpec('summary'),
        ]));
        self::assertSame(McpRouterReader::SCHEMA, $one['schema']);
        self::assertSame('site', $one['router']);
        self::assertSame(['health'], array_keys($one['parts']));
        self::assertSame(ContextSliceKey::SITE_HEALTH, $one['parts']['health']['key']);
        self::assertArrayNotHasKey('sync', $one['parts']);

        $two = $reader->read(new McpReadRequest(7, 'site', [
            'health' => new McpPartReadSpec,
            'sync' => new McpPartReadSpec('detail'),
        ]));
        self::assertSame(['health', 'sync'], array_keys($two['parts']));
        self::assertSame(ContextSliceKey::SITE_SYNC, $two['parts']['sync']['key']);
    }

    public function test_indexability_part_is_unknown_and_rejected(): void
    {
        $registry = $this->routerRegistry();
        $reader = new McpRouterReader($registry);

        $site = $registry->router('site');
        self::assertNull($site->part('indexability'));
        self::assertSame(['health', 'sync'], $site->partKeys());

        $manifest = $registry->manifest();
        $siteManifest = null;
        foreach ($manifest['routers'] as $router) {
            if (($router['key'] ?? '') === 'site') {
                $siteManifest = $router;
                break;
            }
        }
        self::assertIsArray($siteManifest);
        self::assertSame(['health', 'sync'], array_column($siteManifest['parts'], 'key'));
        self::assertStringNotContainsString('indexability', json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('site.indexability', (string) file_get_contents(
            (string) (new ReflectionClass(SeoMcpRouterCatalog::class))->getFileName(),
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown MCP part');
        $reader->read(new McpReadRequest(7, 'site', [
            'indexability' => new McpPartReadSpec('summary'),
        ]));
    }

    public function test_part_specific_parameters_do_not_bleed(): void
    {
        $reader = new McpRouterReader($this->routerRegistry());
        $result = $reader->read(McpReadRequest::fromArray(3, [
            'router' => 'keywords',
            'parts' => [
                'landscape' => [
                    'view' => 'summary',
                    'parameters' => ['limit' => 5],
                ],
                'relationship' => [
                    'view' => 'standard',
                    'parameters' => ['keyword_ref' => 'keyword:9'],
                ],
            ],
        ]));

        self::assertSame(5, $result['parts']['landscape']['data']['seen_limit'] ?? null);
        self::assertSame('keyword:9', $result['parts']['relationship']['data']['seen_keyword_ref'] ?? null);
        self::assertArrayNotHasKey('seen_keyword_ref', $result['parts']['landscape']['data']);
        self::assertArrayNotHasKey('seen_limit', $result['parts']['relationship']['data']);
    }

    public function test_invalid_view_and_unknown_parameter_still_fail(): void
    {
        $reader = new McpRouterReader($this->routerRegistry());

        $this->expectException(InvalidArgumentException::class);
        $reader->read(new McpReadRequest(1, 'site', [
            'health' => new McpPartReadSpec('mega'),
        ]));
    }

    public function test_unknown_parameter_rejected_by_context(): void
    {
        $reader = new McpRouterReader($this->routerRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter not allowed');
        $reader->read(new McpReadRequest(1, 'gsc', [
            'performance' => new McpPartReadSpec(null, ['weird' => 1]),
        ]));
    }

    public function test_relationship_sections_projection(): void
    {
        $data = [
            'keyword' => ['id' => 11],
            'topics' => [['id' => 1]],
            'focus_articles' => [['article_id' => 3]],
            'related_keywords' => ['items' => [['id' => 2]]],
            'internal_links' => ['available' => true],
            'gsc' => ['available' => true],
            'meta' => ['relation_issues' => []],
        ];

        $filtered = KeywordRelationshipSectionFilter::apply($data, ['keyword', 'topics']);
        self::assertSame(['keyword', 'topics'], array_keys($filtered));
        self::assertArrayNotHasKey('related_keywords', $filtered);
        self::assertArrayNotHasKey('internal_links', $filtered);
        self::assertArrayNotHasKey('gsc', $filtered);

        $definition = (new ReflectionClass(KeywordsRelationshipSliceProvider::class))
            ->newInstanceWithoutConstructor()
            ->definition();
        self::assertContains('sections', $definition->optionalParameters);
        self::assertSame(
            ['keyword', 'topics', 'focus_articles', 'related_keywords', 'internal_links', 'gsc', 'meta'],
            KeywordRelationshipSectionFilter::ALLOWLIST,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid keywords.relationship section');
        KeywordRelationshipSectionFilter::apply($data, ['keyword', 'not_a_section']);
    }

    public function test_multi_part_gsc_shares_one_source_load(): void
    {
        $counter = new class
        {
            public int $calls = 0;
        };
        $gateway = new class($counter) implements GscContextLoader
        {
            public function __construct(private object $counter) {}

            public function forSite(int $siteId, string $periodKey): GscContext
            {
                $this->counter->calls++;

                return new GscContext(
                    siteId: $siteId,
                    periodKey: $periodKey,
                    metrics: ['clicks' => 1, 'impressions' => 2, 'absent' => false],
                    summary: [
                        'period' => ['current' => $periodKey],
                        'totals' => ['clicks' => 1],
                        'comparison' => [],
                        'top_queries' => [],
                        'top_pages' => [],
                        'rising_queries' => [],
                        'falling_queries' => [],
                        'ctr_opportunities' => [],
                        'near_page_one' => [],
                        'decay_candidates' => [],
                        'new_content_candidates' => [],
                        'cannibalization' => [],
                        'possible_cannibalization' => [],
                    ],
                    context: [],
                    sourceUpdatedAt: '2026-09-01T00:00:00+00:00',
                    generatedAt: '2026-09-26T00:00:00+00:00',
                    available: true,
                    stale: false,
                );
            }

            public function sourceUpdatedAt(int $siteId): ?string
            {
                return null;
            }

            public function latestSyncedPeriodOnOrBefore(int $siteId, string $onOrBeforePeriod): ?string
            {
                return null;
            }
        };

        $source = new GscContextSource($gateway);
        $providers = [
            new GscPerformanceSliceProvider($source),
            new GscOpportunitiesSliceProvider($source),
            new GscCannibalizationSliceProvider($source),
        ];
        $all = [];
        foreach (ContextSliceKey::all() as $key) {
            $matched = null;
            foreach ($providers as $provider) {
                if ($provider->definition()->key === $key) {
                    $matched = $provider;
                    break;
                }
            }
            $all[] = $matched ?? $this->stubProvider($key);
        }

        $context = new ContextRegistry($all);
        $reader = new McpRouterReader(SeoMcpRouterCatalog::build($context));
        $result = $reader->read(new McpReadRequest(5, 'gsc', [
            'performance' => new McpPartReadSpec('summary', ['period' => '2026-08']),
            'opportunities' => new McpPartReadSpec('summary', ['period' => '2026-08']),
            'cannibalization' => new McpPartReadSpec('summary', ['period' => '2026-08']),
        ]));

        self::assertSame(1, $counter->calls);
        self::assertSame(['performance', 'opportunities', 'cannibalization'], array_keys($result['parts']));
    }

    public function test_mcp_namespace_boundary_guards(): void
    {
        $root = dirname(__DIR__, 2).'/src/Services/Mcp';
        self::assertDirectoryExists($root);
        foreach ($this->phpFiles($root) as $file) {
            $src = (string) file_get_contents($file);
            $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;

            self::assertStringNotContainsString('Http::', $code, $file);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Http', $code, $file);
            self::assertStringNotContainsString('Eloquent', $code, $file);
            self::assertStringNotContainsString('::query(', $code, $file);
            self::assertStringNotContainsString('DB::', $code, $file);
            self::assertStringNotContainsString('Services\\MonthlyMcp', $code, $file);
            self::assertStringNotContainsString('AgentWorkspace', $code, $file);
            self::assertStringNotContainsString('ContentProjectMcpServer', $code, $file);
            self::assertStringNotContainsString('/api/', $code, $file);
            self::assertStringNotContainsString('AuthenticateServiceApi', $code, $file);
            self::assertStringNotContainsString('service_api_credentials', $code, $file);
        }
    }

    public function test_service_provider_keeps_scoped_bindings(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/SeoServiceProvider.php');
        self::assertMatchesRegularExpression(
            '/->bind\(\s*\\\\?Omnichannel\\\\Addons\\\\Seo\\\\Services\\\\GscContext\\\\GscContextLoader::class\s*,\s*\\\\?Omnichannel\\\\Addons\\\\Seo\\\\Services\\\\GscContext\\\\GscContextGateway::class/',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/->scoped\(\s*\\\\?Omnichannel\\\\Addons\\\\Seo\\\\Services\\\\GscContext\\\\GscContextSource::class/',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/->scoped\(\s*\\\\?Omnichannel\\\\Addons\\\\Seo\\\\Services\\\\Context\\\\Registry\\\\ContextRegistry::class/',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/->scoped\(\s*\\\\?Omnichannel\\\\Addons\\\\Seo\\\\Services\\\\Mcp\\\\Router\\\\McpRouterRegistry::class/',
            $src,
        );
        self::assertStringNotContainsString(
            'singleton(\\Omnichannel\\Addons\\Seo\\Services\\Mcp\\Router\\McpRouterRegistry::class)',
            $src,
        );
    }

    private function routerRegistry(): McpRouterRegistry
    {
        return SeoMcpRouterCatalog::build($this->stubContextRegistry());
    }

    private function stubContextRegistry(): ContextRegistry
    {
        $providers = [];
        foreach (ContextSliceKey::all() as $key) {
            $providers[] = $this->stubProvider($key);
        }

        return new ContextRegistry($providers);
    }

    private function stubProvider(string $key): ContextSliceProvider
    {
        return new class($key) implements ContextSliceProvider
        {
            public function __construct(private readonly string $key) {}

            public function definition(): ContextSliceDefinition
            {
                $required = $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP ? ['keyword_ref'] : [];
                $optional = match (true) {
                    str_starts_with($this->key, 'gsc.') => ['period', 'period_key', 'limit'],
                    $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP => ['keyword_id', 'sections'],
                    in_array($this->key, [
                        ContextSliceKey::SEO_FINDINGS,
                        ContextSliceKey::SEO_INTERNAL_LINKS,
                        ContextSliceKey::KEYWORDS_LANDSCAPE,
                    ], true) => ['limit'],
                    default => [],
                };

                return new ContextSliceDefinition(
                    key: $this->key,
                    description: 'Stub description for '.$this->key,
                    scope: 'site',
                    views: ['summary', 'standard', 'detail'],
                    defaultView: $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP ? 'standard' : 'summary',
                    requiredParameters: $required,
                    optionalParameters: $optional,
                    periodAware: str_starts_with($this->key, 'gsc.'),
                );
            }

            public function provide(ContextSliceRequest $request): ContextSlice
            {
                $data = ['ok' => true, 'view' => $request->view->value];
                if (array_key_exists('limit', $request->parameters)) {
                    $data['seen_limit'] = $request->limit();
                }
                if (array_key_exists('keyword_ref', $request->parameters) || array_key_exists('keyword_id', $request->parameters)) {
                    $data['seen_keyword_ref'] = $request->keywordRef();
                }
                if (array_key_exists('period', $request->parameters) || array_key_exists('period_key', $request->parameters)) {
                    $data['seen_period'] = $request->periodKey();
                }

                return ContextSlice::make($this->key, $request->siteId, $data, null, true, null, false);
            }
        };
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
