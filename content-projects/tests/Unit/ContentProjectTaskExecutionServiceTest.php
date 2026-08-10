<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTaskExecutionResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContentProjectTaskExecutionServiceTest extends TestCase
{
    public function test_execution_result_from_legacy_success(): void
    {
        $result = ContentProjectTaskExecutionResult::fromLegacyItemRow([
            'task_id' => 10,
            'run_item_id' => 20,
            'status' => 'success',
            'message' => 'Done',
            'article_id' => 99,
            'steps' => [
                ['type' => 'prompt', 'prompt_id' => 7],
            ],
        ], durationSeconds: 1.5);

        self::assertTrue($result->success);
        self::assertFalse($result->failed);
        self::assertFalse($result->cancelled);
        self::assertSame(ContentProjectArticleSemanticStatus::Completed, $result->toArticleSemanticStatus());
        self::assertSame([7], $result->promptIds);
        self::assertSame(1.5, $result->durationSeconds);
        self::assertSame('success', $result->toLegacyItemRow()['status']);
    }

    public function test_execution_result_from_legacy_failed_retryable(): void
    {
        $result = ContentProjectTaskExecutionResult::fromLegacyItemRow([
            'task_id' => 1,
            'status' => 'failed',
            'message' => 'AI timeout',
            'error_code' => ContentProjectErrorCode::ExternalWorkflowFailed->value,
        ]);

        self::assertTrue($result->failed);
        self::assertTrue($result->retryable);
        self::assertSame(ContentProjectArticleSemanticStatus::Failed, $result->toArticleSemanticStatus());
    }

    public function test_execution_result_cancelled_not_retryable(): void
    {
        $result = ContentProjectTaskExecutionResult::fromLegacyItemRow([
            'task_id' => 1,
            'status' => 'failed',
            'message' => 'Cancelled by user.',
            'error_detail' => 'Cancelled by user.',
        ]);

        self::assertTrue($result->cancelled);
        self::assertFalse($result->retryable);
        self::assertSame(ContentProjectArticleSemanticStatus::Cancelled, $result->toArticleSemanticStatus());
    }

    public function test_retry_task_is_thin_adapter(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php'
        );
        $pos = strpos($source, 'function retryTask(');
        self::assertNotFalse($pos);
        $next = strpos($source, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($source, $pos, $next - $pos) : substr($source, $pos, 800);
        self::assertStringContainsString('taskExecution->execute', $chunk);
        self::assertStringNotContainsString('runOneTask(', $chunk);
        self::assertLessThan(25, substr_count($chunk, "\n"), 'retryTask must stay thin');
    }

    public function test_article_runner_does_not_call_retry_task(): void
    {
        $runner = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectArticleRunner.php'
        );
        self::assertStringContainsString('ContentProjectTaskExecutionService', $runner);
        self::assertStringContainsString('taskExecution->execute', $runner);
        self::assertStringNotContainsString('retryTask(', $runner);
        self::assertStringNotContainsString('SeoProjectWorkflowRunService', $runner);
    }

    public function test_engine_does_not_call_retry_task_or_execution_service_directly(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringNotContainsString('retryTask(', $engine);
        self::assertStringNotContainsString('ContentProjectTaskExecutionService', $engine);
        self::assertStringNotContainsString('taskExecution', $engine);
    }

    public function test_execution_service_public_api(): void
    {
        $ref = new ReflectionClass(
            \Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectTaskExecutionService::class
        );
        foreach (['execute', 'executeLoadedTask'] as $method) {
            self::assertTrue($ref->hasMethod($method));
            self::assertTrue((new ReflectionMethod($ref->getName(), $method))->isPublic());
        }
    }

    public function test_pipeline_entry_is_public_for_execution_service_only(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php'
        );
        self::assertStringContainsString('function runTaskPipeline(', $source);
        self::assertStringContainsString('ContentProjectTaskExecutionService', $source);
    }
}
