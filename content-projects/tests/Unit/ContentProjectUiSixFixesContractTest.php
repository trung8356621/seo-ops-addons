<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationDecision;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests for the six UI/workflow fixes batch (nav nest, picker 28,
 * article links target=_blank, WP permalink action, media library 50, generation skip).
 */
final class ContentProjectUiSixFixesContractTest extends TestCase
{
    public function test_publishing_queue_nests_under_content_projects_nav(): void
    {
        $hub = (string) file_get_contents((new ReflectionClass(PublishingQueueHub::class))->getFileName());
        self::assertStringContainsString("slug = 'publishing-queue'", $hub);
        self::assertStringContainsString('shouldRegisterNavigation = false', $hub);
        self::assertStringContainsString('getNavigationParentItem', $hub);
        self::assertStringContainsString('SeoProjectResource::getNavigationLabel()', $hub);
        self::assertStringContainsString('canManageContentProjectWorkflow', $hub);

        $resource = (string) file_get_contents((new ReflectionClass(SeoProjectResource::class))->getFileName());
        self::assertStringContainsString('getNavigationItems', $resource);
        self::assertStringContainsString('parentItem($parentLabel)', $resource);
        self::assertStringContainsString('PublishingQueueHub::getUrl()', $resource);
        self::assertStringContainsString('SeoPanelRoutes::isPublishingQueueNav()', $resource);
        self::assertStringContainsString('SeoPanelRoutes::isProjectsModule()', $resource);
        self::assertStringContainsString('SeoPanelRoutes::isProjectPlannerNav()', $resource);
        self::assertStringContainsString('SeoPanelRoutes::isProjectsListNav()', $resource);
    }

    public function test_image_picker_uses_per_page_28_and_tab_reset(): void
    {
        $controller = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Http/Controllers/ArticleMediaPickerController.php',
        );
        self::assertMatchesRegularExpression('/^\s*28,\s*$/m', $controller);
        self::assertDoesNotMatchRegularExpression('/^\s*24,\s*$/m', $controller);

