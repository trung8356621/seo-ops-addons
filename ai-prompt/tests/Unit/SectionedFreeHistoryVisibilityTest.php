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
 * History visibility contracts for sectioned / legacy sectioned_free — no generation algorithm changes.
 */
final class SectionedFreeHistoryVisibilityTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function nestedChildren(array $parent): array
    {
        $children = $parent['child_steps'] ?? [];

        return is_array($children) ? array_values($children) : [];
    }

    public function test_expand_legacy_sectioned_free_nests_parent_sections_and_assemble(): void
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

        self::assertCount(1, $rows);
        $parent = $rows[0];
        self::assertSame(100, (int) $parent['result_id']);
        self::assertSame('sectioned_free_parent', (string) ($parent['outline_subtask'] ?? ''));
        self::assertSame('sectioned_free', (string) ($parent['generation_strategy'] ?? ''));
        self::assertStringContainsString('MULTIPLE_PASS', (string) ($parent['prompt_name'] ?? ''));

        $children = $this->nestedChildren($parent);
        self::assertCount(5, $children); // 4 sections + assemble
        self::assertSame('article.content.section.generate', (string) $children[0]['hook_key']);
        self::assertSame(201, (int) $children[0]['result_id']);
        self::assertSame(204, (int) $children[3]['result_id']);
        self::assertSame('assemble', (string) ($children[4]['outline_subtask'] ?? ''));
        self::assertFalse((bool) ($children[0]['persists_as_outline'] ?? false));
        self::assertNull($children[0]['outline_markdown'] ?? null);
    }

    public function test_expand_canonical_sectioned_preserves_sectioned_label(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Viết bài',
            'status' => 'completed',
            'result_id' => 50,
            'hook_key' => 'article.content.generate',
            'generation_strategy' => 'sectioned',
            'generation_shape' => 'sectioned',
            'prompt_result_ids' => [50, 61, 62],
            'child_prompt_result_ids' => [61, 62],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('sectioned', (string) ($rows[0]['generation_strategy'] ?? ''));
        $names = array_map(
            static fn (array $row): string => (string) ($row['prompt_name'] ?? ''),
            array_merge([$rows[0]], $this->nestedChildren($rows[0])),
        );
        foreach ($names as $name) {
            self::assertStringNotContainsString('Outline', $name);
            self::assertStringNotContainsString('Vocabulary', $name);
        }
        self::assertCount(3, $this->nestedChildren($rows[0])); // 2 sections + assemble
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
        self::assertSame('sectioned', (string) ($result['generation_strategy'] ?? $result['metrics']['generation_strategy'] ?? ''));
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

        self::assertCount(1, $rows);
        self::assertSame('failed', (string) $rows[0]['status']);
        $sectionRows = array_values(array_filter(
            $this->nestedChildren($rows[0]),
            static fn (array $row): bool => (string) ($row['hook_key'] ?? '') === 'article.content.section.generate',
        ));
        self::assertCount(4, $sectionRows);
    }
}
