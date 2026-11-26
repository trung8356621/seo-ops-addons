<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Services\ArticlePostContentFaqPlaceholder;
use Omnichannel\Addons\Content\Support\ArticleMarkdownImportParser;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * History Apply must reuse Generate Markdown→HTML path, not dump raw Markdown.
 * Sanitized PromptResult #4853-shaped fixture (no production mutation).
 */
final class ArticleAiHistoryApplyMarkdownHydrationTest extends TestCase
{
    /** Sanitized PromptResult #4853-like Markdown. */
    private function pr4853LikeMarkdown(): string
    {
        return <<<'MD'
Meta description: Shein là gì? Khám phá toàn bộ thông tin về gã khổng lồ thời trang nhanh.

SEO title: Shein là gì? Các bước mua hàng tại Shein đơn giản nhất

Shein không còn là cái tên xa lạ với giới trẻ yêu thời trang trên toàn thế giới.
Chỉ trong vài năm ngắn ngủi, thương hiệu này đã vươn lên trở thành gã khổng lồ
trong ngành **thời trang nhanh** (fast fashion).

## 1. Nguồn Gốc và Lịch Sử Hình Thành

Shein bắt đầu như một cửa hàng online nhỏ và mở rộng nhờ mô hình sản xuất nhanh.

### Mô hình sản xuất

- Sản xuất nhanh
- Kiểm tra đánh giá kỹ lưỡng

## 2. Cách Mua Hàng

1. Tạo tài khoản
2. Chọn sản phẩm
3. Thanh toán quốc tế an toàn

#### Lưu ý cuối

Ưu tiên những món đồ thực sự cần thiết.
MD;
    }

    private function markdownHtml(): ArticleMarkdownToHtmlService
    {
        return new ArticleMarkdownToHtmlService(new SimpleMarkdownHtmlConverter);
    }

    private function faqService(): ArticleContentFaqService
    {
        $parser = new class(new SeoPromptSettingsService, new SeoOverviewSettingsService) extends WorkflowParserService
        {
            public function parseFaqsFromContent(string $content, bool $treatAllAsFaqSection = false): array
            {
                return [];
            }

            public function removeFaqAndAppendShortcodeFromContent(string $content): string
            {
                return $content;
            }
        };

        $faqEditor = (new ReflectionClass(ArticleFaqEditorService::class))->newInstanceWithoutConstructor();

        return new ArticleContentFaqService(
            $parser,
            $this->markdownHtml(),
            new ArticlePostContentFaqPlaceholder,
            $faqEditor,
        );
    }

    public function test_canonical_prepare_import_and_to_html_for_pr4853_shape(): void
    {
        $service = $this->markdownHtml();
        $prepared = $service->prepareImport($this->pr4853LikeMarkdown());

        self::assertStringNotContainsString('Meta description:', $prepared['markdown']);
        self::assertStringNotContainsString('SEO title:', $prepared['markdown']);
        self::assertNotNull($prepared['meta_description']);
        self::assertStringContainsString('Shein là gì?', (string) $prepared['meta_description']);
        self::assertStringContainsString('Shein không còn là cái tên', $prepared['markdown']);

        $html = $service->toHtml($prepared['markdown']);
        self::assertStringContainsString('<h2>', $html);
        self::assertStringContainsString('<h3>', $html);
        self::assertStringContainsString('<h4>', $html);
        self::assertStringContainsString('<strong>thời trang nhanh</strong>', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<ol>', $html);
        self::assertMatchesRegularExpression('/<h2[^>]*>\s*1\.\s*Nguồn Gốc/u', $html);
        self::assertSame(2, preg_match_all('/<h2\b/i', $html));
        self::assertSame(0, preg_match_all('/##\s+1\.\s*Nguồn/u', $html));
    }

    public function test_prepare_editor_html_from_ai_output_reuses_convert_markdown_import(): void
    {
        $prepared = $this->faqService()->prepareEditorHtmlFromAiOutput($this->pr4853LikeMarkdown());

        self::assertSame('markdown', $prepared['source']);
        self::assertStringContainsString('<h2>', $prepared['html']);
        self::assertStringContainsString('<strong>thời trang nhanh</strong>', $prepared['html']);
        self::assertStringNotContainsString('Meta description:', $prepared['html']);
        self::assertStringNotContainsString('SEO title:', $prepared['html']);
        self::assertStringContainsString('Shein không còn là cái tên', $prepared['html']);
    }

    public function test_existing_html_document_is_not_double_parsed_as_markdown(): void
    {
        $html = '<h2>Already HTML</h2><p>Body with <strong>bold</strong> text.</p>';
        $prepared = $this->faqService()->prepareEditorHtmlFromAiOutput($html);

        self::assertSame('html', $prepared['source']);
        self::assertStringContainsString('<h2>Already HTML</h2>', $prepared['html']);
        self::assertStringContainsString('<strong>bold</strong>', $prepared['html']);
        self::assertStringNotContainsString('&lt;h2&gt;', $prepared['html']);
    }

    public function test_parser_is_html_document_gate_matches_import_parser(): void
    {
        $parser = new ArticleMarkdownImportParser;
        self::assertTrue($parser->isHtmlDocument('<p>Hello</p>'));
        self::assertTrue($parser->isHtmlDocument("<h2>Title</h2>\n<p>Body</p>"));
        self::assertFalse($parser->isHtmlDocument("## Title\n\nBody paragraph"));
        self::assertFalse($parser->isHtmlDocument('Meta description: x'));
    }

    public function test_apply_service_wires_canonical_converter_before_versioned_save(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleAiHistoryApplyService::class))->getFileName(),
        );

        self::assertStringContainsString('prepareEditorHtmlFromAiOutput', $src);
        self::assertStringContainsString('writeArticleRow', $src);
        self::assertStringContainsString('content_apply_saved', $src);
        $preparePos = strpos($src, 'prepareEditorHtmlFromAiOutput');
        $writePos = strpos($src, 'writeArticleRow');
        self::assertNotFalse($preparePos);
        self::assertNotFalse($writePos);
        self::assertLessThan($writePos, $preparePos);
    }

    public function test_edit_article_normalizes_legacy_markdown_pending_once(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle::class
            ))->getFileName(),
        );

        self::assertStringContainsString("\$payloadFormat !== 'editor_html'", $src);
        self::assertStringContainsString('prepareEditorHtmlFromAiOutput', $src);
        self::assertStringContainsString("\$payloadFormat = 'editor_html'", $src);
        self::assertStringContainsString("['payload_format'] = \$payloadFormat", $src);
        self::assertStringContainsString('ArticleAiHistoryPendingDraftStore', $src);
    }
}
