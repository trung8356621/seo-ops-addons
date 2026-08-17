<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\RelationManagers\ProjectItemsRelationManager;
use Omnichannel\Addons\ContentProjects\Services\ArticlePipelineRerunService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemStepHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Batch B â€” Rerun unification behind CommandBus + RunEngine.
 */
final class ContentProjectRerunUnifyTest extends TestCase
{
    public function test_enum_is_strict_outline_or_article(): void
    {
        self::assertSame('outline', ContentProjectRerunFromStep::Outline->value);
        self::assertSame('article', ContentProjectRerunFromStep::Article->value);
        self::assertSame(ContentProjectRerunFromStep::Outline, ContentProjectRerunFromStep::tryFromMixed('regenerate_outline'));
        self::assertSame(ContentProjectRerunFromStep::Article, ContentProjectRerunFromStep::tryFromMixed('regenerate_article'));
        self::assertNull(ContentProjectRerunFromStep::tryFromMixed('image'));
        self::assertNull(ContentProjectRerunFromStep::tryFromMixed('free-form'));
    }

    public function test_full_and_step_handlers_start_engine_once_after_validation(): void
    {
        $full = $this->source(RerunProjectItemsHandler::class);
        $step = $this->source(RerunProjectItemStepHandler::class);

        self::assertStringContainsString('eligibility->validateFull', $full);
        self::assertStringContainsString('runEngine->start', $full);
        self::assertSame(1, substr_count($full, 'runEngine->start'));
        self::assertStringContainsString('hasConflictingActiveExecution', $full);

        self::assertStringContainsString('eligibility->validateStep', $step);
        self::assertStringContainsString('runEngine->start', $step);
        self::assertSame(1, substr_count($step, 'runEngine->start'));
        self::assertStringContainsString('rerun_from_step', $step);
        self::assertStringContainsString('rerun_sync', $step);
    }

    public function test_validation_runs_before_start_run(): void
    {
        foreach ([RerunProjectItemsHandler::class, RerunProjectItemStepHandler::class] as $class) {
            $src = $this->source($class);
            $validatePos = strpos($src, 'eligibility->validate');
            $startPos = strpos($src, 'workflowRunService->startRun');
            self::assertNotFalse($validatePos);
            self::assertNotFalse($startPos);
            self::assertLessThan($startPos, $validatePos);
        }
    }

    public function test_registrar_maps_step_command(): void
    {
        $src = $this->source(ContentProjectCommandBusRegistrar::class);
        self::assertStringContainsString('RerunProjectItemStepCommand::class => RerunProjectItemStepHandler::class', $src);
        self::assertStringContainsString('RerunProjectItemsCommand::class => RerunProjectItemsHandler::class', $src);
    }

    public function test_filament_single_and_bulk_use_command_bus(): void
    {
        $view = $this->source(ViewSeoProject::class);
        self::assertStringContainsString('RerunProjectItemsCommand', $view);
        self::assertStringContainsString('RerunProjectItemStepCommand', $view);
        self::assertStringContainsString('function rerunOne', $view);
        self::assertStringContainsString('ContentProjectRerunFromStep', $view);
        self::assertStringNotContainsString('ContentProjectStepRerunService', $view);
        self::assertStringNotContainsString('->execute($run', $view);

        $relation = $this->source(ProjectItemsRelationManager::class);
        self::assertStringContainsString('RerunProjectItemStepCommand', $relation);
        self::assertStringContainsString('ContentProjectRerunFromStep', $relation);
        self::assertStringNotContainsString('->execute($run', $relation);
    }

    public function test_editor_pipeline_goes_through_command_bus_not_legacy_job(): void
    {
        $service = $this->source(ArticlePipelineRerunService::class);
        self::assertStringContainsString('RerunProjectItemStepCommand', $service);
        self::assertStringContainsString('commandBus->dispatch', $service);
        self::assertStringContainsString('syncExecution: true', $service);
        self::assertStringNotContainsString('RerunArticlePipelineJob', $service);
        self::assertStringNotContainsString('dispatchSync', $service);

        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/src/Jobs/RerunArticlePipelineJob.php',
        );

        $edit = $this->source(EditArticle::class);
        self::assertStringContainsString('queueArticlePipelineRerun', $edit);
        self::assertStringContainsString('ArticlePipelineRerunService', $edit);
        self::assertStringContainsString('ArticleAiHistoryApplicationService', $edit);

