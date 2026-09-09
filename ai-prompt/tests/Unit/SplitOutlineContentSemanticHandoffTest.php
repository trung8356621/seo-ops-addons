<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePrepareSections;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePromptIsolationGuard;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Services\SplitOutlineContentSemanticBinder;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use PHPUnit\Framework\TestCase;

final class SplitOutlineContentSemanticHandoffTest extends TestCase
{
    private const CLEAN_OUTLINE = <<<'MD'
# Effortless Chic

[MỞ BÀI — KHÔNG HEADING]
Intro points.

## H2: Bản chất cốt lõi
- a1

## H2: Capsule Wardrobe
### H3: Cost Per Wear
- b1

## H2: Styling
- c1
MD;

    private const CLEAN_VOCAB = <<<'MD'
### Holonymy
- Capsule Wardrobe
- Personal Style

### Synonyms
- Understated Elegance
MD;

    public function test_a_split_outline_structured_handoff_binds_clean_semantic_fields(): void
    {
        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            [
                'input' => $this->combinedTransport(self::CLEAN_OUTLINE, self::CLEAN_VOCAB),
                'title' => 'Effortless Chic',
                'keyword' => 'Effortless Chic',
            ],
            self::CLEAN_OUTLINE,
            self::CLEAN_VOCAB,
            null,
        );

        $this->assertSame(self::CLEAN_OUTLINE, $bound['article_outline']);
        $this->assertSame(self::CLEAN_VOCAB, $bound['article_vocabulary']);
        $this->assertStringNotContainsString(ArticleGenerationInputResolver::OUTLINE_START, $bound['article_outline']);
        $this->assertStringNotContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $bound['article_outline']);
        $this->assertStringNotContainsString(ArticleGenerationInputResolver::OUTLINE_START, $bound['article_vocabulary']);
        $this->assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, (string) $bound['input']);
    }

    public function test_b_transport_markers_do_not_leak_when_only_combined_blob_exists(): void
    {
        $combined = $this->combinedTransport(self::CLEAN_OUTLINE, self::CLEAN_VOCAB);
        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            ['input' => $combined],
            null,
            null,
            $combined,
        );

        $this->assertSame(trim(self::CLEAN_OUTLINE), trim((string) $bound['article_outline']));
        $this->assertSame(trim(self::CLEAN_VOCAB), trim((string) $bound['article_vocabulary']));
        $this->assertStringNotContainsString('[START_TASK_', (string) $bound['article_outline']);
        $this->assertStringNotContainsString('[START_TASK_', (string) $bound['article_vocabulary']);
    }

    public function test_c_section_plan_from_clean_outline_is_non_empty(): void
    {
        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            ['input' => $this->combinedTransport(self::CLEAN_OUTLINE, self::CLEAN_VOCAB)],
            self::CLEAN_OUTLINE,
            self::CLEAN_VOCAB,
        );

        $units = (new SectionedFreePrepareSections())->prepare((string) $bound['article_outline']);
        $this->assertGreaterThan(0, count($units));
    }

    public function test_d_vocabulary_survives_handoff_into_sectioned_context(): void
    {
        $secret = 'UNIQUE_VOCAB_HANDOFF_MARKER_XYZ';
        $vocab = self::CLEAN_VOCAB."\n- {$secret}\n";
        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            ['input' => $this->combinedTransport(self::CLEAN_OUTLINE, $vocab)],
            self::CLEAN_OUTLINE,
            null,
            $this->combinedTransport(self::CLEAN_OUTLINE, $vocab),
        );

        $this->assertNotSame('', trim((string) ($bound['article_vocabulary'] ?? '')));
        $this->assertStringContainsString($secret, (string) $bound['article_vocabulary']);
    }

    public function test_e_safety_guard_still_rejects_contaminated_section_prompt(): void
    {
        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage('forbidden whole-article marker');

        (new SectionedFreePromptIsolationGuard())->assertSectionPromptIsIsolated(
            "CURRENT SECTION:\n## H2 A\n\n".ArticleGenerationInputResolver::VOCABULARY_START."\nleak\n",
        );
    }

    public function test_f_single_outline_markerless_input_still_binds(): void
    {
        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            ['input' => self::CLEAN_OUTLINE],
            self::CLEAN_OUTLINE,
            self::CLEAN_VOCAB,
        );

        $this->assertSame(self::CLEAN_OUTLINE, $bound['article_outline']);
        $this->assertSame(self::CLEAN_VOCAB, $bound['article_vocabulary']);
        $this->assertSame(self::CLEAN_OUTLINE, $bound['input']);
    }

    public function test_g_shape_contract_free_still_maps_to_sectioned(): void
    {
        $this->assertTrue(ArticleGenerationShape::fromRouteCostClass('free')->isSectioned());
        $this->assertFalse(ArticleGenerationShape::fromRouteCostClass('paid')->isSectioned());
        $this->assertSame(ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO, ArticleGenerationShape::SOURCE_ROUTE_COST_AUTO);
    }

    public function test_sectioned_generator_reaches_section_execution_boundary_without_provider(): void
    {
        $bound = (new SplitOutlineContentSemanticBinder())->bind(
            [
                'title' => 'Effortless Chic',
                'primary_keyword' => 'Effortless Chic',
                'input' => $this->combinedTransport(self::CLEAN_OUTLINE, self::CLEAN_VOCAB),
            ],
            self::CLEAN_OUTLINE,
            self::CLEAN_VOCAB,
        );

        $calls = 0;
        $result = (new SectionedFreeArticleGenerator())->run(
            [
                'title' => 'Effortless Chic',
                'primary_keyword' => 'Effortless Chic',
                'outline' => (string) $bound['article_outline'],
                'article_vocabulary' => (string) $bound['article_vocabulary'],
            ],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls): array {
                $calls++;
                $this->assertStringNotContainsString('[START_TASK_', $prompt);
                $this->assertStringNotContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $prompt);

                return [
                    'output' => '## '.$unit->label."\n".str_repeat('word ', 130),
                    'model' => 'free-a',
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
        );

        $this->assertGreaterThan(0, $calls);
        $this->assertSame($calls, (int) $result['usage']['provider_calls']);
        $this->assertGreaterThan(0, count($result['units']));
    }

    private function combinedTransport(string $outline, string $vocab): string
    {
        return ArticleGenerationInputResolver::OUTLINE_START."\n"
            .trim($outline)."\n"
            .ArticleGenerationInputResolver::OUTLINE_END."\n\n"
            .ArticleGenerationInputResolver::VOCABULARY_START."\n"
            .trim($vocab)."\n"
            .ArticleGenerationInputResolver::VOCABULARY_END;
    }
}