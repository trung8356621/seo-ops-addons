<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Console\DiagnoseContentProjectTaskHistoryCommand;
use Omnichannel\Addons\ContentProjects\Console\RecoverLegacyContentProjectTaskCommand;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SelectExistingArticleForProjectItemHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCreateGenerationGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticlePickerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectLegacyTaskClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectLegacyTaskRecoveryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectLegacyTaskWpSearchService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectSiteLinkRepairService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectLegacyTaskForensicContractTest extends TestCase
{
    public function test_prompt_cannot_run_before_local_article_exists_for_create(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentProjectCreateGenerationGuard::CODE_MISSING_LOCAL_ARTICLE);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 11,
            'project_site_id' => 2,
            'task_article_id' => 0,
            'article_id' => 0,
            'article_site_id' => 0,
            'context_article_id' => 0,
            'task_keyword' => 'Vải Xion',
            'prompt_focus_keyword' => 'Vải Xion',
        ]);
    }

    public function test_prompt_focus_keyword_must_match_task_keyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentProjectCreateGenerationGuard::CODE_FOCUS_KEYWORD_MISMATCH);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 11,
            'project_site_id' => 2,
            'task_article_id' => 100,
            'article_id' => 100,
            'article_site_id' => 2,
            'context_article_id' => 100,
            'task_keyword' => 'Vải Xion',
            'prompt_focus_keyword' => '',
        ]);
    }

    public function test_article_site_must_equal_project_site_before_ai(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentProjectCreateGenerationGuard::CODE_ARTICLE_WRONG_SITE);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 11,
            'project_site_id' => 2,
            'task_article_id' => 9672,
            'article_id' => 9672,
            'article_site_id' => 4,
            'context_article_id' => 9672,
            'task_keyword' => 'Vải Xion',
            'prompt_focus_keyword' => 'Vải Xion',
        ]);
    }

    public function test_stale_numeric_article_id_alone_is_not_provenance(): void
    {
        $class = ContentProjectLegacyTaskClassifier::classifyCurrentArticle([
            'task_article_id' => 9672,
            'current_article_id' => 9672,
            'current_article_site_id' => 4,
            'project_site_id' => 2,
            'independent_provenance' => false,
            'semantic_match' => false,
            'article_row_exists' => true,
        ]);
        self::assertSame(ContentProjectLegacyTaskClassifier::COLLISION_STALE_ID, $class);
    }

    public function test_cross_site_valid_article_is_rejected_as_ownership(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentProjectCreateGenerationGuard::CODE_ARTICLE_WRONG_SITE);

        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 11,
            'project_site_id' => 2,
            'task_article_id' => 9673,
            'article_id' => 9673,
            'article_site_id' => 4,
            'context_article_id' => 9673,
            'task_keyword' => 'Vải thun',
            'prompt_focus_keyword' => 'Vải thun',
        ]);
    }

    public function test_missing_historical_article_with_reused_id_is_corruption(): void
    {
        $class = ContentProjectLegacyTaskClassifier::classifyCurrentArticle([
            'task_article_id' => 9675,
            'current_article_id' => 9675,
            'current_article_site_id' => 4,
            'project_site_id' => 2,
            'independent_provenance' => false,
            'semantic_match' => false,
            'article_row_exists' => true,
        ]);
        self::assertSame(ContentProjectLegacyTaskClassifier::COLLISION_STALE_ID, $class);
        self::assertSame(
            ContentProjectLegacyTaskClassifier::PROMPT_KEYWORD_MISSING,
            ContentProjectLegacyTaskClassifier::classifyPromptKeyword('vải da PVC', ''),
        );
        self::assertSame(
            ContentProjectLegacyTaskClassifier::ORDER_PROMPT_BEFORE_ARTICLE,
            ContentProjectLegacyTaskClassifier::classifyCreationOrder(
                '2026-07-27 08:00:00',
                '2026-07-28 10:00:00',
                null,
            ),
        );
    }

    public function test_legacy_corrupted_title_is_not_automatic_identity_for_create(): void
    {
        self::assertFalse(ContentProjectLegacyTaskClassifier::exactTitleFallbackAllowed(
            SeoProjectTask::TYPE_CREATE,
            ContentProjectLegacyTaskClassifier::PROMPT_KEYWORD_MISSING,
        ));

        $reconciler = $this->source(ContentProjectExistingArticleReconciler::class);
        self::assertStringContainsString('CREATE titles were often AI-generated', $reconciler);
        $createService = $this->source(CreateArticlesFromTaskService::class);
        self::assertStringContainsString('ContentProjectCreateGenerationGuard::assertBeforeAi', $createService);
    }

    public function test_wp_recovery_only_attaches_unambiguous_strong_candidate(): void
    {
        $search = new ContentProjectLegacyTaskWpSearchService;
        $none = $search->pickStrongUnambiguous([], 'Vải Xion', null);
        self::assertSame('none', $none['status']);

        $ambiguous = $search->pickStrongUnambiguous([
            ['ok' => true, 'wp_post_id' => 11, 'title' => 'A', 'evidence' => 'observe'],
            ['ok' => true, 'wp_post_id' => 12, 'title' => 'B', 'evidence' => 'observe'],
        ], 'Vải Xion', null);
        self::assertSame('ambiguous', $ambiguous['status']);
        self::assertNull($ambiguous['candidate']);

        $exact = $search->pickStrongUnambiguous([
            ['ok' => true, 'wp_post_id' => 99, 'title' => 'Vải Xion', 'evidence' => 'slug', 'slug' => 'vai-xion'],
        ], 'Vải Xion', 'Vải Xion');
        self::assertSame('exact', $exact['status']);
        self::assertSame(99, (int) $exact['candidate']['wp_post_id']);

        $weakSearchOnly = $search->pickStrongUnambiguous([
            ['ok' => true, 'wp_post_id' => 7, 'title' => 'Balo kéo sinh viên', 'evidence' => 'wp_v2_search'],
        ], 'Vải Xion', null);
        self::assertSame('none', $weakSearchOnly['status']);
    }

    public function test_recovery_never_mutates_or_deletes_foreign_article(): void
    {
        $src = $this->source(ContentProjectLegacyTaskRecoveryService::class);
        self::assertStringContainsString('Never mutates foreign SeoArticle.site_id', $src);
        self::assertStringContainsString('Never deletes the foreign article', $src);
        self::assertStringNotContainsString('$article->site_id =', $src);
        self::assertStringNotContainsString('SeoArticle::query()->whereKey($invalidId)->delete', $src);
        self::assertStringContainsString("\$task->article_id = null", $src);

        $diagnose = new DiagnoseContentProjectTaskHistoryCommand;
        self::assertSame('seo:content-project:diagnose-task-history', $diagnose->getName());
        $recover = new RecoverLegacyContentProjectTaskCommand;
        self::assertSame('seo:content-project:recover-legacy-task', $recover->getName());
        self::assertTrue($recover->getDefinition()->hasOption('dry-run'));
        self::assertTrue($recover->getDefinition()->hasOption('detach'));
    }

    public function test_legacy_detach_never_auto_reconciles_or_creates(): void
    {
        $recovery = $this->source(ContentProjectLegacyTaskRecoveryService::class);
        self::assertStringContainsString("\$task->article_id = null", $recovery);
        self::assertStringContainsString('auto_reconcile => NO', $recovery);
        self::assertStringContainsString('create_article => NO', $recovery);
        self::assertStringContainsString('ContentProjectManualArticleResolution::mark', $recovery);
        self::assertStringNotContainsString('reconcileTask', $recovery);
        self::assertStringNotContainsString('reconcileProjectMissingLinks', $recovery);
        self::assertStringNotContainsString('createDraftArticle', $recovery);

        $reconciler = $this->source(ContentProjectExistingArticleReconciler::class);
        self::assertStringContainsString('create_unlinked_requires_manual_resolution', $reconciler);
        self::assertStringContainsString('ContentProjectManualArticleResolution::requiresManualResolution', $reconciler);

        $repair = $this->source(ContentProjectSiteLinkRepairService::class);
        self::assertStringNotContainsString('reconcileTask($locked', $repair);
        self::assertStringContainsString('auto_reconcile=NO', $repair);

        $readModel = $this->source(ContentProjectItemOperationsReadModel::class);
        self::assertStringContainsString('(int) $capability->existingArticleId === $taskArticleId', $readModel);
        self::assertStringContainsString("'article_empty_label' => \$articleEmptyLabel", $readModel);

        $sync = $this->source(SeoProjectTaskSyncService::class);
        $editable = substr($sync, (int) strpos($sync, 'EDITABLE_FIELDS'), 450);
        self::assertStringNotContainsString("'article_id'", $editable);
    }

    public function test_unlinked_create_ui_offers_manual_link_and_create_new(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_badge' => ['key' => 'pending'],
            'can_generate' => true,
            'is_generate_pending_runnable' => true,
            'type' => SeoProjectTask::TYPE_CREATE,
            'generation_recovery_action' => ContentProjectGenerationRecoveryDecision::ACTION_GENERATE,
            'article_edit_url' => null,
            'is_genuinely_running' => false,
        ]);
        self::assertTrue($actions['select_existing_article']);
        self::assertTrue($actions['create_or_rerun']);
        self::assertSame('create', $actions['create_or_rerun_label']);

        $menu = LegacyAddonPath::read('resources/views/components/content-project-item-actions-menu.blade.php');
        self::assertStringContainsString('item_action_select_existing_article', $menu);
        self::assertStringContainsString('item_action_smart_create', $menu);

        $meta = LegacyAddonPath::read('resources/views/components/content-project-item-meta.blade.php');
        self::assertStringContainsString('item_article_unlinked', $meta);

        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        self::assertStringContainsString("'item_article_unlinked' => 'Chưa có bài viết'", $vi);
        self::assertStringContainsString("'item_action_select_existing_article' => 'Liên kết bài có sẵn'", $vi);
        self::assertStringContainsString("'item_action_smart_create' => 'Tạo bài mới'", $vi);
    }

    public function test_manual_picker_and_attach_are_site_scoped_including_create(): void
    {
        $picker = $this->source(ContentProjectExistingArticlePickerService::class);
        self::assertStringContainsString("->where('site_id', \$siteId)", $picker);

        $handler = $this->source(SelectExistingArticleForProjectItemHandler::class);
        self::assertStringContainsString('articleBelongsToSite', $handler);
        self::assertStringContainsString('SeoProjectTask::isNewArticleType', $handler);
        self::assertStringContainsString('article_wrong_site', $handler);

        $create = $this->source(CreateArticlesFromTaskService::class);
        self::assertStringContainsString('$skipOriginReuse = true', $create);
        self::assertStringContainsString('ContentProjectCreateGenerationGuard::assertBeforeAi', $create);
    }

    public function test_happy_path_create_guard_passes(): void
    {
        ContentProjectCreateGenerationGuard::assertState([
            'type' => SeoProjectTask::TYPE_CREATE,
            'project_id' => 11,
            'project_site_id' => 2,
            'task_article_id' => 100,
            'article_id' => 100,
            'article_site_id' => 2,
            'context_article_id' => 100,
            'task_keyword' => 'Vải Xion',
            'prompt_focus_keyword' => 'Vải Xion',
        ]);
        $this->addToAssertionCount(1);
    }

    private function source(string $class): string
    {
        $ref = new ReflectionClass($class);
        $file = (string) $ref->getFileName();

        return (string) file_get_contents($file);
    }
}