        $actionsBlade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );
        self::assertStringNotContainsString('data-seo-pipeline-rerun', $actionsBlade);
    }

    public function test_mcp_agent_full_rerun_still_uses_items_command(): void
    {
        $factory = $this->source(ContentProjectAgentCommandFactory::class);
        // Full rerun capability must stay on RerunProjectItemsCommand (not step).
        self::assertStringContainsString("'content_project.rerun' => new RerunProjectItemsCommand", $factory);
        // Step rerun is a separate capability â€” allowed in factory, not on full-rerun path.
        self::assertStringContainsString("'content_project.rerun_step' => new RerunProjectItemStepCommand", $factory);
        self::assertStringNotContainsString("'content_project.rerun' => new RerunProjectItemStepCommand", $factory);
    }

    public function test_worker_routes_step_and_preserves_published(): void
    {
        $workflow = $this->source(SeoProjectWorkflowRunService::class);
        self::assertStringContainsString('runRerunFromStepForContext', $workflow);
        self::assertStringContainsString('restorePublishedLifecycle', $workflow);
        self::assertStringContainsString('isPublishedLifecycle', $workflow);
        self::assertStringContainsString('markPublishedRerunDirty', $workflow);
        self::assertStringNotContainsString("'status' => \$snapshot['task_status']", $workflow);

        $create = $this->source(CreateArticlesFromTaskService::class);
        self::assertStringContainsString('function runRerunFromStepForContext', $create);
        self::assertStringContainsString('runPublishWorkflowForContext($context, $siteId)', $create);
        $fullPos = strpos($create, "variables['rerun_scope'] ?? ''");
        self::assertNotFalse($fullPos);
        $rewritePos = strpos($create, 'TYPE_REWRITE', $fullPos);
        $outlinePos = strpos($create, 'runOutlineThenArticleForContext', $fullPos);
        self::assertNotFalse($outlinePos);
        self::assertTrue($rewritePos === false || $outlinePos < $rewritePos, 'CREATE full rerun must outline+article before rewrite-only branch');
        self::assertStringContainsString('withForcedAiRegenerate', $create);
    }

    public function test_engine_dispatches_worker_once_with_optional_sync(): void
    {
        $engine = $this->source(ContentProjectRunEngine::class);
        $pos = strpos($engine, 'function dispatchNextArticle');
        self::assertNotFalse($pos);
        $next = strpos($engine, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($engine, $pos, $next - $pos) : substr($engine, $pos, 5000);
        self::assertStringContainsString('rerun_sync', $chunk);
        self::assertStringContainsString('dispatchSync', $chunk);
        self::assertSame(1, substr_count($chunk, 'RunContentProjectArticleJob::dispatch('));
        self::assertSame(1, substr_count($chunk, 'RunContentProjectArticleJob::dispatchSync('));
    }

    public function test_eligibility_guard_blocks_archived_improve_and_conflicts(): void
    {
        $src = $this->source(ContentProjectRerunEligibilityGuard::class);
        self::assertStringContainsString('Archived item cannot be rerun', $src);
        self::assertStringNotContainsString('Improve items are manual-only', $src);
        self::assertStringContainsString('Article-only rerun requires a usable outline', $src);
        self::assertStringContainsString('Active conflicting execution', $src);
    }

    public function test_no_filament_or_editor_bypass_of_command_bus_for_rerun(): void
    {
        $view = $this->source(ViewSeoProject::class);
        $relation = $this->source(ProjectItemsRelationManager::class);
        $editorAdapter = $this->source(ArticlePipelineRerunService::class);

        foreach ([$view, $relation, $editorAdapter] as $src) {
            self::assertStringNotContainsString('RerunArticlePipelineJob', $src);
            self::assertStringNotContainsString('stepRerun->', $src);
        }
    }

    public function test_command_shapes(): void
    {
        $full = new RerunProjectItemsCommand(1, [2, 3], 'full', ['generate_post_images' => true]);
        self::assertSame('content_project.rerun', $full->name());
        self::assertTrue((bool) ($full->settings['generate_post_images'] ?? false));

        $step = new RerunProjectItemStepCommand(
            1,
            [2],
            ContentProjectRerunFromStep::Outline,
            true,
            99,
            'full',
            true,
        );
        self::assertSame('content_project.rerun_step', $step->name());
        self::assertTrue($step->includeDownstream);
        self::assertTrue($step->syncExecution);
        self::assertSame(99, $step->sourceArticleId);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }
}
