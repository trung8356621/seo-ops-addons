<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePromptIsolationGuard;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategyResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicy;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicyApplier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Prove sectioned_free never enters normal article prompt compilation.
 */
final class SectionedFreeNormalCompilerIsolationTest extends TestCase
{
    public function test_explicit_binding_branches_before_compile_prompt(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName(),
        );
        $branchPos = strpos($src, 'ArticleGenerationExecutionPlanner');
        $compilePos = strpos($src, 'compilePrompt(');
        $this->assertNotFalse($branchPos);
        $this->assertNotFalse($compilePos);
        $this->assertLessThan($compilePos, $branchPos, 'primary/shape planner must run before compilePrompt');
        $this->assertStringContainsString('SectionedFreeHookOrchestrator', $src);
        $this->assertStringContainsString('isSectioned()', $src);
    }

    public function test_compile_prompt_source_guards_sectioned_free(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(PromptRunnerService::class))->getFileName());
        $this->assertStringContainsString('SECTIONED_NORMAL_COMPILER_INVOKED', $src);

        $fnStart = strpos($src, 'function compilePrompt(');
        $this->assertNotFalse($fnStart);
        $slice = substr($src, $fnStart, 700);
        $this->assertStringContainsString('isSectioned()', $slice);
    }

    public function test_policy_applier_no_longer_stamps_strategy_override(): void
    {
        $ref = new ReflectionClass(ContentProjectItemGenerationPolicyApplier::class);
        /** @var ContentProjectItemGenerationPolicyApplier $applier */
        $applier = $ref->newInstanceWithoutConstructor();

        $policy = new ContentProjectItemGenerationPolicy(
            tone: null,
            toneIsAutomaticVariety: true,
            toneWasSticky: false,
            contentLengthMode: null,
            contentLengthTargetWords: 2000,
            generationMode: null,
            generationStrategy: ArticleGenerationStrategy::SectionedFree,
            modelOverrideId: null,
            modelOverrideMode: null,
            titleProtection: null,
            protectTitle: false,
        );

        $vars = $applier->stampVariables(['article_length' => '2000'], $policy);
        $this->assertArrayNotHasKey('generation_strategy', $vars);
        $this->assertSame('sectioned_free', $vars['legacy_generation_strategy_override'] ?? null);
        $this->assertArrayNotHasKey('generation_strategy_override', $vars);
    }

    public function test_unique_vocabulary_marker_never_reaches_section_prompts(): void
    {
        $secret = 'UNIQUE_RAW_VOCAB_SECRET_938271';
        $outline = <<<'MD'
# Title
Intro

## One
- a

## Two
- b

## Three
- c

## Four
- d

## Five
- e

## Six
- f
MD;
        $vocab = "[START_TASK_2_VOCABULARY]\n{$secret}\nHolonymy dump\n[END_TASK_2_VOCABULARY]";
        $calls = [];
        $generator = new SectionedFreeArticleGenerator();
        $result = $generator->run(
            [
                'title' => 'Title',
                'primary_keyword' => 'kw',
                'article_outline' => $outline,
                'article_vocabulary' => $vocab,
                'outline' => $outline,
                'input' => $outline,
                'article_length' => 2000,
            ],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls, $secret): array {
                $calls[] = $prompt;
                $this->assertStringNotContainsString($secret, $prompt);
                $this->assertStringNotContainsString('[START_TASK_2_VOCABULARY]', $prompt);
                $this->assertStringNotContainsString('[END_TASK_2_VOCABULARY]', $prompt);
                $this->assertStringNotContainsString('DYNAMIC WORD ALLOCATION', $prompt);
                $this->assertStringNotContainsString('STRICT LENGTH REQUIREMENT', $prompt);
                $this->assertStringNotContainsString('ROLE & GOAL', $prompt);
                $this->assertStringNotContainsString('2000', $prompt);
                $this->assertStringContainsString('CURRENT SECTION', $prompt);
                $this->assertMatchesRegularExpression('/250|300|350/', $prompt);

                return [
                    'output' => str_repeat('word ', max(80, $unit->preferredTargetWords)),
                    'model' => 'free',
                    'provider' => 'test',
                    'connection_id' => 1,
                    'attempt_count' => 1,
                ];
            },
        );

        $planned = (int) ($result['metrics']['planned_unit_count'] ?? 0);
        $this->assertGreaterThanOrEqual(6, $planned);
        $this->assertGreaterThanOrEqual(6, count($calls));
        $this->assertSame(count($calls), (int) ($result['usage']['provider_calls'] ?? 0));
    }

    public function test_isolation_guard_rejects_whole_article_prompt(): void
    {
        $guard = new SectionedFreePromptIsolationGuard();
        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage(SectionedFreePromptIsolationGuard::FAILURE_CODE);
        $guard->assertSectionPromptIsIsolated(
            "ROLE & GOAL\nSTRICT LENGTH REQUIREMENT\n[START_TASK_2_VOCABULARY]\nx\n[END_TASK_2_VOCABULARY]\nDYNAMIC WORD ALLOCATION\ntarget = 2000",
            ['section_id' => 'section_01', 'run_id' => 'sf_test'],
        );
    }

    public function test_single_pass_strategy_still_default(): void
    {
        $resolver = new ArticleGenerationStrategyResolver();
        $this->assertFalse($resolver->resolve([])->isSectionedFree());
        $this->assertFalse($resolver->resolve(['generation_strategy' => 'single_pass'])->isSectionedFree());
        $this->assertTrue($resolver->resolve(['generation_strategy' => 'sectioned_free'])->isSectionedFree());
        $this->assertTrue($resolver->resolve(['generation_strategy_override' => 'sectioned_free'])->isSectionedFree());
    }
}
