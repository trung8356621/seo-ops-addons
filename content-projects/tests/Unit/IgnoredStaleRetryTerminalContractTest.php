<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArticleRowStatusResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRowStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ignored_stale: terminal, no auto-retry, not presented as content applied.
 */
final class IgnoredStaleRetryTerminalContractTest extends TestCase
{
    public function test_ignored_stale_is_terminal_and_not_active(): void
    {
        self::assertTrue(ContentProjectExecutionStatus::isTerminal('ignored_stale'));
        self::assertContains('ignored_stale', ContentProjectExecutionStatus::terminalStatuses());
        self::assertNotContains('ignored_stale', ContentProjectExecutionStatus::activeStatuses());
        self::assertFalse(ContentProjectExecutionStatus::isActive('ignored_stale'));
    }

    public function test_ignored_stale_does_not_match_transient_ai_retry_policy(): void
    {
        $meta = ContentProjectTransientAiRetryPolicy::fromFailedItemRow([
            'status' => 'success',
            'persist_status' => 'ignored_stale',
            'message' => 'Kết quả bị bỏ qua vì bài đã được sửa (ignored_stale).',
            'error_code' => null,
            'steps' => [],
        ]);
        self::assertNull($meta, 'ignored_stale must not schedule transient AI retry');
    }

    public function test_create_articles_skips_focus_sync_on_ignored_stale(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/CreateArticlesFromTaskService.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('PERSIST_IGNORED_STALE', $source);
        self::assertMatchesRegularExpression(
            '/PERSIST_IGNORED_STALE.*?return\s*\[/s',
            $source,
        );
        self::assertStringNotContainsString(
            'syncFocusKeywordFromContext($article',
            $this->ignoredStaleReturnBlock($source),
        );
    }

    public function test_workflow_run_skips_post_run_pipeline_on_ignored_stale(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/SeoProjectWorkflowRunService.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString("persistStatus === 'ignored_stale'", $source);
        self::assertStringContainsString('isIgnoredStale', $source);
        self::assertStringContainsString('! $isIgnoredStale', $source);
        self::assertStringContainsString("postRunPipeline->apply", $source);
    }

    public function test_read_model_does_not_present_ignored_stale_as_applied_success(): void
    {
        $resolver = new ContentProjectArticleRowStatusResolver;
        $status = $resolver->resolve([
            'status' => 'success',
            'persist_status' => ArticleWritingExecutionResult::PERSIST_IGNORED_STALE,
            'workflow_steps' => [],
        ]);

        self::assertSame(ContentProjectArticleRowStatus::CODE_IGNORED_STALE, $status->code);
        self::assertNotSame(ContentProjectArticleRowStatus::CODE_COMPLETED, $status->code);
        self::assertStringContainsString('Bỏ qua', $status->label);
        self::assertStringNotContainsString('Hoàn tất', $status->label);
        self::assertStringNotContainsString('Content applied', $status->label);
    }

    public function test_execution_result_constant_locked(): void
    {
        self::assertSame('ignored_stale', ArticleWritingExecutionResult::PERSIST_IGNORED_STALE);
        $ref = new ReflectionClass(ArticleWritingExecutionResult::class);
        self::assertTrue($ref->hasConstant('PERSIST_IGNORED_STALE'));
    }

    private function ignoredStaleReturnBlock(string $source): string
    {
        if (! preg_match(
            '/if\s*\(\s*\$result->persistStatus\s*===\s*.*?PERSIST_IGNORED_STALE.*?\{(.*?)\}/s',
            $source,
            $m,
        )) {
            self::fail('CreateArticlesFromTaskService ignored_stale early-return block not found');
        }

        return (string) $m[1];
    }
}
