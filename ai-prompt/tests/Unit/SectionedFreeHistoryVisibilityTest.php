<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeArticleGenerator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeHookOrchestrator;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeTrackedProviderCall;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionTrace;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * History visibility contracts for sectioned_free — no generation algorithm changes.
 */
final class SectionedFreeHistoryVisibilityTest extends TestCase
{
    public function test_expand_sectioned_free_emits_parent_and_each_section_row(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Viết bài theo dàn ý',
            'status' => 'failed',
            'message' => 'SECTIONED_FREE_FINAL_TOO_SHORT',
            'result_id' => 100,
            'hook_key' => 'article.content.generate',
            'execution_source' => 'sectioned_free_orchestrator',
            'generation_strategy' => 'sectioned_free',
            'prompt_result_ids' => [100, 201, 202, 203, 204],
            'child_prompt_result_ids' => [201, 202, 203, 204],
        ]);

        self::assertCount(5, $rows);
        self::assertSame(100, (int) $rows[0]['result_id']);
        self::assertStringContainsString('Sectioned free', (string) $rows[0]['prompt_name']);
        self::assertSame('article.content.section.generate', (string) $rows[1]['hook_key']);
        self::assertSame(201, (int) $rows[1]['result_id']);
        self::assertSame(204, (int) $rows[4]['result_id']);
        self::assertFalse((bool) ($rows[1]['persists_as_outline'] ?? false));
        self::assertNull($rows[1]['outline_markdown'] ?? null);
    }

    public function test_multi_prompt_result_ids_do_not_become_outline_vocabulary(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Viết bài theo dàn ý',
            'status' => 'failed',
            'result_id' => 50,
            'hook_key' => 'article.content.generate',
            'generation_strategy' => 'sectioned_free',
            'prompt_result_ids' => [50, 61, 62],
            'child_prompt_result_ids' => [61, 62],
        ]);

        $names = array_map(static fn (array $row): string => (string) ($row['prompt_name'] ?? ''), $rows);
        foreach ($names as $name) {
            self::assertStringNotContainsString('Outline', $name);
            self::assertStringNotContainsString('Vocabulary', $name);
        }
        self::assertCount(3, $rows);
    }

    public function test_workflow_trace_preserves_child_prompt_result_ids(): void
    {
        $trace = WorkflowExecutionTrace::fromSteps([
            [
                'node_id' => 'write',
                'type' => 'prompt',
                'title' => 'Viết bài theo dàn ý',
                'status' => 'failed',
                'result_id' => 10,
                'prompt_result_ids' => [10, 11, 12, 13],
                'child_prompt_result_ids' => [11, 12, 13],
                'generation_strategy' => 'sectioned_free',
                'execution_source' => 'sectioned_free_orchestrator',
                'hook_key' => 'article.content.generate',
            ],
        ]);

        self::assertSame([11, 12, 13], $trace[0]['child_prompt_result_ids']);
        self::assertSame([10, 11, 12, 13], $trace[0]['prompt_result_ids']);
        self::assertSame('sectioned_free', $trace[0]['generation_strategy']);
    }

    public function test_link_service_collects_child_prompt_result_ids(): void
    {
        $src = file_get_contents((string) (new ReflectionClass(PromptResultLinkService::class))->getFileName()) ?: '';
        self::assertStringContainsString('child_prompt_result_ids', $src);
    }

    public function test_tracked_call_links_immediately_and_orchestrator_has_no_wrapping_transaction(): void
    {
        $tracked = file_get_contents((string) (new ReflectionClass(SectionedFreeTrackedProviderCall::class))->getFileName()) ?: '';
        $orch = file_get_contents((string) (new ReflectionClass(SectionedFreeHookOrchestrator::class))->getFileName()) ?: '';

        self::assertStringContainsString('linkChildImmediately', $tracked);
        self::assertStringContainsString('PromptResultLinkService', $tracked);
        self::assertStringContainsString('linkPromptResultImmediately', $orch);
        self::assertStringNotContainsString('DB::transaction', $orch);
        self::assertStringNotContainsString('DB::transaction', $tracked);
    }

    public function test_entry_path_provider_calls_match_planned_sections(): void
    {
        $outline = <<<'MD'
# Title
Intro text

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
MD;
        $calls = 0;
        $childIds = [];
        $generator = new SectionedFreeArticleGenerator();
        $result = $generator->run(
            [
                'title' => 'Title',
                'primary_keyword' => 'kw',
                'outline' => $outline,
                'input' => $outline,
                'article_length' => 1500,
            ],
            function (SectionedFreeSectionUnit $unit, string $prompt) use (&$calls, &$childIds): array {
                $calls++;
                $fakeId = 1000 + $calls;
                $childIds[] = $fakeId;
                self::assertStringNotContainsString('TASK_2_VOCABULARY', $prompt);

                return [
                    'output' => str_repeat('word ', max(60, $unit->preferredTargetWords)),
                    'model' => 'free-model',
                    'provider' => 'test',
                    'connection_id' => 1,
                    'attempt_count' => 1,
                    'prompt_result_ids' => [$fakeId],
                    'usage' => [
                        'prompt_result_id' => $fakeId,
                        'provider_calls' => 1,
                    ],
                ];
            },
        );

        $planned = (int) ($result['metrics']['planned_unit_count'] ?? $result['metrics']['generation_unit_count'] ?? 0);
        self::assertGreaterThanOrEqual(5, $planned);
        self::assertSame($planned, $calls);
        self::assertCount($calls, $childIds);
        self::assertSame($calls, (int) ($result['usage']['provider_calls'] ?? 0));
    }

    public function test_parent_fail_history_step_still_expands_successful_children(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Viết bài theo dàn ý',
            'status' => 'failed',
            'message' => 'OUTPUT_TRUNCATED',
            'result_id' => 900,
            'hook_key' => 'article.content.generate',
            'execution_source' => 'sectioned_free_orchestrator',
            'generation_strategy' => 'sectioned_free',
            'prompt_result_ids' => [900, 901, 902, 903, 904],
            'child_prompt_result_ids' => [901, 902, 903, 904],
        ]);

        $sectionRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['hook_key'] ?? '') === 'article.content.section.generate',
        ));
        self::assertCount(4, $sectionRows);
        self::assertSame('failed', (string) $rows[0]['status']);
    }
}
