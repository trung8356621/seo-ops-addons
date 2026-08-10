<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoMediaArticleSlugFixAllService;
use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use Omnichannel\Addons\Media\Services\SeoMediaStorageService;
use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentRenameService;
use PHPUnit\Framework\TestCase;

final class SeoMediaArticleSlugFixAllServiceTest extends TestCase
{
    private SeoMediaArticleSlugFixAllService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->makeService();
    }

    public function test_keyword_slug_base_normalizes_vietnamese(): void
    {
        self::assertSame('tui-xach-nam', $this->service->keywordToImageSlugBase('Túi xách nam'));
    }

    public function test_image_slug_from_keyword_appends_index(): void
    {
        self::assertSame('tui-xach-nam-1', $this->service->imageSlugFromKeyword('Túi xách nam', 1));
        self::assertSame('tui-xach-nam-2', $this->service->imageSlugFromKeyword('Túi xách nam', 2));
    }

    public function test_article_without_images_skips_fix_all(): void
    {
        $article = new SeoArticle(['body' => '<p>Không có ảnh</p>']);

        $result = $this->service->fixAllForArticle($article);

        self::assertTrue($result['skipped']);
        self::assertSame(0, $result['applied']);
    }

    public function test_article_has_local_images_detects_img_tag(): void
    {
        $article = new SeoArticle([
            'body' => '<p><img src="/storage/seo-media/site-1/test.jpg" data-seo-media-id="9" /></p>',
        ]);

        self::assertTrue($this->service->articleHasLocalImages($article));
    }

    public function test_sequential_image_slugs_stay_unique_for_same_keyword(): void
    {
        $slugs = [
            $this->service->imageSlugFromKeyword('Test keyword', 1),
            $this->service->imageSlugFromKeyword('Test keyword', 2),
            $this->service->imageSlugFromKeyword('Test keyword', 3),
        ];

        self::assertSame(['test-keyword-1', 'test-keyword-2', 'test-keyword-3'], $slugs);
        self::assertCount(3, array_unique($slugs));
    }

    public function test_rerun_slug_fix_skips_already_normalized_slugs(): void
    {
        $article = new SeoArticle([
            'body' => '<img src="/storage/seo-media/site-1/test-keyword-1.jpg" data-seo-media-id="1" />',
        ]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => 'seo_focus_keyword',
                'meta_value' => 'Test keyword',
            ]),
        ]));

        $result = $this->service->fixAllForArticle($article);

        self::assertTrue($result['success']);
        self::assertSame(0, $result['applied']);
        self::assertStringContainsString('đã chuẩn', mb_strtolower($result['message']));
    }

    private function makeService(): SeoMediaArticleSlugFixAllService
    {
        $urlReplacement = new SeoMediaUrlReplacementService();
        $slugFix = new SeoMediaArticleSlugFixService(
            $this->createMock(SeoMediaStorageService::class),
            $urlReplacement,
            new WordPressAttachmentRenameService(),
        );

        return new SeoMediaArticleSlugFixAllService($slugFix, $urlReplacement);
    }
}
