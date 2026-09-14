<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkItemDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkItemDecisionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * One bulk = one SeoProjectRun + lazy JIT per item (no child resume/restart runs).
 */
final class ContentProjectLazyBulkGenerationContractTest extends TestCase
{
    public function test_generate_handler_creates_single_lazy_bulk_run_without_partition(): void
    {
        $src = $this->source(GenerateProjectItemsHandler::class);

        self::assertStringContainsString("'lazy_bulk' => true", $src);
        self::assertStringContainsString('execution_refs\' => [$executionRef]', $src);
        self::assertStringNotContainsString('partitionResumableFailed', $src);
        self::assertStringNotContainsString('ResumeProjectItemFromFailedStepCommand', $src);
        self::assertStringNotContainsString('RestartGenerationWithKeywordCommand', $src);
        self::assertStringNotContainsString('reconcileProject', $src);
        self::assertStringNotContainsString('bulkPlanner->partition', $src);
        self::assertStringNotContainsString('whereIn(\'id\', $allAffected)->update', $src);
        // Command still has the deprecated field; handler must not gate on it.
        self::assertStringNotContainsString('$command->technicalConfirm', $src);
    }

    public function test_prepare_run_queue_seeds_membership_without_lifecycle_mutate(): void
    {
        $workflow = $this->source(SeoProjectWorkflowRunService::class);
        $runItems = $this->source(SeoProjectRunItemService::class);

        self::assertStringContainsString('lazy_bulk', $workflow);
        self::assertStringContainsString('seedBulkMembership', $workflow);
        self::assertStringContainsString('function seedBulkMembership', $runItems);
        self::assertStringContainsString('mutateTaskLifecycle: false', $runItems);
    }

    public function test_run_engine_jit_decide_and_fail_closed(): void
    {
        $src = $this->source(ContentProjectRunEngine::class);

        self::assertStringContainsString('decideLazyBulkItem', $src);
        self::assertStringContainsString('ContentProjectBulkItemDecisionService', $src);
        self::assertStringContainsString('failClosedBulk', $src);
        self::assertStringContainsString('skipClaimedBulkItem', $src);
        self::assertStringContainsString('applyLazyBulkDecision', $src);
        self::assertSame('php_engine', ContentProjectRunEngine::SETTINGS_ENGINE_KEY);
    }

    public function test_jit_decision_operations_cover_spec(): void
    {
        self::assertSame('generate_new', ContentProjectBulkItemDecision::OP_GENERATE_NEW);
        self::assertSame('resume_from_failed_step', ContentProjectBulkItemDecision::OP_RESUME_FROM_FAILED_STEP);
        self::assertSame('restart_with_keyword', ContentProjectBulkItemDecision::OP_RESTART_WITH_KEYWORD);
        self::assertSame('skip', ContentProjectBulkItemDecision::OP_SKIP);

        $src = $this->source(ContentProjectBulkItemDecisionService::class);
        self::assertStringContainsString('active_editor_session', $src);
        self::assertStringContainsString('TYPE_REWRITE', $src);
        self::assertStringContainsString('findActiveSession', $src);
        self::assertStringContainsString('REASON_DIRTY', $src);
        self::assertStringContainsString('ACTION_RESUME', $src);
    }

    public function test_recovery_uses_php_engine_not_shadow_engine_bag(): void
    {
        $recovery = $this->source(ContentProjectGenerationRecoveryService::class);
        $method = new ReflectionMethod(ContentProjectGenerationRecoveryService::class, 'releaseStaleDispatchIfOwnedBy');
        $start = $method->getStartLine() ?? 0;
        $end = $method->getEndLine() ?? 0;
        $lines = array_slice(explode("\n", $recovery), max(0, $start - 1), max(1, $end - $start + 1));
        $body = implode("\n", $lines);

        self::assertStringContainsString('SETTINGS_ENGINE_KEY', $body);
        self::assertStringNotContainsString("settings['engine']", $body);
        self::assertStringNotContainsString('$settings[\'engine\']', $body);
    }

    public function test_ui_has_no_technical_confirm_checkbox(): void
    {
        $src = $this->source(SeoProjectResource::class);
        $pos = strpos($src, 'makeGeneratePendingItemsAction');
        self::assertNotFalse($pos);
        $chunk = substr($src, $pos, 4500);

        self::assertStringNotContainsString('technical_confirm_full_rerun', $chunk);
        self::assertStringNotContainsString('generate_pending_technical_confirm', $chunk);
        self::assertStringContainsString("'lazy_bulk' => true", $chunk);
    }

    public function test_rewrite_human_edit_guard_exists(): void
    {
        $src = $this->source(SeoProjectWorkflowRunService::class);
        self::assertStringContainsString('rewriteHumanEditConflictMessage', $src);
        self::assertStringContainsString('article_updated_at_snapshot', $this->source(ContentProjectRunEngine::class));
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
