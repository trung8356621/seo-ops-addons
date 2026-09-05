<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class OpenAiCompatibleTextExtractionAndCheckpointTest extends TestCase
{
    public function test_extract_text_response_supports_string_and_content_blocks(): void
    {
        $adapter = new OpenAiCompatibleProtocolAdapter;

        self::assertSame(
            'Hello world',
            $adapter->extractTextResponse([
                'choices' => [['message' => ['content' => 'Hello world'], 'finish_reason' => 'stop']],
            ]),
        );

        self::assertSame(
            "Part A\nPart B",
            $adapter->extractTextResponse([
                'choices' => [[
                    'message' => [
                        'content' => [
                            ['type' => 'reasoning', 'text' => 'secret thinking'],
                            ['type' => 'text', 'text' => 'Part A'],
                            ['type' => 'output_text', 'text' => 'Part B'],
                        ],
                    ],
                    'finish_reason' => 'stop',
                ]],
            ]),
        );

        self::assertSame(
            'legacy completion',
            $adapter->extractTextResponse([
                'choices' => [['text' => 'legacy completion', 'finish_reason' => 'stop']],
            ]),
        );
    }

    public function test_extract_text_response_ignores_reasoning_only_payload(): void
    {
        $adapter = new OpenAiCompatibleProtocolAdapter;
        self::assertSame('', $adapter->extractTextResponse([
            'choices' => [[
                'message' => [
                    'content' => [
                        ['type' => 'thinking', 'text' => 'do not use as article'],
                    ],
                ],
                'finish_reason' => 'stop',
            ]],
        ]));
    }

    public function test_in_memory_checkpoint_reused_without_identity(): void
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $resolve = new ReflectionMethod($runner, 'resolveSplitOutlineCheckpoint');
        $resolve->setAccessible(true);

        $state = new WorkflowExecutionState;
        $state->meta['split_structure_outline'] = "# In memory outline";
        $state->meta['split_structure_outline_result_id'] = 77;

        $resolved = $resolve->invoke($runner, $state, $this->context(100, 10, 'túi balo'));
        self::assertSame('# In memory outline', $resolved['body']);
        self::assertSame(77, $resolved['prompt_result_id']);
    }

    public function test_durable_checkpoint_requires_matching_run_and_fingerprint(): void
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $identity = new ReflectionMethod($runner, 'splitOutlineCheckpointIdentity');
        $identity->setAccessible(true);
        $fingerprint = new ReflectionMethod($runner, 'buildSplitOutlineInputFingerprint');
        $fingerprint->setAccessible(true);

        $ctx100 = $this->context(100, 10, 'túi balo');
        $id100 = $identity->invoke($runner, $ctx100);
        self::assertSame(100, $id100['run_id']);
        self::assertSame(10, $id100['run_item_id']);
        self::assertNotSame('', $id100['input_fingerprint']);

        $ctx101 = $this->context(101, 10, 'túi balo');
        $id101 = $identity->invoke($runner, $ctx101);
        self::assertNotSame($id100['run_id'], $id101['run_id']);
        self::assertSame($id100['input_fingerprint'], $id101['input_fingerprint']);

        $ctxChanged = $this->context(100, 10, 'balo khác');
        $idChanged = $identity->invoke($runner, $ctxChanged);
        self::assertNotSame($id100['input_fingerprint'], $idChanged['input_fingerprint']);

        $fpDirect = $fingerprint->invoke($runner, $ctx100->variables);
        self::assertSame($id100['input_fingerprint'], $fpDirect);
    }

    public function test_runner_checkpoint_source_requires_identity_fields(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString("'input_fingerprint'", $src);
        self::assertStringContainsString('buildSplitOutlineInputFingerprint', $src);
        self::assertStringContainsString('splitOutlineCheckpointIdentity', $src);
        self::assertStringContainsString('Legacy plain-text durable checkpoint', $src);
    }

    /**
     * @return TaskTestContext
     */
    private function context(int $runId, int $runItemId, string $subject): TaskTestContext
    {
        return new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: null,
            variables: [
                'run_id' => (string) $runId,
                'run_item_id' => (string) $runItemId,
                'focus_keyword' => $subject,
                'input' => $subject,
                'article_id' => '1',
                'site_id' => '1',
                'project_id' => '1',
            ],
            summary: 'test',
        );
    }
}