        $workspace = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Http/Controllers/WorkspaceMediaPickerController.php',
        );
        self::assertMatchesRegularExpression('/^\s*28,\s*$/m', $workspace);
        self::assertDoesNotMatchRegularExpression('/^\s*24,\s*$/m', $workspace);

        $edit = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );
        self::assertStringContainsString('$perPage = 28', $edit);
        self::assertMatchesRegularExpression('/^\s*28,\s*$/m', $edit);

        $picker = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/editor/host/SharedMediaPicker.jsx',
        );
        self::assertStringContainsString('// Tab change always resets to page 1', $picker);
        self::assertStringContainsString('const page = 1;', $picker);
        self::assertStringContainsString('loadTab(tab, 1, search', $picker);
    }

    public function test_article_edit_links_are_real_anchors_with_blank_target(): void
    {
        $meta = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-meta.blade.php'),
        );
        self::assertStringContainsString('target="_blank"', $meta);
        self::assertStringContainsString('rel="noopener noreferrer"', $meta);
        self::assertStringNotContainsString('@click.prevent', $meta);
        self::assertStringContainsString('claimNeedsReviewArticle', $meta);

        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('target="_blank"', $actions);
        self::assertStringContainsString('rel="noopener noreferrer"', $actions);
        self::assertStringNotContainsString('@click.prevent', $actions);

        $thumb = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-thumbnail.blade.php'),
        );
        self::assertStringContainsString('target="_blank"', $thumb);
        self::assertStringNotContainsString('@click.prevent', $thumb);

        $pqMenu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/publishing-queue-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('target="_blank"', $pqMenu);
        self::assertStringContainsString('rel="noopener noreferrer"', $pqMenu);

        $media = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/media-library.blade.php'),
        );
        self::assertStringContainsString('seo-media-library-article-link', $media);
        self::assertMatchesRegularExpression(
            '/target="_blank"[\s\S]{0,120}seo-media-library-article-link|seo-media-library-article-link[\s\S]{0,120}target="_blank"/',
            $media,
        );

        $hub = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/publishing-queue-hub.blade.php'),
        );
        self::assertStringNotContainsString('window.location.href = url', $hub);

        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringContainsString('claimNeedsReviewArticle', $ops);
        self::assertStringNotContainsString('window.location.href = target', $ops);
        // Double quotes inside x-data="{...}" break the HTML attribute (raw JS leak).
        self::assertStringNotContainsString('target="_blank"', $ops);
    }

    public function test_wordpress_view_action_requires_published_permalink(): void
    {
        $published = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'published',
            'article_edit_url' => '/seo/articles/1/edit',
            'wp_permalink' => 'https://example.com/post-1/',
        ]);
        self::assertTrue($published['view_on_wordpress']);
        self::assertTrue($published['open_article']);

        $publishedNoUrl = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'published',
            'wp_permalink' => '',
        ]);
        self::assertFalse($publishedNoUrl['view_on_wordpress']);

        $failed = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'failed',
            'wp_permalink' => 'https://example.com/post-1/',
        ]);
        self::assertFalse($failed['view_on_wordpress']);

        $unscheduled = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'unscheduled',
            'wp_permalink' => 'https://example.com/post-1/',
        ]);
        self::assertFalse($unscheduled['view_on_wordpress']);

        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/publishing-queue-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('view_on_wordpress', $menu);
        self::assertStringContainsString('item_action_view_on_wordpress', $menu);

        $publisher = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Extension/WordPressPublisher.php',
        );
        self::assertStringContainsString('persistPermalink', $publisher);
        self::assertStringContainsString('permalink:', $publisher);

        $readModel = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectPublishingQueueReadModel.php',
        );
        self::assertStringContainsString("'wp_permalink'", $readModel);
        self::assertStringContainsString("'wp_permalink'", $readModel);
    }

    public function test_media_library_uses_per_page_50(): void
    {
        $service = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Services/SeoMediaLibraryService.php',
        );
        self::assertStringContainsString('int $perPage = 50', $service);

        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Filament/Pages/MediaLibrary.php',
        );
        self::assertStringContainsString("'filterSearch'", $page);
        self::assertStringContainsString("\$this->page = 1", $page);
        self::assertStringContainsString("#[Url]", $page);
        self::assertStringContainsString('activeTab', $page);
    }

    public function test_generation_blocked_skipped_by_classifier_and_rerun_guard(): void
    {
        $classifier = new ContentProjectItemGenerationClassifier(new ContentProjectLifecycle);
        $blocked = $classifier->classifySnapshot([
            'task_id' => 9,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_FAILED,
            'article_id' => 0,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Failed->value,
            'generation_blocked' => true,
            'generation_blocked_at' => '2026-08-04T00:00:00+00:00',
        ]);
        self::assertSame(ContentProjectItemGenerationDecision::ACTION_SKIP, $blocked->action);
        self::assertSame('generation_blocked', $blocked->reason);

        $runnable = $classifier->classifySnapshot([
            'task_id' => 10,
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_FAILED,
            'article_id' => 0,
            'lifecycle_phase' => ContentProjectLifecyclePhase::Failed->value,
            'generation_blocked' => false,
        ]);
        self::assertTrue($runnable->shouldRun());

        $model = (string) file_get_contents((new ReflectionClass(SeoProjectTask::class))->getFileName());
        self::assertStringContainsString('scopeEligibleForGeneration', $model);
        self::assertStringContainsString('isGenerationBlocked', $model);
        self::assertStringContainsString('generation_blocked_at', $model);

        $migration = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_08_04_160000_add_generation_blocked_to_seo_project_tasks.php');
        self::assertFileExists($migration);
        $migSrc = (string) file_get_contents($migration);
        self::assertStringContainsString('generation_blocked_at', $migSrc);
        self::assertStringContainsString('generation_blocked_by', $migSrc);
        self::assertStringContainsString('generation_block_reason', $migSrc);

        $guard = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Support/ContentProjectRerunEligibilityGuard.php',
        );
        self::assertStringContainsString('Item Ã„â€˜ÃƒÂ£ Ã„â€˜Ã†Â°Ã¡Â»Â£c Ã„â€˜ÃƒÂ¡nh dÃ¡ÂºÂ¥u bÃ¡Â»Â qua tÃ¡ÂºÂ¡o bÃƒÂ i.', $guard);

        $actionGuard = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Support/ContentProject/ContentProjectItemActionGuard.php',
        );
        self::assertStringContainsString('Item Ã„â€˜ÃƒÂ£ Ã„â€˜Ã†Â°Ã¡Â»Â£c Ã„â€˜ÃƒÂ¡nh dÃ¡ÂºÂ¥u bÃ¡Â»Â qua tÃ¡ÂºÂ¡o bÃƒÂ i.', $actionGuard);

        $classifierSrc = (string) file_get_contents(
            (new ReflectionClass(ContentProjectItemGenerationClassifier::class))->getFileName(),
        );
        self::assertStringContainsString('eligibleForGeneration()', $classifierSrc);

        $registrar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('BlockProjectItemGenerationCommand::class', $registrar);
        self::assertStringContainsString('UnblockProjectItemGenerationCommand::class', $registrar);

        $presenter = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => true,
            'generation_blocked' => false,
            'message' => 'Article Ã„â€˜ÃƒÂ£ thuÃ¡Â»â„¢c task khÃƒÂ¡c.',
            'article_edit_url' => '/seo/articles/1/edit',
        ]);
        self::assertTrue($presenter['skip_generation']);
        self::assertFalse($presenter['allow_generation']);

        $skipped = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => false,
            'generation_blocked' => true,
            'message' => 'Article Ã„â€˜ÃƒÂ£ thuÃ¡Â»â„¢c task khÃƒÂ¡c.',
            'article_edit_url' => '/seo/articles/1/edit',
        ]);
        self::assertFalse($skipped['skip_generation']);
        self::assertTrue($skipped['allow_generation']);
        self::assertFalse($skipped['run_again']);
        self::assertFalse($skipped['generate']);
        self::assertFalse($skipped['create_or_rerun']);

        $view = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        self::assertStringContainsString('skipGenerationOne', $view);
        self::assertStringContainsString('skipGenerationSelected', $view);
        self::assertStringContainsString('allowGenerationOne', $view);
        self::assertStringContainsString('BlockProjectItemGenerationCommand', $view);
        self::assertStringContainsString('UnblockProjectItemGenerationCommand', $view);

        $blockHandler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/BlockProjectItemGenerationHandler.php',
        );
        self::assertStringContainsString('metadata:', $blockHandler);
        self::assertStringContainsString('$itemIds,', $blockHandler);
        self::assertStringNotContainsString("'item_ids' => \$itemIds]", $blockHandler);

        $unblockHandler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/UnblockProjectItemGenerationHandler.php',
        );
        self::assertStringContainsString('metadata:', $unblockHandler);
        self::assertStringContainsString('$itemIds,', $unblockHandler);
        self::assertStringNotContainsString("'item_ids' => \$itemIds]", $unblockHandler);

        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('skipGenerationOne', $menu);
        self::assertStringContainsString('allowGenerationOne', $menu);
        self::assertStringContainsString('item_action_skip_generation_confirm', $menu);
    }
}
