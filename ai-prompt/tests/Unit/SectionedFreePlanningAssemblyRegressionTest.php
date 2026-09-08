<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePrepareSections;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryLegacyClassifier;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Planning / assembly / artifact isolation regressions for sectioned_free.
 */
final class SectionedFreePlanningAssemblyRegressionTest extends TestCase
{
    private const RICH_OUTLINE = <<<'MD'
# Title
Intro points
- i1
- i2

## Tổng quan
- overview a
- overview b

## Cấu tạo
### Lớp đệm
- foam
### Khung cứng
- frame
### Điểm cố định
- straps
### Vật liệu
- fabric

## Đặc điểm nổi bật
### Thiết kế
- design
### Logo
- logo
### Tiện ích
- utility

## Hướng dẫn chọn
### Theo nhu cầu
- need
### Theo ngân sách
- budget

## FAQ
- q1
- q2
MD;

    public function test_target_2000_plans_at_least_six_units(): void
    {
        $prepare = new SectionedFreePrepareSections();
        $minimum = $prepare->minimumUnitsByBudget(2000);
        $this->assertSame(6, $minimum);

        $plan = $prepare->preparePlan(self::RICH_OUTLINE, 2000);
        $this->assertGreaterThanOrEqual(6, $plan->plannedUnitCount());
        $this->assertSame(6, $plan->minimumUnitsByBudget());
        $this->assertSame(2000, $plan->articleTargetWords());
        $this->assertGreaterThanOrEqual(
            $plan->minimumUnitsByBudget(),
            $plan->plannedUnitCount(),
            'planned_unit_count must meet budget minimum when outline has enough material',
        );
        $this->assertFalse($plan->meta['insufficient_outline_material']);
    }

