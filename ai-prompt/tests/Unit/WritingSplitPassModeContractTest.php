<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePlan;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\Support\WritingSectionScopeInstructions;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WritingSplitPassModeContractTest extends TestCase
{
    private const SAMPLE_OUTLINE = <<<'MD'
[MỞ BÀI — KHÔNG HEADING]
Giới thiệu ngắn về chủ đề.

## Lợi ích sản phẩm
Gợi ý: nêu 2 lợi ích chính.

### Tăng hiệu suất
Note: nêu số liệu.

### Giảm chi phí
Note: so sánh trước/sau.

## Câu hỏi thường gặp
- Hỏi 1?
- Hỏi 2?

## Kết luận
Tóm tắt và CTA.
MD;

    public function test_toggle_off_is_single_pass_even_for_free_primary(): void
    {
        // Legacy helper retained; runtime authority is fromRouteCostClass / GenerationShapeResolver.
        self::assertSame(
            ArticleGenerationShape::SinglePass,
            ArticleGenerationShape::fromWritingSplitEnabled(false),
        );
        self::assertSame(
            ArticleGenerationShape::Sectioned,
            ArticleGenerationShape::fromRouteCostClass('free'),
        );
        self::assertSame(
            ArticleGenerationShape::SinglePass,
            ArticleGenerationShape::fromRouteCostClass('paid'),
        );
    }

    public function test_toggle_on_is_multiple_pass_even_for_paid_primary(): void
    {
        self::assertSame(
            ArticleGenerationShape::Sectioned,
            ArticleGenerationShape::fromWritingSplitEnabled(true),
        );
        // Runtime ignores this preference — paid route cost wins.
        self::assertSame(
            ArticleGenerationShape::SinglePass,
            ArticleGenerationShape::fromPrimaryIsFree(false),
        );
    }

    public function test_planner_reads_structured_rows_not_parse_nodes(): void
    {
        $rows = (new OutlineStructuredRowsNormalizer())->normalize(self::SAMPLE_OUTLINE);
        self::assertNotEmpty($rows);
        self::assertSame(OutlineStructuredRowsNormalizer::KIND_INTRO, $rows[0]['kind']);

        $plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);
        $roles = array_map(static fn (SectionedFreeSectionUnit $u): string => $u->role, $plan->units);

        self::assertContains(SectionedFreeSectionUnit::ROLE_INTRO, $roles);
        self::assertContains(SectionedFreeSectionUnit::ROLE_FAQ, $roles);
        self::assertContains(SectionedFreeSectionUnit::ROLE_CONCLUSION, $roles);
        self::assertSame(1, count(array_filter($roles, static fn (string $r): bool => $r === SectionedFreeSectionUnit::ROLE_FAQ)));
        self::assertSame(1, count(array_filter($roles, static fn (string $r): bool => $r === SectionedFreeSectionUnit::ROLE_CONCLUSION)));

        $h3Labels = [];
        foreach ($plan->units as $unit) {
            if ($unit->role === SectionedFreeSectionUnit::ROLE_BODY) {
                $h3Labels[] = $unit->label;
            }
        }
        self::assertContains('Tăng hiệu suất', $h3Labels);
        self::assertContains('Giảm chi phí', $h3Labels);
        self::assertSame('structured_outline_rows', $plan->meta['source'] ?? null);
    }

    public function test_conclusion_and_faq_not_duplicated_as_bare_h2(): void
    {
        $rows = (new OutlineStructuredRowsNormalizer())->normalize(self::SAMPLE_OUTLINE);
        $plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);

        $conclusionCount = 0;
        $faqCount = 0;
        foreach ($plan->units as $unit) {
            if ($unit->role === SectionedFreeSectionUnit::ROLE_CONCLUSION) {
                $conclusionCount++;
            }
            if ($unit->role === SectionedFreeSectionUnit::ROLE_FAQ) {
                $faqCount++;
            }
            if ($unit->role === SectionedFreeSectionUnit::ROLE_BODY) {
                self::assertStringNotContainsStringIgnoringCase('kết luận', $unit->label);
                self::assertStringNotContainsStringIgnoringCase('faq', $unit->label);
                self::assertStringNotContainsStringIgnoringCase('câu hỏi', $unit->label);
            }
        }
        self::assertSame(1, $conclusionCount);
        self::assertSame(1, $faqCount);
    }

    public function test_compiled_h3_prompt_contains_scope_and_slice_only(): void
    {
        $rows = (new OutlineStructuredRowsNormalizer())->normalize(self::SAMPLE_OUTLINE);
        $plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);
        $h3 = null;
        foreach ($plan->units as $unit) {
            if ($unit->label === 'Tăng hiệu suất') {
                $h3 = $unit;
                break;
            }
        }
        self::assertInstanceOf(SectionedFreeSectionUnit::class, $h3);

        $slice = $h3->scopeMarkdown();
        $scoped = WritingSectionScopeInstructions::wrapSlice(
            $slice,
            OutlineStructuredRowsNormalizer::KIND_H3,
            true,
        );

        // Simulate SAME Writing prompt that only has {{input}} — scope must still appear.
        $compiled = str_replace('{{input}}', $scoped, "Viết bài SEO đầy đủ.\n\n{{input}}");

        self::assertStringContainsString('WRITING SCOPE: SECTION', $compiled);
        self::assertStringContainsString('Không tạo lại SEO title', $compiled);
        self::assertStringContainsString('Tăng hiệu suất', $compiled);
        self::assertStringNotContainsString('Giảm chi phí', $compiled);
        self::assertStringNotContainsString('Câu hỏi thường gặp', $compiled);
        self::assertStringNotContainsString('Kết luận', $compiled);
        self::assertStringNotContainsString('previous section output', mb_strtolower($compiled));
    }

    public function test_generator_retry_failed_middle_step_reuses_completed(): void
    {
        $rows = (new OutlineStructuredRowsNormalizer())->normalize(self::SAMPLE_OUTLINE);
        $plan = (new WritingMultiplePassStepPlanner())->planFromRows($rows);
        self::assertGreaterThanOrEqual(3, count($plan->units));

        $longBody = str_repeat('section body word ', 130);
        $wordCount = str_word_count(trim(preg_replace('/\s+/u', ' ', $longBody) ?? ''));
        $state = new SectionedFreeRunState();
        $state->setRun('test_run');
        foreach ($plan->units as $unit) {
            $state->planSection($unit);
        }
        $first = $plan->units[0];
        $second = $plan->units[1];
        $state->markCompleted($first->sectionId, $longBody, $wordCount);
        $state->markFailed($second->sectionId, 'provider error');

        $ctx = [
            'outline' => self::SAMPLE_OUTLINE,
            'article_outline' => self::SAMPLE_OUTLINE,
            'writing_split_enabled' => true,
            'pass_mode' => 'multiple_pass',
            'structured_outline_rows' => $rows,
            'writing_multiple_pass_plan' => $plan,
            'section_prompt_factory' => static function (SectionedFreeSectionUnit $unit): string {
                return WritingSectionScopeInstructions::wrapSlice(
                    $unit->scopeMarkdown(),
                    $unit->role,
                    true,
                );
            },
        ];

        $executor = function (SectionedFreeSectionUnit $unit, string $prompt) use (&$executed, $longBody): array {
            $executed[] = $unit->sectionId;
            self::assertStringContainsString('WRITING SCOPE: SECTION', $prompt);

            return [
                'output' => $longBody,
                'model' => 'deepseek/deepseek-chat',
                'provider' => 'openrouter',
                'connection_id' => 1,
                'attempt_count' => 1,
                'fallback_count' => 0,
            ];
        };

        $executed = [];
        $generator = new SectionedFreeArticleGenerator();

        // Retry only the failed middle step — earlier completed stays; remaining incomplete not run yet.
        try {
            $generator->run($ctx, $executor, $state, $second->sectionId, 'test_run');
        } catch (\Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException $e) {
            // Expected: remaining sections still incomplete after single-section retry.
            self::assertSame('SECTIONED_FREE_SECTION_FAILED', $e->context['failure_code'] ?? null);
        }

        self::assertContains($second->sectionId, $executed);
        self::assertNotContains($first->sectionId, $executed);
        self::assertTrue($state->isCompleted($first->sectionId));
        self::assertTrue($state->isCompleted($second->sectionId));

        // Resume remaining steps (no rerun id) — completed reused.
        $executed = [];
        $result = $generator->run($ctx, $executor, $state, null, 'test_run');
        self::assertNotContains($first->sectionId, $executed);
        self::assertNotContains($second->sectionId, $executed);
        self::assertNotSame('', $result['assembled']);
        self::assertStringNotContainsString('SEO Title:', $result['assembled']);
    }

    public function test_assemble_does_not_duplicate_parent_h2_when_stripped(): void
    {
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'h3_01',
            order: 0,
            label: 'Child',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [[
                'kind' => 'h3',
                'heading' => 'Child',
                'level' => 3,
                'body' => 'note',
                'emit_heading' => true,
                'parent_h2' => 'Parent',
            ]],
            requiredPoints: [],
            targetMinWords: 180,
            targetMaxWords: 400,
            preferredTargetWords: 300,
            parentH2: 'Parent',
            emitParentHeading: false,
        );

        $assembled = (new SectionedFreeAssembleArticle())->assemble([
            [
                'section_id' => 'h3_01',
                'section_order' => 0,
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
                'output' => "## Parent\n\n### Child\n\nBody text here.",
            ],
        ], [$unit]);

        // emitParentHeading=false → strip leading parent H2; no duplicate from assembler.
        self::assertSame(0, substr_count($assembled, '## Parent'));
        self::assertStringContainsString('### Child', $assembled);
    }

    public function test_planner_source_does_not_use_sectioned_free_prepare_sections(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WritingMultiplePassStepPlanner::class))->getFileName(),
        );
        self::assertStringNotContainsString('SectionedFreePrepareSections', $src);
        self::assertStringNotContainsString('parseNodes', $src);
        self::assertStringContainsString('planFromRows', $src);
    }

    public function test_section_compiler_strips_sectioned_shape_for_compile(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WritingSectionPromptCompiler::class))->getFileName(),
        );
        self::assertStringContainsString('SinglePass', $src);
        self::assertStringContainsString('writing_scope', $src);
        self::assertStringContainsString('wrapSlice', $src);
    }

    public function test_execution_planner_uses_route_cost_not_writing_split(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('GenerationShapeResolver', $src);
        self::assertStringContainsString('SOURCE_ROUTE_COST_AUTO', $src);
        self::assertStringNotContainsString('WritingSplitPreference', $src);
        self::assertStringNotContainsString('fromWritingSplitEnabled', $src);
        self::assertStringNotContainsString('fromPrimaryIsFree', $src);
    }

    public function test_rows_normalizer_captures_notes_and_parent(): void
    {
        $rows = (new OutlineStructuredRowsNormalizer())->normalize(self::SAMPLE_OUTLINE);
        $byTitle = [];
        foreach ($rows as $row) {
            $byTitle[$row['title']] = $row;
        }
        self::assertArrayHasKey('Tăng hiệu suất', $byTitle);
        self::assertSame(OutlineStructuredRowsNormalizer::KIND_H3, $byTitle['Tăng hiệu suất']['kind']);
        self::assertNotSame('', $byTitle['Tăng hiệu suất']['note']);
        self::assertNotNull($byTitle['Tăng hiệu suất']['parent_id']);
        self::assertSame(OutlineStructuredRowsNormalizer::KIND_FAQ, $byTitle['Câu hỏi thường gặp']['kind']);
        self::assertSame(OutlineStructuredRowsNormalizer::KIND_CONCLUSION, $byTitle['Kết luận']['kind']);
    }
}
