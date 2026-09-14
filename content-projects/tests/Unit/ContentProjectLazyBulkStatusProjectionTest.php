<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\Carbon;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleRuntimeStatusResolver;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPendingOpsDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Lazy-bulk: run_item.pending ≠ task waiting_worker; queue is server-owned.
 */
final class ContentProjectLazyBulkStatusProjectionTest extends TestCase
{
    private ContentProjectArticleRuntimeStatusResolver $resolver;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ContentProjectArticleRuntimeStatusResolver;
        $this->now = Carbon::parse('2026-09-14T08:00:00+00:00');
    }

    public function test_before_claim_failed_and_never_generated_lifecycle_preserved(): void
    {
        $failedA = $this->resolveMembership('failed', taskId: 1, runItemId: 101);
        $failedB = $this->resolveMembership('failed', taskId: 2, runItemId: 102);
        $failedC = $this->resolveMembership('failed', taskId: 3, runItemId: 103);
        $never = $this->resolveMembership('pending', taskId: 4, runItemId: 104);

        foreach ([$failedA, $failedB, $failedC] as $status) {
            self::assertSame(ContentProjectArticleRuntimeStatus::STATE_FAILED, $status->state);
            self::assertSame('failed', ContentProjectOpsStateClassifier::classify($this->row($status, 'failed'))['generation_key']);
            self::assertTrue(ContentProjectFailedOpsDefinition::matches($this->row($status, 'failed')));
            self::assertFalse(ContentProjectPendingOpsDefinition::matches($this->row($status, 'failed')));
        }

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION, $never->state);
        self::assertSame('Chưa chạy', $never->label);
        self::assertFalse(ContentProjectPendingOpsDefinition::matches($this->row($never, 'pending')));
    }

    public function test_after_a_claim_only_current_item_shows_waiting_worker(): void
    {
        $dispatch = [
            'run_item_id' => 101,
            'task_id' => 1,
            'attempt' => 1,
            'token' => 'tok-a',
            'dispatched_at' => $this->ago(5),
            'claimed_at' => null,
            'last_heartbeat_at' => $this->ago(5),
            'current_step' => 'queued',
        ];

        $a = $this->resolve([
            'run_item' => $this->item([
                'id' => 101,
                'task_id' => 1,
                'status' => 'pending',
            ]),
            'task_status' => 'failed',
            'active_dispatch' => $dispatch,
        ]);
        $b = $this->resolveMembership('failed', taskId: 2, runItemId: 102);
        $c = $this->resolveMembership('failed', taskId: 3, runItemId: 103);
        $d = $this->resolveMembership('pending', taskId: 4, runItemId: 104);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_QUEUED, $a->state);
        self::assertSame('Đang chờ worker', $a->label);
        self::assertSame('queued', ContentProjectOpsStateClassifier::classify($this->row($a, 'pending'))['generation_key']);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_FAILED, $b->state);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_FAILED, $c->state);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION, $d->state);
    }

    public function test_after_a_terminal_and_b_claim_only_b_is_current(): void
    {
        $dispatchB = [
            'run_item_id' => 102,
            'task_id' => 2,
            'attempt' => 1,
            'token' => 'tok-b',
            'dispatched_at' => $this->ago(3),
            'claimed_at' => null,
            'last_heartbeat_at' => $this->ago(3),
            'current_step' => 'queued',
        ];

        $a = $this->resolve([
            'run_item' => $this->item([
                'id' => 101,
                'task_id' => 1,
                'status' => 'success',
                'finished_at_iso' => $this->ago(30),
            ]),
            'task_status' => 'completed',
            'active_dispatch' => $dispatchB,
        ]);
        $b = $this->resolve([
            'run_item' => $this->item([
                'id' => 102,
                'task_id' => 2,
                'status' => 'pending',
            ]),
            'task_status' => 'failed',
            'active_dispatch' => $dispatchB,
        ]);
        $c = $this->resolveMembership('failed', taskId: 3, runItemId: 103);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_COMPLETED, $a->state);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_QUEUED, $b->state);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_FAILED, $c->state);
    }

    public function test_seed_bulk_membership_does_not_mutate_task_lifecycle(): void
    {
        $src = $this->source(SeoProjectRunItemService::class);
        self::assertStringContainsString('function seedBulkMembership', $src);
        self::assertStringContainsString('mutateTaskLifecycle: false', $src);

        $method = new ReflectionMethod(SeoProjectRunItemService::class, 'seedBulkMembership');
        $start = $method->getStartLine() ?? 0;
        $end = $method->getEndLine() ?? 0;
        $lines = array_slice(explode("\n", $src), max(0, $start - 1), max(1, $end - $start + 1));
        $body = implode("\n", $lines);
        self::assertStringContainsString('mutateTaskLifecycle: false', $body);
        self::assertStringNotContainsString('mutateTaskLifecycle: true', $body);
    }

    public function test_lazy_bulk_prepare_uses_seed_not_prepare_operation(): void
    {
        $src = $this->source(SeoProjectWorkflowRunService::class);
        $pos = strpos($src, 'function prepareRunQueue');
        self::assertNotFalse($pos);
        $next = strpos($src, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($src, $pos, $next - $pos) : substr($src, $pos, 8000);

        self::assertStringContainsString('lazy_bulk', $chunk);
        self::assertStringContainsString('seedBulkMembership', $chunk);
    }

    public function test_generate_handler_starts_engine_server_side_without_ui_kick(): void
    {
        $handler = $this->source(GenerateProjectItemsHandler::class);
        self::assertStringContainsString("'lazy_bulk' => true", $handler);
        self::assertStringContainsString('runEngine->start', $handler);
        self::assertStringContainsString('// Do not touch task updated_at / lifecycle — membership only.', $handler);
        self::assertMatchesRegularExpression('/ContentProjectActionResult::ok\([\s\S]*?\[\],/', $handler);

        $engine = $this->source(ContentProjectRunEngine::class);
        $startPos = strpos($engine, 'function start(');
        self::assertNotFalse($startPos);
        $nextFn = strpos($engine, "\n    public function ", $startPos + 1);
        $startChunk = $nextFn !== false
            ? substr($engine, $startPos, $nextFn - $startPos)
            : substr($engine, $startPos, 2500);
        self::assertStringContainsString('dispatchNextArticle', $startChunk);

        $finishPos = strpos($engine, 'function handleArticleFinished');
        self::assertNotFalse($finishPos);
        $finishNext = strpos($engine, "\n    public function ", $finishPos + 1);
        $finishChunk = $finishNext !== false
            ? substr($engine, $finishPos, $finishNext - $finishPos)
            : substr($engine, $finishPos, 6000);
        self::assertStringContainsString('dispatchNextArticle($run)', $finishChunk);

        $job = $this->source(RunContentProjectArticleJob::class);
        self::assertStringContainsString('handleArticleFinished', $job);
        self::assertStringNotContainsString('dispatchNextArticle', $job);

        $resource = $this->source(SeoProjectResource::class);
        self::assertStringNotContainsString('dispatchNextArticle', $resource);

        $view = $this->source(ViewSeoProject::class);
        self::assertStringNotContainsString('dispatchNextArticle', $view);
        $mount = new ReflectionMethod(ViewSeoProject::class, 'mount');
        $mountBody = $this->methodBody($mount);
        self::assertStringNotContainsString('requestStop', $mountBody);
        self::assertStringNotContainsString('cancel', strtolower($mountBody));
        self::assertStringNotContainsString('recover', strtolower($mountBody));
    }

    public function test_membership_pending_exec_does_not_override_failed_generation_status_contract(): void
    {
        // Read-model gate: only current dispatched item may rewrite failed → pending.
        $readModel = $this->source(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel::class,
        );
        self::assertStringContainsString('isCurrentDispatchedRunItem', $readModel);
        self::assertStringContainsString('resolveDisplayLastActivity', $readModel);
        self::assertStringContainsString('latestAttemptQueued && $genStatus === SeoProjectTask::STATUS_FAILED', $readModel);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolve(array $context): ContentProjectArticleRuntimeStatus
    {
        return $this->resolver->resolve($context + [
            'run_id' => 50,
            'run_status' => SeoProjectRun::STATUS_RUNNING,
            'processing_count' => 0,
            'has_dispatch_tracking' => true,
            'heartbeat_stale_seconds' => 1200,
            'now' => $this->now,
            'active_dispatch' => null,
        ]);
    }

    private function resolveMembership(string $taskStatus, int $taskId, int $runItemId): ContentProjectArticleRuntimeStatus
    {
        return $this->resolve([
            'run_item' => $this->item([
                'id' => $runItemId,
                'task_id' => $taskId,
                'status' => 'pending',
            ]),
            'task_status' => $taskStatus,
            'active_dispatch' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'id' => 900,
            'run_id' => 50,
            'task_id' => 77,
            'status' => 'pending',
            'action' => null,
            'attempt' => 1,
            'message' => null,
            'error_message' => null,
            'started_at_iso' => null,
            'finished_at_iso' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ContentProjectArticleRuntimeStatus $status, string $generationStatus): array
    {
        return [
            'runtime_status' => $status->toArray(),
            'generation_status' => $generationStatus,
            'execution_status' => 'pending',
            'is_genuinely_running' => $status->isActive,
            'is_generation_stale' => false,
            'type' => 'new',
            'article_id' => 0,
        ];
    }

    private function ago(int $seconds): string
    {
        return $this->now->copy()->subSeconds($seconds)->toIso8601String();
    }

    /**
     * @param  class-string|object  $class
     */
    private function source(string|object $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($path);

        return (string) file_get_contents($path);
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $src = $this->source($method->getDeclaringClass()->getName());
        $start = $method->getStartLine() ?? 0;
        $end = $method->getEndLine() ?? 0;

        return implode("\n", array_slice(explode("\n", $src), max(0, $start - 1), max(1, $end - $start + 1)));
    }
}
