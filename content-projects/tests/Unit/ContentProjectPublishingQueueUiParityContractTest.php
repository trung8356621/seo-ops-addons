<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectPublishingQueueUiParityContractTest extends TestCase
{
    public function test_shared_ops_components_exist(): void
    {
        $base = LegacyAddonPath::resolve('resources/views/components');
        foreach ([
            'content-project-ops-styles.blade.php',
            'content-project-summary-cards.blade.php',
            'content-project-items-list.blade.php',
            'content-project-filter-toolbar.blade.php',
            'content-project-bulk-selection-toolbar.blade.php',
            'publishing-queue-item-actions-menu.blade.php',
        ] as $file) {
            self::assertFileExists($base.'/'.$file, $file);
        }

        self::assertTrue(class_exists(PublishingQueueItemActionsPresenter::class));
    }

    public function test_hub_and_ops_reuse_shared_components(): void
    {
        $hub = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/publishing-queue-hub.blade.php'),
        );
        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );

        foreach ([
            'content-project-ops-styles',
            'content-project-summary-cards',
            'content-project-items-list',
            'content-project-filter-toolbar',
            'content-project-bulk-selection-toolbar',
        ] as $component) {
            self::assertStringContainsString($component, $hub, 'hub missing '.$component);
            self::assertStringContainsString($component, $ops, 'ops missing '.$component);
        }

        self::assertStringNotContainsString('pq-hub-kpi-grid', $hub);
        self::assertStringContainsString('variant="publishing_queue"', $hub);
        self::assertStringContainsString(':show-checkbox="true"', $hub);
        self::assertStringNotContainsString('pq-hub-project', $hub);
        self::assertStringNotContainsString('selectableProjects', $hub);
        self::assertStringContainsString('variant="content_project"', $ops);

        $hubClass = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Filament/Pages/PublishingQueueHub.php',
        );
        foreach ([
            'bulkSchedule(?string $at = null)',
            'bulkScheduleInMinutes(int $minutes)',
            'bulkScheduleTomorrowMorning()',
            'bulkUnschedule()',
            'bulkPublishNow()',
            'bulkRetryPublish()',
            'bulkCancelPublish()',
        ] as $method) {
            self::assertStringContainsString($method, $hubClass);
        }
        self::assertStringContainsString('withProjectFromItems($this->selectedItemIds()', $hubClass);
    }

    public function test_shared_thumbnail_is_large_enough_for_ops_lists(): void
    {
        $thumb = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-thumbnail.blade.php'),
        );
        $styles = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-ops-styles.blade.php'),
        );

        self::assertStringContainsString("'size' => 'w-12 h-12'", $thumb);
        self::assertStringContainsString("['w-12 shrink-0']", $thumb);
        self::assertStringContainsString('.cp-ops-col-thumb { width: 4rem; min-width: 4rem; }', $styles);
    }

    public function test_publishing_queue_read_model_exposes_thumbnail_fields(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueReadModel::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('thumbnail_url', $src);
        self::assertStringContainsString('primary_label', $src);
        self::assertStringContainsString('publish_badge', $src);
        self::assertStringContainsString('wp_featured_image_url', $src);
    }

    public function test_pq_presenter_gates_by_publish_state(): void
    {
        $unscheduled = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'unscheduled',
            'article_edit_url' => '/a/1',
        ]);
        self::assertTrue($unscheduled['schedule']);
        self::assertTrue($unscheduled['publish_now']);
        self::assertTrue($unscheduled['return_to_content_project']);
        self::assertFalse($unscheduled['remove_from_queue']);
        self::assertFalse($unscheduled['retry_publish']);
        self::assertFalse($unscheduled['publish_now'] && $unscheduled['retry_now']);

        $failed = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'failed',
            'article_edit_url' => null,
        ]);
        self::assertTrue($failed['retry_publish']);
        self::assertTrue($failed['return_to_content_project']);
        self::assertFalse($failed['schedule']);
        self::assertFalse($failed['view_on_wordpress'] ?? false);
        self::assertFalse($failed['show_recover_banner']);

        $published = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'published',
            'article_edit_url' => '/a/1',
            'wp_permalink' => 'https://example.com/hello/',
        ]);
        self::assertTrue($published['view_on_wordpress']);
        self::assertTrue($published['return_to_content_project']);
        self::assertFalse($published['schedule']);
    }
}
