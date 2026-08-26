<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\AiGeneratedContentNormalizer;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiGeneratedContentNormalizerTest extends TestCase
{
    private function normalizer(): AiGeneratedContentNormalizer
    {
        return new AiGeneratedContentNormalizer;
    }

    public function test_inline_emoji_paragraph_structure_preserved(): void
    {
        $in = '<p>✅ Độ tin cậy cao: Quy trình sản xuất khép kín đảm bảo sản phẩm luôn đạt chuẩn. ✅ Công nghệ hiện đại: Sử dụng máy móc tiên tiến. ✅ Hỗ trợ tùy biến: Khách hàng có thể yêu cầu logo riêng.</p>';
        $out = $this->normalizer()->normalizeHtml($in);

        self::assertSame($in, $out);
        self::assertSame(1, substr_count(strtolower($out), '<p'));
        self::assertSame(0, substr_count(strtolower($out), '<br'));
        self::assertSame(0, substr_count($out, "\n"));
    }

    public function test_typography_fix_does_not_add_breaks_around_emoji(): void
    {
        $in = '<p>✅ Độ tin cậy cao: Tốt. ✅ Công nghệ hiện đại: Có kỹ thuật,ứng dụng thực tế. ✅ Hỗ trợ tùy biến: Linh hoạt.</p>';
        $out = $this->normalizer()->normalizeHtml($in);

        self::assertSame(
            '<p>✅ Độ tin cậy cao: Tốt. ✅ Công nghệ hiện đại: Có kỹ thuật, ứng dụng thực tế. ✅ Hỗ trợ tùy biến: Linh hoạt.</p>',
            $out,
        );
        self::assertSame(0, substr_count($out, "\n"));
        self::assertSame(0, substr_count(strtolower($out), '<br'));
    }

    public function test_existing_br_and_paragraphs_preserved(): void
    {
        self::assertSame(
            '<p>Dòng thứ nhất.<br>Dòng thứ hai.</p>',
            $this->normalizer()->normalizeHtml('<p>Dòng thứ nhất.<br>Dòng thứ hai.</p>'),
        );
        self::assertSame(
            '<p>Paragraph 1.</p><p>Paragraph 2.</p>',
            $this->normalizer()->normalizeHtml('<p>Paragraph 1.</p><p>Paragraph 2.</p>'),
        );
    }

    public function test_list_and_bold_inline_structure_preserved(): void
    {
        $list = '<ul><li>✅ Ưu điểm một</li><li>✅ Ưu điểm hai</li><li>✅ Ưu điểm ba</li></ul>';
        self::assertSame($list, $this->normalizer()->normalizeHtml($list));

        $bold = '<p>✅ <strong>Độ tin cậy cao:</strong> Quy trình tốt. ✅ <strong>Công nghệ hiện đại:</strong> Máy móc tiên tiến.</p>';
        self::assertSame($bold, $this->normalizer()->normalizeHtml($bold));
        self::assertSame(1, substr_count(strtolower($bold), '<p'));
        self::assertSame(2, substr_count(strtolower($this->normalizer()->normalizeHtml($bold)), '<strong'));
    }

    public function test_full_pipeline_soft_break_markdown_plus_typography(): void
    {
        $md = "✅ Độ tin cậy cao: Quy trình tốt.\n✅ Công nghệ hiện đại: Có kỹ thuật,ứng dụng.\n✅ Hỗ trợ tùy biến: Linh hoạt.";
        $import = (new \Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter)->toHtml($md);
        $normalized = $this->normalizer()->normalizeHtml($import);

        self::assertStringContainsString('kỹ thuật, ứng dụng', $normalized);
        self::assertStringNotContainsString('kỹ thuật,ứng dụng', $normalized);
        self::assertStringNotContainsString('<br', strtolower($normalized));
        self::assertDoesNotMatchRegularExpression('/\n\s*✅/u', $normalized);
        self::assertStringContainsString('Quy trình tốt. ✅ Công nghệ', $normalized);
    }

    public function test_punctuation_comma_vietnamese(): void
    {
        self::assertSame(
            'kỹ thuật, ứng dụng thực tế đến mẹo pro',
            $this->normalizer()->normalizePlainText('kỹ thuật,ứng dụng thực tế đến mẹo pro'),
        );
        self::assertSame(
            'cách chọn, cách dùng và bảo quản',
            $this->normalizer()->normalizePlainText('cách chọn,cách dùng và bảo quản'),
        );
        self::assertSame(
            'học tập, làm việc',
            $this->normalizer()->normalizePlainText('học tập,làm việc'),
        );
    }

    public function test_punctuation_semicolon_colon_exclaim_question(): void
    {
        self::assertSame('abc; def', $this->normalizer()->normalizePlainText('abc;def'));
        self::assertSame('Lưu ý: Không sử dụng', $this->normalizer()->normalizePlainText('Lưu ý:Không sử dụng'));
        self::assertSame('Stop! Now', $this->normalizer()->normalizePlainText('Stop!Now'));
        self::assertSame('Ready? Go', $this->normalizer()->normalizePlainText('Ready?Go'));
    }

    public function test_urls_emails_domains_numbers_unchanged(): void
    {
        $n = $this->normalizer();
        self::assertSame('https://example.com/a,b', $n->normalizePlainText('https://example.com/a,b'));
        self::assertSame('https://example.com?a=1,b=2', $n->normalizePlainText('https://example.com?a=1,b=2'));
        self::assertSame('www.example.com/a,b', $n->normalizePlainText('www.example.com/a,b'));
        self::assertSame('test@example.com', $n->normalizePlainText('test@example.com'));
        self::assertSame('example.com', $n->normalizePlainText('example.com'));
        self::assertSame('1.5', $n->normalizePlainText('1.5'));
        self::assertSame('10,000', $n->normalizePlainText('10,000'));
        self::assertSame('1,000,000', $n->normalizePlainText('1,000,000'));
        self::assertSame('192.168.1.1', $n->normalizePlainText('192.168.1.1'));
        self::assertSame('v1.2.3', $n->normalizePlainText('v1.2.3'));
    }

    public function test_does_not_split_direct_word_glue(): void
    {
        self::assertSame('sổtay', $this->normalizer()->normalizePlainText('sổtay'));
        self::assertSame('máytính', $this->normalizer()->normalizePlainText('máytính'));
    }

    public function test_html_text_node_normalized_attributes_preserved(): void
    {
        $html = '<a href="https://example.com/a,b" data-value="abc,def">kỹ thuật,ứng dụng</a>';
        $out = $this->normalizer()->normalizeHtml($html);
        self::assertStringContainsString('href="https://example.com/a,b"', $out);
        self::assertStringContainsString('data-value="abc,def"', $out);
        self::assertStringContainsString('kỹ thuật, ứng dụng', $out);
    }

    public function test_html_paragraph(): void
    {
        $out = $this->normalizer()->normalizeHtml('<p>kỹ thuật,ứng dụng thực tế</p>');
        self::assertSame('<p>kỹ thuật, ứng dụng thực tế</p>', $out);
    }

    public function test_code_pre_script_style_unchanged(): void
    {
        $n = $this->normalizer();
        self::assertStringContainsString('<pre>foo,bar</pre>', $n->normalizeHtml('<pre>foo,bar</pre>'));
        self::assertStringContainsString('<code>foo,bar</code>', $n->normalizeHtml('<code>foo,bar</code>'));
        self::assertStringContainsString('<script>a,b</script>', $n->normalizeHtml('<script>a,b</script>'));
        self::assertStringContainsString('<style>.a,b{}</style>', $n->normalizeHtml('<style>.a,b{}</style>'));
    }

    public function test_publish_article_wires_normalizer(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptTestPublishService::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('AiGeneratedContentNormalizer', $src);
        self::assertStringContainsString('normalizeHtml', $src);
    }

    public function test_generate_rewrite_improve_use_publish_article(): void
    {
        $writing = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleWritingExecutionService::class))->getFileName() ?: '',
        );
        $improve = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleImproveExecutionService::class))->getFileName() ?: '',
        );
        $runner = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('publishArticle', $writing);
        self::assertStringContainsString('publishArticle', $improve);
        self::assertStringContainsString('publishArticle', $runner);
    }

    public function test_markdown_import_then_normalize_pipeline(): void
    {
        $faqServiceFile = (string) file_get_contents(
            (new ReflectionClass(ArticleContentFaqService::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('convertMarkdownImport', $faqServiceFile);

        $md = "## Mục\n\nkỹ thuật,ứng dụng thực tế";
        $import = (new \Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter)->toHtml($md);
        $normalized = $this->normalizer()->normalizeHtml($import);
        self::assertStringContainsString('kỹ thuật, ứng dụng', $normalized);
        self::assertStringNotContainsString('kỹ thuật,ứng dụng', $normalized);
    }
}
