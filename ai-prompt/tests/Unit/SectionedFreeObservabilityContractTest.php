<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeBreadcrumbBag;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeExecutionGuard;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeHookOrchestrator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionCallRecorder;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeTrackedProviderCall;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Observability + invariant contracts for sectioned_free (no generation optimization).
 */
final class SectionedFreeObservabilityContractTest extends TestCase
{
    protected function tearDown(): void
    {
        SectionedFreeExecutionGuard::leave();
        parent::tearDown();
    }

    public function test_orchestrator_persists_child_prompt_results_via_tracked_call(): void
    {
        $src = file_get_contents((string) (new ReflectionClass(SectionedFreeHookOrchestrator::class))->getFileName()) ?: '';
        $this->assertStringContainsString('SectionedFreeTrackedProviderCall', $src);
        $this->assertStringContainsString('child_prompt_result_ids', $src);
        $this->assertStringContainsString('SectionedFreeExecutionGuard::enter', $src);
        $this->assertStringContainsString('SECTIONED_FREE_SECTION_FAILED', $src);
        $this->assertStringContainsString('section_provider_call_created', $src);
        $this->assertStringContainsString('sections_planned', $src);
        $this->assertStringContainsString("'prompt_result_ids'", $src);
    }

    public function test_tracked_call_and_recorder_store_inspectable_prompt_fields(): void
    {
        $recorderSrc = file_get_contents((string) (new ReflectionClass(SectionedFreeSectionCallRecorder::class))->getFileName()) ?: '';
        $trackedSrc = file_get_contents((string) (new ReflectionClass(SectionedFreeTrackedProviderCall::class))->getFileName()) ?: '';

        $this->assertStringContainsString('article.content.section.generate', $recorderSrc);
        $this->assertStringContainsString('compiled_prompt', $recorderSrc);
        $this->assertStringContainsString('parent_prompt_result_id', $recorderSrc);
        $this->assertStringContainsString('section_id', $recorderSrc);
        $this->assertStringContainsString('prompt_character_count', $recorderSrc);
        $this->assertStringContainsString('target_words', $recorderSrc);
        $this->assertStringContainsString('provider_call_id', $trackedSrc);
        $this->assertStringContainsString('prompt_hash', $trackedSrc);
        $this->assertStringContainsString('completeSuccess', $trackedSrc);
        $this->assertStringContainsString('completeFailure', $trackedSrc);
        $this->assertStringContainsString('linkChildImmediately', $trackedSrc);
        $this->assertStringContainsString('PromptResultLinkService', $trackedSrc);
    }

    public function test_generator_invokes_executor_once_per_planned_section(): void
    {
        $outline = <<<'MD'
# Title
Intro

## A
- a1

## B
- b1

## C
- c1

## D
- d1
MD;
        $calls = [];
        $generator = new SectionedFreeArticleGenerator();
        $result = $generator->run(
            [
                'title' => 'T',
                'primary_keyword' => 'kw',
                'intent' => 'info',
                'language' => 'vi',
                'outline' => $outline,
            ],
            function ($unit, string $prompt) use (&$calls): array {
                $calls[] = [
                    'section_id' => $unit->sectionId,
                    'prompt' => $prompt,
                ];
                $words = str_repeat('word ', 130);

                return [
                    'output' => trim($words),
                    'model' => 'fake-free',
                    'provider' => 'fake',
                    'connection_id' => 1,
                    'attempt_count' => 1,
                    'fallback_count' => 0,
                ];
            },
        );

        $planned = (int) ($result['metrics']['generation_unit_count'] ?? 0);
        $this->assertGreaterThanOrEqual(4, $planned);
        $this->assertCount($planned, $calls);
        $this->assertSame($planned, (int) ($result['usage']['provider_calls'] ?? 0));

        foreach ($calls as $call) {
            $this->assertStringNotContainsString('UNIQUE_VOCAB', $call['prompt']);
            $this->assertStringNotContainsString('target 1000', $call['prompt']);
            $this->assertStringNotContainsString('DYNAMIC WORD ALLOCATION', $call['prompt']);
        }
    }

    public function test_legacy_validator_throws_invariant_when_guard_active(): void
    {
        SectionedFreeExecutionGuard::enter([
            'run_id' => 'sf_test',
            'parent_prompt_result_id' => 99,
        ]);

        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage('SECTIONED_FREE_LEGACY_VALIDATOR_REACHED');

        (new ArticleGenerationLengthValidator)->assertAcceptable(str_repeat('word ', 433), 1000);
    }

    public function test_legacy_pipeline_throws_invariant_when_strategy_is_sectioned_free(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline();
        $ref = new ReflectionClass($pipeline);
        $method = $ref->getMethod('assertSectionedFreeDidNotReachLegacyValidator');
        $method->setAccessible(true);

        try {
            $method->invoke($pipeline, [
                'article_length' => 1000,
                'generation_strategy' => 'sectioned_free',
                'resolved_generation_strategy' => 'sectioned_free',
            ], 1000);
            $this->fail('Expected SECTIONED_FREE_LEGACY_VALIDATOR_REACHED');
        } catch (PromptRunException $exception) {
            $this->assertStringContainsString('SECTIONED_FREE_LEGACY_VALIDATOR_REACHED', $exception->getMessage());
            $this->assertStringNotContainsString('minimum: 501', $exception->getMessage());
            $this->assertStringNotContainsString('Output shorter than minimum', $exception->getMessage());
        }
    }

    public function test_single_pass_still_reports_output_truncated_for_433_words(): void
    {
        $this->expectException(\Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated::class);
        (new ArticleGenerationLengthValidator)->assertAcceptable(str_repeat('word ', 433), 1000);
    }

    public function test_breadcrumb_bag_records_runtime_events(): void
    {
        $bag = new SectionedFreeBreadcrumbBag();
        $bag->push('strategy_received', ['value' => 'sectioned_free']);
        $bag->push('strategy_resolved', ['value' => 'sectioned_free']);
        $bag->push('branch_entered', ['class' => SectionedFreeHookOrchestrator::class]);
        $bag->push('sections_planned', ['count' => 4]);

        $all = $bag->all();
        $this->assertCount(4, $all);
        $this->assertSame('sections_planned', $bag->toArray()['last_event']);
        $this->assertSame(4, $all[3]['data']['count']);
    }

    public function test_task_workflow_runner_forwards_prompt_result_ids(): void
    {
        $src = file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner::class))->getFileName(),
        ) ?: '';
        $this->assertStringContainsString("\$hookResult['prompt_result_ids']", $src);
    }

    public function test_history_service_renders_section_children(): void
    {
        $src = file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\Content\Services\ArticleExecutionHistory\ArticleExecutionHistoryService::class,
            ))->getFileName(),
        ) ?: '';
        $this->assertStringContainsString('sectioned_free_section', $src);
        $this->assertStringContainsString('child_prompt_result_ids', $src);
        $this->assertStringContainsString('article.content.section.generate', $src);
    }
}
