<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Tests\TestCase;

final class ArticlePostImagesRestoreTest extends TestCase
{
    public function test_inject_into_empty_sections_inserts_images_after_h2(): void
    {
        $article = new SeoArticle(['site_id' => 1]);
        $html = <<<'HTML'
<p>Intro text</p>
<h2>Section A</h2>
<p>Body A</p>
<h2>Section B</h2>
<p>Body B</p>
HTML;

        $postImages = [
            [
                'wp_attachment_id' => 101,
                'src' => 'https://example.com/a.jpg',
                'slug' => 'image-a',
                'alt' => 'A',
            ],
            [
                'wp_attachment_id' => 102,
                'src' => 'https://example.com/b.jpg',
                'slug' => 'image-b',
                'alt' => 'B',
            ],
        ];

        $result = app(ArticlePostImagesService::class)
            ->injectIntoEmptySections($article, $html, $postImages);

        $this->assertSame(2, preg_match_all('/<img[\s>]/iu', $result));
        $this->assertStringContainsString('wp-image-101', $result);
        $this->assertStringContainsString('wp-image-102', $result);
        $this->assertStringContainsString('<h2>Section A</h2>', $result);
    }

    public function test_inject_skips_when_html_already_has_all_images(): void
    {
        $article = new SeoArticle(['site_id' => 1]);
        $html = '<h2>S</h2><figure><img src="https://example.com/a.jpg" class="wp-image-1" /></figure>';

        $postImages = [
            ['wp_attachment_id' => 1, 'src' => 'https://example.com/a.jpg', 'slug' => 'a'],
        ];

        $result = app(ArticlePostImagesService::class)
            ->injectIntoEmptySections($article, $html, $postImages);

        $this->assertSame($html, $result);
    }

    public function test_prepare_editor_html_keeps_raw_and_decodes_entities(): void
    {
        $article = new SeoArticle(['site_id' => 1]);
        $service = app(ArticlePostImagesService::class);

        $prepared = $service->prepareEditorHtmlFromWordPressSources(
            $article,
            '<p>V&#x1EA3;i chuy&#xEA;n d&#x1EE5;ng</p><img src="https://example.com/a.jpg" class="wp-image-1" alt="A" />',
            '<p class="font-claude-response-body">Should not replace raw</p><img src="https://example.com/rendered.jpg" />',
            [[
                'wp_attachment_id' => 1,
                'src' => 'https://example.com/a.jpg',
                'alt' => 'A',
            ]],
        );

        $this->assertStringContainsString('Vải chuyên dụng', $prepared);
        $this->assertStringContainsString('example.com/a.jpg', $prepared);
        $this->assertStringNotContainsString('font-claude-response-body', $prepared);
        $this->assertStringNotContainsString('&#x1EA3;', $prepared);
    }

    public function test_prepare_editor_html_injects_when_sections_lack_images(): void
    {
        $article = new SeoArticle(['site_id' => 1]);
        $service = app(ArticlePostImagesService::class);

        $prepared = $service->prepareEditorHtmlFromWordPressSources(
            $article,
            "<h2>Section A</h2><p>Body A</p>\n<h2>Section B</h2><p>Body B</p>",
            '',
            [[
                'wp_attachment_id' => 101,
                'src' => 'https://example.com/a.jpg',
                'slug' => 'image-a',
                'alt' => 'A',
            ]],
        );

        $this->assertStringContainsString('<img', $prepared);
        $this->assertStringContainsString('wp-image-101', $prepared);
    }
}
