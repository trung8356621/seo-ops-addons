<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use PHPUnit\Framework\TestCase;

final class ArticleWritingInputFormatterTest extends TestCase
{
    public function test_outline_format_includes_source_rule_and_body(): void
    {
        $raw = "[START_TASK_1_OUTLINE]\nA\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\nB\n[END_TASK_2_VOCABULARY]";

        $formatted = (new ArticleWritingInputFormatter)->format(
            ArticleWritingInput::fromOutlineArtifact(
                rawArtifact: $raw,
                title: 'Tiêu đề X',
                keyword: 'từ khóa Y',
                description: 'mô tả Z',
            ),
        );

        self::assertStringContainsString('Loại đầu vào: Dàn ý và hướng dẫn viết', $formatted);
        self::assertStringContainsString('Quy tắc nguồn: Dàn ý và hướng dẫn viết là cấu trúc bắt buộc.', $formatted);
        self::assertStringContainsString('Tiêu đề: Tiêu đề X', $formatted);
        self::assertStringContainsString('Từ khóa chính: từ khóa Y', $formatted);
        self::assertStringContainsString('Mô tả bổ sung: mô tả Z', $formatted);
        self::assertStringContainsString("Nội dung đầu vào:\n".$raw, $formatted);
        self::assertStringNotContainsString('Gallery', $formatted);
    }

    public function test_empty_fields_omit_labels(): void
    {
        $formatted = (new ArticleWritingInputFormatter)->format(
            ArticleWritingInput::fromBrief(title: 'Only title'),
        );

        self::assertStringContainsString('Tiêu đề: Only title', $formatted);
        self::assertStringNotContainsString('Từ khóa chính:', $formatted);
        self::assertStringNotContainsString('Mô tả bổ sung:', $formatted);
        self::assertStringNotContainsString('Mô tả:', $formatted);
        self::assertStringNotContainsString('Nội dung đầu vào:', $formatted);
        self::assertStringContainsString('Tự xây cấu trúc phù hợp', $formatted);
    }

    public function test_existing_article_rule_and_body(): void
    {
        $formatted = (new ArticleWritingInputFormatter)->format(
            ArticleWritingInput::fromExistingArticleBody(
                bodyMarkdown: "# Old\n\nBody text",
                title: 'T',
                keyword: 'K',
            ),
        );

        self::assertStringContainsString('Loại đầu vào: Bài viết hiện có', $formatted);
        self::assertStringContainsString('không paraphrase từng câu', $formatted);
        self::assertStringContainsString("Nội dung đầu vào:\n# Old\n\nBody text", $formatted);
    }

    public function test_legacy_adapter_maps_rewrite_hook_to_generate(): void
    {
        $adapter = new ArticleWritingLegacyRewriteAdapter(new ArticleWritingInputFormatter);

        self::assertSame(
            ArticleWritingLegacyRewriteAdapter::GENERATE_HOOK,
            $adapter->canonicalizeHookKey(ArticleWritingLegacyRewriteAdapter::LEGACY_REWRITE_HOOK),
        );
        self::assertSame(
            'article.title.generate',
            $adapter->canonicalizeHookKey('article.title.generate'),
        );
        self::assertSame(ArticleWritingSourceType::ExistingArticle, $adapter->defaultSourceTypeForLegacyRewrite());
    }

    public function test_apply_to_variables_stamps_source_metadata(): void
    {
        $writing = ArticleWritingInput::fromOutlineArtifact(
            rawArtifact: 'raw',
            title: 'T',
            keyword: 'K',
            articleId: 9,
            runId: 1,
            runItemId: 2,
            sourcePromptResultId: 3,
        );
        $vars = (new ArticleWritingInputFormatter)->applyToVariables($writing, [
            'gallery_description' => 'gallery only',
        ]);

        self::assertSame(ArticleWritingSourceType::Outline->value, $vars['article_writing_source_type']);
        self::assertSame('raw', $vars['article_writing_raw_input']);
        self::assertSame(1, $vars['source_run_id']);
        self::assertSame(2, $vars['source_run_item_id']);
        self::assertSame(3, $vars['source_prompt_result_id']);
        self::assertTrue($vars['article_writing_formatted']);
        self::assertSame('gallery only', $vars['gallery_description']);
        self::assertStringContainsString('Loại đầu vào:', (string) $vars['input']);
    }

    public function test_history_metadata_includes_source_hash(): void
    {
        $meta = ArticleWritingInput::fromBrief(
            title: 'A',
            keyword: 'B',
            description: 'C',
        )->historyMetadata();

        self::assertSame('brief', $meta['source_type']);
        self::assertTrue($meta['description_present']);
        self::assertNotSame('', $meta['source_hash']);
    }
}
