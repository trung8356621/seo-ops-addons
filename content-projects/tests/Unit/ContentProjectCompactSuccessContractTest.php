<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessPlanner;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGeneratorDoneClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectCompactSuccessContractTest extends TestCase
{
    private ContentProjectGeneratorDoneClassifier $classifier;

    private ContentProjectCompactSuccessPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ContentProjectGeneratorDoneClassifier;
        $this->planner = new ContentProjectCompactSuccessPlanner;
    }

    public function test_generator_done_requires_content_and_completed_generation(): void
    {
        self::assertTrue($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Review,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Draft,
        ), true));

        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Review,
            generation: ContentProjectItemGenerationState::Completed,
            review: ContentProjectItemReviewState::Draft,
        ), false));

        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Draft,
            generation: ContentProjectItemGenerationState::Pending,
        ), false));

        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Draft,
            generation: ContentProjectItemGenerationState::Pending,
        ), true));

        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Failed,
            generation: ContentProjectItemGenerationState::Failed,
        ), true));
    }

    public function test_case1_auto_partition_capacity_30(): void
    {
        // 37 generator_done + 21 not_done across 5 projects, capacity 30 → 2 done buckets.
        $taskId = 1;
        $make = function (int $done, int $notDone, int $projectId) use (&$taskId): array {
            $items = [];
            for ($i = 0; $i < $done; $i++) {
                $items[] = $this->item($taskId++, true, true);
            }
            for ($i = 0; $i < $notDone; $i++) {
                $items[] = $this->item($taskId++, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING);
            }

            return $this->project($projectId, 'P'.$projectId, $items);
        };

        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $make(12, 5, 1),
                $make(10, 4, 2),
                $make(8, 5, 3),
                $make(5, 4, 4),
                $make(2, 3, 5),
            ],
        ];

        $plan = $this->planner->plan($scope);

        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
        self::assertSame(30, (int) $plan['capacity']);
        self::assertSame(37, (int) $plan['totals']['generator_done']);
        self::assertSame(21, (int) $plan['totals']['not_done']);
        self::assertCount(2, $plan['done_bucket_project_ids']);
        self::assertTrue($plan['can_execute']);
        self::assertFalse($plan['creates_project']);
        self::assertGreaterThan(0, (int) $plan['totals']['moves']);

        $after = $this->indexByProject($plan['projects_after']);
        $doneIds = $plan['done_bucket_project_ids'];
        $doneTotal = 0;
        foreach ($doneIds as $id) {
            self::assertSame(0, (int) $after[$id]['not_done_count'], 'done bucket must be cleaned of not_done');
            $doneTotal += (int) $after[$id]['generator_done_count'];
            self::assertLessThanOrEqual(30, (int) $after[$id]['total']);
        }
        self::assertSame(37, $doneTotal);
    }

    public function test_month_scope_compacts_generator_done_across_domains(): void
    {
        $scope = [
            'site_id' => 0,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    $this->itemForSite(1, 10, true, true),
                    $this->itemForSite(2, 20, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
                $this->project(2, 'B', [
                    $this->itemForSite(3, 20, true, true),
                    $this->itemForSite(4, 30, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
                $this->project(3, 'C', [
                    $this->itemForSite(5, 30, true, true),
                ]),
            ],
        ];

        $plan = $this->planner->plan($scope);

        self::assertTrue($plan['can_execute']);
        self::assertSame(3, (int) $plan['totals']['generator_done']);
        self::assertSame(2, (int) $plan['totals']['not_done']);
        self::assertSame(
            0,
            (int) $plan['skipped_summary'][ContentProjectCompactSuccessPlanner::SKIP_WRONG_DOMAIN_MONTH],
        );

        $after = $this->indexByProject($plan['projects_after']);
        $doneIds = $plan['done_bucket_project_ids'];
        self::assertCount(1, $doneIds);
        self::assertSame(3, (int) $after[$doneIds[0]]['generator_done_count']);
        self::assertSame(0, (int) $after[$doneIds[0]]['not_done_count']);
    }

    public function test_month_scope_compacts_done_projects_across_writers(): void
    {
        $scope = [
            'site_id' => 0,
            'month' => '2026-08',
            'projects' => [
                $this->projectForWriter(1, 'Yen', 101, $this->many(1, 13, true)),
                $this->projectForWriter(2, 'Trang', 102, $this->many(101, 11, true)),
                $this->projectForWriter(3, 'Uyen', 103, $this->many(201, 12, true)),
                $this->projectForWriter(4, 'Nu', 104, $this->many(301, 11, true)),
                $this->projectForWriter(5, 'Quyen', 105, $this->many(401, 11, false)),
                $this->projectForWriter(6, 'Empty', 106, []),
            ],
        ];

        $plan = $this->planner->plan($scope);
        $after = $this->indexByProject($plan['projects_after']);
        $doneIds = $plan['done_bucket_project_ids'];

        self::assertFalse($plan['preserve_writer']);
        self::assertTrue($plan['can_execute']);
        self::assertSame(47, (int) $plan['totals']['generator_done']);
        self::assertCount(2, $doneIds);
        self::assertSame(0, (int) $plan['skipped_summary'][ContentProjectCompactSuccessPlanner::SKIP_WRONG_DOMAIN_MONTH]);
        self::assertGreaterThanOrEqual(2, (int) $plan['totals']['moves_generator_done']);

        $doneTotal = 0;
        foreach ($doneIds as $id) {
            self::assertSame(0, (int) $after[$id]['not_done_count']);
            self::assertLessThanOrEqual(30, (int) $after[$id]['total']);
            $doneTotal += (int) $after[$id]['generator_done_count'];
        }
        self::assertSame(47, $doneTotal);
    }

    public function test_case2_planner_import_without_content_is_not_done(): void
    {
        self::assertFalse($this->classifier->isGeneratorDone($this->state(
            lifecycle: ContentProjectLifecyclePhase::Draft,
            generation: ContentProjectItemGenerationState::Pending,
        ), false));

        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    $this->item(1, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                    $this->item(2, true, true),
                ]),
                $this->project(2, 'B', [
                    $this->item(3, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
            ],
        ];
        $plan = $this->planner->plan($scope);
        foreach ($plan['moves'] as $move) {
            if ($move['reason'] === ContentProjectCompactSuccessPlanner::REASON_PACK_GENERATOR_DONE) {
                self::assertNotSame(1, (int) $move['task_id']);
                self::assertNotSame(3, (int) $move['task_id']);
            }
        }
        self::assertContains(1, $plan['done_bucket_project_ids']);
    }

    public function test_case3_false_success_missing_content_not_generator_done(): void
    {
        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    $this->item(1, false, false, ContentProjectCompactSuccessPlanner::SKIP_MISSING_CONTENT),
                    $this->item(2, true, true),
                ]),
                $this->project(2, 'B', [
                    $this->item(3, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
            ],
        ];
        $plan = $this->planner->plan($scope);
        foreach ($plan['moves'] as $move) {
            self::assertNotSame(1, (int) $move['task_id']);
        }
        self::assertSame(
            1,
            (int) $plan['skipped_summary'][ContentProjectCompactSuccessPlanner::SKIP_MISSING_CONTENT],
        );
    }

    public function test_case4_active_item_not_moved(): void
    {
        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    [
                        'task_id' => 1,
                        'site_id' => 10,
                        'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                        'generator_done' => true,
                        'movable' => false,
                        'skip_reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING,
                    ],
                    $this->item(2, true, true),
                    $this->item(3, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
                $this->project(2, 'B', [
                    $this->item(4, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
            ],
        ];
        $plan = $this->planner->plan($scope);
        foreach ($plan['moves'] as $move) {
            self::assertNotSame(1, (int) $move['task_id']);
        }
        self::assertSame(
            1,
            (int) $plan['skipped_summary'][ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING],
        );
    }

    public function test_case5_scheduled_published_unsafe_skipped(): void
    {
        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    [
                        'task_id' => 1,
                        'site_id' => 10,
                        'kind' => ContentProjectCompactSuccessPlanner::KIND_UNSAFE_LOCKED,
                        'generator_done' => true,
                        'movable' => false,
                        'skip_reason' => ContentProjectCompactSuccessPlanner::SKIP_SCHEDULED_PUBLISHED,
                    ],
                    $this->item(2, true, true),
                ]),
                $this->project(2, 'B', [
                    $this->item(3, false, true, ContentProjectCompactSuccessPlanner::SKIP_PENDING),
                ]),
            ],
        ];
        $plan = $this->planner->plan($scope);
        foreach ($plan['moves'] as $move) {
            self::assertNotSame(1, (int) $move['task_id']);
        }
        self::assertSame(
            1,
            (int) $plan['skipped_summary'][ContentProjectCompactSuccessPlanner::SKIP_SCHEDULED_PUBLISHED],
        );
    }

    public function test_case6_import_guard_prefers_work_projects(): void
    {
        $packingSrc = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionPackingService::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('listReusableWorkProjects', $packingSrc);
        self::assertStringContainsString('projectHasGeneratorDoneItems', $packingSrc);
        self::assertStringContainsString('listAppendableProjects', $packingSrc);
        self::assertStringContainsString('canAcceptMoreItems', $packingSrc);
    }

    public function test_bidirectional_moves_pack_and_clean(): void
    {
        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    ...$this->many(1, 8, true),
                    ...$this->many(101, 4, false),
                ]),
                $this->project(2, 'B', [
                    ...$this->many(201, 3, true),
                    ...$this->many(301, 6, false),
                ]),
            ],
        ];
        $plan = $this->planner->plan($scope);
        $reasons = array_column($plan['moves'], 'reason');
        self::assertContains(ContentProjectCompactSuccessPlanner::REASON_PACK_GENERATOR_DONE, $reasons);
        self::assertContains(ContentProjectCompactSuccessPlanner::REASON_CLEAN_NOT_DONE, $reasons);
    }

    public function test_no_manual_destination_required(): void
    {
        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'projects' => [
                $this->project(1, 'A', [
                    ...$this->many(1, 4, true),
                    ...$this->many(101, 3, false),
                ]),
                $this->project(2, 'B', [
                    ...$this->many(201, 2, true),
                    ...$this->many(301, 2, false),
                ]),
            ],
        ];
        $plan = $this->planner->plan($scope);
        self::assertArrayNotHasKey('destination_project_id', $plan);
        self::assertNotEmpty($plan['done_bucket_project_ids']);
        self::assertTrue($plan['can_execute']);
        self::assertGreaterThan(0, (int) $plan['totals']['moves']);
    }

    public function test_blocked_active_run_prevents_moves(): void
    {
        $scope = [
            'site_id' => 10,
            'month' => '2026-08',
            'blocked' => true,
            'block_reason' => ContentProjectCompactSuccessPlanner::SKIP_ACTIVE_RUNNING,
            'projects' => [
                $this->project(1, 'A', $this->many(1, 5, true)),
                $this->project(2, 'B', $this->many(101, 3, false)),
            ],
        ];
        $plan = $this->planner->plan($scope);
        self::assertFalse($plan['can_execute']);
        self::assertSame(0, (int) $plan['totals']['moves']);
        self::assertTrue($plan['blocked']);
    }

    public function test_service_and_ui_wired_for_auto_partition(): void
    {
        $serviceSrc = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessService::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('content_project_compact_success:', $serviceSrc);
        self::assertStringContainsString('Cache::lock', $serviceSrc);
        self::assertStringContainsString('transaction', $serviceSrc);
        self::assertStringNotContainsString('destination_project_id', $serviceSrc);
        self::assertStringNotContainsString('includeScheduledPublished', $serviceSrc);
        self::assertStringNotContainsString('SeoProject::query()->create', $serviceSrc);
        self::assertStringNotContainsString('GenerateProjectItems', $serviceSrc);
        self::assertStringNotContainsString("'target_date'", $serviceSrc);

        $listSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ListSeoProjects.php',
        );
        self::assertStringContainsString('compact_success_items', $listSrc);
        self::assertStringContainsString('->preview(0, $month)', $listSrc);
        self::assertStringNotContainsString('destination_project_id', $listSrc);
        self::assertStringNotContainsString('include_scheduled_published', $listSrc);
        self::assertStringContainsString('moved_generator_done', $listSrc);
        // Compact remains month-wide (site_id=0). Domain Select is Balance-only.
        self::assertTrue(
            (bool) preg_match(
                '/function compactSuccessFormSchema\(\): array\s*\{(?P<body>.*?)(?=\n    (?:private|protected|public) function)/s',
                $listSrc,
                $compactForm,
            ),
            'compactSuccessFormSchema() must exist',
        );
        self::assertStringNotContainsString("Select::make('site_id')", $compactForm['body']);
        self::assertStringContainsString('balance_months', $listSrc);
        self::assertStringContainsString("Select::make('site_id')", $listSrc);

        $plannerSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectCompactSuccessPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('pack_generator_done', $plannerSrc);
        self::assertStringContainsString('clean_not_done_from_done_bucket', $plannerSrc);
        self::assertStringContainsString('selectDoneBuckets', $plannerSrc);

        $vi = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/lang/vi/filament.php',
        );
        self::assertStringContainsString("'compact_success' => 'Gom bài đã xong'", $vi);
        self::assertStringContainsString('Chỉ tính bài đã generator xong và có content', $vi);
        self::assertStringNotContainsString('destination_project_id', $listSrc);
    }

    public function test_lock_key_isolates_domain_and_month(): void
    {
        $service = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessService;
        self::assertSame(
            'content_project_compact_success:5:2026-08',
            $service->lockKey(5, '2026-08'),
        );
        self::assertSame(
            'content_project_compact_success:all:2026-08',
            $service->lockKey(0, '2026-08'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function project(int $id, string $name, array $items): array
    {
        return $this->projectForWriter($id, $name, 1, $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function projectForWriter(int $id, string $name, int $writerId, array $items): array
    {
        return [
            'project_id' => $id,
            'name' => 'Project '.$name,
            'writer_id' => $writerId,
            'writer_name' => 'Writer '.$writerId,
            'archived' => false,
            'items' => $items,
        ];
    }

    /**
     * @return array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}
     */
    private function item(int $id, bool $generatorDone, bool $movable, ?string $skipReason = null): array
    {
        return $this->itemForSite($id, 10, $generatorDone, $movable, $skipReason);
    }

    /**
     * @return array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}
     */
    private function itemForSite(
        int $id,
        int $siteId,
        bool $generatorDone,
        bool $movable,
        ?string $skipReason = null,
    ): array
    {
        return [
            'task_id' => $id,
            'site_id' => $siteId,
            'kind' => $generatorDone
                ? ContentProjectCompactSuccessPlanner::KIND_GENERATOR_DONE
                : ContentProjectCompactSuccessPlanner::KIND_NOT_DONE,
            'generator_done' => $generatorDone,
            'movable' => $movable,
            'skip_reason' => $skipReason,
        ];
    }

    /**
     * @return list<array{task_id: int, site_id: int, kind: string, generator_done: bool, movable: bool, skip_reason: string|null}>
     */
    private function many(int $startId, int $count, bool $generatorDone): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->item(
                $startId + $i,
                $generatorDone,
                true,
                $generatorDone ? null : ContentProjectCompactSuccessPlanner::SKIP_PENDING,
            );
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexByProject(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['project_id']] = $row;
        }

        return $out;
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
