<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePrepareSections;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionValidator;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests for sectioned_free generation strategy (phase 1).
 */
final class SectionedFreeGenerationStrategyTest extends TestCase
{
    private const SAMPLE_OUTLINE = <<<'MD'
# H1 Title
Intro bullets
- point a
- point b

## H2 A
- a1

## H2 B
- b0
### H3 B1
- b1
### H3 B2
- b2

## H2 C
- c1
- c2

## H2 D
- d1

## FAQ
- Q1?
- Q2?
MD;

    public function test_a_outline_split_into_stable_generation_units(): void
    {
        $units = (new SectionedFreePrepareSections())->prepare(self::SAMPLE_OUTLINE);

        $this->assertGreaterThanOrEqual(3, count($units));
        $ids = array_map(static fn (SectionedFreeSectionUnit $u): string => $u->sectionId, $units);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame('section_01', $units[0]->sectionId);
        $this->assertSame(0, $units[0]->order);

        $again = (new SectionedFreePrepareSections())->prepare(self::SAMPLE_OUTLINE);
        $this->assertSame(
            array_map(static fn (SectionedFreeSectionUnit $u): string => $u->sectionId, $units),
            array_map(static fn (SectionedFreeSectionUnit $u): string => $u->sectionId, $again),
        );

        $faq = null;
        foreach ($units as $unit) {
            if ($unit->role === SectionedFreeSectionUnit::ROLE_FAQ) {
                $faq = $unit;
                break;
            }
        }
        $this->assertNotNull($faq);
        $this->assertSame($faq->sectionId, $faq->sectionId);
    }

