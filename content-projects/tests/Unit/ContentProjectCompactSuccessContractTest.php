<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemDashboardBucket;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessPlanner;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectAuditSuccessClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectCompactSuccessContractTest extends TestCase
{
    private ContentProjectAuditSuccessClassifier $classifier;

    private ContentProjectCompactSuccessPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ContentProjectAuditSuccessClassifier;
        $this->planner = new ContentProjectCompactSuccessPlanner;
    }

    public function test_classifier_counts_success_only(): void
    {
        self::assertTrue($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Review,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Draft,
        )));
        self::assertTrue($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Approved,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Approved,
        )));
        self::assertTrue($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::WaitingPublish,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Approved,
            publish: ContentProjectItemPublishState::Scheduled,
        )));
        self::assertTrue($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Published,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Approved,
            publish: ContentProjectItemPublishState::Published,
            hasPublished: true,
        )));

        self::assertFalse($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Draft,
            generation: ContentProjectItemGenerationState::Pending,
        )));
        self::assertFalse($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Generating,
            generation: ContentProjectItemGenerationState::Writing,
        )));
        self::assertFalse($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Failed,
            generation: ContentProjectItemGenerationState::Failed,
        )));
        self::assertFalse($this->classifier->isAuditSuccessFromBucket(ContentProjectItemDashboardBucket::WaitingAi));
        self::assertFalse($this->classifier->isAuditSuccessFromBucket(ContentProjectItemDashboardBucket::AiRunning));
        self::assertFalse($this->classifier->isAuditSuccessFromBucket(ContentProjectItemDashboardBucket::Failed));
        self::assertTrue($this->classifier->isAuditSuccessFromBucket(ContentProjectItemDashboardBucket::WaitingReview));
    }

    public function test_published_with_rerun_still_audit_success(): void
    {
        self::assertTrue($this->classifier->isAuditSuccess($this->state(
            lifecycle: ContentProjectLifecyclePhase::Published,
            generation: ContentProjectItemGenerationState::Writing,
            review: ContentProjectItemReviewState::Approved,
            publish: ContentProjectItemPublishState::Published,
            hasPublished: true,
        )));
    }

    public function test_compact_creates_no_project_and_packs_highest_first(): void
    {
        $scope = $this->exampleScope();
        $plan = $this->planner->plan($scope);

        self::assertFalse($plan['creates_project']);
        self::assertFalse($plan['archives_project']);
        self::assertSame(count($scope['projects']), (int) $plan['totals']['projects']);
        self::assertSame(36, (int) $plan['totals']['success']);
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
        self::assertSame(30, (int) $plan['capacity']);

        $afterById = [];
        foreach ($plan['projects_after'] as $row) {
            $afterById[(int) $row['project_id']] = $row;
        }

        self::assertSame(30, (int) $afterById[1]['success_count']);
        self::assertSame(0, (int) $afterById[1]['non_success_count']);
        self::assertTrue($afterById[1]['audit_ready']);

        self::assertSame(6, (int) $afterById[2]['success_count']);
        self::assertSame(0, (int) $afterById[2]['non_success_count']);

        $nonTargetNonSuccess = (int) $afterById[3]['non_success_count'] + (int) $afterById[4]['non_success_count'];
        self::assertSame(16, $nonTargetNonSuccess);

        // Project count unchanged.
        self::assertCount(4, $plan['projects_after']);
        self::assertCount(4, $plan['projects_before']);
    }

    public function test_failed_and_pending_not_counted_as_success(): void
    {
        $plan = $this->planner->plan($this->exampleScope());
        self::assertSame(36, (int) $plan['totals']['success']);
        self::assertSame(16, (int) $plan['totals']['non_success']);
    }

    public function test_running_items_skipped_not_moved(): void
    {
        $scope = $this->exampleScope();
        // Mark one success in B as not movable (active_dispatch).
        $scope['projects'][1]['items'][0]['movable'] = false;
        $scope['projects'][1]['items'][0]['skip_reason'] = 'active_dispatch';
        $lockedId = (int) $scope['projects'][1]['items'][0]['task_id'];

        $plan = $this->planner->plan($scope);
        foreach ($plan['moves'] as $move) {
            self::assertNotSame($lockedId, (int) $move['task_id']);
        }
        self::assertNotEmpty($plan['skipped']);
        self::assertSame($lockedId, (int) $plan['skipped'][0]['task_id']);
    }

    public function test_active_editor_skip_reason_preserved(): void
    {
        $scope = $this->exampleScope();
        $scope['projects'][0]['items'][0]['movable'] = false;
        $scope['projects'][0]['items'][0]['skip_reason'] = 'active_editor_session';
        $plan = $this->planner->plan($scope);
        self::assertSame('active_editor_session', $plan['skipped'][0]['reason']);
    }

    public function test_cross_domain_items_never_mixed_into_success_counts(): void
    {
        $scope = $this->exampleScope();
        $scope['projects'][0]['items'][] = [
            'task_id' => 9001,
            'site_id' => 99,
            'success' => true,
            'movable' => true,
            'skip_reason' => null,
        ];
        $plan = $this->planner->plan($scope);
        // Foreign success must not inflate scoped success total.
        self::assertSame(36, (int) $plan['totals']['success']);
        foreach ($plan['moves'] as $move) {
            self::assertNotSame(9001, (int) $move['task_id']);
        }
    }

    public function test_writer_preservation_default_no_cross_writer_moves(): void
    {
        $scope = $this->exampleScope();
        // Writer 2 owns project D only.
        $scope['projects'][3]['writer_id'] = 2;
        $scope['projects'][3]['writer_name'] = 'Writer B';

        $plan = $this->planner->plan($scope);
        foreach ($plan['moves'] as $move) {
            $from = (int) $move['from_project_id'];
            $to = (int) $move['to_project_id'];
            $fromWriter = $from === 4 ? 2 : 1;
            $toWriter = $to === 4 ? 2 : 1;
            self::assertSame($fromWriter, $toWriter);
        }
        self::assertTrue($plan['preserve_writer']);
    }

    public function test_idempotent_when_already_compacted(): void
    {
        $first = $this->planner->plan($this->exampleScope());
        $after = $this->rebuildScopeFromPlan($this->exampleScope(), $first);
        $second = $this->planner->plan($after);

        self::assertTrue($second['already_compacted'] || (int) $second['totals']['moves'] === 0);
        self::assertSame(0, (int) $second['totals']['moves']);
    }

    public function test_capacity_rule_uses_execution_limit(): void
    {
        self::assertSame(
            ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
            $this->planner->capacity(),
        );
    }

    public function test_service_and_ui_wired_without_new_project_or_ai(): void
    {
        $serviceSrc = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessService::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('content_project_compact_success:', $serviceSrc);
        self::assertStringContainsString('Cache::lock', $serviceSrc);
        self::assertStringContainsString('transaction', $serviceSrc);
        self::assertStringNotContainsString('SeoProject::query()->create', $serviceSrc);
        self::assertStringNotContainsString('GenerateProjectItems', $serviceSrc);
        self::assertStringContainsString('archives_project', $serviceSrc);
        self::assertStringContainsString('creates_project', $serviceSrc);
        self::assertMatchesRegularExpression("/\\['archives_project'\\]\\s*=\\s*false/", $serviceSrc);

        $listSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ListSeoProjects.php',
        );
        self::assertStringContainsString('compact_success_items', $listSrc);
        self::assertStringContainsString('ContentProjectCompactSuccessService', $listSrc);

        $classifierSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAuditSuccessClassifier::class))->getFileName(),
        );
        self::assertStringContainsString('isAuditSuccess', $classifierSrc);
    }

    public function test_lock_key_isolates_domain_and_month(): void
    {
        $service = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessService;
        self::assertSame(
            'content_project_compact_success:5:2026-08',
            $service->lockKey(5, '2026-08'),
        );
        self::assertNotSame(
            $service->lockKey(5, '2026-08'),
            $service->lockKey(5, '2026-09'),
        );
        self::assertNotSame(
            $service->lockKey(5, '2026-08'),
            $service->lockKey(6, '2026-08'),
        );
    }

    /**
     * Spec example: A13s+5ns, B11s+1ns, C8s+7ns, D4s+3ns.
     *
     * @return array{site_id: int, month: string, projects: list<array<string, mixed>>}
     */
    private function exampleScope(): array
    {
        $taskId = 1;
        $make = static function (int $success, int $nonSuccess, int $projectId) use (&$taskId): array {
            $items = [];
            for ($i = 0; $i < $success; $i++) {
                $items[] = [
                    'task_id' => $taskId++,
                    'site_id' => 10,
                    'success' => true,
                    'movable' => true,
                    'skip_reason' => null,
                ];
            }
            for ($i = 0; $i < $nonSuccess; $i++) {
                $items[] = [
                    'task_id' => $taskId++,
                    'site_id' => 10,
                    'success' => false,
                    'movable' => true,
                    'skip_reason' => null,
                ];
            }

            return [
                'project_id' => $projectId,
                'name' => 'Project '.chr(64 + $projectId),
                'writer_id' => 1,
                'writer_name' => 'Writer A',
                'archived' => false,
                'items' => $items,
            ];
        };

        return [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $make(13, 5, 1),
                $make(11, 1, 2),
                $make(8, 7, 3),
                $make(4, 3, 4),
            ],
        ];
    }

    /**
     * @param  array{site_id: int, month: string, projects: list<array<string, mixed>>}  $scope
     * @param  array<string, mixed>  $plan
     * @return array{site_id: int, month: string, projects: list<array<string, mixed>>}
     */
    private function rebuildScopeFromPlan(array $scope, array $plan): array
    {
        $byProject = [];
        foreach ($scope['projects'] as $project) {
            $byProject[(int) $project['project_id']] = $project;
            $byProject[(int) $project['project_id']]['items'] = [];
        }

        $itemMap = [];
        foreach ($scope['projects'] as $project) {
            foreach ($project['items'] as $item) {
                $itemMap[(int) $item['task_id']] = [
                    'item' => $item,
                    'project_id' => (int) $project['project_id'],
                ];
            }
        }

        foreach ($plan['moves'] as $move) {
            $taskId = (int) $move['task_id'];
            if (! isset($itemMap[$taskId])) {
                continue;
            }
            $itemMap[$taskId]['project_id'] = (int) $move['to_project_id'];
        }

        foreach ($itemMap as $row) {
            $pid = (int) $row['project_id'];
            $byProject[$pid]['items'][] = $row['item'];
        }

        $scope['projects'] = array_values($byProject);

        return $scope;
    }

    private function state(
        ContentProjectLifecyclePhase $lifecycle,
        ContentProjectItemGenerationState $generation,
        ContentProjectItemReviewState $review = ContentProjectItemReviewState::None,
        ContentProjectItemPublishState $publish = ContentProjectItemPublishState::None,
        bool $hasPublished = false,
    ): ContentProjectItemState {
        return new ContentProjectItemState(
            lifecycleState: $lifecycle,
            generationState: $generation,
            reviewState: $review,
            publishState: $publish,
            executionState: ContentProjectItemExecutionState::Idle,
            archiveState: ContentProjectItemArchiveState::None,
            availableActions: [],
            blockingReason: null,
            currentError: null,
            currentErrorSource: ContentProjectItemErrorSource::None,
            hasPublishedRevision: $hasPublished,
        );
    }
}
