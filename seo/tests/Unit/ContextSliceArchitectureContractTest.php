<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\ContextDataQuality;
use Omnichannel\Addons\Seo\Services\Context\ContextEnvelopeBuilder;
use Omnichannel\Addons\Seo\Services\Context\ContextFreshness;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextFormatter;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextProjection;
use Omnichannel\Addons\Seo\Services\Context\Providers\SiteHealthSliceProvider;
use Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\SiteContext\Dto\SiteContext;
use Omnichannel\Addons\Seo\Services\SiteContext\Readers\SiteSeoHealthReader;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextAssembler;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;
use Omnichannel\Addons\Seo\SeoServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContextSliceArchitectureContractTest extends TestCase
{
    public function test_context_namespace_does_not_depend_on_monthly_mcp(): void
    {
        $root = dirname(__DIR__, 2).'/src/Services/Context';
        $files = $this->phpFiles($root);
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('Services\\MonthlyMcp', $src, $file);
            self::assertStringNotContainsString('MonthlyMcpSourcePayload', $src, $file);
            self::assertStringNotContainsString('MonthlyMcpFreshness', $src, $file);
            self::assertStringNotContainsString('McpDataQualityGuard', $src, $file);
            self::assertStringNotContainsString('McpSourceKey', $src, $file);
            self::assertStringNotContainsString('Http::', $src, $file);
            self::assertStringNotContainsString('ContentProjectMcpServer', $src, $file);
        }
    }

    public function test_site_and_gsc_context_namespaces_do_not_depend_on_monthly_mcp(): void
    {
        foreach ([
            dirname(__DIR__, 2).'/src/Services/SiteContext',
            dirname(__DIR__, 2).'/src/Services/GscContext',
        ] as $root) {
            foreach ($this->phpFiles($root) as $file) {
                $src = (string) file_get_contents($file);
                self::assertStringNotContainsString('Services\\MonthlyMcp', $src, $file);
                self::assertStringNotContainsString('MonthlyMcpSourcePayload', $src, $file);
                self::assertStringNotContainsString('toMonthlyPayload', $src, $file);
                self::assertStringNotContainsString('toMonthlyParts', $src, $file);
            }
        }
    }

    public function test_canonical_dtos_do_not_produce_monthly_payload(): void
    {
        self::assertFalse(method_exists(SiteContext::class, 'toMonthlyPayload'));
        self::assertFalse(method_exists(GscContext::class, 'toMonthlyParts'));
        self::assertFalse(method_exists(GscContext::class, 'toMonthlyPayload'));

        $siteSrc = (string) file_get_contents((string) (new ReflectionClass(SiteContext::class))->getFileName());
        $gscSrc = (string) file_get_contents((string) (new ReflectionClass(GscContext::class))->getFileName());
        self::assertStringNotContainsString('MonthlyMcpSourcePayload', $siteSrc);
        self::assertStringNotContainsString('MonthlyMcpSourcePayload', $gscSrc);
    }

    public function test_schemas_remain_compatible(): void
    {
        self::assertSame('site.mcp.v1', SiteContext::SCHEMA);
        self::assertSame('gsc.mcp.v1', GscContext::SCHEMA);
        self::assertSame('keywords.mcp.v2', KeywordLandscapeGateway::SCHEMA);
        self::assertSame('keyword.relationship.v1', KeywordRelationshipGateway::SCHEMA);
    }

    public function test_registry_allowlists_known_keys_and_rejects_unknown(): void
    {
        $registry = $this->stubRegistry();
        self::assertSame(ContextSliceKey::all(), $registry->keys());
        self::assertTrue($registry->has(ContextSliceKey::SITE_HEALTH));
        self::assertTrue($registry->has(ContextSliceKey::SITE_SYNC));
        self::assertFalse($registry->has('site.indexability'));
        self::assertNotContains('site.indexability', ContextSliceKey::all());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown context slice key');
        $registry->get(1, 'planning.strategy');
    }

    public function test_site_indexability_is_not_registered_for_ai_mcp(): void
    {
        self::assertFalse(defined(ContextSliceKey::class.'::SITE_INDEXABILITY'));
        self::assertFileDoesNotExist(
            dirname(__DIR__, 2).'/src/Services/Context/Providers/SiteIndexabilitySliceProvider.php',
        );

        $providerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoServiceProvider::class))->getFileName(),
        );
        self::assertStringNotContainsString('SiteIndexabilitySliceProvider', $providerSrc);
        self::assertStringNotContainsString('site.indexability', $providerSrc);

        self::assertTrue(method_exists(SiteSeoHealthReader::class, 'indexability'));
        $readerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SiteSeoHealthReader::class))->getFileName(),
        );
        self::assertStringContainsString('function indexability', $readerSrc);
        self::assertStringContainsString('not Google', $readerSrc);

        $assemblerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SiteContextAssembler::class))->getFileName(),
        );
        self::assertStringContainsString('->indexability(', $assemblerSrc);
    }

    public function test_parameterized_slice_validates_required_parameters(): void
    {
        $registry = $this->stubRegistry();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keyword_ref');
        $registry->get(1, ContextSliceKey::KEYWORDS_RELATIONSHIP);
    }

    public function test_health_provider_does_not_assemble_full_site_context(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteHealthSliceProvider::class))->getFileName(),
        );
        self::assertStringNotContainsString('SiteContextAssembler', $src);
        self::assertStringNotContainsString('SiteContextGateway', $src);
        self::assertStringNotContainsString('distribution', $src);
        self::assertStringNotContainsString('internalLinking', $src);
        self::assertStringNotContainsString('indexability', $src);
    }

    public function test_assembler_uses_neutral_context_helpers(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteContextAssembler::class))->getFileName(),
        );
        self::assertStringContainsString('ContextFreshness', $src);
        self::assertStringContainsString('ContextDataQuality', $src);
        self::assertStringNotContainsString('MonthlyMcpFreshness', $src);
        self::assertStringNotContainsString('McpDataQualityGuard', $src);
        self::assertStringNotContainsString('McpSourceKey', $src);
    }

    public function test_list_truncation_and_formatter_are_structured(): void
    {
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => $i];
        }
        $slice = ContextListSlice::fromAll($items, 5);
        self::assertSame(30, $slice['total']);
        self::assertSame(5, $slice['returned']);
        self::assertTrue($slice['truncated']);
        self::assertCount(5, $slice['items']);

        $formatted = (new ContextFormatter)->format(ContextSlice::make(
            'demo',
            9,
            [
                'count' => 0,
                'empty_list' => [],
                'null_value' => null,
                'items' => $slice,
                'ai_lines' => ['Your site currently has...'],
            ],
            null,
            true,
            '2026-09-26T00:00:00+00:00',
            false,
        ));
        self::assertSame(0, $formatted['data']['count']);
        self::assertArrayNotHasKey('empty_list', $formatted['data']);
        self::assertArrayNotHasKey('null_value', $formatted['data']);
        self::assertArrayHasKey('items', $formatted['data']);
        self::assertSame('site:9', $formatted['scope']['site_ref']);
    }

    public function test_projection_summary_strips_prose_fields(): void
    {
        $projected = (new ContextProjection)->projectData(
            ['clicks' => 10, 'ai_lines' => ['hello'], 'text' => 'prose', 'note' => 'x'],
            ContextView::Summary,
        );
        self::assertSame(10, $projected['clicks']);
        self::assertArrayNotHasKey('ai_lines', $projected);
        self::assertArrayNotHasKey('text', $projected);
        self::assertArrayNotHasKey('note', $projected);
    }

    public function test_gateways_remain_without_http_loopback(): void
    {
        foreach ([
            SiteContextGateway::class,
            GscContextGateway::class,
            KeywordLandscapeGateway::class,
            KeywordRelationshipGateway::class,
            ContextRegistry::class,
        ] as $class) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
            self::assertStringNotContainsString('Http::', $code, $class);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Http', $code, $class);
        }
    }

    public function test_freshness_and_quality_live_in_context_namespace(): void
    {
        self::assertTrue(class_exists(ContextFreshness::class));
        self::assertTrue(class_exists(ContextDataQuality::class));
        self::assertSame(48, ContextFreshness::STALE_HOURS);
        self::assertTrue(ContextFreshness::isSourceStale(null));
        self::assertSame([], (new ContextDataQuality)->siteWarnings(0, [], []));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    private function stubRegistry(): ContextRegistry
    {
        $providers = [];
        foreach (ContextSliceKey::all() as $key) {
            $providers[] = new class($key) implements ContextSliceProvider
            {
                public function __construct(private readonly string $key) {}

                public function definition(): ContextSliceDefinition
                {
                    $required = $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP ? ['keyword_ref'] : [];
                    $optional = match (true) {
                        str_starts_with($this->key, 'gsc.') => ['period', 'period_key', 'limit'],
                        $this->key === ContextSliceKey::KEYWORDS_RELATIONSHIP => ['keyword_id'],
                        in_array($this->key, [
                            ContextSliceKey::SEO_FINDINGS,
                            ContextSliceKey::SEO_INTERNAL_LINKS,
                            ContextSliceKey::KEYWORDS_LANDSCAPE,
                        ], true) => ['limit'],
                        default => [],
                    };

                    return new ContextSliceDefinition(
                        key: $this->key,
                        description: $this->key,
                        scope: 'site',
                        views: ['summary', 'standard', 'detail'],
                        defaultView: 'summary',
                        requiredParameters: $required,
                        optionalParameters: $optional,
                        periodAware: str_starts_with($this->key, 'gsc.'),
                    );
                }

                public function provide(ContextSliceRequest $request): ContextSlice
                {
                    return ContextSlice::make($this->key, $request->siteId, ['ok' => true], null, true, null, false);
                }
            };
        }

        return new ContextRegistry($providers);
    }
}
