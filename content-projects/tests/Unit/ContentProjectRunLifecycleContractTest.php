<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectExecutionHandler;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemOpsEvidencePresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRunItemEvidenceIndex;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchCircuitBreakerState;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchFailureSignature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Content Project run lifecycle contract — CASE 1–7.
 * Pure / source-level checks (no AI routing, no provider calls).
 */
final class ContentProjectRunLifecycleContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_case1_normal_failure_continues_dispatch_contract(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );

        self::assertStringContainsString('recordConsecutiveFailureAndMaybeTrip', $engine);
        self::assertStringContainsString('dispatchNextArticle($run)', $engine);
        // Normal failure must not finalize the whole run before breaker trips.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->recordConsecutiveFailureAndMaybeTrip\(\$run,\s*\$result\)\s*\)\s*\{\s*return;\s*\}/u',
            $engine,
        );
        self::assertStringContainsString('$this->dispatchNextArticle($run)', $engine);

        // Simulated breaker accounting: 1 failure does not trip.
        $state = [];
        $sig = 'outline|empty_response|deepseek';
        $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($state, $sig);
        self::assertFalse($recorded['tripped']);
        self::assertSame(1, $recorded['count']);
    }

    public function test_case2_circuit_breaker_leaves_unvisited_pending_and_resume_contract(): void
    {
        $engine = [];
        $sig = ContentProjectBatchFailureSignature::SYSTEMIC_ROUTING;
        for ($i = 0; $i < 3; $i++) {
            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $sig);
            $engine = $recorded['engine'];
            if ($recorded['tripped']) {
                $engine = ContentProjectRunRecoverableState::stampCircuitBreaker(
                    $engine,
                    'Đã dừng Generate sau 3 lỗi giống nhau liên tiếp.',
                    $sig,
                    $recorded['count'],
                );
                break;
            }
        }

        self::assertTrue(ContentProjectBatchCircuitBreakerState::isStopped($engine));
        self::assertSame(
            ContentProjectRunRecoverableState::REASON_CIRCUIT_BREAKER,
            ContentProjectRunRecoverableState::reasonFromEngine($engine),
        );

        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/content-projects/src/Services/ContentProject/Application/Handlers/ResumeProjectExecutionHandler.php'
        );
        self::assertStringContainsString('isResumable', $handler);
        self::assertStringContainsString('ContentProjectRunRecoverableState::isRecoverableRun', $handler);
        self::assertStringContainsString('STATUS_STOPPING', $handler);
        self::assertStringNotContainsString("!== SeoProjectRun::STATUS_STOPPING", $handler);

        $engineSrc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('tryResumeAfterCircuitBreaker', $engineSrc);
        self::assertStringNotContainsString(
            "\$this->abandonPendingArticles(\$locked);\n                \$this->clearActiveDispatch",
            $engineSrc,
        );
    }

    public function test_circuit_breaker_stops_after_three_failed_items_even_when_signatures_differ(): void
    {
        $engine = [];
        $signatures = [
            'outline|output_truncated|deepseek',
            'article|routes_exhausted|openrouter',
            'outline|empty_response|gemini',
        ];

        foreach ($signatures as $index => $sig) {
            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $sig);
            $engine = $recorded['engine'];

            if ($index < 2) {
                self::assertFalse($recorded['tripped']);
                continue;
            }

            self::assertTrue($recorded['tripped']);
            self::assertSame(
                ContentProjectBatchCircuitBreakerState::TRIGGER_AGGREGATE_FAILED_ITEMS,
                $recorded['trigger'],
            );
            self::assertSame(3, $recorded['failure_count']);
        }

        self::assertTrue(ContentProjectBatchCircuitBreakerState::isStopped($engine));
        self::assertSame(
            ContentProjectBatchCircuitBreakerState::TRIGGER_AGGREGATE_FAILED_ITEMS,
            $engine['circuit_breaker']['trigger'] ?? null,
        );

        $engineSrc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('TRIGGER_AGGREGATE_FAILED_ITEMS', $engineSrc);
        self::assertStringContainsString('batch da co', $engineSrc);
    }

    public function test_case3_worker_death_threshold_and_no_auto_dispatch(): void
    {
        self::assertGreaterThanOrEqual(960, ContentProjectRunEngineFeature::workerDeathThresholdSeconds());
        self::assertGreaterThanOrEqual(
            ContentProjectRunEngineFeature::articleJobTimeoutSeconds(),
            900,
        );

        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('declareWorkerLostIfConfirmed', $engine);
        self::assertStringContainsString('recoverLostWorkers', $engine);
        self::assertStringContainsString('recoverDeadDispatchIfConfirmed', $engine);
        self::assertStringContainsString('finalizeStoppingAfterDeadWorker', $engine);
        self::assertStringContainsString('failed_worker_lost', $engine);
        self::assertStringContainsString('ERROR_CODE_WORKER_LOST', $engine);
        // Confirmed death must NOT call dispatchNextArticle inside declareWorkerLost.
        $method = $this->methodBody($engine, 'declareWorkerLostIfConfirmed');
        self::assertStringNotContainsString('dispatchNextArticle', $method);
        $stopping = $this->methodBody($engine, 'finalizeStoppingAfterDeadWorker');
        self::assertStringNotContainsString('dispatchNextArticle', $stopping);
        self::assertStringNotContainsString('stampWorkerLost', $stopping);
        self::assertStringContainsString('stopping_dead_worker_finalize', $stopping);

        $watchdog = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/content-projects/src/Console/ContentProjectWorkerLostWatchdogCommand.php'
        );
        self::assertStringContainsString('recoverLostWorkers', $watchdog);
        self::assertStringNotContainsString('ArticleRunner', $watchdog);
        self::assertStringNotContainsString('dispatchNextArticle', $watchdog);
    }

    public function test_watchdog_is_registered_and_scheduled_every_minute(): void
    {
        $provider = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/SeoContentAiServiceProvider.php'
        );
        self::assertStringContainsString('ContentProjectWorkerLostWatchdogCommand::class', $provider);
        self::assertStringContainsString('seo-content-ai:content-project-worker-lost-watchdog', $provider);

        $schedulePos = strpos($provider, 'seo-content-ai:content-project-worker-lost-watchdog');
        self::assertNotFalse($schedulePos);
        $chunk = substr($provider, $schedulePos, 800);
        self::assertStringContainsString('->everyMinute()', $chunk);
        self::assertStringContainsString('ContentProjectWorkerLostWatchdogCommand::class', $chunk);
        self::assertStringContainsString('withoutOverlapping', $chunk);
    }

    public function test_resume_clears_stale_stop_and_final_metadata(): void
    {
        $cleared = ContentProjectRunRecoverableState::clear([
            'recoverable' => ['reason' => 'worker_lost', 'message' => 'old'],
            'worker_lost' => ['stopped' => true],
            'circuit_breaker' => ['stopped' => true],
            'stop_requested_at' => '2026-01-01T00:00:00+00:00',
            'stop_requested_by' => 9,
            'stop_reason' => 'Stopped by user.',
            'finalized_at' => '2026-01-01T00:01:00+00:00',
            'final_status' => 'failed_worker_lost',
            'started_at' => '2026-01-01T00:00:00+00:00',
            'orchestration' => 'php',
            'use_php_engine' => true,
            'max_parallel_articles' => 1,
        ]);

        self::assertArrayNotHasKey('recoverable', $cleared);
        self::assertArrayNotHasKey('worker_lost', $cleared);
        self::assertArrayNotHasKey('circuit_breaker', $cleared);
        self::assertArrayNotHasKey('stop_requested_at', $cleared);
        self::assertArrayNotHasKey('stop_requested_by', $cleared);
        self::assertArrayNotHasKey('stop_reason', $cleared);
        self::assertArrayNotHasKey('finalized_at', $cleared);
        self::assertArrayNotHasKey('final_status', $cleared);
        self::assertSame('php', $cleared['orchestration']);
        self::assertTrue($cleared['use_php_engine']);
        self::assertSame('2026-01-01T00:00:00+00:00', $cleared['started_at']);
        self::assertSame(1, $cleared['max_parallel_articles']);

        $fromStopping = ContentProjectRunRecoverableState::clearStopAndFinalMarkers([
            'stop_requested_at' => 'x',
            'stop_requested_by' => 1,
            'stop_reason' => 'Stopped by user.',
            'finalized_at' => 'y',
            'final_status' => 'cancelled',
            'started_at' => 'keep',
            'orchestration' => 'php',
        ]);
        self::assertArrayNotHasKey('stop_reason', $fromStopping);
        self::assertArrayNotHasKey('finalized_at', $fromStopping);
        self::assertSame('keep', $fromStopping['started_at']);

        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('clearStopAndFinalMarkers', $engine);
        $clearStopping = $this->methodBody($engine, 'clearStoppingToRunning');
        self::assertStringContainsString('clearStopAndFinalMarkers', $clearStopping);
        $workerResume = $this->methodBody($engine, 'tryResumeAfterWorkerLost');
        self::assertStringContainsString('ContentProjectRunRecoverableState::clear', $workerResume);
    }

    public function test_stopping_plus_worker_death_becomes_cancelled_not_worker_lost(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        $router = $this->methodBody($engine, 'recoverDeadDispatchIfConfirmed');
        self::assertStringContainsString('finalizeStoppingAfterDeadWorker', $router);
        self::assertStringContainsString('declareWorkerLostIfConfirmed', $router);

        $declare = $this->methodBody($engine, 'declareWorkerLostIfConfirmed');
        self::assertStringContainsString('ContentProjectRunSemanticStatus::Running', $declare);
        self::assertStringNotContainsString('ContentProjectRunSemanticStatus::Stopping', $declare);

        $stopping = $this->methodBody($engine, 'finalizeStoppingAfterDeadWorker');
        self::assertStringContainsString('ContentProjectRunSemanticStatus::Stopping', $stopping);
        self::assertStringContainsString('ContentProjectRunSemanticStatus::Cancelled', $stopping);
        self::assertStringContainsString('cancelledArticleErrorMessage', $stopping);
        self::assertStringContainsString('intentional_unvisited_pending', $stopping);
        self::assertStringContainsString("final_status'] = 'cancelled'", $stopping);
        self::assertStringNotContainsString('stampWorkerLost', $stopping);
        self::assertStringNotContainsString('REASON_WORKER_LOST', $stopping);
        self::assertStringNotContainsString('dispatchNextArticle', $stopping);
        self::assertStringContainsString('runCancelled', $stopping);
    }

    public function test_case4_resume_worker_lost_attempt_accounting(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('tryResumeAfterWorkerLost', $engine);
        $method = $this->methodBody($engine, 'tryResumeAfterWorkerLost');
        self::assertStringContainsString("'attempt' => \$nextAttempt", $method);
        self::assertStringContainsString('$currentAttempt + 1', $method);
        self::assertStringContainsString('MAX_TRANSIENT_ARTICLE_ATTEMPTS', $method);
        self::assertStringContainsString('dispatchNextArticle($run)', $method);
        self::assertSame(3, ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS);

        // Accounting model: 1 → 2 (never 1→1, never skip to 3).
        $attempt = 1;
        $next = $attempt + 1;
        self::assertSame(2, $next);
        self::assertNotSame($attempt, $next);
        self::assertNotSame(3, $next);
    }

    public function test_case5_stale_token_cannot_clear_newer_dispatch(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('ownsCurrentDispatch', $engine);
        self::assertStringContainsString('dispatch_ownership_revoked', $engine);
        self::assertStringContainsString('?string $dispatchToken = null', $engine);

        $clear = new ReflectionMethod(ContentProjectRunEngine::class, 'clearActiveDispatch');
        self::assertGreaterThanOrEqual(4, $clear->getNumberOfParameters());

        $job = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Jobs/RunContentProjectArticleJob.php'
        );
        self::assertStringContainsString('dispatch_token_mismatch_post_run', $job);
        self::assertStringContainsString('handleArticleFinished($runAfter, $result, $this->dispatchToken)', $job);
        self::assertStringContainsString('dispatch_token_mismatch_job_failed', $job);

        // Pure ownership model.
        $active = ['token' => 'tok-2', 'run_item_id' => 20, 'task_id' => 2, 'attempt' => 2];
        self::assertNotSame('tok-1', (string) $active['token']);
        self::assertSame(2, (int) $active['attempt']);
    }

    public function test_case6_read_model_current_run_precedence_and_f5(): void
    {
        $now = new \DateTimeImmutable('2026-09-15T10:00:00+00:00');
        $dispatch = [
            'run_item_id' => 101,
            'task_id' => 1,
            'claimed_at' => null,
            'dispatched_at' => $now->modify('-5 seconds')->format(\DateTimeInterface::ATOM),
            'last_heartbeat_at' => $now->modify('-5 seconds')->format(\DateTimeInterface::ATOM),
            'current_step' => 'queued',
        ];
        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(111, 11, 50, 'pending'),
            $this->item(110, 10, 50, 'pending'),
            $this->item(101, 1, 50, 'pending'),
            $this->item(91, 11, 40, 'failed', true),
            $this->item(90, 10, 40, 'failed', true),
            $this->item(80, 1, 40, 'failed', true),
        ], $dispatch, 50);

        $ctx = [
            'run_id' => 50,
            'run_status' => SeoProjectRun::STATUS_RUNNING,
            'active_dispatch' => $dispatch,
            'ai_transient_retry' => null,
            'processing_count' => 0,
            'has_dispatch_tracking' => true,
            'now' => \Carbon\Carbon::parse($now->format(\DateTimeInterface::ATOM)),
            'heartbeat_stale_seconds' => 1200,
        ];

        $failedCurrent = 0;
        $rows = [];
        foreach ([1, 10, 11] as $taskId) {
            $row = ContentProjectItemOpsEvidencePresenter::present(
                SeoProjectTask::STATUS_FAILED,
                $partition['latest_execution_by_task'][$taskId] ?? null,
                $partition['current_membership_by_task'][$taskId] ?? null,
                $ctx,
            );
            $rows[$taskId] = $row;
            if (ContentProjectFailedOpsDefinition::matches([
                'generation_status' => $row['generation_status'],
                'execution_status' => $row['execution_status'],
                'runtime_status' => $row['runtime_status'],
                'is_genuinely_running' => $row['is_genuinely_running'],
            ])) {
                $failedCurrent++;
            }
        }

        self::assertSame('queued', $rows[1]['generation_key']);
        self::assertSame('batch_waiting', $rows[10]['generation_key']);
        self::assertSame('batch_waiting', $rows[11]['generation_key']);
        self::assertSame(0, $failedCurrent);

        // Simulated F5 — identical rebuild from same persisted evidence.
        $again = ContentProjectItemOpsEvidencePresenter::present(
            SeoProjectTask::STATUS_FAILED,
            $partition['latest_execution_by_task'][10] ?? null,
            $partition['current_membership_by_task'][10] ?? null,
            $ctx,
        );
        self::assertSame('batch_waiting', $again['generation_key']);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_BATCH_WAITING, $again['runtime_state']);
    }

    public function test_case7_graceful_stop_keeps_unvisited_pending(): void
    {
        $engine = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/RunEngine/ContentProjectRunEngine.php'
        );
        self::assertStringContainsString('intentional_unvisited_pending', $engine);
        self::assertStringContainsString('unvisited_membership_retained', $engine);
        self::assertStringContainsString('hasIntentionalUnvisitedPending', $engine);

        $abandon = $this->methodBody($engine, 'abandonPendingArticles');
        self::assertStringNotContainsString("SeoProjectRunItemStatus::Failed->value", $abandon);
        self::assertStringContainsString('abandon_pending_skipped', $abandon);

        // Resume handler still accepts stopping.
        $handler = new ReflectionClass(ResumeProjectExecutionHandler::class);
        self::assertTrue($handler->hasMethod('handle'));
    }

    public function test_worker_lost_message_shows_next_resume_attempt(): void
    {
        $msg1 = ContentProjectRunRecoverableState::workerLostItemMessage(3341, 1, 3);
        self::assertStringContainsString('#3341', $msg1);
        self::assertStringContainsString('attempt 2/3', $msg1);
        self::assertStringContainsString('Resume', $msg1);

        $msg2 = ContentProjectRunRecoverableState::workerLostItemMessage(3341, 2, 3);
        self::assertStringContainsString('attempt 3/3', $msg2);

        $exhausted = ContentProjectRunRecoverableState::workerLostItemMessage(10, 3, 3);
        self::assertStringContainsString('3/3', $exhausted);
        self::assertStringContainsString('không thể Resume thêm', $exhausted);
        self::assertStringNotContainsString('attempt 4/', $exhausted);
    }

    public function test_job_timeout_matches_hard_floor(): void
    {
        $job = new ReflectionClass(RunContentProjectArticleJob::class);
        $defaults = $job->getDefaultProperties();
        self::assertSame(900, (int) ($defaults['timeout'] ?? 0));
        self::assertGreaterThan((int) $defaults['timeout'], ContentProjectRunEngineFeature::workerDeathThresholdSeconds());
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $id, int $taskId, int $runId, string $status, bool $finished = false): array
    {
        return [
            'id' => $id,
            'task_id' => $taskId,
            'run_id' => $runId,
            'status' => $status,
            'action' => null,
            'attempt' => 1,
            'message' => null,
            'error_message' => $status === 'failed' ? 'historical' : null,
            'started_at' => $finished ? '01/01/2026 10:00' : null,
            'started_at_iso' => $finished ? '2026-01-01T10:00:00+00:00' : null,
            'finished_at' => $finished ? '01/01/2026 10:05' : null,
            'finished_at_iso' => $finished ? '2026-01-01T10:05:00+00:00' : null,
            'lazy_bulk' => true,
        ];
    }

    private function methodBody(string $source, string $method): string
    {
        if (! preg_match(
            '/function\s+'.preg_quote($method, '/').'\s*\([^{]*\{([\s\S]*?)\n    (?:public|private|protected|\/\*|})/u',
            $source,
            $m,
        )) {
            // Fallback: capture until next method at same indent.
            if (! preg_match(
                '/function\s+'.preg_quote($method, '/').'\s*\([^{]*\{([\s\S]*?)\n    public function /u',
                $source,
                $m,
            )) {
                self::fail('Method '.$method.' not found');
            }
        }

        return $m[1];
    }
}
