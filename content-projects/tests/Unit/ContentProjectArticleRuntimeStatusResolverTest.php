<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\Carbon;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleRuntimeStatusResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPendingOpsDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Runtime clarity contract — only dispatch-backed evidence may read as "Đang tạo".
 */
final class ContentProjectArticleRuntimeStatusResolverTest extends TestCase
{
    private const HEARTBEAT_STALE_SECONDS = 1200;

    private ContentProjectArticleRuntimeStatusResolver $resolver;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ContentProjectArticleRuntimeStatusResolver;
        $this->now = Carbon::parse('2026-01-15T10:00:00+00:00');
    }

    public function test_1_processing_with_matching_alive_dispatch_is_actively_processing(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item([
                'status' => 'processing',
                'action' => 'article.content.generate',
                'started_at_iso' => $this->ago(45),
            ]),
            'active_dispatch' => $this->dispatch([
                'claimed_at' => $this->ago(50),
                'last_heartbeat_at' => $this->ago(10),
                'current_step' => 'running_article',
            ]),
            'processing_count' => 1,
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_ACTIVELY_PROCESSING, $status->state);
        self::assertTrue($status->isActive);
        self::assertTrue($status->showSpinner);
        self::assertSame('Đang tạo', $status->label);
        self::assertSame(ContentProjectArticleRuntimeStatus::STEP_WRITING, $status->stepLabel);
        self::assertSame('Đang xử lý 45s', $status->timeLabel);
        self::assertSame('running', ContentProjectOpsStateClassifier::classify($this->row($status))['generation_key']);
    }

    public function test_resolve_without_now_key_does_not_warn(): void
    {
        $prev = error_reporting(E_ALL);
        try {
            $status = $this->resolver->resolve([
                'run_item' => [
                    'id' => 1,
                    'status' => 'failed',
                    'attempt' => 1,
                ],
                'run_status' => SeoProjectRun::STATUS_COMPLETED,
                'heartbeat_stale_seconds' => self::HEARTBEAT_STALE_SECONDS,
            ]);
        } finally {
            error_reporting($prev);
        }

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_FAILED, $status->state);
        self::assertFalse($status->isActive);
    }

    public function test_2_pending_with_queued_dispatch_is_queued_without_spinner(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item([
                'status' => 'pending',
                'action' => null,
                'started_at_iso' => null,
            ]),
            'active_dispatch' => $this->dispatch([
                'claimed_at' => null,
                'dispatched_at' => $this->ago(12),
                'last_heartbeat_at' => $this->ago(12),
                'current_step' => 'queued',
            ]),
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_QUEUED, $status->state);
        self::assertFalse($status->isActive);
        self::assertFalse($status->showSpinner);
        self::assertSame('Đang chờ worker', $status->label);
        self::assertSame('queued', ContentProjectOpsStateClassifier::classify($this->row($status))['generation_key']);
    }

    public function test_3_pending_with_scheduled_ai_retry_is_waiting_for_ai(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item([
                'status' => 'pending',
                'attempt' => 2,
                'message' => 'Đang chờ AI — thử lại sau ~1 phút',
            ]),
            'active_dispatch' => null,
            'ai_transient_retry' => [
                'run_item_id' => 900,
                'task_id' => 77,
                'attempt' => 2,
                'delay_seconds' => 60,
                'scheduled_at' => $this->ago(15),
            ],
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_WAITING_AI_RETRY, $status->state);
        self::assertFalse($status->isActive);
        self::assertSame('Chờ AI', $status->label);
        self::assertSame(45, $status->retryAfterSeconds);
        self::assertSame('Thử lại sau ~1 phút', $status->timeLabel);
        self::assertSame('waiting_ai', ContentProjectOpsStateClassifier::classify($this->row($status))['generation_key']);
    }

    public function test_4_processing_with_stale_heartbeat_is_possibly_stuck(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item([
                'status' => 'processing',
                'action' => 'article.outline.structure.generate',
                'started_at_iso' => $this->ago(4000),
            ]),
            'active_dispatch' => $this->dispatch([
                'claimed_at' => $this->ago(4000),
                'last_heartbeat_at' => $this->ago(3600),
                'current_step' => 'running_article',
            ]),
            'processing_count' => 1,
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING, $status->state);
        self::assertFalse($status->isActive);
        self::assertFalse($status->showSpinner);
        self::assertSame('Có thể bị kẹt', $status->label);
        self::assertSame(ContentProjectArticleRuntimeStatus::STEP_OUTLINE, $status->stepLabel);
        self::assertSame('stale', ContentProjectOpsStateClassifier::classify($this->row($status))['generation_key']);
    }

    public function test_5_successful_attempt_beats_sticky_writing_task_status(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item(['status' => 'success']),
            'task_status' => 'writing',
            'active_dispatch' => $this->dispatch([
                'run_item_id' => 900,
                'claimed_at' => $this->ago(30),
                'last_heartbeat_at' => $this->ago(5),
            ]),
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_COMPLETED, $status->state);
        self::assertFalse($status->isActive);
    }

    public function test_6_failed_attempt_beats_sticky_writing_task_status(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item(['status' => 'failed']),
            'task_status' => 'writing',
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_FAILED, $status->state);
        self::assertFalse($status->isActive);
        self::assertSame('Lỗi', $status->label);
    }

    public function test_7_no_dispatch_and_no_processing_is_not_active(): void
    {
        $pending = $this->resolve([
            'run_item' => $this->item(['status' => 'pending']),
            'active_dispatch' => null,
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_PENDING, $pending->state);
        self::assertFalse($pending->isActive);
        self::assertFalse($pending->showSpinner);

        // Sticky task.status=writing with no execution row at all — the original bug.
        $sticky = $this->resolve([
            'run_item' => null,
            'task_status' => 'writing',
            'active_dispatch' => null,
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION, $sticky->state);
        self::assertFalse($sticky->isActive);
        self::assertSame(
            'pending',
            ContentProjectOpsStateClassifier::classify($this->row($sticky))['generation_key'],
        );
    }

    public function test_8_two_processing_rows_yield_exactly_one_active(): void
    {
        $shared = [
            'active_dispatch' => $this->dispatch([
                'run_item_id' => 900,
                'claimed_at' => $this->ago(30),
                'last_heartbeat_at' => $this->ago(5),
                'current_step' => 'running_article',
            ]),
            'processing_count' => 2,
        ];

        $owned = $this->resolve($shared + [
            'run_item' => $this->item([
                'id' => 900,
                'status' => 'processing',
                'started_at_iso' => $this->ago(30),
            ]),
        ]);
        $orphan = $this->resolve($shared + [
            'run_item' => $this->item([
                'id' => 901,
                'status' => 'processing',
                'started_at_iso' => $this->ago(3000),
            ]),
        ]);

        self::assertTrue($owned->isActive);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_ACTIVELY_PROCESSING, $owned->state);

        self::assertFalse($orphan->isActive);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_INCONSISTENT_PROCESSING, $orphan->state);
        self::assertNotNull($orphan->warning);

        $activeCount = (int) $owned->isActive + (int) $orphan->isActive;
        self::assertSame(1, $activeCount, 'At most one row per run may be genuinely running.');
    }

    public function test_10_delayed_retry_dispatch_reads_as_waiting_for_ai_not_running(): void
    {
        $status = $this->resolve([
            'run_item' => $this->item(['status' => 'pending', 'attempt' => 2]),
            'active_dispatch' => $this->dispatch([
                'claimed_at' => null,
                'current_step' => 'ai_retry_delayed',
                'dispatched_at' => $this->ago(5),
                'last_heartbeat_at' => $this->ago(5),
            ]),
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_WAITING_AI_RETRY, $status->state);
        self::assertFalse($status->isActive);
        self::assertFalse($status->showSpinner);
        self::assertSame('Chờ AI', $status->label);
    }

    public function test_processing_after_run_terminated_is_not_active(): void
    {
        $status = $this->resolve([
            'run_status' => SeoProjectRun::STATUS_FAILED,
            'run_item' => $this->item([
                'status' => 'processing',
                'started_at_iso' => $this->ago(20),
            ]),
            'active_dispatch' => $this->dispatch([
                'claimed_at' => $this->ago(25),
                'last_heartbeat_at' => $this->ago(5),
            ]),
            'processing_count' => 1,
        ]);

        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_STALE_PROCESSING, $status->state);
        self::assertFalse($status->isActive);
    }

    public function test_pending_rows_left_by_circuit_breaker_are_not_pending_ops(): void
    {
        $status = $this->resolve([
            'run_status' => SeoProjectRun::STATUS_FAILED,
            'run_item' => $this->item(['status' => 'pending']),
            'active_dispatch' => null,
        ]);

        self::assertFalse(ContentProjectPendingOpsDefinition::matches($this->row($status)));
        self::assertTrue(ContentProjectPendingOpsDefinition::matches($this->row(
            $this->resolve([
                'run_item' => $this->item(['status' => 'pending']),
                'active_dispatch' => $this->dispatch([
                    'claimed_at' => null,
                    'current_step' => 'queued',
                    'dispatched_at' => $this->ago(3),
                ]),
            ]),
        )));
    }

    public function test_step_label_maps_technical_hooks(): void
    {
        self::assertSame(
            ContentProjectArticleRuntimeStatus::STEP_OUTLINE,
            ContentProjectArticleRuntimeStatusResolver::stepLabel('article.outline.structure.generate'),
        );
        self::assertSame(
            ContentProjectArticleRuntimeStatus::STEP_VOCABULARY,
            ContentProjectArticleRuntimeStatusResolver::stepLabel('article.vocabulary.generate'),
        );
        self::assertSame(
            ContentProjectArticleRuntimeStatus::STEP_WRITING,
            ContentProjectArticleRuntimeStatusResolver::stepLabel('article.content.rewrite'),
        );
        self::assertNull(ContentProjectArticleRuntimeStatusResolver::stepLabel('queued'));
        self::assertNull(ContentProjectArticleRuntimeStatusResolver::stepLabel('ai_retry_delayed'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolve(array $context): ContentProjectArticleRuntimeStatus
    {
        return $this->resolver->resolve($context + [
            'run_id' => 5,
            'run_status' => SeoProjectRun::STATUS_RUNNING,
            'processing_count' => 0,
            'has_dispatch_tracking' => true,
            'heartbeat_stale_seconds' => self::HEARTBEAT_STALE_SECONDS,
            'now' => $this->now,
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
            'run_id' => 5,
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dispatch(array $overrides = []): array
    {
        return $overrides + [
            'run_item_id' => 900,
            'task_id' => 77,
            'attempt' => 1,
            'token' => 'tok-abc',
            'dispatched_at' => $this->ago(60),
            'last_heartbeat_at' => $this->ago(10),
            'claimed_at' => null,
            'current_step' => 'queued',
        ];
    }

    /**
     * Minimal ops row carrying the resolver output, for classifier assertions.
     *
     * @return array<string, mixed>
     */
    private function row(ContentProjectArticleRuntimeStatus $status): array
    {
        return [
            'runtime_status' => $status->toArray(),
            'is_genuinely_running' => $status->isActive,
            'generation_status' => 'pending',
            'execution_status' => 'pending',
            'article_id' => 0,
            'type' => 'create',
            'queue_status' => 'none',
        ];
    }

    private function ago(int $seconds): string
    {
        return $this->now->copy()->subSeconds($seconds)->toIso8601String();
    }
}
