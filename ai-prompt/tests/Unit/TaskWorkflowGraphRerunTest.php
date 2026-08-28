<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class TaskWorkflowGraphRerunTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function branchingTaskFlow(): array
    {
        return [
            'nodes' => [
                ['id' => 'input', 'type' => 'user_input', 'title' => 'Input', 'data' => []],
                ['id' => 'outline', 'type' => 'end', 'title' => 'Prompt Outline', 'data' => []],
                ['id' => 'extract', 'type' => 'end', 'title' => 'Extract Keywords', 'data' => []],
                ['id' => 'save_vocab', 'type' => 'end', 'title' => 'Save Vocabulary', 'data' => []],
                ['id' => 'writing', 'type' => 'end', 'title' => 'Writing Prompt', 'data' => []],
                ['id' => 'save_article', 'type' => 'end', 'title' => 'Save Article', 'data' => []],
                ['id' => 'unrelated_a', 'type' => 'end', 'title' => 'Unrelated A', 'data' => []],
                ['id' => 'unrelated_b', 'type' => 'end', 'title' => 'Unrelated B', 'data' => []],
            ],
            'edges' => [
                ['sourceNode' => 'input', 'targetNode' => 'outline'],
                ['sourceNode' => 'outline', 'targetNode' => 'extract', 'sourcePort' => 'task_2_vocabulary'],
                ['sourceNode' => 'extract', 'targetNode' => 'save_vocab'],
                ['sourceNode' => 'outline', 'targetNode' => 'writing', 'sourcePort' => 'total'],
                ['sourceNode' => 'writing', 'targetNode' => 'save_article'],
                ['sourceNode' => 'unrelated_a', 'targetNode' => 'unrelated_b'],
            ],
        ];
    }

    private function makeTask(?array $flow = null): SeoTask
    {
        $task = new SeoTask;
        $task->forceFill([
            'id' => 9001,
            'is_active' => true,
            'flow_data' => $flow ?? $this->branchingTaskFlow(),
        ]);

        return $task;
    }

    private function makeContext(string $summary, array $variables = [], ?int $siteId = null): TaskTestContext
    {
        return new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: null,
            variables: $variables,
            summary: $summary,
            siteId: $siteId,
        );
    }

    private function runnerWithMinimalDeps(bool $withArticleInput = false): TaskWorkflowTestRunner
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();

        if ($withArticleInput) {
            $parser = (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\WorkflowParserService::class))
                ->newInstanceWithoutConstructor();
            $outlineResolver = new ArticleOutlineResolver($parser);
            $articleInput = new ReflectionProperty(TaskWorkflowTestRunner::class, 'articleGenerationInput');
            $articleInput->setValue($runner, new ArticleGenerationInputResolver($outlineResolver));
            $workflowParser = new ReflectionProperty(TaskWorkflowTestRunner::class, 'workflowParser');
            $workflowParser->setValue($runner, $parser);
        }

        return $runner;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<string>
     */
    private function executedNodeIds(array $steps): array
    {
        return array_values(array_map(
            static fn (array $step): string => (string) ($step['node_id'] ?? ''),
            array_filter(
                $steps,
                static fn (array $step): bool => in_array((string) ($step['status'] ?? ''), ['completed', 'ok'], true),
            ),
        ));
    }

    public function test_rerun_from_outline_executes_downstream_graph_not_unrelated_branch(): void
    {
        $runner = $this->runnerWithMinimalDeps();
        $task = $this->makeTask();
        $context = $this->makeContext('graph test', ['input' => 'topic'], siteId: 1);

        $steps = $runner->runFromNodeId($task, $context, 'outline', seedOutlineFromArticle: false);

        self::assertEqualsCanonicalizing(
            ['outline', 'extract', 'save_vocab', 'writing', 'save_article'],
            $this->executedNodeIds($steps),
        );

        $skipped = array_column(
            array_filter($steps, static fn (array $s): bool => ($s['status'] ?? '') === 'skipped'),
            'node_id',
        );
        self::assertContains('unrelated_a', $skipped);
        self::assertContains('unrelated_b', $skipped);
    }

    public function test_rerun_from_writing_skips_outline_and_vocabulary_branch(): void
    {
        $runner = $this->runnerWithMinimalDeps(withArticleInput: true);
        $artifact = "[START_TASK_1_OUTLINE]\n## H\n[END_TASK_1_OUTLINE]\n\n"
            ."[START_TASK_2_VOCABULARY]\n- term\n[END_TASK_2_VOCABULARY]";
        $context = $this->makeContext('writing rerun', [
            'input' => $artifact,
            'article_writing_raw_input' => $artifact,
        ], siteId: 1);

        $steps = $runner->runFromNodeId($this->makeTask(), $context, 'writing', seedOutlineFromArticle: true);

        self::assertSame(['writing', 'save_article'], $this->executedNodeIds($steps));

        foreach ($steps as $step) {
            if (! in_array((string) ($step['node_id'] ?? ''), ['outline', 'extract', 'save_vocab', 'unrelated_a', 'unrelated_b'], true)) {
                continue;
            }
            self::assertSame('skipped', $step['status'] ?? null, 'Unexpected execution: '.($step['node_id'] ?? ''));
        }
    }

    public function test_outline_failure_blocks_vocabulary_and_writing_descendants(): void
    {
        $runner = $this->runnerWithMinimalDeps();
        $flow = $this->branchingTaskFlow();
        $flow['nodes'] = array_map(static function (array $node): array {
            if (($node['id'] ?? '') === 'outline') {
                $node['type'] = 'prompt';
                $node['data'] = ['promptId' => 999999];
            }

            return $node;
        }, $flow['nodes']);

        $steps = $runner->runFromNodeId(
            $this->makeTask($flow),
            $this->makeContext('fail outline', siteId: 1),
            'outline',
            seedOutlineFromArticle: false,
        );

        $byId = [];
        foreach ($steps as $step) {
            $byId[(string) ($step['node_id'] ?? '')] = (string) ($step['status'] ?? '');
        }

        self::assertSame('failed', $byId['outline'] ?? null);
        self::assertSame('skipped', $byId['extract'] ?? null);
        self::assertSame('skipped', $byId['save_vocab'] ?? null);
        self::assertSame('skipped', $byId['writing'] ?? null);
    }

    public function test_save_vocabulary_failure_returns_failed_status_in_runner(): void
    {
        $src = strtolower((string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        ));
        self::assertStringContainsString('vocabulary save failed', $src);
        self::assertStringContainsString("'status' => 'failed'", $src);
    }

    public function test_vocabulary_save_dispatched_via_action_node_not_rerun_service(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString("'keyword.vocabulary.save'", $src);
        self::assertStringContainsString('executeSaveVocabularyResearchAction', $src);

        $createSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/../content-projects/src/Services/CreateArticlesFromTaskService.php',
        );
        self::assertStringNotContainsString('keyword.vocabulary.save', $createSrc);
    }

    public function test_outline_vocabulary_scope_skips_content_keeps_save(): void
    {
        $runner = $this->runnerWithMinimalDeps();
        $flow = [
            'nodes' => [
                ['id' => 'outline', 'type' => 'end', 'title' => 'Outline', 'data' => []],
                ['id' => 'extract', 'type' => 'end', 'title' => 'Extract', 'data' => []],
                [
                    'id' => 'writing',
                    'type' => 'prompt',
                    'title' => 'Viết bài',
                    'data' => ['hook_key' => 'article.content.generate'],
                ],
                [
                    'id' => 'save_vocab',
                    'type' => 'action',
                    'title' => 'Save vocabulary research',
                    'data' => ['actionType' => 'save_vocabulary_research'],
                ],
            ],
            'edges' => [
                ['sourceNode' => 'outline', 'targetNode' => 'extract'],
                ['sourceNode' => 'extract', 'targetNode' => 'writing'],
                ['sourceNode' => 'writing', 'targetNode' => 'save_vocab'],
            ],
        ];

        $steps = $runner->runFromNodeId(
            $this->makeTask($flow),
            $this->makeContext('outline vocab scope', ['input' => 'topic'], siteId: 1),
            'outline',
            seedOutlineFromArticle: false,
            skipContentWriting: true,
        );

        $byId = [];
        foreach ($steps as $step) {
            $byId[(string) ($step['node_id'] ?? '')] = $step;
        }

        self::assertSame('ok', $byId['outline']['status'] ?? null);
        self::assertSame('ok', $byId['extract']['status'] ?? null);
        self::assertSame('skipped', $byId['writing']['status'] ?? null);
        self::assertSame('outline_vocabulary_scope', $byId['writing']['skip_reason'] ?? null);
        // save_vocabulary must still be attempted (not scope-skipped), even when after content.
        self::assertArrayHasKey('save_vocab', $byId);
        self::assertNotSame('outline_vocabulary_scope', $byId['save_vocab']['skip_reason'] ?? null);
        self::assertNotSame(
            'Bỏ qua — phạm vi outline/vocabulary (không viết bài).',
            $byId['save_vocab']['message'] ?? null,
        );
    }

    public function test_disconnected_branch_not_executed_when_topo_places_it_after_outline(): void
    {
        $runner = $this->runnerWithMinimalDeps();
        $flow = [
            'nodes' => [
                ['id' => 'outline', 'type' => 'end', 'title' => 'Outline', 'data' => []],
                ['id' => 'writing', 'type' => 'end', 'title' => 'Writing', 'data' => []],
                ['id' => 'unrelated_a', 'type' => 'end', 'title' => 'Unrelated A', 'data' => []],
                ['id' => 'unrelated_b', 'type' => 'end', 'title' => 'Unrelated B', 'data' => []],
            ],
            'edges' => [
                ['sourceNode' => 'outline', 'targetNode' => 'writing'],
                ['sourceNode' => 'unrelated_a', 'targetNode' => 'unrelated_b'],
            ],
        ];

        $steps = $runner->runFromNodeId(
            $this->makeTask($flow),
            $this->makeContext('disconnected'),
            'outline',
            seedOutlineFromArticle: false,
        );

        self::assertEqualsCanonicalizing(['outline', 'writing'], $this->executedNodeIds($steps));
        $unrelated = null;
        foreach ($steps as $step) {
            if (($step['node_id'] ?? '') === 'unrelated_a') {
                $unrelated = $step;
                break;
            }
        }
        self::assertIsArray($unrelated);
        self::assertSame('skipped', $unrelated['status'] ?? null);
    }

    public function test_branching_output_ports_both_branches_execute(): void
    {
        $runner = $this->runnerWithMinimalDeps();
        $executed = $this->executedNodeIds(
            $runner->runFromNodeId(
                $this->makeTask(),
                $this->makeContext('ports', ['input' => 'topic'], siteId: 1),
                'outline',
                seedOutlineFromArticle: false,
            ),
        );

        self::assertContains('extract', $executed);
        self::assertContains('writing', $executed);
    }
}
