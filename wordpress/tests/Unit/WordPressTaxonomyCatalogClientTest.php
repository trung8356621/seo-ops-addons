<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Mockery;
use Omnichannel\Addons\WordPress\Services\WordPressTaxonomyCatalogClient;
use Tests\TestCase;

final class WordPressTaxonomyCatalogClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalizes_minimal_catalog_payload(): void
    {
        Http::fake([
            'https://example.test/wp-json/omi-seo-ai/v1/taxonomy-catalog/category' => Http::response([
                'schema' => 'taxonomy_catalog.v1',
                'taxonomy' => 'category',
                'items' => [
                    ['id' => 1, 'name' => 'Tin tức', 'parent' => 0, 'slug' => 'drop-me'],
                    ['id' => 2, 'name' => 'Trong nước', 'parent' => 1],
                    ['id' => 0, 'name' => 'skip'],
                ],
            ], 200),
        ]);

        $result = (new WordPressTaxonomyCatalogClient())->fetch($this->mockSite(), 'category');

        $this->assertTrue($result->ok);
        $this->assertSame('category', $result->taxonomy);
        $this->assertSame([
            ['id' => 1, 'name' => 'Tin tức', 'parent' => 0],
            ['id' => 2, 'name' => 'Trong nước', 'parent' => 1],
        ], $result->items);
    }

    public function test_forces_tag_parent_to_zero(): void
    {
        Http::fake([
            'https://example.test/wp-json/omi-seo-ai/v1/taxonomy-catalog/post_tag' => Http::response([
                'taxonomy' => 'post_tag',
                'items' => [
                    ['id' => 20, 'name' => 'SEO', 'parent' => 9],
                ],
            ], 200),
        ]);

        $result = (new WordPressTaxonomyCatalogClient())->fetch($this->mockSite(), 'post_tag');

        $this->assertTrue($result->ok);
        $this->assertSame(0, $result->items[0]['parent']);
    }

    public function test_404_is_unavailable_not_article_fallback(): void
    {
        Http::fake([
            'https://example.test/wp-json/omi-seo-ai/v1/taxonomy-catalog/category' => Http::response([
                'code' => 'rest_no_route',
            ], 404),
        ]);

        $result = (new WordPressTaxonomyCatalogClient())->fetch($this->mockSite(), 'category');

        $this->assertFalse($result->ok);
        $this->assertSame('unsupported', $result->code);
        $this->assertSame([], $result->items);
    }

    private function mockSite(): Site
    {
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('getKey')->andReturn(7);
        $site->shouldReceive('__get')->with('domain')->andReturn('https://example.test');
        $site->shouldReceive('getAttribute')->with('domain')->andReturn('https://example.test');
        $site->shouldReceive('loadMissing')->andReturnSelf();
        $site->shouldReceive('getMeta')->andReturnUsing(
            static fn (string $key): mixed => $key === 'seo_read_token' ? 'read-token' : null,
        );

        return $site;
    }
}
