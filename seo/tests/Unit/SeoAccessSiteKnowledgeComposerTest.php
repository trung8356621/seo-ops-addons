<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\Access\SeoAccessSiteKnowledgeComposer;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpDraft;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SeoAccessSiteKnowledgeComposerTest extends TestCase
{
    public function test_source_omits_tone_and_merges_distribution(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessSiteKnowledgeComposer::class))->getFileName()
        );

        self::assertStringContainsString('seo.access.site.v2', $src);
        self::assertStringContainsString('SiteContentDistributionAggregator', $src);
        self::assertStringContainsString('important_pages', $src);
        self::assertStringContainsString('content_distribution', $src);
        self::assertStringContainsString('root_product_cat', $src);
        self::assertStringContainsString("'available' => false", $src);
        self::assertStringNotContainsString('indexability', $src);
        self::assertStringNotContainsString('is_indexable', $src);
        self::assertStringNotContainsString("'tone'", $src);
        self::assertStringNotContainsString('writing_context.tone', $src);
    }

    public function test_important_pages_total_uses_root_product_cat_and_truncation(): void
    {
        $composer = (new ReflectionClass(SeoAccessSiteKnowledgeComposer::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(SeoAccessSiteKnowledgeComposer::class, 'composeImportantPages');
        $method->setAccessible(true);

        $items = array_map(
            static fn (int $i): array => [
                'url' => '/c-'.$i,
                'title' => 'C'.$i,
                'page_type' => 'product_category',
                'type' => 'product_category',
                'keyword' => 'k'.$i,
                'taxonomy' => 'product_cat',
                'term_id' => $i,
                'parent_term_id' => 0,
                'confidence' => 0.9,
            ],
            range(1, 60),
        );

        /** @var array{total: int|null, returned: int, truncated: bool, items: list<array<string, mixed>>} $wrapped */
        $wrapped = $method->invoke(
            $composer,
            $items,
            'ecommerce_catalog',
            'e-commerce',
            ['root_product_cat' => 83, 'product' => 500],
        );

        self::assertSame(83, $wrapped['total']);
        self::assertSame(60, $wrapped['returned']);
        self::assertTrue($wrapped['truncated']);
        self::assertCount(60, $wrapped['items']);
        self::assertArrayNotHasKey('confidence', $wrapped['items'][0]);
    }

    public function test_news_manual_total_is_null(): void
    {
        $composer = (new ReflectionClass(SeoAccessSiteKnowledgeComposer::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SeoAccessSiteKnowledgeComposer::class, 'composeImportantPages');
        $method->setAccessible(true);

        /** @var array{total: int|null, returned: int, truncated: bool} $wrapped */
        $wrapped = $method->invoke(
            $composer,
            [['url' => '/a', 'title' => 'A', 'page_type' => 'page', 'type' => 'page']],
            'news_manual',
            'news',
            ['root_product_cat' => 0],
        );

        self::assertNull($wrapped['total']);
        self::assertSame(1, $wrapped['returned']);
        self::assertFalse($wrapped['truncated']);
    }

    public function test_site_mcp_empty_and_generator_omit_tone(): void
    {
        self::assertArrayNotHasKey('tone', SiteMcpDraft::empty()['content_context']);

        $generatorSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SiteMcpGenerator::class))->getFileName()
        );
        self::assertStringNotContainsString("'tone' => \$tone", $generatorSrc);
        self::assertStringContainsString('Site/domain tone is retired', $generatorSrc);
    }
}
