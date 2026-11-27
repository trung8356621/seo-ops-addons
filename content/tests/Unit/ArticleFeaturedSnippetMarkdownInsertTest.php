<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\Content\Services\ArticleFeaturedSnippetGeneratorService;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Services\ArticlePostContentFaqPlaceholder;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Featured Snippet Insert must hydrate ## H2 + Markdown tables via the canonical
 * prepareEditorHtmlFromAiOutput path (including outer ```markdown fences).
 */
final class ArticleFeaturedSnippetMarkdownInsertTest extends TestCase
{
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
            new ArticleMarkdownToHtmlService(new SimpleMarkdownHtmlConverter),
            new ArticlePostContentFaqPlaceholder,
            $faqEditor,
        );
    }

    private function generatorForConvert(): ArticleFeaturedSnippetGeneratorService
    {
        $ref = new ReflectionClass(ArticleFeaturedSnippetGeneratorService::class);
        /** @var ArticleFeaturedSnippetGeneratorService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('contentFaq');
        $prop->setAccessible(true);
        $prop->setValue($service, $this->faqService());

        return $service;
    }

    private function sampleMarkdown(): string
    {
        return <<<'MD'
## So sánh sản phẩm nổi bật

| Tiêu chí | Option A | Option B |
|---|---|---|
| Giá | 10 | 20 |
| Chất lượng | Cao | Trung bình |
MD;
    }

    public function test_convert_preserves_h2_and_markdown_table(): void
    {
        $html = $this->generatorForConvert()->convertOutputToEditorHtml($this->sampleMarkdown());

        self::assertStringContainsString('<h2>So sánh sản phẩm nổi bật</h2>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Tiêu chí</th>', $html);
        self::assertStringContainsString('<td>Giá</td>', $html);
        self::assertGreaterThanOrEqual(3, preg_match_all('/<tr\b/i', $html));
        self::assertStringNotContainsString('## ', $html);
        self::assertStringNotContainsString('|---|', $html);
        self::assertStringNotContainsString('<pre>', $html);
    }

    public function test_convert_unwraps_outer_markdown_fence(): void
    {
        $fenced = "```markdown\n".$this->sampleMarkdown()."\n```";
        $html = $this->generatorForConvert()->convertOutputToEditorHtml($fenced);

        self::assertStringContainsString('<h2>So sánh sản phẩm nổi bật</h2>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringNotContainsString('<pre>', $html);
        self::assertStringNotContainsString('|---|', $html);
        self::assertStringNotContainsString('## So sánh', $html);
    }

    public function test_generator_uses_canonical_prepare_editor_html_path(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleFeaturedSnippetGeneratorService::class))->getFileName(),
        );

        self::assertStringContainsString('prepareEditorHtmlFromAiOutput', $src);
        self::assertStringContainsString('convertOutputToEditorHtml', $src);
        self::assertStringNotContainsString('->toFeaturedSnippetEditorHtml(', $src);
        self::assertStringNotContainsString('$this->markdownHtml->toHtml', $src);
    }

    public function test_insert_pipeline_keeps_real_h2_section_parser(): void
    {
        $outline = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/js/hooks/useArticleEditorOutline.js',
        );
        $blocks = (string) file_get_contents(
            dirname(__DIR__, 3).'/media/resources/js/utils/blockImageUtils.js',
        );
        $modal = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo/resources/js/components/FeaturedSnippetPromptModal.jsx',
        );

        self::assertStringContainsString('parseFeaturedSnippetNewSectionBlocks', $outline);
        self::assertStringContainsString('syncOutlineForNewSectionBlock(headingBlock, afterHeadingId)', $outline);
        self::assertStringContainsString('confirmFeaturedSnippetPromptInsert', $outline);
        self::assertStringNotContainsString('callEditArticleLivewire', substr(
            $outline,
            (int) strpos($outline, 'confirmFeaturedSnippetPromptInsert'),
            800,
        ));

        self::assertStringContainsString('export function parseFeaturedSnippetNewSectionBlocks', $blocks);
        self::assertStringContainsString("node.tagName.toLowerCase() === 'h2'", $blocks);

        self::assertStringContainsString('data-seo-fs-insert', $modal);
        self::assertStringContainsString('onConfirmInsert', $modal);
        self::assertStringContainsString('featured_snippet_prompt_insert', $modal);
    }

    public function test_convert_output_method_is_public_for_insert_hydration(): void
    {
        $method = new ReflectionMethod(ArticleFeaturedSnippetGeneratorService::class, 'convertOutputToEditorHtml');
        self::assertTrue($method->isPublic());
    }
}
