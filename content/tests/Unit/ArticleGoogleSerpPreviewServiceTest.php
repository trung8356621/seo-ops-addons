<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\ArticleGoogleSerpPreviewService;
use Tests\TestCase;

final class ArticleGoogleSerpPreviewServiceTest extends TestCase
{
    public function test_builds_product_preview_from_synthetic_schema(): void
    {
        if (config('database.connections.omi_seo_ai') === null) {
            $this->markTestSkipped('Connection omi_seo_ai chưa được cấu hình trong môi trường test.');
        }

        $article = new SeoArticle([
            'title' => 'Balo học sinh',
            'type' => 'product',
        ]);
        $article->setRelation('articleMetas', collect());

        $preview = app(ArticleGoogleSerpPreviewService::class)->buildForArticle(
            $article,
            'Tiêu đề SEO tùy chỉnh',
            'Mô tả ngắn cho Google.',
            'https://shop.test/san-pham/balo',
        );

        $this->assertSame('product', $preview['type']);
        $this->assertSame('Tiêu đề SEO tùy chỉnh', $preview['title']);
        $this->assertSame('Mô tả ngắn cho Google.', $preview['description']);
        $this->assertStringContainsString('shop.test', $preview['display_url']);
    }
}