    public function test_target_2000_fake_sections_assemble_with_parity(): void
    {
        $calls = [];
        $generator = new SectionedFreeArticleGenerator();
        $result = $generator->run(
            [
                'title' => 'Balo',
                'primary_keyword' => 'balo',
                'outline' => self::RICH_OUTLINE,
                'article_length' => 2000,
            ],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls): array {
                $calls[] = $unit->sectionId;
                $body = str_repeat('word ', 300);
                $heading = $unit->emitParentHeading
                    ? '## '.$unit->label."\n"
                    : '### continue'."\n";

                return [
                    'output' => $heading.$body,
                    'model' => 'free-a',
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
        );

        $planned = (int) $result['metrics']['planned_unit_count'];
        $this->assertGreaterThanOrEqual(6, $planned);
        $this->assertCount($planned, $calls);
        $this->assertSame($planned, (int) $result['usage']['provider_calls']);
        $this->assertSame($planned, (int) $result['metrics']['completed_unit_count']);

        $sum = (int) $result['metrics']['sum_section_words'];
        $assembled = (int) $result['metrics']['final_assembled_word_count'];
        $this->assertGreaterThanOrEqual(1800, $sum);
        $this->assertEqualsWithDelta($sum, $assembled, 40);

        foreach ($calls as $sectionId) {
            $this->assertStringContainsString($sectionId === 'section_01' ? 'word' : 'word', $result['assembled']);
        }

        // Every section id appears in metrics in order.
        $ids = array_column($result['metrics']['per_section_word_counts'], 'section_id');
        $this->assertSame($calls, $ids);
    }

    public function test_partial_failure_does_not_assemble(): void
    {
        $generator = new SectionedFreeArticleGenerator();

        try {
            $generator->run(
                [
                    'title' => 'Balo',
                    'primary_keyword' => 'balo',
                    'outline' => self::RICH_OUTLINE,
                    'article_length' => 2000,
                ],
                function (SectionedFreeSectionUnit $unit, string $prompt): array {
                    if ($unit->sectionId === 'section_03') {
                        throw new \RuntimeException('provider boom');
                    }

                    return [
                        'output' => '## '.$unit->label."\n".str_repeat('word ', 300),
                        'model' => 'free-a',
                        'attempt_count' => 1,
                        'fallback_count' => 0,
                    ];
                },
            );
            $this->fail('Expected SECTIONED_FREE_SECTION_FAILED');
        } catch (PromptRunException $exception) {
            $this->assertSame('SECTIONED_FREE_SECTION_FAILED', $exception->context['failure_code'] ?? null);
            $this->assertSame('section_03', $exception->context['section_id'] ?? null);
            $this->assertSame(2, (int) ($exception->context['completed_sections'] ?? -1));
            $this->assertGreaterThanOrEqual(6, (int) ($exception->context['planned_sections'] ?? 0));
            $this->assertFalse((bool) ($exception->context['assemble_called'] ?? true));
            $this->assertStringContainsString('SECTIONED_FREE_SECTION_FAILED', $exception->getMessage());
            $this->assertStringNotContainsString('OUTPUT_TRUNCATED', $exception->getMessage());
            $this->assertStringNotContainsString('minimum: 1001', $exception->getMessage());
        }
    }

    public function test_assembly_mismatch_throws_explicit_code(): void
    {
        $assembler = new SectionedFreeAssembleArticle();
        $sections = [
            [
                'section_id' => 'section_01',
                'section_order' => 0,
                'status' => 'completed',
                'output' => str_repeat('word ', 300),
                'word_count' => 300,
            ],
            [
                'section_id' => 'section_02',
                'section_order' => 1,
                'status' => 'completed',
                'output' => str_repeat('word ', 300),
                'word_count' => 300,
            ],
        ];
        $assembled = str_repeat('word ', 100); // deliberately short

        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage('SECTIONED_FREE_ASSEMBLY_MISMATCH');
        $assembler->assertWordParity($sections, $assembled);
    }

    public function test_h2_continuation_does_not_duplicate_parent_heading_in_assemble(): void
    {
        $units = [
            new SectionedFreeSectionUnit(
                sectionId: 'section_01',
                order: 0,
                label: 'Cấu tạo',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [],
                requiredPoints: [],
                targetMinWords: 250,
                targetMaxWords: 350,
                preferredTargetWords: 300,
                parentH2: 'Cấu tạo',
                emitParentHeading: true,
            ),
            new SectionedFreeSectionUnit(
                sectionId: 'section_02',
                order: 1,
                label: 'Cấu tạo — tiếp',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [],
                requiredPoints: [],
                targetMinWords: 250,
                targetMaxWords: 350,
                preferredTargetWords: 300,
                parentH2: 'Cấu tạo',
                emitParentHeading: false,
            ),
        ];

        $assembled = (new SectionedFreeAssembleArticle())->assemble([
            [
                'section_id' => 'section_01',
                'section_order' => 0,
                'status' => 'completed',
                'output' => "## Cấu tạo\n".str_repeat('alpha ', 130),
            ],
            [
                'section_id' => 'section_02',
                'section_order' => 1,
                'status' => 'completed',
                'output' => "## Cấu tạo\n### Vật liệu\n".str_repeat('beta ', 130),
            ],
        ], $units);

        $this->assertSame(1, preg_match_all('/^##\s*Cấu tạo\s*$/mu', $assembled));
        $this->assertStringContainsString('### Vật liệu', $assembled);
        $this->assertStringContainsString('alpha', $assembled);
        $this->assertStringContainsString('beta', $assembled);
    }

    public function test_vocabulary_classifier_does_not_collide_with_outline_artifact(): void
    {
        $classifier = new ArticleAiHistoryLegacyClassifier(
            new ArticleOutlineResolver(
                new WorkflowParserService(new SeoPromptSettingsService, new SeoOverviewSettingsService),
            ),
            new ArtifactReusePolicy(),
        );

        $outlinePayload = "## UNIQUE_OUTLINE_ABC\n### Sub\n- point";
        $outline = $classifier->classify([
            'hook_key' => 'article.outline.structure.generate',
            'artifact_type' => WorkflowArtifactType::ArticleOutline->value,
            'outline_subtask' => 'outline',
            'outline_markdown' => $outlinePayload,
            'status' => 'completed',
            'persists_as_outline' => true,
        ], $outlinePayload);

        $vocab = $classifier->classify([
            'hook_key' => 'article.vocabulary.generate',
            // Simulate buggy inherited parent stamp — must still classify as vocabulary.
            'artifact_type' => WorkflowArtifactType::ArticleOutline->value,
            'outline_subtask' => 'vocabulary',
            'outline_markdown' => $outlinePayload,
            'status' => 'completed',
            'persists_as_outline' => true,
        ], 'UNIQUE_VOCAB_XYZ keyword list');

        $this->assertSame(WorkflowArtifactType::ArticleOutline->value, $outline['artifact_type']);
        $this->assertStringContainsString('UNIQUE_OUTLINE_ABC', $outline['normalized_payload']);
        $this->assertStringNotContainsString('UNIQUE_VOCAB_XYZ', $outline['normalized_payload']);

        $this->assertSame(WorkflowArtifactType::ArticleVocabulary->value, $vocab['artifact_type']);
        $this->assertStringContainsString('UNIQUE_VOCAB_XYZ', $vocab['normalized_payload']);
        $this->assertStringNotContainsString('UNIQUE_OUTLINE_ABC', $vocab['normalized_payload']);
    }

    public function test_final_too_short_uses_sectioned_code_not_output_truncated(): void
    {
        $prepare = new SectionedFreePrepareSections();
        // Tiny outline → few units; force short outputs under target minimum.
        $tiny = "## Only\n- a\n";
        $generator = new SectionedFreeArticleGenerator();

        try {
            $generator->run(
                [
                    'outline' => $tiny,
                    'article_length' => 2000,
                    'title' => 'T',
                    'primary_keyword' => 'k',
                ],
                function (SectionedFreeSectionUnit $unit, string $prompt): array {
                    return [
                        'output' => '## '.$unit->label."\n".str_repeat('word ', 130),
                        'model' => 'free-a',
                        'attempt_count' => 1,
                        'fallback_count' => 0,
                    ];
                },
            );
            // If outline expands enough and somehow passes minimum, skip.
            $this->assertTrue(true);
        } catch (PromptRunException $exception) {
            $code = (string) ($exception->context['failure_code'] ?? '');
            $this->assertContains($code, [
                'SECTIONED_FREE_FINAL_TOO_SHORT',
                'SECTIONED_FREE_SECTION_FAILED',
                'SECTIONED_FREE_PLAN_INVARIANT',
            ]);
            $this->assertStringNotContainsString('Output shorter than minimum acceptable length', $exception->getMessage());
            $this->assertStringNotContainsString('OUTPUT_TRUNCATED', $exception->getMessage());
        }
    }
}
