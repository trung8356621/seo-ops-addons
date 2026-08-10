<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use PHPUnit\Framework\TestCase;

/**
 * Confirm preview / editor / tools entry points share one renderer.
 */
final class ArticleMarkdownToHtmlServiceCentralizationTest extends TestCase
{
    public function test_service_delegates_to_same_converter_instance_behavior(): void
    {
        $converter = new SimpleMarkdownHtmlConverter;
        $service = new ArticleMarkdownToHtmlService($converter);

        $markdown = "## Mục\n\n- mục A\n\n| X | Y |\n| --- | --- |\n| 1 | 2 |";
        $viaService = $service->toHtml($markdown);
        $viaConverter = $converter->toHtml($markdown);

        $this->assertSame($viaConverter, $viaService);
        $this->assertStringContainsString('<h2>Mục</h2>', $viaService);
        $this->assertStringContainsString('<table>', $viaService);
    }

    public function test_convert_with_metadata_matches_to_html(): void
    {
        $service = new ArticleMarkdownToHtmlService(new SimpleMarkdownHtmlConverter);
        $markdown = "Meta Description: Mô tả\n\n## Thân bài\n\nText.";

        $withMeta = $service->convertWithMetadata($markdown);
        $html = $service->toHtml($markdown);

        $this->assertSame($html, $withMeta['html']);
        $this->assertSame('Mô tả', $withMeta['meta_description']);
        $this->assertStringNotContainsString('Meta Description', $withMeta['html']);
    }
}
