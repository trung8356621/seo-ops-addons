<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeChildOutputNormalizer;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use PHPUnit\Framework\TestCase;

final class SectionedFreeMetaAndMarkdownPipelineTest extends TestCase
{
    public function test_child_metadata_wrappers_stripped_on_assemble(): void
    {
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'h3_01',
            order: 0,
            label: 'A',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [
                ['kind' => 'h3', 'level' => 3, 'heading' => 'A', 'body' => '', 'emit_heading' => true],
            ],
            requiredPoints: [],
            targetMinWords: 40,
            targetMaxWords: 120,
            preferredTargetWords: 80,
            parentH2: 'Parent',
            emitParentHeading: true,
            includedH3s: ['A'],
        );

        $child = <<<'MD'
Meta description: Đây là meta bị lặp từ section child.

SEO title: Title bị lặp từ section child.

Body paragraph about canvas bags and logo print quality.
MD;

        $assembled = (new SectionedFreeAssembleArticle())->assemble([
            [
                'section_id' => 'h3_01',
                'section_order' => 0,
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
                'output' => $child,
            ],
        ], [$unit]);

        self::assertStringNotContainsString('Meta description:', $assembled);
        self::assertStringNotContainsString('SEO title:', $assembled);
        self::assertStringContainsString('## Parent', $assembled);
        self::assertStringContainsString('### A', $assembled);
        self::assertStringContainsString('Body paragraph about canvas bags', $assembled);
    }

    public function test_normalizer_counts_metadata_occurrences(): void
    {
        $n = new SectionedFreeChildOutputNormalizer();
        $text = "Meta description: one\n\nBody\n\nMeta description: two\nSEO title: t\n";
        $counts = $n->countMetadataOccurrences($text);
        self::assertSame(2, $counts['meta_description']);
        self::assertSame(1, $counts['seo_title']);
        $clean = $n->stripArticleMetadataWrappers($text);
        self::assertStringNotContainsString('Meta description:', $clean);
        self::assertStringContainsString('Body', $clean);
    }

    public function test_markdown_headings_survive_prepare_import_and_to_html(): void
    {
        $md = <<<'MD'
Meta description: Strip me.

SEO title: Strip me title.

## H2 title

### H3 title

**bold text**

## **H2 emphasized**

Paragraph after.
MD;

        $service = new ArticleMarkdownToHtmlService(new SimpleMarkdownHtmlConverter());
        $prepared = $service->prepareImport($md);
        self::assertStringNotContainsString('Meta description:', $prepared['markdown']);
        self::assertSame('Strip me title.', $prepared['seo_title'] ?? null);

        $html = $service->toHtml($prepared['markdown']);
        self::assertStringContainsString('<h2>H2 title</h2>', $html);
        self::assertStringContainsString('<h3>H3 title</h3>', $html);
        self::assertStringContainsString('<strong>bold text</strong>', $html);
        self::assertMatchesRegularExpression('/<h2>\s*<strong>H2 emphasized<\/strong>\s*<\/h2>/i', $html);
        self::assertStringNotContainsString('<p><strong>H2 title</strong></p>', $html);
    }

    public function test_featured_snippet_downgrade_is_fs_only_path(): void
    {
        $converter = new SimpleMarkdownHtmlConverter();
        $articleHtml = $converter->toHtml("## Keep Heading\n\nBody.");
        self::assertStringContainsString('<h2>Keep Heading</h2>', $articleHtml);

        $fsHtml = $converter->toFeaturedSnippetEditorHtml("## FS Heading\n\nrow");
        self::assertStringContainsString('<p><strong>FS Heading</strong></p>', $fsHtml);
        self::assertStringNotContainsString('<h2>', $fsHtml);
    }

    public function test_multiple_pass_faq_unit_skips_ai_and_emits_placeholder(): void
    {
        $rows = (new \Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer())->normalize(<<<'MD'
[MỞ BÀI]
Intro notes

## Body H2
### Child
notes

## Câu hỏi thường gặp
- Q1?

## Kết luận
Done
MD);
        $plan = (new \Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner())->planFromRows($rows);
        $faqIds = [];
        foreach ($plan->units as $u) {
            if ($u->role === SectionedFreeSectionUnit::ROLE_FAQ) {
                $faqIds[] = $u->sectionId;
            }
        }
        self::assertNotEmpty($faqIds);

        $called = [];
        $body = str_repeat('word ', 130);
        $result = (new \Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator())->run(
            [
                'outline' => 'x',
                'writing_split_enabled' => true,
                'writing_multiple_pass_plan' => $plan,
                'title' => 'Canonical Title',
                'article_length' => '400',
                'section_prompt_factory' => static function (SectionedFreeSectionUnit $unit): string {
                    return \Omnichannel\Addons\AiPrompt\Support\WritingSectionScopeInstructions::wrapSlice(
                        $unit->scopeMarkdown(),
                        $unit->role,
                        true,
                        'Canonical Title',
                    );
                },
            ],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$called, $body): array {
                $called[] = $unit->sectionId;

                return [
                    'output' => $body,
                    'model' => 'm',
                    'provider' => 'p',
                    'connection_id' => 1,
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
        );

        foreach ($faqIds as $id) {
            self::assertNotContains($id, $called);
            self::assertStringContainsString('[omi_faq]', (string) ($result['state']->toArray()['sections'][$id]['output'] ?? ''));
        }
        self::assertStringContainsString('[omi_faq]', $result['assembled']);
    }
}
