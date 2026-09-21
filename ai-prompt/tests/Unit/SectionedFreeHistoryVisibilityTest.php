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

    public function test_canonicalize_nests_parent_children_only_no_top_level_section(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'canonicalizeSectionedWritingLayout');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $groups */
        $groups = $method->invoke($service, [[
            'id' => 'run-1',
            'prompts' => [
                [
                    'result_id' => 100,
                    'hook_key' => 'article.content.generate',
                    'history_role' => 'orchestrator',
                    'status' => 'completed',
                    'child_prompt_result_ids' => [201, 202],
                    'children' => [
                        [
                            'result_id' => 201,
                            'hook_key' => 'article.content.section.generate',
                            'history_role' => 'provider_call',
                            'section_id' => 's1',
                            'parent_prompt_result_id' => 100,
                        ],
                        [
                            'result_id' => 202,
                            'hook_key' => 'article.content.section.generate',
                            'history_role' => 'provider_call',
                            'section_id' => 's2',
                            'parent_prompt_result_id' => 100,
                        ],
                        [
                            'result_id' => null,
                            'history_role' => 'assemble',
                            'hook_key' => 'article.content.generate',
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertCount(1, $groups);
        self::assertCount(1, $groups[0]['prompts']);
        $parent = $groups[0]['prompts'][0];
        self::assertSame('orchestrator', $parent['history_role']);
        self::assertCount(3, $parent['children']);
        $topHooks = array_map(
            static fn (array $p): string => (string) ($p['hook_key'] ?? ''),
            $groups[0]['prompts'],
        );
        self::assertNotContains('article.content.section.generate', $topHooks);
    }

    public function test_canonicalize_attaches_section_with_parent_id_even_when_duplicate_top_level(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'canonicalizeSectionedWritingLayout');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $groups */
        $groups = $method->invoke($service, [[
            'id' => 'run-1',
            'prompts' => [
                [
                    'result_id' => 50,
                    'hook_key' => 'article.content.generate',
                    'history_role' => 'orchestrator',
                    'child_prompt_result_ids' => [61],
                    'children' => [
                        [
                            'result_id' => 61,
                            'hook_key' => 'article.content.section.generate',
                            'history_role' => 'provider_call',
                            'parent_prompt_result_id' => 50,
                            'section_id' => 's1',
                        ],
                        ['result_id' => null, 'history_role' => 'assemble'],
                    ],
                ],
                [
                    'result_id' => 61,
                    'hook_key' => 'article.content.section.generate',
                    'history_role' => 'provider_call',
                    'parent_prompt_result_id' => 50,
                    'section_id' => 's1',
                    'prompt_name' => 'Section 1/2 duplicate top-level',
                ],
            ],
        ]]);

        self::assertCount(1, $groups[0]['prompts']);
        $parent = $groups[0]['prompts'][0];
        $childIds = array_map(
            static fn (array $c): int => (int) ($c['result_id'] ?? 0),
            array_filter(
                $parent['children'],
                static fn (array $c): bool => ($c['history_role'] ?? '') === 'provider_call',
            ),
        );
        self::assertSame([61], array_values($childIds));
    }

    public function test_canonicalize_hides_orphan_legacy_section_without_parent(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'canonicalizeSectionedWritingLayout');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $groups */
        $groups = $method->invoke($service, [[
            'id' => 'article-prompts',
            'prompts' => [
                [
                    'result_id' => 999,
                    'hook_key' => 'article.content.section.generate',
                    'history_role' => 'provider_call',
                    'section_id' => 'orphan',
                    'parent_prompt_result_id' => 404,
                    'prompt_name' => 'Orphan section',
                ],
                [
                    'result_id' => 10,
                    'hook_key' => 'article.outline.generate',
                    'status' => 'completed',
                    'prompt_name' => 'Outline',
                ],
            ],
        ]]);

        self::assertCount(1, $groups);
        self::assertCount(1, $groups[0]['prompts']);
        self::assertSame(10, (int) $groups[0]['prompts'][0]['result_id']);
        self::assertSame('article.outline.generate', $groups[0]['prompts'][0]['hook_key']);
    }

    public function test_canonicalize_attaches_section_discovered_only_via_parent_prompt_result_id(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'canonicalizeSectionedWritingLayout');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $groups */
        $groups = $method->invoke($service, [[
            'id' => 'run-1',
            'prompts' => [
                [
                    'result_id' => 70,
                    'hook_key' => 'article.content.generate',
                    'history_role' => 'orchestrator',
                    'status' => 'failed',
                    'child_prompt_result_ids' => [],
                    'children' => [
                        ['result_id' => null, 'history_role' => 'assemble'],
                    ],
                ],
                [
                    'result_id' => 71,
                    'hook_key' => 'article.content.section.generate',
                    'history_role' => 'provider_call',
                    'parent_prompt_result_id' => 70,
                    'section_id' => 's1',
                    'attempts' => [
                        ['result_id' => 71, 'history_role' => 'provider_attempt', 'status' => 'failed'],
                        ['result_id' => 72, 'history_role' => 'provider_attempt', 'status' => 'completed'],
                    ],
                ],
            ],
        ]]);

        self::assertCount(1, $groups[0]['prompts']);
        $parent = $groups[0]['prompts'][0];
        self::assertSame('failed', $parent['status']);
        $sections = array_values(array_filter(
            $parent['children'],
            static fn (array $c): bool => ($c['history_role'] ?? '') === 'provider_call',
        ));
        self::assertCount(1, $sections);
        self::assertSame(71, (int) $sections[0]['result_id']);
        self::assertCount(2, $sections[0]['attempts'] ?? []);
    }

    public function test_canonicalize_leaves_single_pass_and_non_writing_unchanged(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'canonicalizeSectionedWritingLayout');
        $method->setAccessible(true);

        $input = [[
            'id' => 'run-1',
            'prompts' => [
                [
                    'result_id' => 1,
                    'hook_key' => 'article.content.generate',
                    'history_role' => null,
                    'generation_strategy' => 'single',
                    'status' => 'completed',
                ],
                [
                    'result_id' => 2,
                    'hook_key' => 'article.outline.generate',
                    'status' => 'completed',
                ],
                [
                    'result_id' => 3,
                    'hook_key' => 'media.image.generate',
                    'status' => 'completed',
                ],
            ],
        ]];

        /** @var list<array<string, mixed>> $groups */
        $groups = $method->invoke($service, $input);
        self::assertCount(3, $groups[0]['prompts']);
        self::assertSame([1, 2, 3], array_map(
            static fn (array $p): int => (int) $p['result_id'],
            $groups[0]['prompts'],
        ));
    }

    public function test_expand_discovers_children_via_parent_prompt_result_id_on_results(): void
    {
        $parent = new \Omnichannel\Addons\AiPrompt\Models\PromptResult;
        $parent->id = 80;
        $parent->input_snapshot = [
            'sectioned_free_orchestrator' => true,
            'hook_key' => 'article.content.generate',
            'generation_strategy' => 'sectioned',
        ];

        $child = new \Omnichannel\Addons\AiPrompt\Models\PromptResult;
        $child->id = 81;
        $child->input_snapshot = [
            'sectioned_free_section' => true,
            'hook_key' => 'article.content.section.generate',
            'section_id' => 's1',
            'parent_prompt_result_id' => 80,
        ];

        $results = collect([80 => $parent, 81 => $child]);
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'result_id' => 80,
            'hook_key' => 'article.content.generate',
            'generation_strategy' => 'sectioned',
            'execution_source' => 'sectioned_orchestrator',
            'child_prompt_result_ids' => [],
            'prompt_result_ids' => [80],
        ], $results);

        self::assertCount(1, $rows);
        $children = $this->nestedChildren($rows[0]);
        $sectionIds = array_map(
            static fn (array $c): int => (int) ($c['result_id'] ?? 0),
            array_filter(
                $children,
                static fn (array $c): bool => ($c['history_role'] ?? '') === 'provider_call',
            ),
        );
        self::assertSame([81], array_values($sectionIds));
    }
}
