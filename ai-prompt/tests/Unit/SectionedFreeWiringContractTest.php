<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArtifactSplitter;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeKeywordSuggester;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePrepareSections;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionPromptBuilder;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionValidator;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategyResolver;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use PHPUnit\Framework\TestCase;

/**
 * Wiring + prompt-contract tests for sectioned_free (fix phase).
 */
final class SectionedFreeWiringContractTest extends TestCase
{
    private const VOCAB_MARKER = 'UNIQUE_VOCABULARY_SECRET_MARKER_ABC123';

    private const SECTION_D_SECRET = 'SECTION_D_SECRET_DETAIL';

    private const ARTIFACT = <<<'TXT'
[START_TASK_1_OUTLINE]
# Title
Intro points
- i1

## H2 A
- a1

## H2 B
### H3 B1
- b1
### H3 B2
- b2

## H2 C
- c1

## H2 D
- d1
SECTION_D_SECRET_DETAIL

## FAQ
- q1
[END_TASK_1_OUTLINE]
[START_TASK_2_VOCABULARY]
UNIQUE_VOCABULARY_SECRET_MARKER_ABC123
- balo học sinh Trà Vinh
- thiết kế balo sinh viên
- logo Đại học Trà Vinh
long analysis sentence that must never appear in writer prompts because it is vocabulary dump prose
[END_TASK_2_VOCABULARY]
TXT;

    public function test_strategy_resolver_prefers_stamped_item_strategy(): void
    {
        $resolver = new ArticleGenerationStrategyResolver();
        $this->assertSame(
            ArticleGenerationStrategy::Sectioned,
            $resolver->resolve(['_item_generation_strategy' => 'sectioned_free']),
        );
        $this->assertSame(
            ArticleGenerationStrategy::SinglePass,
            $resolver->resolve([]),
        );
        $stamped = $resolver->stamp([], ArticleGenerationStrategy::SectionedFree);
        $this->assertSame('sectioned', $stamped['resolved_generation_strategy']);
        $this->assertSame('sectioned', $stamped['generation_strategy']);
        $this->assertSame('sectioned', $stamped['_item_generation_strategy']);
        $this->assertSame('sectioned', $stamped['generation_shape']);
    }

    public function test_artifact_splitter_keeps_vocabulary_but_outline_only_for_planner(): void
    {
        $parts = (new SectionedFreeArtifactSplitter())->split(self::ARTIFACT);
        $this->assertTrue($parts['vocabulary_persisted']);
        $this->assertStringContainsString(self::VOCAB_MARKER, $parts['vocabulary_raw']);
        $this->assertStringNotContainsString(self::VOCAB_MARKER, $parts['outline_markdown']);
        $this->assertStringContainsString(self::SECTION_D_SECRET, $parts['outline_markdown']);
    }

    public function test_section_prompts_are_compact_and_isolated(): void
    {
        $captured = [];
        $body = str_repeat('word ', 130);
        $result = (new SectionedFreeArticleGenerator())->run(
            [
                'title' => 'Balo học sinh',
                'primary_keyword' => 'balo học sinh',
                'outline' => self::ARTIFACT,
            ],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$captured, $body): array {
                $captured[] = ['section_id' => $unit->sectionId, 'prompt' => $prompt];

                return [
                    'output' => '## '.$unit->label."\n".$body,
                    'model' => 'free-a',
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
        );

        $this->assertSame(count($result['units']), count($captured));
        $this->assertSame(count($result['units']), (int) $result['usage']['provider_calls']);
        $this->assertFalse($result['usage']['vocabulary_injected_into_writer']);
        $this->assertTrue($result['vocabulary_persisted']);

        foreach ($captured as $row) {
            $prompt = $row['prompt'];
            $this->assertStringContainsString('CURRENT SECTION:', $prompt);
            $this->assertStringContainsString('ARTICLE MAP:', $prompt);
            $this->assertStringContainsString('Target length:', $prompt);
            $this->assertStringNotContainsString('DYNAMIC WORD ALLOCATION', $prompt);
            $this->assertStringNotContainsString('target 1000 words', $prompt);
            $this->assertStringNotContainsString('80% of 1000', $prompt);
            $this->assertStringNotContainsString(self::VOCAB_MARKER, $prompt);
            $this->assertStringNotContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $prompt);
            $this->assertStringNotContainsString('[START_TASK_1_OUTLINE]', $prompt);
            $this->assertLessThan(6000, mb_strlen($prompt), 'section prompt should stay compact');
        }

        // Section that is not D must not include SECTION_D_SECRET_DETAIL as current-section detail.
        foreach ($captured as $row) {
            if ($row['section_id'] === 'section_04' || str_contains($row['prompt'], 'H2 D')) {
                // D may include its own detail when it is CURRENT SECTION.
                continue;
            }
            // ARTICLE MAP may list "H2 D" heading but not the secret detail body.
            $this->assertStringNotContainsString(self::SECTION_D_SECRET, $row['prompt']);
        }
    }

