<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use Omnichannel\Addons\AiPrompt\System\LegacySeoTaskWorkflowRuntimePort;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression: System Workflow must accept nested variables (workflow_execution_snapshot)
 * without "Array to string conversion" at TaskTestContext::fromArray / input fallback.
 */
final class LegacySeoTaskWorkflowStructuredVariablesTest extends TestCase
{
    public function test_adapter_does_not_stringify_input_arrays(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->getFileName());

        self::assertStringNotContainsString(
            'static fn (mixed $v): string => is_string($v) ? $v : (string) $v',
            $src,
        );
        self::assertStringContainsString("'variables' => \$request->input", $src);
        self::assertStringContainsString('TaskTestContext::fromArray', $src);
    }

    public function test_full_run_context_payload_preserves_structured_variables(): void
    {
        $snapshot = [
            'flow_data_hash' => 'hash_cp',
            'roles' => ['article_content_generate' => 'n_content'],
        ];

        // Shape used when callers pass task_test_context (full_run / from_node content-project path).
        $context = $this->fromArrayWithoutArrayToStringWarning([
            'article_id' => null,
            'is_new_article' => true,
            'matched_by' => 'content_project',
            'summary' => 'create',
            'variables' => [
                'focus_keyword' => 'focus kw',
                'workflow_execution_snapshot' => $snapshot,
            ],
            'site_id' => 4,
            'project_task_type' => 'create',
        ]);

        self::assertSame('focus kw', $context->variables['focus_keyword']);
        self::assertSame($snapshot, $context->variables['workflow_execution_snapshot']);
    }

    public function test_from_node_input_fallback_preserves_structured_variables(): void
    {
        $snapshot = [
            'flow_data_hash' => 'hash_input',
            'nested' => ['a' => 1],
        ];

        // Exact payload shape after adapter fix: variables = $request->input (no string cast).
        $context = $this->fromArrayWithoutArrayToStringWarning([
            'article_id' => null,
            'is_new_article' => true,
            'matched_by' => 'system_workflow',
            'summary' => 'System Workflow disposable/raw input',
            'variables' => [
                'focus_keyword' => 'from input',
                'workflow_execution_snapshot' => $snapshot,
            ],
        ]);

        self::assertSame('from input', $context->variables['focus_keyword']);
        self::assertSame($snapshot, $context->variables['workflow_execution_snapshot']);
    }

    public function test_create_and_rewrite_boundary_still_passes_snapshot_array(): void
    {
        $createSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/../content-projects/src/Services/CreateArticlesFromTaskService.php',
        );
        $writingSrc = (string) file_get_contents(
            dirname(__DIR__, 3).'/../content/src/Services/ArticleWritingExecutionService.php',
        );

        self::assertStringContainsString("\$variables['workflow_execution_snapshot'] = \$wfSnap", $createSrc);
        self::assertStringContainsString("'task_test_context' => \$taskContext->toArray()", $writingSrc);
        self::assertStringContainsString('runContentNodeViaSystemWorkflow', $writingSrc);
        self::assertStringContainsString('TaskTestContext::fromArray', (string) file_get_contents(
            (new ReflectionClass(LegacySeoTaskWorkflowRuntimePort::class))->getFileName(),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fromArrayWithoutArrayToStringWarning(array $payload): TaskTestContext
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'Array to string conversion')) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        });

        try {
            return TaskTestContext::fromArray($payload);
        } finally {
            restore_error_handler();
        }
    }
}
