<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OutlineDownstreamSkipOnFailureTest extends TestCase
{
    public function test_workflow_runner_skips_writing_after_outline_fail(): void
    {
        $src = $this->source(TaskWorkflowTestRunner::class);
        self::assertStringContainsString('shouldSkipAfterOutlineFailure', $src);
        self::assertStringContainsString('Không chạy vì bước Dàn ý thất bại.', $src);
        self::assertStringContainsString("'status' => 'skipped'", $src);
        self::assertStringContainsString('outline_failed', $src);
    }

    public function test_workflow_runner_blocks_persist_after_content_fail(): void
    {
        $src = $this->source(TaskWorkflowTestRunner::class);
        self::assertStringContainsString('shouldBlockAfterContentFailure', $src);
        self::assertStringContainsString('content_artifact_missing', $src);
        self::assertStringContainsString('article_content artifact hợp lệ', $src);
        self::assertStringContainsString("'status' => 'blocked'", $src);
    }

    public function test_outline_then_article_blocks_on_outline_fail(): void
    {
        $src = $this->source(CreateArticlesFromTaskService::class);
        self::assertStringContainsString('article_blocked', $src);
        self::assertStringContainsString('Outline fail — article không chạy.', $src);
        self::assertStringContainsString("\$outputs['total']", $src);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }
}
