<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectStaleArticleLinkService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * Missing create-article link → confirm modal → clear + full rerun.
 */
final class ContentProjectMissingArticleRecreateTest extends TestCase
{
    public function test_presenter_flags_confirm_when_stale_missing_article(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => true,
            'is_genuinely_running' => false,
            'stale_missing_article' => true,
            'article_edit_url' => null,
            'available_actions' => ['rerun'],
        ]);

        self::assertTrue($actions['create_or_rerun']);
        self::assertTrue($actions['confirm_recreate_missing_article']);
        self::assertSame('rerun', $actions['create_or_rerun_label']);
    }

    public function test_presenter_skips_confirm_when_article_present(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => true,
            'is_genuinely_running' => false,
            'stale_missing_article' => false,
            'article_edit_url' => '/seo/articles/1/edit',
            'available_actions' => ['rerun'],
        ]);

        self::assertTrue($actions['create_or_rerun']);
        self::assertFalse($actions['confirm_recreate_missing_article']);
    }

    public function test_service_and_ui_wiring_exist(): void
    {
        $service = (string) file_get_contents((new ReflectionClass(ContentProjectStaleArticleLinkService::class))->getFileName());
        self::assertStringContainsString('function isStaleMissingCreateArticle', $service);
        self::assertStringContainsString('function clearForFreshCreate', $service);
        self::assertStringContainsString('isNewArticleType', $service);

        $createService = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService::class))->getFileName(),
        );
        self::assertStringContainsString('ensureProjectTaskDraftArticle', $createService);

        $runner = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner::class))->getFileName(),
        );
        self::assertStringContainsString(
            'use Omnichannel\\Addons\\Content\\Services\\ArticleWritingExecutionService;',
            $runner,
        );
        self::assertStringContainsString('attachToProjectTaskIfNeeded', $runner);

        $origin = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver::class))->getFileName(),
        );
        self::assertStringContainsString('existingStillAlive', $origin);

        $readModel = (string) file_get_contents((new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName());
        self::assertStringContainsString("'stale_missing_article'", $readModel);
        self::assertStringContainsString('isNewArticleType', $readModel);

        $page = (string) file_get_contents((new ReflectionClass(ViewSeoProject::class))->getFileName());
        self::assertStringContainsString('function confirmRecreateMissingArticle', $page);
        self::assertStringContainsString('ContentProjectStaleArticleLinkService', $page);
        self::assertStringContainsString('openMissingArticleConfirm', $page);

        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('open-missing-article-confirm', $menu);
        self::assertStringContainsString('confirm_recreate_missing_article', $menu);

        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringContainsString('openMissingArticleConfirmModal', $ops);
        self::assertStringContainsString('confirmMissingArticleRecreate', $ops);
        self::assertStringContainsString('missing_article_confirm_create', $ops);

        $vi = (string) file_get_contents(LegacyAddonPath::resolve('lang/vi/filament.php'));
        $en = (string) file_get_contents(LegacyAddonPath::resolve('lang/en/filament.php'));
        self::assertStringContainsString("'missing_article_confirm_title'", $vi);
        self::assertStringContainsString("'missing_article_confirm_create'", $vi);
        self::assertStringContainsString("'missing_article_confirm_title'", $en);
        self::assertStringContainsString("'missing_article_confirm_create'", $en);

        // Keep ProjectRoot referenced so path helpers stay consistent with sibling tests.
        self::assertNotSame('', ProjectRoot::path());
    }
}
