<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRunSemanticStatus;
use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Phase 1 contract tests (source + pure unit).
 * Full DB/Bus integration chạy trên remote với SEO connection.
 */
final class ContentProjectRunEnginePhase1Test extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_status_mapper_run_round_trip(): void
    {
        $mapper = new ContentProjectRunStatusMapper;

        self::assertSame(
            ContentProjectRunSemanticStatus::Stopping,
            $mapper->runFromDb(SeoProjectRun::STATUS_STOPPING),
        );
        self::assertSame(
            ContentProjectRunSemanticStatus::Cancelled,
            $mapper->runFromDb(SeoProjectRun::STATUS_CANCELLED),
        );
        self::assertFalse($mapper->runFromDb('stopping')->allowsDispatch());
        self::assertFalse($mapper->runFromDb('cancelled')->allowsDispatch());
        self::assertTrue($mapper->runFromDb('running')->allowsDispatch());
        self::assertTrue($mapper->runFromDb('completed')->isTerminal());
        self::assertTrue($mapper->runFromDb('failed')->isTerminal());
    }

    public function test_status_mapper_article_cancelled_via_error_message(): void
    {
        $mapper = new ContentProjectRunStatusMapper;

        self::assertSame(
            ContentProjectArticleSemanticStatus::Cancelled,
            $mapper->articleFromDb('failed', 'Cancelled by user.'),
        );
        self::assertSame(
            ContentProjectArticleSemanticStatus::Failed,
            $mapper->articleFromDb('failed', 'AI timeout'),
        );
    }

    public function test_article_result_may_dispatch_next_policy(): void
    {
        $failed = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 3,
            status: ContentProjectArticleSemanticStatus::Failed,
        );
        $cancelled = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 3,
            status: ContentProjectArticleSemanticStatus::Cancelled,
        );
        $completed = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 3,
            status: ContentProjectArticleSemanticStatus::Completed,
        );
        $forcedNo = new ArticleExecutionResult(
            runId: 1,
            taskId: 2,
            runItemId: 3,
            status: ContentProjectArticleSemanticStatus::Failed,
            mayDispatchNextOverride: false,
        );

        self::assertTrue($failed->mayDispatchNext());
        self::assertTrue($completed->mayDispatchNext());
        self::assertFalse($cancelled->mayDispatchNext());
        self::assertFalse($forcedNo->mayDispatchNext());
    }

    public function test_phase1_max_parallel_enforced_to_one(): void
    {
        self::assertSame(1, ContentProjectRunEngineFeature::effectiveMaxParallelArticles());
    }

    public function test_engine_owns_dispatch_not_livewire_loop(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        $job = $this->source('Jobs/RunContentProjectArticleJob.php');
        $runner = $this->source('Services/RunEngine/ContentProjectArticleRunner.php');
        $js = $this->source('resources/js/project-run-queue.js');
        $view = $this->source('Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php');
        $list = $this->source('Filament/Resources/SeoProjectResource/Pages/ListSeoProjectRuns.php');

        self::assertStringContainsString('function start(', $engine);
        self::assertStringContainsString('already_running', $engine);
        self::assertStringContainsString('function requestStop(', $engine);
        self::assertStringContainsString('function dispatchNextArticle(', $engine);
        self::assertStringContainsString('function handleArticleFinished(', $engine);
        self::assertStringContainsString('function finalizeIfDone(', $engine);
        self::assertStringContainsString('RunContentProjectArticleJob::dispatch', $engine);
        self::assertStringContainsString('mayDispatchNext', $engine);
        self::assertStringContainsString('hasBlockingActiveDispatch', $engine);
        self::assertStringContainsString('active_dispatch', $engine);
        self::assertStringContainsString('content_project_run.started', $engine);
        self::assertStringContainsString('content_project_run.article_dispatched', $engine);
        self::assertStringContainsString('content_project_run.stop_requested', $engine);
        self::assertStringContainsString('content_project_run.finalized', $engine);
        self::assertStringContainsString('content_project_run.next_dispatch_skipped', $engine);

        self::assertStringContainsString('ContentProjectArticleRunner', $job);
        self::assertStringContainsString('handleArticleFinished', $job);
        self::assertStringContainsString('uniqueId', $job);
        self::assertStringContainsString('dispatch_token_mismatch', $job);
        self::assertStringContainsString('markItemCancelled', $job);
        self::assertStringContainsString('content_project_run.article_claimed', $job);
        self::assertStringContainsString('dispatch_token_mismatch_pre_run', $job);
        self::assertStringContainsString('mayDispatchNextOverride: true', $job);

        self::assertStringContainsString('taskExecution->execute', $runner);
        self::assertStringContainsString('markCompleted: false', $runner);
        self::assertStringNotContainsString('retryTask(', $runner);
        self::assertStringNotContainsString('dispatchNextArticle', $runner);

        self::assertStringContainsString('phpEngine', $js);
        self::assertStringContainsString('startPhpEngineProgressPoll', $js);
        self::assertStringContainsString('JS không được orchestration article', $js);

        // Run History UI removed — generate orchestration lives in CommandBus handler (engine start once).
        $resource = $this->source('Filament/Resources/SeoProjectResource.php');
        self::assertStringContainsString('startGeneratePendingItems', $resource);
        self::assertStringNotContainsString('ContentProjectRunEngine::class)->start', $resource);
        self::assertStringContainsString('getProjectWorkspaceUrl', $resource);
        $generateHandler = $this->source('Services/ContentProject/Application/Handlers/GenerateProjectItemsHandler.php');
        self::assertStringContainsString('runEngine->start', $generateHandler);

        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringContainsString('redirect', $view);
        self::assertStringContainsString('getProjectWorkspaceUrl', $list);
        self::assertStringContainsString('redirect', $list);
    }

    public function test_start_does_not_call_retry_task_directly(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        self::assertStringNotContainsString('retryTask(', $engine);
        self::assertStringNotContainsString('runOneTask(', $engine);
        self::assertStringNotContainsString('PromptRunner', $engine);
    }

    public function test_finalize_waits_for_active_dispatch_when_stopping(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        $finalizePos = strpos($engine, 'function finalizeIfDone');
        self::assertNotFalse($finalizePos);
        $nextFn = strpos($engine, "\n    public function ", $finalizePos + 1);
        $chunk = $nextFn !== false
            ? substr($engine, $finalizePos, $nextFn - $finalizePos)
            : substr($engine, $finalizePos, 8000);
        self::assertStringContainsString('hasBlockingActiveDispatch', $chunk);
        self::assertStringContainsString('activeProcessingCount', $chunk);
        self::assertStringContainsString('abandonPendingArticles', $chunk);
        self::assertStringContainsString('sweepStaleActiveDispatch', $chunk);
    }

    public function test_handle_article_finished_skips_next_on_stop_or_cancel(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        $pos = strpos($engine, 'function handleArticleFinished');
        self::assertNotFalse($pos);
        $nextFn = strpos($engine, "\n    public function ", $pos + 1);
        $chunk = $nextFn !== false
            ? substr($engine, $pos, $nextFn - $pos)
            : substr($engine, $pos, 4000);
        self::assertStringContainsString('isStopRequested', $chunk);
        self::assertStringContainsString('mayDispatchNext', $chunk);
        self::assertStringContainsString('dispatchNextArticle', $chunk);
        self::assertStringContainsString('finalizeIfDone', $chunk);
    }

    public function test_poll_and_mount_are_read_only_when_flag_on(): void
    {
        $view = $this->source('Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php');
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringContainsString('redirect', $view);
        self::assertStringNotContainsString('function pollRunProgress', $view);
        self::assertStringNotContainsString('dispatchNextArticle', $view);
    }

    public function test_legacy_livewire_orchestration_rejected_when_flag_on(): void
    {
        $view = $this->source('Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php');
        // Run Detail UI removed — no Livewire orchestration entry points remain.
        foreach (['function runItemQueued', 'function beginRunQueue', 'function completeRunQueue'] as $fn) {
            self::assertStringNotContainsString($fn, $view);
        }
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
    }

    public function test_js_orchestration_disabled_when_php_engine(): void
    {
        $js = $this->source('resources/js/project-run-queue.js');
        foreach (['processQueue()', 'startQueue(', 'runSingleTask(', 'handleStartQueue('] as $fn) {
            $pos = strpos($js, $fn === 'processQueue()' ? 'async processQueue()' : (
                $fn === 'startQueue(' ? 'async startQueue(' : (
                    $fn === 'runSingleTask(' ? 'async runSingleTask(' : 'handleStartQueue('
                )
            ));
            self::assertNotFalse($pos, $fn);
            $chunk = substr($js, $pos, 350);
            self::assertStringContainsString('phpEngine', $chunk);
        }
    }

    public function test_event_publisher_contract_lists_phase1_events(): void
    {
        $contract = $this->source('Services/RunEngine/ContentProjectRunEventPublisher.php');
        $logging = $this->source('Services/RunEngine/LoggingContentProjectRunEventPublisher.php');

        foreach ([
            'runStarted',
            'runStopping',
            'runCancelled',
            'articleStarted',
            'articleCompleted',
            'articleFailed',
            'articleCancelled',
            'runProgressUpdated',
            'runCompleted',
            'runFailed',
        ] as $method) {
            self::assertStringContainsString('function '.$method.'(', $contract);
        }

        foreach ([
            'run_started',
            'run_stopping',
            'run_cancelled',
            'article_started',
            'article_completed',
            'article_failed',
            'article_cancelled',
            'run_progress_updated',
            'run_completed',
            'run_failed',
        ] as $event) {
            self::assertStringContainsString("'".$event."'", $logging);
        }
    }

    public function test_run_model_exposes_stopping_cancelled_constants(): void
    {
        self::assertSame('stopping', SeoProjectRun::STATUS_STOPPING);
        self::assertSame('cancelled', SeoProjectRun::STATUS_CANCELLED);
    }

    public function test_job_unique_id_includes_run_item_attempt(): void
    {
        $job = new RunContentProjectArticleJob(10, 20, 30, 2, 'token-abc');
        self::assertSame('content-project-run-article:10:30:2', $job->uniqueId());
        self::assertSame(1, $job->tries);
    }

    public function test_status_command_is_read_only(): void
    {
        $source = $this->source('Console/ContentProjectRunStatusCommand.php');
        self::assertStringContainsString('seo:content-project-run:status', $source);
        self::assertStringContainsString('statusSnapshot', $source);
        self::assertStringNotContainsString('dispatchNextArticle', $source);
        self::assertStringNotContainsString('->start(', $source);
        self::assertStringNotContainsString('requestStop', $source);
    }

    public function test_engine_public_api_surface(): void
    {
        $ref = new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine::class);
        foreach ([
            'start', 'resume', 'requestStop', 'dispatchNextArticle', 'handleArticleFinished',
            'finalizeIfDone', 'statusSnapshot', 'healthCheck', 'touchHeartbeat',
            'recoveryPlan', 'applyStaleDispatchRelease',
        ] as $method) {
            self::assertTrue($ref->hasMethod($method), $method);
            $m = new ReflectionMethod($ref->getName(), $method);
            self::assertTrue($m->isPublic());
        }
    }

    public function test_phase15_ttl_and_heartbeat_config_helpers(): void
    {
        self::assertGreaterThanOrEqual(1, ContentProjectRunEngineFeature::activeDispatchTtlMinutes());
        self::assertGreaterThanOrEqual(1, ContentProjectRunEngineFeature::heartbeatStaleMinutes());
    }

    public function test_phase15_sweep_requires_ttl_and_dead_heartbeat(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        self::assertStringContainsString('dispatch_ttl_and_heartbeat_dead', $engine);
        self::assertStringContainsString('stale_dispatch_released', $engine);
        self::assertStringContainsString('function healthCheck(', $engine);
        self::assertStringContainsString('finalized_at', $engine);
        self::assertStringContainsString('function touchHeartbeat(', $engine);
        self::assertStringContainsString('content_project_run.metrics', $engine);
        self::assertStringContainsString('content_project_run.transition', $engine);
    }

    public function test_feature_enabled_for_respects_run_opt_in(): void
    {
        $feature = $this->source('Support/RunEngine/ContentProjectRunEngineFeature.php');
        self::assertStringContainsString('function enabledFor(', $feature);
        self::assertStringContainsString('function enabledForProject(', $feature);
        self::assertStringContainsString('function shouldStartWithPhpEngine(', $feature);
        self::assertStringContainsString('php_engine_project_ids', $feature);
        self::assertStringContainsString('use_php_engine', $feature);
    }

    public function test_noop_event_publisher_exists_for_phase2_placeholder(): void
    {
        $noop = $this->source('Services/RunEngine/NoOpContentProjectRunEventPublisher.php');
        self::assertStringContainsString('implements ContentProjectRunEventPublisher', $noop);
        self::assertStringContainsString('Phase 2 SSE placeholder', $noop);
    }

    public function test_run_settings_persist_use_php_engine(): void
    {
        $settings = \Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings::fromUserInput([
            'generate_post_images' => false,
            'use_php_engine' => true,
        ]);
        self::assertTrue($settings->usePhpEngine);
        $arr = $settings->toArray();
        self::assertSame(true, $arr['use_php_engine']);
        self::assertSame('php', $arr['php_engine']['orchestration'] ?? null);
        self::assertTrue((bool) ($arr['php_engine']['enabled'] ?? false));
    }

    public function test_recover_command_is_dry_run_by_default(): void
    {
        $source = $this->source('Console/ContentProjectRunRecoverCommand.php');
        self::assertStringContainsString('seo:content-project-run:recover', $source);
        self::assertStringContainsString('recoveryPlan', $source);
        self::assertStringContainsString('--apply', $source);
        self::assertStringContainsString('applyStaleDispatchRelease', $source);
        self::assertStringContainsString('Dry-run', $source);
    }

    public function test_view_uses_enabled_for_run_not_global_only(): void
    {
        $resource = $this->source('Filament/Resources/SeoProjectResource.php');
        self::assertStringContainsString('startGeneratePendingItems', $resource);
        self::assertStringContainsString("'use_php_engine' => true", $resource);
        $view = $this->source('Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php');
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringNotContainsString('ContentProjectRunEngineFeature::enabled()', $view);
    }

    public function test_dispatch_selects_pending_article_ordered_by_id(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        $pos = strpos($engine, 'function dispatchNextArticle');
        self::assertNotFalse($pos);
        $nextFn = strpos($engine, "\n    public function ", $pos + 1);
        $chunk = $nextFn !== false
            ? substr($engine, $pos, $nextFn - $pos)
            : substr($engine, $pos, 8000);
        self::assertStringContainsString('articleExecution()', $chunk);
        self::assertStringContainsString('Pending->value', $chunk);
        self::assertStringContainsString("orderBy('id')", $chunk);
        self::assertStringContainsString('lockForUpdate', $chunk);
        self::assertStringContainsString('active_dispatch', $chunk);
    }

    private function source(string $relativeFromAddonRoot): string
    {
        return $this->readLegacyOrMovedAddonFile($relativeFromAddonRoot);
    }
}