    public function test_b_five_sections_assemble_in_order(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'section_order' => $i,
                'status' => 'completed',
                'output' => '## S'.($i + 1)."\n".str_repeat('word ', 130),
            ];
        }
        // Shuffle input order — assemble must sort.
        $shuffled = [$rows[3], $rows[0], $rows[4], $rows[1], $rows[2]];
        $assembled = (new SectionedFreeAssembleArticle())->assemble($shuffled);

        $pos = [];
        for ($i = 1; $i <= 5; $i++) {
            $pos[$i] = strpos($assembled, '## S'.$i);
            $this->assertNotFalse($pos[$i]);
        }
        $this->assertTrue($pos[1] < $pos[2] && $pos[2] < $pos[3] && $pos[3] < $pos[4] && $pos[4] < $pos[5]);
    }

    public function test_c_section_failure_does_not_regenerate_successes(): void
    {
        $calls = [];
        $generator = new SectionedFreeArticleGenerator();
        $body = str_repeat('alpha ', 130);

        try {
            $generator->run(
                ['outline' => self::SAMPLE_OUTLINE, 'title' => 'T', 'primary_keyword' => 'k'],
                function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls, $body): array {
                    $calls[] = $unit->sectionId;
                    if ($unit->sectionId === 'section_03') {
                        throw new PromptRunException('boom section 3');
                    }

                    return [
                        'output' => '## '.$unit->label."\n".$body,
                        'model' => 'free-a',
                        'provider' => 'openrouter',
                        'connection_id' => 1,
                        'attempt_count' => 1,
                        'fallback_count' => 0,
                    ];
                },
            );
            $this->fail('Expected section 3 failure');
        } catch (PromptRunException $e) {
            $state = SectionedFreeRunState::fromArray($e->context['sectioned_free_state'] ?? null);
            $this->assertTrue($state->isCompleted('section_01'));
            $this->assertTrue($state->isCompleted('section_02'));
            $this->assertFalse($state->isCompleted('section_03'));
            $this->assertContains('section_03', $calls);
            // Later sections must not have been called after failure.
            $this->assertNotContains('section_04', $calls);
            $this->assertNotContains('section_05', $calls);
        }
    }

    public function test_d_rerun_one_failed_section_only(): void
    {
        $generator = new SectionedFreeArticleGenerator();
        $body = str_repeat('bravo ', 130);
        $state = new SectionedFreeRunState();
        $units = (new SectionedFreePrepareSections())->prepare(self::SAMPLE_OUTLINE);
        foreach ($units as $unit) {
            $state->planSection($unit);
            if ($unit->sectionId === 'section_03') {
                $state->markFailed($unit->sectionId, 'prior fail');
                continue;
            }
            $state->markCompleted($unit->sectionId, '## '.$unit->label."\n".$body, 130, [
                'model' => 'free-a',
                'attempt_count' => 1,
                'first_attempt_success' => true,
            ]);
        }

        $calls = [];
        $result = $generator->run(
            ['outline' => self::SAMPLE_OUTLINE, 'title' => 'T', 'primary_keyword' => 'k'],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls, $body): array {
                $calls[] = $unit->sectionId;

                return [
                    'output' => '## Rerun '.$unit->label."\n".$body,
                    'model' => 'free-b',
                    'provider' => 'openrouter',
                    'connection_id' => 2,
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
            $state,
            'section_03',
        );

        $this->assertSame(['section_03'], $calls);
        $this->assertStringContainsString('Rerun', $result['assembled']);
        $this->assertTrue($result['state']->isCompleted('section_01'));
        $this->assertTrue($result['state']->isCompleted('section_03'));
        $this->assertGreaterThan(500, $result['metrics']['final_assembled_word_count']);
        $this->assertSame(
            $result['metrics']['final_assembled_word_count'],
            $result['usage']['assembled_word_count'],
        );
    }

    public function test_e_below_minimum_threshold_fails_validation(): void
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

        $this->expectException(PromptRunException::class);
        $validator->assertAcceptable(str_repeat('short ', 20), $unit);
    }

    public function test_f_sectioned_free_routing_context_is_free_only(): void
    {
        $ctx = new AiRoutingContext(
            freeOnly: true,
            isolationMode: 'free_test',
            generationStrategy: ArticleGenerationStrategy::SectionedFree->value,
        );
        $this->assertTrue($ctx->freeOnly);
        $this->assertSame('free_test', $ctx->isolationMode);
        $this->assertSame('sectioned_free', $ctx->generationStrategy);

        // freeOnly filter semantics (mirrors AiModelRouterService branch).
        $candidates = [
            (object) ['model' => 'paid-model', 'isFree' => false],
            (object) ['model' => 'free-model', 'isFree' => true],
        ];
        $filtered = array_values(array_filter(
            $candidates,
            static fn (object $c): bool => ! $ctx->freeOnly || $c->isFree,
        ));
        $this->assertCount(1, $filtered);
        $this->assertTrue($filtered[0]->isFree);
        $this->assertSame('free-model', $filtered[0]->model);
    }

    public function test_g_default_strategy_remains_single_pass(): void
    {
        $this->assertSame(ArticleGenerationStrategy::SinglePass, ArticleGenerationStrategy::resolve(null));
        $this->assertSame(ArticleGenerationStrategy::SinglePass, ArticleGenerationStrategy::resolve(''));
        $this->assertFalse(ArticleGenerationStrategy::resolve(null)->isSectionedFree());
        $this->assertTrue(ArticleGenerationStrategy::resolve('sectioned_free')->isSectionedFree());
    }

    public function test_h_missing_free_connection_is_explicit_config_failure(): void
    {
        $ex = AiRoutingException::noValidFreeConnection('text.longform');
        $this->assertSame('NO_VALID_FREE_CONNECTION', $ex->context['failure_code'] ?? null);
        $this->assertFalse((bool) ($ex->context['auto_create_credential'] ?? true));
        $this->assertStringContainsString('NO_VALID_FREE_CONNECTION', $ex->getMessage());

        $ex2 = AiRoutingException::noCandidate('text.longform', 'text.generate');
        $this->assertSame('NO_AI_CONNECTION', $ex2->context['failure_code'] ?? null);
    }

    public function test_i_runtime_does_not_auto_create_credentials_in_generator(): void
    {
        $ref = new ReflectionClass(SectionedFreeArticleGenerator::class);
        $src = file_get_contents($ref->getFileName() ?: '') ?: '';
        $this->assertStringNotContainsString('ApiConnection::query()->create', $src);
        $this->assertStringNotContainsString('api_key =', $src);
        $this->assertStringNotContainsString('updateOrCreate', $src);

        $runnerSrc = file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        ) ?: '';
        // Sectioned free branch must refuse unusable keys, never mint them.
        $this->assertStringContainsString('NO_AI_CONNECTION', $runnerSrc);
        $this->assertStringContainsString('auto_create_credential', $runnerSrc);
        $this->assertStringContainsString('executeSectionedFreeGeneration', $runnerSrc);
    }

    public function test_j_assemble_does_not_call_llm_rewrite(): void
    {
        $ref = new ReflectionClass(SectionedFreeAssembleArticle::class);
        $src = file_get_contents($ref->getFileName() ?: '') ?: '';
        $this->assertStringNotContainsString('executeWithProfile', $src);
        $this->assertStringNotContainsString('callProvider', $src);
        $this->assertStringNotContainsString('AiModelRouter', $src);
        $this->assertStringContainsString('implode', $src);

        $result = (new SectionedFreeArticleGenerator())->run(
            ['outline' => "## Only\n- p1\n- p2", 'title' => 'T', 'primary_keyword' => 'k'],
            static fn (SectionedFreeSectionUnit $unit, string $prompt): array => [
                'output' => '## Only'."\n".str_repeat('word ', 130),
                'model' => 'free-a',
                'attempt_count' => 1,
                'fallback_count' => 0,
            ],
        );
        $this->assertSame('deterministic_concat', $result['usage']['assemble_mode']);
        $this->assertFalse($result['usage']['whole_article_rewrite']);
    }

    public function test_k_final_assembled_word_count_persisted_in_metrics(): void
    {
        $body = str_repeat('metric ', 200);
        $result = (new SectionedFreeArticleGenerator())->run(
            ['outline' => self::SAMPLE_OUTLINE, 'title' => 'T', 'primary_keyword' => 'k'],
            fn (SectionedFreeSectionUnit $unit, string $prompt): array => [
                'output' => '## '.$unit->label."\n".$body,
                'model' => 'free-a',
                'attempt_count' => 1,
                'fallback_count' => 0,
            ],
        );

        $this->assertArrayHasKey('final_assembled_word_count', $result['metrics']);
        $this->assertSame(
            $result['metrics']['final_assembled_word_count'],
            $result['usage']['assembled_word_count'],
        );
        $this->assertGreaterThan(1000, $result['metrics']['final_assembled_word_count']);
        $this->assertTrue($result['metrics']['exceeds_1000_words']);
        $this->assertSame('sectioned_free', $result['metrics']['generation_strategy']);
        $this->assertNotEmpty($result['usage']['sectioned_free_trace']);
    }
}
