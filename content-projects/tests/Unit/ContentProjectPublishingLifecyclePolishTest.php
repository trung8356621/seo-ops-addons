<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectPublishingQueue;
use Omnichannel\Addons\ContentProjects\Filament\Widgets\ContentProjectQueueHealthWidget;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDashboardStatsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTimelineService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContentProjectPublishingLifecyclePolishTest extends TestCase
{
    public function test_lifecycle_phase_rejects_archived_to_generating(): void
    {
        $archived = ContentProjectLifecyclePhase::Archived;
        self::assertFalse($archived->canTransitionTo(ContentProjectLifecyclePhase::Generating));
        self::assertSame([], $archived->allowedNext());

        $approved = ContentProjectLifecyclePhase::Approved;
        self::assertTrue($approved->canTransitionTo(ContentProjectLifecyclePhase::WaitingPublish));
    }

    public function test_publish_queue_status_active_values(): void
    {
        self::assertContains(ContentProjectPublishQueueStatus::Waiting->value, ContentProjectPublishQueueStatus::activeValues());
        self::assertTrue(ContentProjectPublishQueueStatus::Processing->isActiveQueue());
        self::assertTrue(ContentProjectPublishQueueStatus::Published->isTerminal());
    }

    public function test_core_services_exist(): void
    {
        foreach ([
            ContentProjectDashboardStatsService::class,
            ContentProjectPublishingQueueService::class,
            ContentProjectAutoScheduleService::class,
            ContentProjectQueueHealthService::class,
            ContentProjectTimelineService::class,
            ContentProjectPublishingQueueRunner::class,
            ContentProjectLifecycle::class,
        ] as $class) {
            self::assertTrue(class_exists($class), $class);
        }
    }

    public function test_publishing_queue_page_and_route_registered(): void
    {
        $pages = SeoProjectResource::getPages();
        self::assertArrayHasKey('publishing-queue', $pages);
        self::assertTrue(class_exists(ContentProjectPublishingQueue::class));
        self::assertTrue(method_exists(SeoProjectResource::class, 'getPublishingQueueUrl'));

        // Nested resource route is now a compat redirect to the independent hub (D3).
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueue::class))->getFileName(),
        );
        self::assertStringContainsString('redirect', $src);
        self::assertStringContainsString('canManageContentProjectWorkflow', $src);

        self::assertTrue(class_exists(\Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub::class));
        $hubSrc = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub::class))->getFileName(),
        );
        self::assertStringContainsString('canManageContentProjectWorkflow', $hubSrc);
        $hubBladePath = LegacyAddonPath::resolve('resources/views/filament/pages/publishing-queue-hub.blade.php');
        self::assertFileExists($hubBladePath);
        $hubBlade = (string) file_get_contents($hubBladePath);
        self::assertStringContainsString('content-project-bulk-selection-toolbar', $hubBlade);
        self::assertStringContainsString('variant="publishing_queue"', $hubBlade);
        // bulkPublishNow is wired inside the shared bulk-selection-toolbar's publishing_queue variant.
        $bulkToolbarPath = LegacyAddonPath::resolve('resources/views/components/content-project-bulk-selection-toolbar.blade.php');
        self::assertFileExists($bulkToolbarPath);
        self::assertStringContainsString('bulkPublishNow', (string) file_get_contents($bulkToolbarPath));
    }

    public function test_archive_service_exposes_gate_and_confirm_flag(): void
    {
        $ref = new ReflectionClass(ArchiveContentProjectService::class);
        self::assertTrue($ref->hasMethod('archiveGate'));
        self::assertTrue($ref->hasMethod('assertCanArchive'));

        $archive = $ref->getMethod('archive');
        $params = $archive->getParameters();
        self::assertGreaterThanOrEqual(5, count($params));
        self::assertSame('confirmWaitingPublish', $params[3]->getName());

        $source = $this->readMethodSource($archive);
        self::assertStringContainsString('assertCanArchive', $source);
        self::assertStringContainsString('cancelHiddenStaleRuns', $source);

        $gate = $ref->getMethod('archiveGate');
        $gateSource = $this->readMethodSource($gate);
        self::assertStringContainsString('notConsolidated', $gateSource);
        self::assertStringContainsString('hiddenStaleRunsQuery', $gateSource);
        self::assertStringContainsString('requires_hidden_stale_runs_confirm', $gateSource);

        $assert = $ref->getMethod('assertCanArchive');
        self::assertGreaterThanOrEqual(3, count($assert->getParameters()));
        self::assertSame('confirmHiddenStaleRuns', $assert->getParameters()[2]->getName());
        self::assertSame('confirmHiddenStaleRuns', $params[4]->getName());
    }

    public function test_queue_service_is_batch_oriented(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueueService::class))->getFileName(),
        );
        self::assertStringContainsString('batchUpdate', $source);
        self::assertStringContainsString('function schedule', $source);
        self::assertStringContainsString('function unschedule', $source);
        self::assertStringContainsString('function publishNow', $source);
        self::assertStringContainsString('function retry', $source);
        self::assertStringContainsString('function skip', $source);
        self::assertStringContainsString('function cancelPublish', $source);
        self::assertStringContainsString('function moveTime', $source);
        self::assertStringContainsString('function clearSchedule', $source);
    }

    public function test_auto_schedule_modes_exist(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAutoScheduleService::class))->getFileName(),
        );
        self::assertStringContainsString("'interval'", $source);
        self::assertStringContainsString("'per_day'", $source);
        self::assertStringContainsString("'random_windows'", $source);
    }

    public function test_queue_health_widget_exists(): void
    {
        self::assertTrue(class_exists(ContentProjectQueueHealthWidget::class));
        $listSource = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectQueueHealthWidget', $listSource);
    }

    public function test_timeline_has_no_execution_or_prompt_history(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectTimelineService::class))->getFileName(),
        );
        self::assertStringContainsString('project_created', $source);
        self::assertStringContainsString('ai_finished', $source);
        self::assertStringContainsString('review_completed', $source);
        self::assertStringContainsString('scheduled', $source);
        self::assertStringContainsString('published', $source);
        self::assertStringContainsString('archived', $source);
        self::assertStringNotContainsString('prompt_result', $source);
        self::assertStringNotContainsString('SeoProjectRunItem', $source);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
