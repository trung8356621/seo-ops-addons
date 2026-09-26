<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextFormatter;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextSource;
use Omnichannel\Addons\Seo\SeoServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContextFoundationCleanupContractTest extends TestCase
{
    public function test_site_health_rejects_keyword_id(): void
    {
        $registry = $this->stubRegistry();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter not allowed');
        $registry->get(1, ContextSliceKey::SITE_HEALTH, null, ['keyword_id' => 9]);
    }

    public function test_content_inventory_rejects_period(): void
    {
        $registry = $this->stubRegistry();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter not allowed');
        $registry->get(1, ContextSliceKey::CONTENT_INVENTORY, null, ['period' => '2026-09']);
    }

    public function test_gsc_accepts_period_and_period_key_aliases_only(): void
    {
        $registry = $this->stubRegistry();
        self::assertTrue($registry->get(1, ContextSliceKey::GSC_PERFORMANCE, null, ['period' => '2026-09'])->available);
        self::assertTrue($registry->get(1, ContextSliceKey::GSC_PERFORMANCE, null, ['period_key' => '2026-09'])->available);

        $this->expectException(InvalidArgumentException::class);
        $registry->get(1, ContextSliceKey::GSC_PERFORMANCE, null, ['keyword_id' => 1]);
    }

    public function test_keywords_relationship_accepts_keyword_ref_and_keyword_id(): void
    {
        $registry = $this->stubRegistry();
        self::assertTrue($registry->get(1, ContextSliceKey::KEYWORDS_RELATIONSHIP, null, ['keyword_ref' => 'keyword:1'])->available);
        self::assertTrue($registry->get(1, ContextSliceKey::KEYWORDS_RELATIONSHIP, null, ['keyword_id' => 1])->available);
    }

    public function test_unknown_parameter_rejects(): void
    {
        $registry = $this->stubRegistry();
        $this->expectException(InvalidArgumentException::class);
        $registry->get(1, ContextSliceKey::SITE_SYNC, null, ['sql' => 'select 1']);
    }

    public function test_explicit_invalid_view_rejects_missing_view_uses_default(): void
    {
        $registry = $this->stubRegistry();
        $defaulted = $registry->get(1, ContextSliceKey::SITE_HEALTH, null);
        self::assertSame(ContextSliceKey::SITE_HEALTH, $defaulted->key);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid context view');
        $registry->get(1, ContextSliceKey::SITE_HEALTH, 'super_detail');
    }

    public function test_provider_definition_views_are_enforced(): void
    {
        $registry = $this->stubRegistry();
        $def = $registry->definition(ContextSliceKey::SITE_HEALTH);
        self::assertTrue($def->allowsView('summary'));
        self::assertFalse($def->allowsView('deep'));

        $this->expectException(InvalidArgumentException::class);
        $registry->get(1, ContextSliceKey::SITE_HEALTH, ContextView::Detail);
    }

    public function test_formatter_strips_reserved_keys_recursively_in_all_views(): void
    {
        $formatter = new ContextFormatter;
        $slice = ContextSlice::make(
            'demo',
            3,
            [
                'count' => 0,
                'enabled' => false,
                'ai_lines' => ['You should...'],
                'text' => 'Your site currently...',
                'note' => 'legacy',
                'raw' => ['x' => 1],
                'title' => 'SEO Audit',
                'items' => ContextListSlice::fromAll([
                    [
                        'query' => 'seo audit',
                        'foo' => null,
                        'note' => 'AI prose',
                        'ai_lines' => ['nope'],
                    ],
                    ['query' => 'keep', 'clicks' => 0],
                ], 10),
                'nested' => [
                    'text' => 'drop',
                    'ok' => true,
                    'children' => [
                        ['raw' => 'x', 'id' => 1],
                    ],
                ],
            ],
            null,
            true,
            '2026-09-26T00:00:00+00:00',
            false,
        );

        $formatted = $formatter->format($slice);
        $data = $formatted['data'];
        self::assertSame(0, $data['count']);
        self::assertFalse($data['enabled']);
        self::assertSame('SEO Audit', $data['title']);
        self::assertArrayNotHasKey('ai_lines', $data);
        self::assertArrayNotHasKey('text', $data);
        self::assertArrayNotHasKey('note', $data);
        self::assertArrayNotHasKey('raw', $data);
        self::assertSame('seo audit', $data['items']['items'][0]['query']);
        self::assertArrayNotHasKey('note', $data['items']['items'][0]);
        self::assertArrayNotHasKey('foo', $data['items']['items'][0]);
        self::assertSame(0, $data['items']['items'][1]['clicks']);
        self::assertTrue($data['nested']['ok']);
        self::assertArrayNotHasKey('text', $data['nested']);
        self::assertSame(1, $data['nested']['children'][0]['id']);
        self::assertArrayNotHasKey('raw', $data['nested']['children'][0]);
        self::assertSame(2, $data['items']['returned']);
        self::assertFalse($data['items']['truncated']);
    }

    public function test_gsc_source_and_registry_are_scoped_not_singleton(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoServiceProvider::class))->getFileName(),
        );
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
        self::assertStringNotContainsString(
            'singleton(\\Omnichannel\\Addons\\Seo\\Services\\GscContext\\GscContextSource::class)',
            $src,
        );

        $load = new ReflectionMethod(GscContextSource::class, 'load');
        self::assertTrue($load->isPublic());
        $ctor = (new ReflectionClass(GscContextSource::class))->getDocComment() ?: '';
        self::assertStringContainsString('scoped', strtolower($ctor));
    }

    public function test_keywords_landscape_provider_does_not_duplicate_source_updated_at_in_data(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/Context/Providers/KeywordsLandscapeSliceProvider.php',
        );
        self::assertStringNotContainsString("'source_updated_at' =>", $src);
        self::assertStringContainsString('sourceUpdatedAt', $src);
    }

    public function test_all_twelve_registry_keys_remain_registered(): void
    {
        self::assertCount(12, ContextSliceKey::all());
        self::assertNotContains('site.indexability', ContextSliceKey::all());
        $registry = $this->stubRegistry();
        self::assertSame(ContextSliceKey::all(), $registry->keys());
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
                    // SITE_HEALTH deliberately omits Detail to prove definition view enforcement.
                    $views = $this->key === ContextSliceKey::SITE_HEALTH
                        ? ['summary', 'standard']
                        : ['summary', 'standard', 'detail'];

                    return new ContextSliceDefinition(
                        key: $this->key,
                        description: $this->key,
                        scope: 'site',
                        views: $views,
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
