<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategyResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: Content Project hook path resolves primary/shape BEFORE whole-article compile.
 */
final class SectionedFreeHookPathBranchTest extends TestCase
{
    public function test_explicit_binding_executor_branches_before_legacy_compile(): void
    {
        $ref = new ReflectionClass(PromptHookExplicitBindingExecutor::class);
        $src = file_get_contents((string) $ref->getFileName()) ?: '';

        $branchPos = strpos($src, 'ArticleGenerationExecutionPlanner');
        $compilePos = strpos($src, 'legacy_compiled_prompt');
        $enginePos = strpos($src, '$this->engine->execute');

        $this->assertNotFalse($branchPos, 'missing primary/shape planner');
        $this->assertNotFalse($compilePos, 'missing legacy compile');
        $this->assertNotFalse($enginePos, 'missing engine execute');
        $this->assertLessThan($compilePos, $branchPos, 'planner must run before legacy compile');
        $this->assertLessThan($enginePos, $branchPos, 'planner must run before engine.execute');
        $this->assertStringContainsString('SectionedFreeHookOrchestrator', $src);
        $this->assertStringContainsString('isSectioned()', $src);
    }

    public function test_prompt_runner_branches_before_compile_prompt(): void
    {
        $ref = new ReflectionClass(PromptRunnerService::class);
        $src = file_get_contents((string) $ref->getFileName()) ?: '';

        $runPos = strpos($src, 'public function run(');
        $this->assertNotFalse($runPos);
        $compiledMethodPos = strpos($src, 'public function runWithCompiledPrompt(');
        $this->assertNotFalse($compiledMethodPos);
        $slice = substr($src, $runPos, $compiledMethodPos - $runPos);
        $plannerPos = strpos($slice, 'articleExecutionPlanner()');
        $compilePos = strpos($slice, '$this->compilePrompt(');
        $this->assertNotFalse($plannerPos);
        $this->assertNotFalse($compilePos);
        $this->assertLessThan($compilePos, $plannerPos);
        $this->assertStringContainsString('runSectionedFreeAsPromptResult', $src);
    }

    public function test_strategy_resolver_is_canonical_for_item_and_runner(): void
    {
        $resolver = new ArticleGenerationStrategyResolver();
        $fromItem = $resolver->resolve([
            '_item_generation_strategy' => 'sectioned_free',
        ]);
        $fromRunner = $resolver->resolve([
            'generation_strategy' => 'sectioned_free',
        ]);
        $this->assertSame(ArticleGenerationStrategy::Sectioned, $fromItem);
        $this->assertSame(ArticleGenerationStrategy::Sectioned, $fromRunner);
        $this->assertSame(ArticleGenerationStrategy::SinglePass, $resolver->resolve([]));
    }
}
