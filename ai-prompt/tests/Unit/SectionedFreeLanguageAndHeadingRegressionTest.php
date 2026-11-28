<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionPromptBuilder;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionValidator;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use PHPUnit\Framework\TestCase;

final class SectionedFreeLanguageAndHeadingRegressionTest extends TestCase
{
    public function test_1_article_language_vi_includes_language_policy_in_section_prompt(): void
    {
        $builder = new SectionedFreeSectionPromptBuilder;
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_01',
            order: 0,
            label: 'Tổng quan về áo sơ mi',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [['kind' => 'h2', 'heading' => 'Tổng quan về áo sơ mi', 'level' => 2, 'body' => '']],
            requiredPoints: ['Chất liệu thoáng mát'],
            targetMinWords: 150,
            targetMaxWords: 250,
            preferredTargetWords: 200,
        );

        $prompt = $builder->build($unit, [
            'title' => 'Hướng dẫn chọn áo sơ mi',
            'primary_keyword' => 'áo sơ mi',
            'language' => 'vi',
            'article_map' => ['Tổng quan về áo sơ mi'],
        ]);

        self::assertStringContainsString('Vietnamese (vi)', $prompt);
        self::assertStringContainsString('LANGUAGE POLICY:', $prompt);
        self::assertStringContainsString('write in natural, consistent Vietnamese', $prompt);
        self::assertStringContainsString('Do not introduce unrelated foreign scripts.', $prompt);
    }

    public function test_2_article_language_non_vi_does_not_force_vietnamese(): void
    {
        $builder = new SectionedFreeSectionPromptBuilder;
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_01',
            order: 0,
            label: 'Technical SEO Overview',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [['kind' => 'h2', 'heading' => 'Technical SEO Overview', 'level' => 2, 'body' => '']],
            requiredPoints: ['Crawlability and indexing'],
            targetMinWords: 150,
            targetMaxWords: 250,
            preferredTargetWords: 200,
        );

        $prompt = $builder->build($unit, [
            'title' => 'Technical SEO Guide',
            'primary_keyword' => 'technical seo',
            'language' => 'en',
            'article_map' => ['Technical SEO Overview'],
        ]);

        self::assertStringContainsString('English (en)', $prompt);
        self::assertStringContainsString('LANGUAGE POLICY:', $prompt);
        self::assertStringNotContainsString('Vietnamese', $prompt);
    }

    public function test_3_custom_section_prompt_factory_preserves_language_policy_without_duplication(): void
    {
        $outline = "# Title\n\n## Section One\n- point a\n";
        $body = 'Nội dung tiếng Việt tự nhiên cho phần này với đầy đủ thông tin hữu ích về sản phẩm. ';
        $longBody = str_repeat($body, 15);

        $capturedWithoutPolicy = [];
        (new SectionedFreeArticleGenerator)->run(
            [
                'title' => 'Bài viết mẫu',
                'primary_keyword' => 'bài viết mẫu',
                'language' => 'vi',
                'outline' => $outline,
                'section_prompt_factory' => static fn (SectionedFreeSectionUnit $u): string => 'Custom prompt for '.$u->label,
            ],
            static function (SectionedFreeSectionUnit $u, string $prompt) use (&$capturedWithoutPolicy, $longBody): array {
                $capturedWithoutPolicy[] = $prompt;

                return ['output' => $longBody, 'model' => 'free-a'];
            },
        );

        self::assertNotEmpty($capturedWithoutPolicy);
        foreach ($capturedWithoutPolicy as $prompt) {
            self::assertStringContainsString('LANGUAGE POLICY:', $prompt);
            self::assertStringContainsString('Vietnamese (vi)', $prompt);
        }

        $capturedWithPolicy = [];
        (new SectionedFreeArticleGenerator)->run(
            [
                'title' => 'Bài viết mẫu',
                'primary_keyword' => 'bài viết mẫu',
                'language' => 'vi',
                'outline' => $outline,
                'section_prompt_factory' => static fn (SectionedFreeSectionUnit $u): string => "Custom prompt\n\nLANGUAGE POLICY:\n- Requested language: Vietnamese (vi)",
            ],
            static function (SectionedFreeSectionUnit $u, string $prompt) use (&$capturedWithPolicy, $longBody): array {
                $capturedWithPolicy[] = $prompt;

                return ['output' => $longBody, 'model' => 'free-a'];
            },
        );

        foreach ($capturedWithPolicy as $prompt) {
            self::assertSame(1, substr_count($prompt, 'LANGUAGE POLICY:'));
        }
    }

    public function test_4_multiple_pass_compiler_propagates_language_and_interpolates_output_block(): void
    {
        $prompt = new SeoPrompt;
        $prompt->system_prompt = 'System prompt in {{language}}.';
        $prompt->prompt_template = "Write section:\n{{outline}}";

        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturnCallback(
            static fn (SeoPrompt $p, array $vars): string => 'System prompt in '.($vars['language'] ?? '')."\n\n".$vars['outline']
        );

        $compiler = new WritingSectionPromptCompiler($runner);
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'group_01',
            order: 0,
            label: 'Phong cách phối đồ',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [[
                'kind' => 'h2',
                'heading' => 'Phong cách phối đồ',
                'level' => 2,
                'body' => 'Gợi ý phối đồ hằng ngày',
                'emit_heading' => true,
                'row_id' => 'r1',
            ]],
            requiredPoints: ['Gợi ý phối đồ hằng ngày'],
            targetMinWords: 120,
            targetMaxWords: 220,
            preferredTargetWords: 170,
            sourceRowIds: ['r1'],
        );

        $compiled = $compiler->compile($prompt, [
            'title' => 'Thời trang công sở',
            'language' => 'vi',
        ], $unit);

        self::assertStringNotContainsString('{{language}}', $compiled);
        self::assertStringContainsString('Vietnamese (vi)', $compiled);
        self::assertStringContainsString('LANGUAGE POLICY:', $compiled);
    }

    public function test_5_vietnamese_with_seo_api_iso_cnc_and_brand_names_remains_valid(): void
    {
        $validator = new SectionedFreeSectionValidator;
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_01',
            order: 0,
            label: 'Tối ưu kỹ thuật và tích hợp hệ thống',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [],
            requiredPoints: [],
            targetMinWords: 100,
            targetMaxWords: 200,
            preferredTargetWords: 150,
        );

        $text = str_repeat(
            'Doanh nghiệp cần chuẩn hóa quy trình SEO kỹ thuật, kết nối REST API với WordPress và hệ thống quản trị nội bộ theo tiêu chuẩn ISO 9001. '
            .'Các thiết bị gia công CNC hiện đại cùng dòng sản phẩm iPhone 16 Pro Max hay giày Nike Air Force 1 đều đòi hỏi mô tả chuẩn xác bằng tiếng Việt tự nhiên. ',
            4,
        );

        $check = $validator->checkLanguageConsistency($text, $unit, 'vi');
        self::assertTrue($check['ok']);
        self::assertFalse($check['certain_drift']);

        $words = $validator->assertAcceptable($text, $unit, 'vi');
        self::assertGreaterThanOrEqual(120, $words);
    }

    public function test_6_detects_certain_foreign_language_drift_and_warns_on_uncertain_cases(): void
    {
        $validator = new SectionedFreeSectionValidator;
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_01',
            order: 0,
            label: 'Phối đồ thanh lịch',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [],
            requiredPoints: [],
            targetMinWords: 100,
            targetMaxWords: 200,
            preferredTargetWords: 150,
        );

        // Real fixture: Chinese characters injected into Vietnamese prose.
        $cjkDrift = str_repeat('Áo phồng vai giúp cân đối tỷ lệ cơ thể người mặc hiệu quả. ', 6)
            .'Lý do: Phồng vai cân bằng 視覺重心 giúp phần thân trên hài hòa hơn.';
        $cjkCheck = $validator->checkLanguageConsistency($cjkDrift, $unit, 'vi');
        self::assertTrue($cjkCheck['certain_drift']);
        self::assertSame('unexpected_foreign_script', $cjkCheck['reason']);

        // Korean Hangul drift in Vietnamese prose.
        $hangulDrift = str_repeat('Phong cách tối giản đang được ưa chuộng tại Việt Nam. ', 6)
            .'Thiết kế này mang đậm phong cách 아이돌 패션 hiện đại.';
        $hangulCheck = $validator->checkLanguageConsistency($hangulDrift, $unit, 'vi');
        self::assertTrue($hangulCheck['certain_drift']);

        // Long English prose sentence in Vietnamese article.
        $englishDrift = str_repeat('Nội dung tiếng Việt chuẩn mực về cách chọn trang phục công sở. ', 5)
            .'This section explains why you should always choose breathable fabrics when the weather is hot and humid.';
        $enCheck = $validator->checkLanguageConsistency($englishDrift, $unit, 'vi');
        self::assertTrue($enCheck['certain_drift']);
        self::assertSame('long_foreign_sentence', $enCheck['reason']);

        // Uncertain case (single foreign char): warning only, does not throw.
        $uncertain = str_repeat('Chất liệu vải cotton mềm mại mang lại cảm giác thoải mái cả ngày dài. ', 9).'Ký hiệu 中 minh họa.';
        $uncertainCheck = $validator->checkLanguageConsistency($uncertain, $unit, 'vi');
        self::assertFalse($uncertainCheck['certain_drift']);
        self::assertNotNull($uncertainCheck['warning']);
        self::assertGreaterThan(100, $validator->assertAcceptable($uncertain, $unit, 'vi'));

        // Intentional foreign term from outline is whitelisted.
        $whitelisted = str_repeat('Phong cách này lấy cảm hứng từ thẩm mỹ 視覺重心 trong thiết kế Á Đông. ', 6);
        $whitelistCheck = $validator->checkLanguageConsistency($whitelisted, $unit, 'vi', ['Thẩm mỹ 視覺重心']);
        self::assertFalse($whitelistCheck['certain_drift']);
    }

    public function test_7_retry_only_failing_section_on_language_drift_with_bounded_limit(): void
    {
        $outline = "# Title\n\n## Phần một\n- a1\n\n## Phần hai\n- b1\n";
        $validVi = str_repeat('Nội dung tiếng Việt tự nhiên, mạch lạc và đúng chủ đề được yêu cầu. ', 15);
        $driftVi = $validVi.' Lý do: Phồng vai cân bằng 視覺重心.';

        $callsBySection = [];
        $result = (new SectionedFreeArticleGenerator)->run(
            ['title' => 'Bài viết', 'primary_keyword' => 'bài viết', 'language' => 'vi', 'outline' => $outline],
            static function (SectionedFreeSectionUnit $unit) use (&$callsBySection, $validVi, $driftVi): array {
                $callsBySection[$unit->sectionId] = ($callsBySection[$unit->sectionId] ?? 0) + 1;
                if ($unit->sectionId === 'section_02' && $callsBySection[$unit->sectionId] === 1) {
                    return ['output' => $driftVi, 'model' => 'free-a'];
                }

                return ['output' => $validVi, 'model' => 'free-b'];
            },
        );

        self::assertSame(1, $callsBySection['section_01']);
        self::assertSame(2, $callsBySection['section_02']);
        self::assertStringNotContainsString('視覺重心', $result['assembled']);

        // Persistent drift fails boundedly after 1 retry (2 total calls), never infinite loop.
        $persistentCalls = 0;
        try {
            (new SectionedFreeArticleGenerator)->run(
                ['title' => 'Bài viết', 'primary_keyword' => 'bài viết', 'language' => 'vi', 'outline' => "# Title\n\n## Phần một\n- a1\n"],
                static function () use (&$persistentCalls, $driftVi): array {
                    $persistentCalls++;

                    return ['output' => $driftVi, 'model' => 'free-a'];
                },
            );
            self::fail('Expected SECTIONED_FREE_SECTION_FAILED');
        } catch (PromptRunException $e) {
            self::assertSame('SECTIONED_FREE_SECTION_FAILED', $e->context['failure_code'] ?? null);
            self::assertSame(2, $persistentCalls);
        }
    }

    public function test_8_to_11_strips_markdown_plaintext_html_and_numbered_duplicate_headings(): void
    {
        $assembler = new SectionedFreeAssembleArticle;
        $body = str_repeat('Nội dung chi tiết giúp bạn phối đồ đẹp và tự tin hơn mỗi ngày. ', 12);

        $units = [
            new SectionedFreeSectionUnit(
                sectionId: 's1',
                order: 0,
                label: '3.2. Phong cách Casual/Streetwear: Năng động, Cá tính',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'h3',
                    'heading' => '3.2. Phong cách Casual/Streetwear: Năng động, Cá tính',
                    'level' => 3,
                    'body' => '',
                    'emit_heading' => true,
                ]],
                requiredPoints: [],
                targetMinWords: 50,
                targetMaxWords: 200,
                preferredTargetWords: 120,
            ),
            new SectionedFreeSectionUnit(
                sectionId: 's2',
                order: 1,
                label: 'Phong cách Công sở',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'h2',
                    'heading' => 'Phong cách Công sở',
                    'level' => 2,
                    'body' => '',
                    'emit_heading' => true,
                ]],
                requiredPoints: [],
                targetMinWords: 50,
                targetMaxWords: 200,
                preferredTargetWords: 120,
            ),
            new SectionedFreeSectionUnit(
                sectionId: 's3',
                order: 2,
                label: 'Phụ kiện đi kèm',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'h2',
                    'heading' => 'Phụ kiện đi kèm',
                    'level' => 2,
                    'body' => '',
                    'emit_heading' => true,
                ]],
                requiredPoints: [],
                targetMinWords: 50,
                targetMaxWords: 200,
                preferredTargetWords: 120,
            ),
            new SectionedFreeSectionUnit(
                sectionId: 's4',
                order: 3,
                label: 'Bảo quản trang phục',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'h3',
                    'heading' => 'Bảo quản trang phục',
                    'level' => 3,
                    'body' => '',
                    'emit_heading' => true,
                ]],
                requiredPoints: [],
                targetMinWords: 50,
                targetMaxWords: 200,
                preferredTargetWords: 120,
            ),
        ];

        $assembled = $assembler->assemble([
            // 8 & 11: Markdown heading with wrong level & numbered H3
            ['section_id' => 's1', 'section_order' => 0, 'status' => 'completed', 'output' => "## 3.2. Phong cách Casual/Streetwear: Năng động, Cá tính\n".$body],
            // 9: Plain-text heading
            ['section_id' => 's2', 'section_order' => 1, 'status' => 'completed', 'output' => "Phong cách Công sở\n".$body],
            // 10: HTML heading
            ['section_id' => 's3', 'section_order' => 2, 'status' => 'completed', 'output' => "<h2>Phụ kiện đi kèm</h2>\n".$body],
            // 9 + formatting: Bold heading with numbering variation
            ['section_id' => 's4', 'section_order' => 3, 'status' => 'completed', 'output' => "**4.1. Bảo quản trang phục:**\n".$body],
        ], $units);

        self::assertSame(1, substr_count($assembled, '3.2. Phong cách Casual/Streetwear: Năng động, Cá tính'));
        self::assertSame(1, substr_count($assembled, 'Phong cách Công sở'));
        self::assertSame(1, substr_count($assembled, 'Phụ kiện đi kèm'));
        self::assertSame(1, substr_count($assembled, 'Bảo quản trang phục'));
        self::assertStringNotContainsString('<h2>', $assembled);
    }

    public function test_12_parent_h2_in_continuation_is_not_duplicated(): void
    {
        $assembler = new SectionedFreeAssembleArticle;
        $body = str_repeat('Nội dung phần tiếp theo rất chi tiết và hữu ích cho người đọc. ', 10);

        $u1 = new SectionedFreeSectionUnit(
            sectionId: 's1',
            order: 0,
            label: 'Cách chọn chất liệu',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [[
                'kind' => 'h3',
                'heading' => 'Vải cotton',
                'level' => 3,
                'body' => '',
                'emit_heading' => true,
            ]],
            requiredPoints: [],
            targetMinWords: 50,
            targetMaxWords: 200,
            preferredTargetWords: 120,
            parentH2: 'Cách chọn chất liệu',
            emitParentHeading: true,
        );

        $u2 = new SectionedFreeSectionUnit(
            sectionId: 's2',
            order: 1,
            label: 'Vải linen',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [[
                'kind' => 'h3',
                'heading' => 'Vải linen',
                'level' => 3,
                'body' => '',
                'emit_heading' => true,
            ]],
            requiredPoints: [],
            targetMinWords: 50,
            targetMaxWords: 200,
            preferredTargetWords: 120,
            parentH2: 'Cách chọn chất liệu',
            emitParentHeading: false,
        );

        $assembled = $assembler->assemble([
            ['section_id' => 's1', 'section_order' => 0, 'status' => 'completed', 'output' => "### Vải cotton\n".$body],
            ['section_id' => 's2', 'section_order' => 1, 'status' => 'completed', 'output' => "Cách chọn chất liệu — tiếp\n### Vải linen\n".$body],
        ], [$u1, $u2]);

        self::assertSame(1, preg_match_all('/^##\s+Cách chọn chất liệu$/mu', $assembled));
        self::assertSame(1, preg_match_all('/^###\s+Vải cotton$/mu', $assembled));
        self::assertSame(1, preg_match_all('/^###\s+Vải linen$/mu', $assembled));
    }

    public function test_13_to_17_grouped_rows_intro_conclusion_word_parity_body_preservation_and_order(): void
    {
        $rows = [
            ['id' => 'intro', 'kind' => 'intro', 'level' => 0, 'title' => 'Mở bài', 'note' => 'Giới thiệu'],
            ['id' => 'h2_1', 'kind' => 'h2', 'level' => 2, 'title' => '1. Xu hướng thời trang', 'note' => 'Tổng quan'],
            ['id' => 'h3_1', 'kind' => 'h3', 'level' => 3, 'title' => '1.1. Tối giản', 'note' => 'Minimalism'],
            ['id' => 'h3_2', 'kind' => 'h3', 'level' => 3, 'title' => '1.2. Streetwear', 'note' => 'Cá tính'],
            ['id' => 'concl', 'kind' => 'conclusion', 'level' => 2, 'title' => 'Kết luận', 'note' => 'Tóm tắt'],
        ];

        $plan = (new WritingMultiplePassStepPlanner)->planFromRows($rows);
        $assembler = new SectionedFreeAssembleArticle;
        $body = str_repeat('Nội dung phân tích chuyên sâu bằng tiếng Việt chuẩn mực. ', 15);
        $normalSentenceWithHeadingWords = '1.1. Tối giản là lựa chọn hàng đầu của giới trẻ hiện nay khi phối đồ. ';

        $sections = [];
        foreach ($plan->units as $unit) {
            $markers = $unit->managedRowMarkers();
            if ($markers !== []) {
                $chunks = [];
                foreach ($markers as $marker => $meta) {
                    // AI echoes duplicate headings in plain-text/HTML/Markdown inside markers.
                    $chunks[] = $marker."\n".$meta['heading']."\n".$normalSentenceWithHeadingWords.$body;
                }
                $out = implode("\n\n", $chunks);
            } elseif ($unit->role === SectionedFreeSectionUnit::ROLE_INTRO) {
                $out = $body;
            } else {
                $out = "## Kết luận\n".$body;
            }
            $sections[] = [
                'section_id' => $unit->sectionId,
                'section_order' => $unit->order,
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
                'output' => $out,
            ];
        }

        $assembled = $assembler->assemble($sections, $plan->units);
        $parity = $assembler->assertWordParity($sections, $assembled, 40, $plan->units);

        // 13 & 17: Grouped rows keep H2/H3 once, in exact outline order.
        self::assertSame([
            '## 1. Xu hướng thời trang',
            '### 1.1. Tối giản',
            '### 1.2. Streetwear',
            '## Kết luận',
        ], $assembler->parseHeadingSequence($assembled));

        // 14: Intro has no invented heading; conclusion has only its outline heading.
        self::assertStringNotContainsString('## Mở bài', $assembled);
        self::assertSame(1, preg_match_all('/^##\s+Kết luận$/mu', $assembled));

        // 15: Word parity valid.
        self::assertGreaterThan(0, $parity['assembled_words']);

        // 16: Normal body sentence containing heading words was NOT stripped.
        self::assertStringContainsString(trim($normalSentenceWithHeadingWords), $assembled);
    }
}
