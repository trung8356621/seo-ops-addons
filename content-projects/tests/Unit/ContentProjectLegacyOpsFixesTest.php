<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\PublishProjectItemsNowHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RetryProjectItemPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectWorkspaceSaveService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression: legacy project items with past scheduled_publish_at + ops UI fixes.
 */
final class ContentProjectLegacyOpsFixesTest extends TestCase
{
    public function test_publish_now_and_retry_normalize_past_schedule_via_enqueue_explicit(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueueService::class))->getFileName(),
        );

        self::assertStringContainsString('enqueueExplicitPublish', $source);
        self::assertStringContainsString("'scheduled_publish_at' => \$now", $source);
        self::assertStringContainsString('last_publish_error', $source);
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Cancelled', $source);
        self::assertStringContainsString('asRetry', $source);

        $publishNow = $this->methodSource(
            new ReflectionMethod(ContentProjectPublishingQueueService::class, 'publishNow'),
        );
        self::assertStringContainsString('enqueueExplicitPublish', $publishNow);
        self::assertStringNotContainsString('return $this->schedule(', $publishNow);

        $retry = $this->methodSource(
            new ReflectionMethod(ContentProjectPublishingQueueService::class, 'retry'),
        );
        self::assertStringContainsString('enqueueExplicitPublish', $retry);
        self::assertStringNotContainsString("where('publish_queue_status', ContentProjectPublishQueueStatus::Failed->value)", $retry);
    }

    public function test_handlers_dispatch_queue_runner_not_direct_publisher(): void
    {
        $publishHandler = (string) file_get_contents(
            (new ReflectionClass(PublishProjectItemsNowHandler::class))->getFileName(),
        );
        $retryHandler = (string) file_get_contents(
            (new ReflectionClass(RetryProjectItemPublishingHandler::class))->getFileName(),
        );

        self::assertStringContainsString('queueRunner->dispatchDue()', $publishHandler);
        self::assertStringContainsString('queueRunner->dispatchDue()', $retryHandler);
        self::assertStringContainsString('publishNow(', $publishHandler);
        self::assertStringContainsString('retry(', $retryHandler);
        self::assertStringNotContainsString('PublisherResolver', $publishHandler);
        self::assertStringNotContainsString('PublisherResolver', $retryHandler);
        self::assertStringNotContainsString('ContentPublisher', $publishHandler);
        self::assertStringNotContainsString('ContentPublisher', $retryHandler);
    }

    public function test_filament_publishing_actions_use_command_bus_only(): void
    {
        $trait = (string) file_get_contents(
            (new ReflectionClass(InteractsWithContentProjectPublishingActions::class))->getFileName(),
        );

        self::assertStringContainsString('PublishProjectItemsNowCommand', $trait);
        self::assertStringContainsString('RetryProjectItemPublishingCommand', $trait);
        self::assertStringContainsString('ContentProjectCommandBus', $trait);
        self::assertStringNotContainsString('ContentPublisher', $trait);
        self::assertStringNotContainsString('PublisherResolver', $trait);
        self::assertStringNotContainsString('queueService->publishNow', $trait);
    }

    public function test_workspace_save_ignores_past_schedule_and_skips_wp(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectWorkspaceSaveService::class))->getFileName(),
        );

        self::assertStringContainsString("'schedule_touched' => false", $source);
        self::assertStringContainsString("'wp_api_called' => false", $source);
        self::assertStringNotContainsString('scheduled_publish_at', $source);
        self::assertStringNotContainsString('publishingQueue', $source);
        self::assertStringNotContainsString('ContentPublisher', $source);
    }

    public function test_failed_can_transition_to_waiting_for_publish_now(): void
    {
        $guard = new ContentProjectPublishTransitionGuard();
        $guard->assertCanTransition(
            ContentProjectPublishQueueStatus::Failed,
            ContentProjectPublishQueueStatus::Waiting,
        );
        $guard->assertCanTransition(
            ContentProjectPublishQueueStatus::Cancelled,
            ContentProjectPublishQueueStatus::Waiting,
        );
        $guard->assertCanTransition(
            ContentProjectPublishQueueStatus::Failed,
            ContentProjectPublishQueueStatus::Retrying,
        );
        $guard->assertCanTransition(
            ContentProjectPublishQueueStatus::Published,
            ContentProjectPublishQueueStatus::Waiting,
        );
        self::assertTrue(true);
    }

    public function test_due_tasks_include_null_queue_status(): void
    {
        $selector = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Publishing\Services\Publishing\PublishingDueItemSelector::class))->getFileName(),
        );
        self::assertStringContainsString('orWhereNull(\'publish_queue_status\')', $selector);

        $service = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService::class))->getFileName(),
        );
        self::assertStringContainsString('ProcessScheduledProjectItemPublishCommand', $service);
    }

    public function test_archive_items_command_registered_and_blocks_active_ops(): void
    {
        self::assertSame('content_project.archive_items', (new ArchiveProjectItemsCommand(1, [1]))->name());
        self::assertSame(ContentProjectActionCodes::ITEMS_ARCHIVED, 'items.archived');

        $registrar = (string) file_get_contents(
            (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName(),
        );
        self::assertStringContainsString('ArchiveProjectItemsCommand::class => ArchiveProjectItemsHandler::class', $registrar);

        $handler = (string) file_get_contents(
            (new ReflectionClass(ArchiveProjectItemsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('archiveTasks', $handler);
        self::assertStringContainsString('Cannot archive item while AI generation is active.', $handler);
        self::assertStringContainsString('Cannot archive item while publishing is processing.', $handler);
        self::assertStringContainsString("'wordpress_post_deleted' => false", $handler);
        self::assertStringNotContainsString('deletePost', $handler);
    }

    public function test_view_seo_project_exposes_archive_and_selection_api(): void
    {
        $page = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );

        self::assertStringContainsString('function archiveOne', $page);
        self::assertStringContainsString('function archiveSelected', $page);
        self::assertStringContainsString('ArchiveProjectItemsCommand', $page);
        self::assertStringContainsString('function getHasSelectionProperty', $page);
        self::assertStringContainsString('function getSelectedCountProperty', $page);
        self::assertStringContainsString('normalizeSelectedIds', $page);
        self::assertStringContainsString('clearSelection()', $page);
        self::assertTrue(class_exists(ViewSeoProject::class));
    }

    public function test_selection_methods_toggle_select_page_and_clear(): void
    {
        $page = new ContentProjectSelectionStateStub([
            ['task_id' => 11],
            ['task_id' => 12],
            ['task_id' => 13],
        ]);

        self::assertFalse($page->hasSelection());
        self::assertSame(0, $page->selectedCount());

        $page->toggleSelect(11);
        self::assertTrue($page->hasSelection());
        self::assertSame(1, $page->selectedCount());
        self::assertSame([11], $page->selectedTaskIds);

        $page->toggleSelect(11);
        self::assertFalse($page->hasSelection());
        self::assertSame(0, $page->selectedCount());

        $page->selectPage();
        self::assertSame(3, $page->selectedCount());
        self::assertSame([11, 12, 13], $page->selectedTaskIds);

        $page->clearSelection();
        self::assertFalse($page->hasSelection());
        self::assertSame([], $page->selectedTaskIds);
    }

    public function test_bulk_toolbar_shows_only_when_selected_count_positive(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-bulk-selection-toolbar.blade.php'),
        );
        self::assertStringContainsString('$showCpBulk', $blade);
        self::assertStringContainsString('(int) $selectedCount > 0', $blade);
        self::assertStringContainsString('archiveSelected', $blade);
        self::assertStringContainsString('archive_selected_confirm', $blade);
    }

    public function test_ops_table_has_no_nested_vertical_scroll(): void
    {
        // Table scroll/overflow CSS lives in the shared ops-styles component; the
        // checkbox binding lives in the shared items-list component.
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        $styles = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-ops-styles.blade.php'),
        );
        $itemsList = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );

        self::assertStringContainsString('overflow-x: auto', $styles);
        self::assertStringContainsString('overflow-y: visible', $styles);
        self::assertStringContainsString('max-height: none', $styles);
        self::assertStringNotContainsString('max-height: 70vh', $styles);
        self::assertStringContainsString('wire:model.live="selectedTaskIds"', $itemsList);
        self::assertStringNotContainsString('wire:click="toggleSelect', $blade);
        self::assertStringNotContainsString('wire:click="toggleSelect', $itemsList);
    }

    public function test_actions_menu_restores_archive_item(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('archiveOne', $blade);
        self::assertStringContainsString('>Lifecycle</p>', $blade);
        self::assertStringContainsString('archive_item', $blade);

        $flags = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'approved',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
            'is_scheduled' => false,
        ]);
        self::assertTrue($flags['archive_item']);
        self::assertTrue($flags['has_lifecycle']);

        $running = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'generating',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'running'],
            'can_generate' => false,
            'can_regen' => false,
            'article_edit_url' => null,
            'is_scheduled' => false,
        ]);
        self::assertFalse($running['archive_item']);

        $processing = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'waiting_publish',
            'queue_status' => 'processing',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
            'is_scheduled' => true,
        ]);
        self::assertFalse($processing['archive_item']);
        self::assertTrue($processing['retry_publish'] === false);
    }

    public function test_command_classes_exist_for_publish_retry_archive(): void
    {
        self::assertSame('content_project.publish_now', (new PublishProjectItemsNowCommand(1, [1]))->name());
        self::assertSame('content_project.retry_publish', (new RetryProjectItemPublishingCommand(1, [1]))->name());
        self::assertSame('content_project.archive_items', (new ArchiveProjectItemsCommand(1, [1]))->name());
    }

    private function methodSource(ReflectionMethod $method): string
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

/**
 * Mirrors ViewSeoProject selection semantics for pure PHPUnit (no Livewire boot).
 */
final class ContentProjectSelectionStateStub
{
    /** @var list<int|string> */
    public array $selectedTaskIds = [];

    /** @param list<array{task_id: int}> $rows */
    public function __construct(private array $rows) {}

    public function toggleSelect(int $taskId): void
    {
        $ids = $this->normalizeSelectedIds($this->selectedTaskIds);
        if (in_array($taskId, $ids, true)) {
            $this->selectedTaskIds = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id !== $taskId,
            ));

            return;
        }

        $ids[] = $taskId;
        $this->selectedTaskIds = $ids;
    }

    public function selectPage(): void
    {
        $ids = $this->normalizeSelectedIds($this->selectedTaskIds);
        foreach ($this->rows as $row) {
            $id = (int) ($row['task_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $this->selectedTaskIds = $ids;
    }

    public function clearSelection(): void
    {
        $this->selectedTaskIds = [];
    }

    public function hasSelection(): bool
    {
        return count($this->normalizeSelectedIds($this->selectedTaskIds)) > 0;
    }

    public function selectedCount(): int
    {
        return count($this->normalizeSelectedIds($this->selectedTaskIds));
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeSelectedIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