    public function test_provider_call_count_equals_generation_units_without_retry(): void
    {
        $calls = 0;
        $result = (new SectionedFreeArticleGenerator())->run(
            ['outline' => self::ARTIFACT, 'title' => 'T', 'primary_keyword' => 'k'],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls): array {
                $calls++;

                return [
                    'output' => '## '.$unit->label."\n".str_repeat('alpha ', 130),
                    'model' => 'free-a',
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
        );

        $this->assertSame($result['metrics']['generation_unit_count'], $calls);
        $this->assertSame($calls, (int) $result['usage']['provider_calls']);
    }

    public function test_keyword_suggester_returns_short_list_without_raw_vocab(): void
    {
        $units = (new SectionedFreePrepareSections())->prepare(
            (new SectionedFreeArtifactSplitter())->split(self::ARTIFACT)['outline_markdown'],
        );
        $suggester = new SectionedFreeKeywordSuggester();
        $unit = $units[0];
        $keywords = $suggester->suggestForSection(
            $unit,
            (new SectionedFreeArtifactSplitter())->split(self::ARTIFACT)['vocabulary_raw'],
        );
        $this->assertLessThanOrEqual(5, count($keywords));
        foreach ($keywords as $kw) {
            $this->assertStringNotContainsString(self::VOCAB_MARKER, $kw);
        }
    }

    public function test_validator_220_words_accepted_80_rejected(): void
    {
        $validator = new SectionedFreeSectionValidator();
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_01',
            order: 0,
            label: 'Intro',
            role: SectionedFreeSectionUnit::ROLE_INTRO,
            outlineNodes: [],
            requiredPoints: [],
            targetMinWords: 250,
            targetMaxWords: 350,
            preferredTargetWords: 300,
        );
        $ok = $validator->assertAcceptable(str_repeat('word ', 220), $unit);
        $this->assertSame(220, $ok);

        $this->expectException(\Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException::class);
        $validator->assertAcceptable(str_repeat('word ', 80), $unit);
    }

    public function test_prompt_builder_exposes_current_section_contract(): void
    {
        $builder = new SectionedFreeSectionPromptBuilder();
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'section_03',
            order: 2,
            label: 'Đặc điểm nổi bật',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [
                ['kind' => 'h2', 'heading' => 'Đặc điểm nổi bật', 'level' => 2, 'body' => "- Thiết kế\n- Logo"],
            ],
            requiredPoints: ['Thiết kế', 'Logo'],
            targetMinWords: 250,
            targetMaxWords: 350,
            preferredTargetWords: 280,
        );
        $prompt = $builder->build($unit, [
            'title' => 'Balo học sinh Trường Đại học Trà Vinh năm 2023',
            'primary_keyword' => 'balo học sinh',
            'article_map' => ['Intro', 'Tổng quan', 'Đặc điểm nổi bật', 'FAQ'],
            'suggested_keywords' => ['thiết kế balo sinh viên', 'logo Đại học Trà Vinh'],
        ]);

        $this->assertStringContainsString('You are writing ONE SECTION', $prompt);
        $this->assertStringContainsString('CURRENT SECTION:', $prompt);
        $this->assertStringContainsString('250–350 words', $prompt);
        $this->assertStringContainsString('Suggested keywords:', $prompt);
        $this->assertStringNotContainsString('DYNAMIC WORD ALLOCATION', $prompt);
    }
}
