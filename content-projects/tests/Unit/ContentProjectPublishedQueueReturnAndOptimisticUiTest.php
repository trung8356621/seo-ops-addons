<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemStepHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedEvidence;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Queue return must not fake Published; rerun must not no-op; Last activity is optimistic.
 */
final class ContentProjectPublishedQueueReturnAndOptimisticUiTest extends TestCase
{
    public function test_case_a_return_clears_queue_runtime_and_fake_published_stamp(): void
    {
        $src = $this->source(ContentProjectPublishingQueueService::class);
        $pos = strpos($src, 'function returnToContentProject');
        self::assertNotFalse($pos);
        $next = strpos($src, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($src, $pos, $next - $pos) : substr($src, $pos);

        self::assertStringContainsString("'publishing_queued_at' => null", $chunk);
        self::assertStringContainsString("'scheduled_publish_at' => null", $chunk);
        self::assertStringContainsString('ContentProjectPublishQueueStatus::None', $chunk);
        self::assertStringContainsString("\$attrs['publish_published_at'] = null", $chunk);
        self::assertStringContainsString('ObservedWordPressPostStatus::isLiveOnSite', $chunk);
        self::assertStringContainsString('return_to_content_project', $chunk);
        self::assertStringNotContainsString('fromTaskAndArticle', $chunk);
    }

    public function test_case_b_return_then_rerun_is_accepted_not_silent(): void
    {
        $rerun = $this->source(RerunProjectItemsHandler::class);
        self::assertStringContainsString('eligibility->validateFull', $rerun);
        self::assertStringContainsString('runEngine->start', $rerun);
        self::assertStringContainsString('ContentProjectActionCodes::PUBLISHING_ALREADY_PROCESSING', $rerun);
        self::assertStringContainsString('ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING', $rerun);
        self::assertStringContainsString("->update(['updated_at' => now()])", $rerun);
        self::assertStringNotContainsString("workflow_status === 'published'", $rerun);

        $guard = $this->source(ContentProjectRerunEligibilityGuard::class);
        self::assertStringContainsString('Cannot rerun while publishing is processing.', $guard);
        self::assertStringContainsString('isActivelyPublishing', $guard);
        self::assertStringContainsString('ContentProjectItemAction::Rerun', $guard);
    }

    public function test_case_c_published_rerun_keeps_wp_stamp_and_marks_unpublished_changes(): void
    {
        $workflow = $this->source(SeoProjectWorkflowRunService::class);
        $pos = strpos($workflow, 'function restorePublishedLifecycle');
        self::assertNotFalse($pos);
        $next = strpos($workflow, "\n    private function ", $pos + 1);
        $chunk = $next !== false ? substr($workflow, $pos, $next - $pos) : substr($workflow, $pos, 2500);

        self::assertStringContainsString("'publish_published_at'", $chunk);
        self::assertStringNotContainsString("'status' => \$snapshot['task_status']", $chunk);
        self::assertStringNotContainsString("'publish_queue_status' => \$snapshot['publish_queue_status']", $chunk);

        self::assertStringContainsString('markPublishedRerunDirty', $workflow);
        self::assertStringContainsString('markLocalEditPending', $workflow);
        self::assertStringContainsString('markTaskCompleted', $workflow);
        self::assertStringContainsString('isPublishedLifecycle', $workflow);
    }

    public function test_case_d_queue_metadata_alone_is_not_published(): void
    {
        self::assertFalse(ContentProjectPublishedEvidence::fromObservedAndStamp('draft', '2026-07-01 12:00:00'));
        self::assertFalse(ContentProjectPublishedEvidence::fromObservedAndStamp('pending', null));
        self::assertTrue(ContentProjectPublishedEvidence::fromObservedAndStamp('publish', null));
        self::assertFalse(ContentProjectPublishedEvidence::fromObservedAndStamp(null, '2026-07-01 12:00:00'));
        self::assertFalse(ContentProjectPublishedEvidence::fromObservedAndStamp(null, null));
        self::assertFalse(ContentProjectPublishedEvidence::fromObservedAndStamp('', null));
        self::assertTrue(ContentProjectPublishedEvidence::fromRow([
            'publish_published_at' => '2026-07-01 12:00:00',
            'queue_status' => 'waiting',
            'publishing_queued_at' => '2026-07-01 11:00:00',
        ]));
        self::assertFalse(ContentProjectPublishedEvidence::fromRow([
            'publish_published_at' => '2026-07-01 12:00:00',
            'queue_status' => 'none',
            'publishing_queued_at' => null,
        ]));
    }

    public function test_case_e_frontend_optimistic_last_activity(): void
    {
        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringContainsString('processingRows: {}', $ops);
        self::assertStringContainsString('beginRowProcessing(tid, kind)', $ops);
        self::assertStringContainsString('isRowProcessing(tid)', $ops);
        self::assertStringContainsString('cp-ops-row-processing', $ops);
        self::assertStringContainsString('cp-ops-generation-started', $ops);
        self::assertStringContainsString('startGenerationTablePoll', $ops);
        self::assertStringContainsString('doLazyRefresh(true)', $ops);
        self::assertStringNotContainsString("'retry', 'approve'", $ops);

        $list = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        self::assertStringContainsString('isRowProcessing({{ $tid }})', $list);
        self::assertStringContainsString('publishing_queue_pending_processing', $list);
        self::assertStringContainsString("generation('writing', 'running')", $list);

        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString("\$dispatch('cp-ops-row-processing'", $menu);
        self::assertGreaterThanOrEqual(2, substr_count($menu, "kind: 'generation'"));

        $page = $this->source(ViewSeoProject::class);
        self::assertStringContainsString('function shouldOptimisticRowExit', $page);
        self::assertStringContainsString('ACTION_RETRY', $page);
        self::assertStringContainsString('invalidateOpsCache()', $page);
        self::assertStringContainsString("dispatch('cp-ops-generation-started'", $page);
    }

    public function test_generate_and_step_rerun_stamp_last_activity(): void
    {
        self::assertStringContainsString(
            "->update(['updated_at' => now()])",
            $this->source(GenerateProjectItemsHandler::class),
        );
        self::assertStringContainsString(
            "->update(['updated_at' => now()])",
            $this->source(RerunProjectItemStepHandler::class),
        );
        self::assertSame(
            ContentProjectActionCodes::PUBLISHING_ALREADY_PROCESSING,
            'publishing.already_processing',
        );
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
