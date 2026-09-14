<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\Carbon;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRuntimeStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFailedOpsDefinition;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemOpsEvidencePresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectRunItemEvidenceIndex;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression: lazy-bulk membership must not shadow real execution in the ops read-model.
 *
 * Path: EvidenceIndex::partition → ItemOpsEvidencePresenter → classifier → badge
 * (same presenter mapRow uses).
 */
final class ContentProjectLazyBulkReadModelEvidenceTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-09-14T10:00:00+00:00');
    }

    public function test_scenario_1_failed_historical_survives_newer_membership(): void
    {
        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(200, 1, 11, 'pending', lazyBulk: true),
            $this->item(100, 1, 10, 'failed', lazyBulk: false, finished: true),
        ], null, 11);

        $row = $this->present(
            SeoProjectTask::STATUS_FAILED,
            $partition['latest_execution_by_task'][1] ?? null,
            $partition['current_membership_by_task'][1] ?? null,
            null,
            11,
        );

        self::assertSame('failed', $row['generation_key']);
        self::assertSame('failed', $row['execution_status']);
        self::assertSame('failed', $row['generation_badge']['key']);
        self::assertStringNotContainsStringIgnoringCase('chờ', (string) ($row['generation_badge']['label'] ?? ''));
    }

    public function test_scenario_2_completed_historical_survives_newer_membership(): void
    {
        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(201, 2, 11, 'pending', lazyBulk: true),
            $this->item(100, 2, 10, 'success', lazyBulk: false, finished: true),
        ], null, 11);

        $row = $this->present(
            SeoProjectTask::STATUS_COMPLETED,
            $partition['latest_execution_by_task'][2] ?? null,
            $partition['current_membership_by_task'][2] ?? null,
            null,
            11,
        );

        self::assertSame('generated', $row['generation_key']);
        self::assertSame('success', $row['generation_badge']['key']);
    }

    public function test_scenario_3_never_generated_membership_is_chua_chay_not_cho(): void
    {
        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(202, 3, 11, 'pending', lazyBulk: true),
        ], null, 11);

        $row = $this->present(
            SeoProjectTask::STATUS_PENDING,
            $partition['latest_execution_by_task'][3] ?? null,
            $partition['current_membership_by_task'][3] ?? null,
            null,
            11,
        );

        self::assertNull($partition['latest_execution_by_task'][3] ?? null);
        self::assertSame('not_started', $row['generation_key']);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_NO_ACTIVE_EXECUTION, $row['runtime_state']);
        self::assertSame('Chưa chạy', $row['runtime_label']);
        self::assertSame('not_started', $row['generation_badge']['key']);
        self::assertSame('Chưa chạy', $row['generation_badge']['label']);
    }

    public function test_scenario_4_active_dispatch_on_membership_shows_waiting_worker(): void
    {
        $dispatch = [
            'run_item_id' => 203,
            'task_id' => 4,
            'claimed_at' => null,
            'dispatched_at' => $this->now->copy()->subSeconds(8)->toIso8601String(),
            'last_heartbeat_at' => $this->now->copy()->subSeconds(8)->toIso8601String(),
            'current_step' => 'queued',
        ];
        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(203, 4, 11, 'pending', lazyBulk: true),
            $this->item(100, 4, 10, 'failed', lazyBulk: false, finished: true),
        ], $dispatch, 11);

        $row = $this->present(
            SeoProjectTask::STATUS_FAILED,
            $partition['latest_execution_by_task'][4] ?? null,
            $partition['current_membership_by_task'][4] ?? null,
            $dispatch,
            11,
        );

        self::assertSame('failed', $partition['latest_execution_by_task'][4]['status']);
        self::assertSame('queued', $row['generation_key']);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_QUEUED, $row['runtime_state']);
        self::assertSame('Đang chờ worker', $row['runtime_label']);
        self::assertSame('failed', $row['execution_status']);
    }

    public function test_scenario_5_only_dispatched_item_gets_queued_runtime(): void
    {
        $dispatch = [
            'run_item_id' => 301,
            'task_id' => 1,
            'claimed_at' => null,
            'dispatched_at' => $this->now->copy()->subSeconds(5)->toIso8601String(),
            'current_step' => 'queued',
        ];

        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(304, 4, 50, 'pending', lazyBulk: true),
            $this->item(303, 3, 50, 'pending', lazyBulk: true),
            $this->item(302, 2, 50, 'pending', lazyBulk: true),
            $this->item(301, 1, 50, 'pending', lazyBulk: true),
            $this->item(203, 3, 40, 'failed', lazyBulk: false, finished: true),
            $this->item(202, 2, 40, 'success', lazyBulk: false, finished: true),
            $this->item(201, 1, 40, 'failed', lazyBulk: false, finished: true),
        ], $dispatch, 50);

        $rows = [];
        foreach ([1, 2, 3, 4] as $taskId) {
            $status = match ($taskId) {
                2 => SeoProjectTask::STATUS_COMPLETED,
                4 => SeoProjectTask::STATUS_PENDING,
                default => SeoProjectTask::STATUS_FAILED,
            };
            $rows[$taskId] = $this->present(
                $status,
                $partition['latest_execution_by_task'][$taskId] ?? null,
                $partition['current_membership_by_task'][$taskId] ?? null,
                $dispatch,
                50,
            );
        }

        self::assertSame('queued', $rows[1]['generation_key']);
        self::assertSame('generated', $rows[2]['generation_key']);
        self::assertSame('failed', $rows[3]['generation_key']);
        self::assertSame('not_started', $rows[4]['generation_key']);
    }

    public function test_scenario_6_after_item1_success_only_item2_is_runtime_active(): void
    {
        $dispatch = [
            'run_item_id' => 402,
            'task_id' => 2,
            'claimed_at' => null,
            'dispatched_at' => $this->now->copy()->subSeconds(3)->toIso8601String(),
            'current_step' => 'queued',
        ];

        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(402, 2, 60, 'pending', lazyBulk: true),
            $this->item(401, 1, 60, 'success', lazyBulk: true, finished: true),
            $this->item(302, 2, 50, 'failed', lazyBulk: false, finished: true),
            $this->item(301, 1, 50, 'failed', lazyBulk: false, finished: true),
        ], $dispatch, 60);

        $row1 = $this->present(
            SeoProjectTask::STATUS_COMPLETED,
            $partition['latest_execution_by_task'][1] ?? null,
            $partition['current_membership_by_task'][1] ?? null,
            $dispatch,
            60,
        );
        $row2 = $this->present(
            SeoProjectTask::STATUS_FAILED,
            $partition['latest_execution_by_task'][2] ?? null,
            $partition['current_membership_by_task'][2] ?? null,
            $dispatch,
            60,
        );

        self::assertSame('success', (string) ($partition['latest_execution_by_task'][1]['status'] ?? ''));
        self::assertSame('generated', $row1['generation_key']);
        self::assertSame('queued', $row2['generation_key']);
        self::assertSame(ContentProjectArticleRuntimeStatus::STATE_QUEUED, $row2['runtime_state']);
    }

    public function test_refresh_sequence_a_failed_b_dispatched_c_never_keeps_failed_filter(): void
    {
        // Exact production sequence after F5 rebuild from DB evidence:
        // A(#3341) failed historically; current membership pending (unvisited)
        // B(#3343) owns active_dispatch (queued)
        // C(#3344) never executed — membership only
        $dispatch = [
            'run_item_id' => 33430,
            'task_id' => 3343,
            'claimed_at' => null,
            'dispatched_at' => $this->now->copy()->subSeconds(4)->toIso8601String(),
            'last_heartbeat_at' => $this->now->copy()->subSeconds(4)->toIso8601String(),
            'current_step' => 'queued',
        ];

        // Cover both: settings.lazy_bulk stripped (pre-fix) AND lazy_bulk=true.
        foreach ([false, true] as $lazyBulkFlag) {
            $partition = ContentProjectRunItemEvidenceIndex::partition([
                $this->item(33440, 3344, 900, 'pending', lazyBulk: $lazyBulkFlag),
                $this->item(33430, 3343, 900, 'pending', lazyBulk: $lazyBulkFlag),
                $this->item(33410, 3341, 900, 'pending', lazyBulk: $lazyBulkFlag),
                $this->item(33411, 3341, 800, 'failed', lazyBulk: false, finished: true),
            ], $dispatch, 900);

            self::assertSame(
                'failed',
                $partition['latest_execution_by_task'][3341]['status'] ?? null,
                'A latest_execution must remain historical failed (lazy_bulk='.($lazyBulkFlag ? '1' : '0').')',
            );
            self::assertSame(33410, $partition['current_membership_by_task'][3341]['id']);
            self::assertArrayNotHasKey(3343, $partition['latest_execution_by_task']);
            self::assertArrayNotHasKey(3344, $partition['latest_execution_by_task']);

            $rowA = $this->present(
                SeoProjectTask::STATUS_FAILED,
                $partition['latest_execution_by_task'][3341] ?? null,
                $partition['current_membership_by_task'][3341] ?? null,
                $dispatch,
                900,
            );
            $rowB = $this->present(
                SeoProjectTask::STATUS_PENDING,
                $partition['latest_execution_by_task'][3343] ?? null,
                $partition['current_membership_by_task'][3343] ?? null,
                $dispatch,
                900,
            );
            $rowC = $this->present(
                SeoProjectTask::STATUS_PENDING,
                $partition['latest_execution_by_task'][3344] ?? null,
                $partition['current_membership_by_task'][3344] ?? null,
                $dispatch,
                900,
            );

            self::assertSame('failed', $rowA['generation_key']);
            self::assertSame('failed', $rowA['execution_status']);
            self::assertTrue(ContentProjectFailedOpsDefinition::matches([
                'generation_status' => $rowA['generation_status'],
                'execution_status' => $rowA['execution_status'],
                'runtime_status' => $rowA['runtime_status'],
                'is_genuinely_running' => $rowA['is_genuinely_running'],
            ]));

            self::assertSame('queued', $rowB['generation_key']);
            self::assertSame(ContentProjectArticleRuntimeStatus::STATE_QUEUED, $rowB['runtime_state']);
            self::assertSame('Đang chờ worker', $rowB['runtime_label']);

            self::assertSame('not_started', $rowC['generation_key']);
            self::assertSame('Chưa chạy', $rowC['runtime_label']);
            self::assertNotSame('queued', $rowC['generation_key']);
        }
    }

    public function test_pending_without_timestamps_never_enters_latest_execution_even_when_lazy_bulk_missing(): void
    {
        $partition = ContentProjectRunItemEvidenceIndex::partition([
            $this->item(20, 3341, 2, 'pending', lazyBulk: false),
            $this->item(10, 3341, 1, 'failed', lazyBulk: false, finished: true),
        ], null, 2);

        self::assertSame('failed', $partition['latest_execution_by_task'][3341]['status']);
        self::assertFalse(ContentProjectRunItemEvidenceIndex::countsAsLatestExecution(
            $partition['current_membership_by_task'][3341],
            false,
            null,
        ));
    }

    public function test_read_model_wires_evidence_index_into_map_row(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('runItemEvidenceByTaskIds', $src);
        self::assertStringContainsString('ContentProjectRunItemEvidenceIndex::partition', $src);
        self::assertStringContainsString('ContentProjectItemOpsEvidencePresenter::present', $src);
        self::assertStringContainsString('latest_execution_by_task', $src);
        self::assertStringContainsString('current_membership_by_task', $src);
        self::assertStringNotContainsString('function latestRunItemsByTaskIds', $src);
    }

    /**
     * @param  array<string, mixed>|null  $latestExecution
     * @param  array<string, mixed>|null  $currentMembership
     * @param  array<string, mixed>|null  $activeDispatch
     * @return array<string, mixed>
     */
    private function present(
        string $taskStatus,
        ?array $latestExecution,
        ?array $currentMembership,
        ?array $activeDispatch,
        int $runId,
    ): array {
        return ContentProjectItemOpsEvidencePresenter::present(
            $taskStatus,
            $latestExecution,
            $currentMembership,
            [
                'run_id' => $runId,
                'run_status' => SeoProjectRun::STATUS_RUNNING,
                'active_dispatch' => $activeDispatch,
                'ai_transient_retry' => null,
                'processing_count' => 0,
                'has_dispatch_tracking' => true,
                'now' => $this->now,
                'heartbeat_stale_seconds' => 1200,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        int $id,
        int $taskId,
        int $runId,
        string $status,
        bool $lazyBulk,
        bool $finished = false,
    ): array {
        return [
            'id' => $id,
            'task_id' => $taskId,
            'run_id' => $runId,
            'status' => $status,
            'action' => $status === 'failed' ? 'article.content.generate' : null,
            'attempt' => 1,
            'error_message' => $status === 'failed' ? 'provider error' : null,
            'message' => null,
            'started_at' => $finished ? '01/09/2026 10:00' : null,
            'started_at_iso' => $finished ? '2026-09-01T10:00:00+00:00' : null,
            'finished_at' => $finished ? '01/09/2026 10:05' : null,
            'finished_at_iso' => $finished ? '2026-09-01T10:05:00+00:00' : null,
            'lazy_bulk' => $lazyBulk,
        ];
    }
}
