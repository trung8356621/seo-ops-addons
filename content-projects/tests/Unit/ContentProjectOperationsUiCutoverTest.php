<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectPublishingQueue;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class ContentProjectOperationsUiCutoverTest extends TestCase
{
    public function test_view_project_is_canonical_items_table(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('view-seo-project-operations', $src);
        self::assertStringContainsString('ContentProjectItemOperationsReadModel', $src);
        self::assertStringContainsString('InteractsWithContentProjectPublishingActions', $src);
        self::assertStringContainsString('getHeading', $src);
        self::assertStringContainsString('getSubheading', $src);
        self::assertStringContainsString('ActionGroup::make', $src);
        self::assertStringNotContainsString('extends EditSeoProject', $src);
        // Header shortcut to independent Publishing Queue hub (not nested lifecycle tab).
        self::assertStringContainsString("Action::make('publishing_queue')", $src);
        self::assertStringContainsString('getPublishingQueueUrl', $src);
    }

    public function test_operations_blade_kpi_grid_and_toolbar(): void
    {
        // Content Project ops now composes shared components (content-project-summary-cards,
        // -filter-toolbar, -bulk-selection-toolbar, -items-list, -ops-styles) instead of
        // inlining the KPI grid / table / mobile list / `.cp-ops-*` CSS directly.
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        $itemsList = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        $styles = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-ops-styles.blade.php'),
        );

        self::assertStringContainsString('content-project-ops-styles', $blade);
        self::assertStringContainsString('content-project-summary-cards', $blade);
        self::assertStringContainsString('content-project-filter-toolbar', $blade);
        self::assertStringContainsString('content-project-bulk-selection-toolbar', $blade);
        self::assertStringContainsString('content-project-items-list', $blade);
        self::assertStringContainsString('variant="content_project"', $blade);
        self::assertStringContainsString('itemActionBulkMenu', $blade);
        self::assertStringContainsString('applySummaryFilter', $blade);

        self::assertStringContainsString('content-project-status-badge', $itemsList);
        self::assertStringContainsString('content-project-item-actions-menu', $itemsList);
        self::assertStringContainsString('content-project-item-meta', $itemsList);
        self::assertStringContainsString('cp-ops-table', $itemsList);
        self::assertStringContainsString('cp-ops-mobile-list', $itemsList);
        self::assertStringContainsString('No items match filters', $itemsList);

        self::assertStringContainsString('cp-ops-kpi-grid', $styles);
        self::assertStringContainsString('cp-ops-toolbar', $styles);

        self::assertStringNotContainsString('run_item_run_at', $blade);
        self::assertStringNotContainsString('seo-run-items-wrap', $blade);
        self::assertStringNotContainsString('<h2 class="truncate text-lg', $blade);
    }

    public function test_bulk_toolbar_only_when_selected(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-bulk-selection-toolbar.blade.php'),
        );
        self::assertStringContainsString('(int) $selectedCount > 0', $blade);
        self::assertStringContainsString('Bulk selection actions', $blade);
        self::assertStringContainsString('Content', $blade);
        self::assertStringContainsString('Review', $blade);
        self::assertStringContainsString('Publishing', $blade);
        self::assertStringContainsString('Lifecycle', $blade);
        self::assertStringContainsString('archiveSelected', $blade);
        self::assertStringContainsString('content_project', $blade);
        self::assertStringContainsString('publishing_queue', $blade);
        self::assertStringContainsString('ContentProjectItemActionCatalog', $blade);
        self::assertSame(1, substr_count($blade, 'wire:click="{{ $action[\'bulk_method\'] }}"'));
        self::assertStringNotContainsString('Generate working items', $blade);
        self::assertStringNotContainsString('Regen outline', $blade);
        self::assertStringNotContainsString('Regen article', $blade);
    }

    public function test_actions_menu_groups_and_gates(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('ContentProjectItemActionsPresenter', $blade);
        self::assertStringContainsString('cp-ops-menu', $blade);
        self::assertStringContainsString('>Content</p>', $blade);
        self::assertStringContainsString('>Review</p>', $blade);
        self::assertStringContainsString('>Publishing Queue</p>', $blade);
        self::assertStringContainsString('>Lifecycle</p>', $blade);
        self::assertStringContainsString('>Other</p>', $blade);
        self::assertStringContainsString('archiveOne', $blade);
        self::assertStringContainsString('sendToPublishingQueueOne', $blade);

        $reviewOnly = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
            'is_scheduled' => false,
        ]);
        self::assertTrue($reviewOnly['approve']);
        self::assertFalse($reviewOnly['start_review']);
        self::assertFalse($reviewOnly['generate']);
        self::assertTrue($reviewOnly['create_or_rerun']);
        self::assertFalse($reviewOnly['run_generation_bulk']);
        self::assertTrue($reviewOnly['has_review']);

        $pending = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'pending'],
            'generation_status' => 'pending',
            'is_generate_pending_runnable' => true,
            'can_generate' => true,
            'can_regen' => false,
            'article_edit_url' => null,
            'is_scheduled' => false,
        ]);
        self::assertTrue($pending['create_or_rerun']);
        self::assertSame('create', $pending['create_or_rerun_label']);
        self::assertTrue($pending['run_generation_bulk']);
        self::assertFalse($pending['generate']);
        self::assertFalse($pending['approve']);
        self::assertFalse($pending['publish_now']);
        self::assertFalse($pending['schedule']);

        $notRunnable = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'pending'],
            'generation_status' => 'pending',
            'is_generate_pending_runnable' => false,
            'can_generate' => true,
            'can_regen' => false,
            'article_edit_url' => '/a/2',
            'is_scheduled' => false,
        ]);
        self::assertFalse($notRunnable['generate']);
        self::assertTrue($notRunnable['create_or_rerun']);
        self::assertSame('rerun', $notRunnable['create_or_rerun_label']);
        self::assertFalse($notRunnable['run_generation_bulk']);
    }

    public function test_status_badge_semantic_colors(): void
    {
        $gen = ContentProjectStatusBadgePresenter::generation('writing');
        self::assertSame('running', $gen['key']);
        self::assertStringContainsString('bg-info-', $gen['classes']);
        self::assertArrayHasKey('icon', $gen);

        $fail = ContentProjectStatusBadgePresenter::generation('failed');
        self::assertSame('failed', $fail['key']);
        self::assertStringContainsString('bg-danger-', $fail['classes']);

        $review = ContentProjectStatusBadgePresenter::lifecycle('review');
        self::assertSame('review', $review['key']);
        self::assertStringContainsString('bg-warning-', $review['classes']);

        $queue = ContentProjectStatusBadgePresenter::queue('waiting');
        self::assertSame('waiting', $queue['key']);

        $accent = ContentProjectStatusBadgePresenter::summaryAccent('failed');
        self::assertSame('failed', $accent['key']);
        self::assertStringContainsString('border-l-danger', $accent['ring']);
    }

    public function test_read_model_keeps_three_status_axes(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('generation_status', $src);
        self::assertStringContainsString("'lifecycle'", $src);
        self::assertStringContainsString('queue_status', $src);
        self::assertStringContainsString('applyFilters', $src);
        self::assertStringContainsString('TYPE_IMPROVE', $src);
    }

    public function test_publishing_queue_route_redirects_to_independent_hub(): void
    {
        $pages = SeoProjectResource::getPages();
        self::assertArrayHasKey('publishing-queue', $pages);

        // Nested resource route is a compat redirect (D3); canonical UI moved to the hub (D1/D2).
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueue::class))->getFileName(),
        );
        self::assertStringContainsString('redirect', $src);
        self::assertStringNotContainsString('waiting_publish,published', $src);
        self::assertStringContainsString('canManageContentProjectWorkflow', $src);

        $prop = new ReflectionProperty(ContentProjectPublishingQueue::class, 'record');
        $typeName = (string) $prop->getType();
        self::assertTrue(in_array($typeName, ['int|string', 'string|int'], true));

        self::assertTrue(class_exists(\Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub::class));
        $hubSrc = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub::class))->getFileName(),
        );
        self::assertStringContainsString("slug = 'publishing-queue'", $hubSrc);
        self::assertStringContainsString('canManageContentProjectWorkflow', $hubSrc);

        $resourceSrc = (string) file_get_contents(
            (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString('PublishingQueueHub::getUrl', $resourceSrc);
        self::assertStringNotContainsString("lifecycle' => 'waiting_publish,published'", $resourceSrc);
    }

    public function test_run_history_remains_redirect_only(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProjectRun::class))->getFileName(),
        );
        self::assertStringContainsString('redirect', strtolower($src));
        self::assertStringContainsString('getProjectWorkspaceUrl', $src);
    }

    public function test_test_run_hidden_production_ui(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString("environment('production')", $src);
        self::assertStringContainsString('allowsDevTestGenerateUi', $src);

        $viewSrc = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('allowsDevTestGenerateUi', $viewSrc);
        self::assertStringContainsString('ActionGroup::make', $viewSrc);
    }

    public function test_publishing_actions_reuse_command_bus_trait(): void
    {
        $trait = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithContentProjectPublishingActions.php',
        );
        self::assertStringContainsString('ContentProjectCommandBus', $trait);
        self::assertStringContainsString('PublishProjectItemsNowCommand', $trait);
        self::assertStringContainsString('AutoScheduleProjectItemsCommand', $trait);
        self::assertStringNotContainsString('ContentPublisher', $trait);
    }

    public function test_reusable_ui_components_exist(): void
    {
        $base = LegacyAddonPath::resolve('resources/views/components');
        foreach ([
            'content-project-summary-card.blade.php',
            'content-project-status-badge.blade.php',
            'content-project-filter-toolbar.blade.php',
            'content-project-item-actions-menu.blade.php',
            'content-project-item-meta.blade.php',
            'content-project-bulk-selection-toolbar.blade.php',
        ] as $file) {
            self::assertFileExists($base.'/'.$file);
        }
    }
}
