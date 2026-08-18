<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemStepHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Fail-closed: single-item rerun must not expand to project pending set.
 */
final class ContentProjectRerunScopeIsolationTest extends TestCase
{
    public function test_rerun_handlers_require_explicit_item_refs(): void
    {
        $full = $this->source(RerunProjectItemsHandler::class);
        self::assertStringContainsString('Rerun requires explicit item selection', $full);
        self::assertStringContainsString("'task_ids' => \$itemIds", $full);
        self::assertStringContainsString("'rerun' => true", $full);
        self::assertStringContainsString('AiCostPolicy::FreeOnly', $full);

        $step = $this->source(RerunProjectItemStepHandler::class);
        self::assertStringContainsString('Step rerun requires explicit item selection', $step);
        self::assertStringContainsString("'task_ids' => \$itemIds", $step);
        self::assertStringContainsString("'rerun' => true", $step);
        self::assertStringContainsString('AiCostPolicy::FreeOnly', $step);
    }

    public function test_prepare_run_queue_fail_closed_when_rerun_without_ids(): void
    {
        $src = $this->source(SeoProjectWorkflowRunService::class);
        self::assertStringContainsString('entire-project rerun blocked', $src);
        self::assertStringContainsString('Explicit generate selection', $src);
    }

    public function test_snapshot_preserves_selection_keys_used_by_queue(): void
    {
        $snap = ContentProjectRunSettings::snapshotForRun([
            'task_ids' => [427, 999],
            'rerun' => true,
            'use_php_engine' => true,
        ]);
        self::assertSame([427, 999], $snap['task_ids']);
        self::assertTrue((bool) $snap['rerun']);
    }

    public function test_generate_handler_still_may_resolve_pending_when_refs_empty(): void
    {
        // Project-level generate is allowed; item rerun is not.
        $src = $this->source(GenerateProjectItemsHandler::class);
        self::assertStringContainsString('runnableTaskIds', $src);
        self::assertStringContainsString('AiCostPolicy::FreeOnly', $src);
    }

    public function test_prepare_operation_marks_task_queued(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectRunItemService.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('markTaskQueuedForGeneration', $src);
        self::assertStringContainsString('STATUS_PENDING', $src);
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
