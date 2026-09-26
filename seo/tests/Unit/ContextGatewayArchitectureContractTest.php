<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMcpContextBuilder;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Services\Context\ContextEnvelopeBuilder;
use Omnichannel\Addons\Seo\Services\Context\Contracts\ContextEnvelope;
use Omnichannel\Addons\Seo\Services\DomainSeoMcpService;
use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\SiteMcpContextBuilder;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\GscMonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\KeywordMonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources\SiteMonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\SiteContext\Dto\SiteContext;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextAssembler;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Architecture contracts for the four canonical context boundaries.
 */
final class ContextGatewayArchitectureContractTest extends TestCase
{
    public function test_keyword_landscape_monthly_uses_gateway_not_read_model(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordMonthlyMcpSource::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('file_get_contents(\'http', $src);
        self::assertSame('keywords.mcp.v2', McpSourceKey::Keywords->schema());
        self::assertSame(KeywordLandscapeGateway::SCHEMA, McpSourceKey::Keywords->schema());
    }

    public function test_keyword_relationship_never_writes_monthly_snapshots(): void
    {
        $gatewaySrc = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordRelationshipGateway::class))->getFileName(),
        );
        self::assertStringContainsString('never writes seo_mcp_source_snapshots', $gatewaySrc);
        self::assertStringNotContainsString('MonthlyMcpSource', $gatewaySrc);
        self::assertStringNotContainsString('SeoMcpSourceSnapshot', $gatewaySrc);

        $keys = array_map(static fn (McpSourceKey $k): string => $k->value, McpSourceKey::cases());
        self::assertNotContains('keyword.relationship', $keys);
        self::assertSame('keyword.relationship.v1', KeywordRelationshipGateway::SCHEMA);
        self::assertNotSame(McpSourceKey::Keywords->schema(), KeywordRelationshipGateway::SCHEMA);
    }

    public function test_gsc_monthly_uses_gsc_context_gateway(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GscMonthlyMcpSource::class))->getFileName(),
        );
        self::assertStringContainsString('GscContextGateway', $src);
        self::assertStringNotContainsString('GscMcpContextBuilder', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertSame('gsc.mcp.v1', McpSourceKey::Gsc->schema());
        self::assertSame(GscContextGateway::SCHEMA, GscContext::SCHEMA);

        $gatewaySrc = (string) file_get_contents(
            (string) (new ReflectionClass(GscContextGateway::class))->getFileName(),
        );
        self::assertStringContainsString('GscMcpContextBuilder', $gatewaySrc);
        self::assertTrue(
            str_contains(strtolower($gatewaySrc), 'never live gsc')
            || str_contains(strtolower($gatewaySrc), 'never live'),
        );
    }

    public function test_site_monthly_uses_site_context_gateway(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteMonthlyMcpSource::class))->getFileName(),
        );
        self::assertStringContainsString('SiteContextGateway', $src);
        self::assertStringNotContainsString('SiteMcpContextBuilder', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertSame('site.mcp.v1', McpSourceKey::Site->schema());
        self::assertSame(SiteContextGateway::SCHEMA, SiteContext::SCHEMA);
    }

    public function test_site_mcp_context_builder_is_thin_gateway_adapter(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteMcpContextBuilder::class))->getFileName(),
        );
        self::assertStringContainsString('SiteContextGateway', $src);
        self::assertStringContainsString('@deprecated', $src);
        self::assertStringNotContainsString('SeoFinding::', $src);
        self::assertStringNotContainsString('SeoArticle::', $src);
    }

    public function test_gateways_have_no_http_loopback(): void
    {
        $classes = [
            KeywordLandscapeGateway::class,
            KeywordRelationshipGateway::class,
            GscContextGateway::class,
            SiteContextGateway::class,
            SiteContextAssembler::class,
            KeywordMonthlyMcpSource::class,
            SiteMonthlyMcpSource::class,
            GscMonthlyMcpSource::class,
        ];
        foreach ($classes as $class) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            // Strip docblocks/comments so future-route documentation does not false-positive.
            $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;
            self::assertStringNotContainsString('Http::', $code, $class);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Http', $code, $class);
            self::assertDoesNotMatchRegularExpression('/\bcurl_exec\b/', $code, $class);
            self::assertStringNotContainsString('ContentProjectMcpServer', $code, $class);
            self::assertStringNotContainsString("file_get_contents('http", $code, $class);
        }
    }

    public function test_context_envelope_metadata_shape(): void
    {
        $envelope = ContextEnvelopeBuilder::make(
            'site.mcp.v1',
            1,
            42,
            '2026-09-01T00:00:00+00:00',
            true,
            ['ok' => true],
            '2026-09-26T00:00:00+00:00',
            false,
        );
        self::assertSame('site.mcp.v1', $envelope['schema']);
        self::assertSame(1, $envelope['version']);
        self::assertSame('site:42', $envelope['scope']['site_ref']);
        self::assertSame('2026-09-26T00:00:00+00:00', $envelope['generated_at']);
        self::assertSame('2026-09-01T00:00:00+00:00', $envelope['source_updated_at']);
        self::assertFalse($envelope['stale']);
        self::assertTrue($envelope['available']);
        self::assertSame(['ok' => true], $envelope['data']);

        self::assertTrue(is_a(GscContext::class, ContextEnvelope::class, true));
        self::assertTrue(is_a(SiteContext::class, ContextEnvelope::class, true));
    }

    public function test_domain_seo_mcp_service_delegates_landscape_and_site_context(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DomainSeoMcpService::class))->getFileName(),
        );
        self::assertStringContainsString('NOT the future unified context API', $src);
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('SiteContextGateway', $src);
        self::assertStringContainsString('siteContextGateway', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('Http::', $src);

        $ctor = (new ReflectionClass(DomainSeoMcpService::class))->getConstructor();
        self::assertNotNull($ctor);
        $names = array_map(static fn ($p) => $p->getType()?->getName(), $ctor->getParameters());
        self::assertContains(KeywordLandscapeGateway::class, $names);
        self::assertContains(SiteContextGateway::class, $names);
    }

    public function test_gsc_builder_remains_implementation_detail_behind_gateway(): void
    {
        $gatewayCtor = (new ReflectionClass(GscContextGateway::class))->getConstructor();
        self::assertNotNull($gatewayCtor);
        self::assertSame(
            GscMcpContextBuilder::class,
            $gatewayCtor->getParameters()[0]->getType()?->getName(),
        );
        $builderSrc = (string) file_get_contents(
            (string) (new ReflectionClass(GscMcpContextBuilder::class))->getFileName(),
        );
        self::assertStringContainsString('GscContextGateway', $builderSrc);
        self::assertStringContainsString('never live GSC API', $builderSrc);
    }

    public function test_site_context_gateway_is_site_scoped(): void
    {
        $assemble = new ReflectionMethod(SiteContextAssembler::class, 'assemble');
        self::assertSame('App\\Models\\Site', $assemble->getParameters()[0]->getType()?->getName());

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteContextAssembler::class))->getFileName(),
        );
        self::assertStringContainsString('site_id', $src);
        self::assertStringContainsString("'site_id' => \$siteId", $src);
    }

    public function test_schemas_remain_compatible(): void
    {
        self::assertSame('site.mcp.v1', SiteContext::SCHEMA);
        self::assertSame('gsc.mcp.v1', GscContext::SCHEMA);
        self::assertSame('keywords.mcp.v2', KeywordLandscapeGateway::SCHEMA);
        self::assertSame('keyword.relationship.v1', KeywordRelationshipGateway::SCHEMA);
    }
}
