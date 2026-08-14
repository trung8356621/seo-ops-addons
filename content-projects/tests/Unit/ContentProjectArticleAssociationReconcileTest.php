<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Project\CreateProjectTaskAction;
use Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AddContentProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Content Project ↔ local article association repair + generate_post_images launch wiring.
 */
final class ContentProjectArticleAssociationReconcileTest extends TestCase
{
    public function test_pick_prefers_single_candidate_and_refuses_ambiguity(): void
    {
        $reconciler = new ContentProjectExistingArticleReconciler;

        $ok = $reconciler->pickUnambiguousCandidate([
            ['article_id' => 4354, 'matched_by' => 'automation_origin.seo_project_task'],
            ['article_id' => 4354, 'matched_by' => 'run_item.article_id'],
        ]);
        self::assertSame('ok', $ok['status']);
        self::assertSame(4354, $ok['article_id']);
        self::assertSame('automation_origin.seo_project_task', $ok['matched_by']);

        $ambiguous = $reconciler->pickUnambiguousCandidate([
            ['article_id' => 4354, 'matched_by' => 'automation_origin.seo_project_task'],
            ['article_id' => 9999, 'matched_by' => 'exact.title'],
        ]);
        self::assertSame('ambiguous', $ambiguous['status']);
        self::assertNull($ambiguous['article_id']);

        $missing = $reconciler->pickUnambiguousCandidate([]);
        self::assertSame('missing', $missing['status']);
        self::assertNull($missing['article_id']);
    }

    public function test_reconciler_source_covers_create_and_stale_positive_ids(): void
    {
        $src = $this->source(ContentProjectExistingArticleReconciler::class);

        self::assertStringContainsString('function needsAssociationRepair', $src);
        self::assertStringContainsString('LocalArticleAssociationGuard::resolveLocalArticleId', $src);
        self::assertStringContainsString('automation_origin.seo_project_task', $src);
        self::assertStringContainsString('candidatesFromStaleTaskArticleAsWpPostId', $src);
        self::assertStringContainsString('Heal current/latest run-item mirror', $src);
        self::assertStringContainsString('Create item has no article association yet', $src);
        // Must NOT skip positive article_id blindly (old bug).
        self::assertStringNotContainsString("if ((int) (\$task->article_id ?? 0) > 0) {\n                continue;", $src);
        // Page-load must not be limited to rewrite/improve + null only.
        self::assertStringNotContainsString("->whereIn('type', SeoProjectTask::typesRequiringExistingArticle())", $src);
    }

    public function test_capability_does_not_fallback_to_stale_task_article_id(): void
    {
        $src = $this->source(ContentProjectGenerationCapabilityResolver::class);
        self::assertStringContainsString('$articleResult->isUsable()', $src);
        self::assertStringNotContainsString(
            '((int) ($task->article_id ?? 0) > 0 ? (int) $task->article_id : null)',
            $src,
        );
    }

    public function test_root_writers_reject_non_local_article_ids(): void
    {
        $create = $this->source(CreateProjectTaskAction::class);
        self::assertStringContainsString('LocalArticleAssociationGuard::resolveLocalArticleId', $create);
        self::assertStringContainsString('refusing non-local article_id', $create);

        $add = $this->source(AddContentProjectItemsHandler::class);
        self::assertStringContainsString('LocalArticleAssociationGuard::resolveLocalArticleId', $add);

        $bind = $this->source(SeoProjectRunItemService::class);
        self::assertStringContainsString('article_id không phải local articles.id', $bind);

        $origin = $this->source(ArticleCreateOriginResolver::class);
        self::assertStringContainsString('LocalArticleAssociationGuard::isLocalArticleId', $origin);
    }

    public function test_membership_requires_active_non_archived_project(): void
    {
        $src = $this->source(ContentProjectArticleMembership::class);
        self::assertStringContainsString("->whereNull('archived_at')", $src);
        self::assertStringContainsString('function activeTaskForArticle', $src);
        self::assertStringContainsString('Archived project association is historical', $src);
    }

    public function test_local_article_guard_rejects_non_positive(): void
    {
        self::assertNull(LocalArticleAssociationGuard::resolveLocalArticleId(0));
        self::assertNull(LocalArticleAssociationGuard::resolveLocalArticleId(-1));
        self::assertNull(LocalArticleAssociationGuard::resolveLocalArticleId(null));
        self::assertFalse(LocalArticleAssociationGuard::isLocalArticleId(0));
    }

    public function test_generate_post_images_moved_off_modal_onto_page_state(): void
    {
        $resource = $this->source(\Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::class);
        self::assertStringNotContainsString("Checkbox::make('generate_post_images')", $resource);
        self::assertStringContainsString('?\\Closure $launchSettings = null', $resource);
        self::assertStringContainsString("\$extra['generate_post_images']", $resource);

        $view = $this->source(ViewSeoProject::class);
        self::assertStringContainsString('public bool $generatePostImages = false', $view);
        self::assertStringContainsString("'generate_post_images' => \$this->generatePostImages", $view);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php',
        );
        self::assertStringContainsString('wire:model.live="generatePostImages"', $blade);
        self::assertStringContainsString('ops_generate_post_images', $blade);
    }

    public function test_rerun_command_merges_launch_settings_then_forces_orchestration(): void
    {
        $cmd = new RerunProjectItemsCommand(1, [2], 'full', [
            'generate_post_images' => true,
            'rerun' => false,
            'task_ids' => [999],
        ]);
        self::assertTrue((bool) ($cmd->settings['generate_post_images'] ?? false));

        $handler = $this->source(RerunProjectItemsHandler::class);
        self::assertStringContainsString('ContentProjectRunSettings::fromUserInput', $handler);
        self::assertStringContainsString("'rerun' => true", $handler);
        self::assertStringContainsString("'rerun_scope' => 'full'", $handler);
        self::assertStringContainsString("'use_php_engine' => true", $handler);
        self::assertStringContainsString('FORCE orchestration keys', $handler);

        $settings = ContentProjectRunSettings::fromUserInput($cmd->settings);
        self::assertTrue($settings->generatePostImages);
        $merged = array_merge($settings->toArray(), [
            'task_ids' => [2],
            'rerun' => true,
            'rerun_scope' => 'full',
            'use_php_engine' => true,
        ]);
        self::assertTrue($merged['generate_post_images']);
        self::assertSame([2], $merged['task_ids']);
        self::assertTrue($merged['rerun']);
    }

    public function test_run_settings_snapshot_is_immutable_after_creation(): void
    {
        $snapshot = ContentProjectRunSettings::snapshotForRun([
            'generate_post_images' => true,
            'task_ids' => [10],
            'use_php_engine' => true,
        ]);
        self::assertTrue($snapshot['generate_post_images']);
        self::assertSame([10], $snapshot['task_ids']);

        // Changing a later page checkbox must not mutate a prior snapshot array.
        $later = ContentProjectRunSettings::fromUserInput(['generate_post_images' => false]);
        self::assertFalse($later->generatePostImages);
        self::assertTrue($snapshot['generate_post_images']);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }
}
