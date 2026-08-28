<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectActionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DB-backed Split semantics (requires SEO_TEST_USE_MYSQL=true + migrated omi_seo_ai).
 */
final class SplitDraftContentProjectIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $articleSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
        ) {
            $this->markTestSkipped('seo_projects tables are not available.');
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_projects', 'source_draft_project_id')) {
            $this->fail('source_draft_project_id migration missing — run local migration first (no SKIP).');
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            $this->fail('planning_reviewed_at column missing — required for reviewed-only split.');
        }

        $this->app->forgetInstance(ContentProjectCommandBus::class);
        $this->app->singleton(ContentProjectCommandBus::class, function ($app): ContentProjectCommandBus {
            $bus = new ContentProjectCommandBus(
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessAuditor::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectOperationLogger::class),
            );
            $app->make(ContentProjectCommandBusRegistrar::class)->register($bus);

            return $bus;
        });
    }

    public function test_split_first_n_only_uses_reviewed_and_leaves_unreviewed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $draft = $this->createDraft(93101, 94101);
        $unreviewed = [];
        for ($i = 0; $i < 5; $i++) {
            $unreviewed[] = $this->createTask($draft, 'u-'.$i, reviewed: false);
        }
        $reviewed = [];
        for ($i = 0; $i < 10; $i++) {
            $reviewed[] = $this->createTask($draft, 'r-'.$i, reviewed: true);
        }

        $splitter = app(SplitDraftContentProjectService::class);
        self::assertSame(15, $splitter->currentDraftItemCount($draft));
        self::assertSame(10, $splitter->currentReviewedDraftItemCount($draft));

        $result = $splitter->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_FIRST_N,
            3,
            [],
            null,
            [88101],
        );

        self::assertSame(3, $result['moved_count']);
        self::assertSame(12, $result['remaining_count']);
        self::assertSame(88101, (int) $result['assignee_id']);
        self::assertFalse($result['auto_generate']);
        self::assertTrue($result['has_real_writer'] ?? false);
        self::assertSame('2026-08-01', $result['month']);

        $execution = SeoProject::query()->find($result['execution_project_id']);
        self::assertInstanceOf(SeoProject::class, $execution);
        self::assertSame(88101, (int) $execution->user_id);
        self::assertTrue(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment::hasRealWriter($execution));
        self::assertSame(SeoProject::defaultNameFromMonth('2026-08-01'), (string) $execution->name);
        self::assertSame('2026-08-01', Carbon::parse((string) $execution->month)->format('Y-m-d'));

        $moved = array_slice($reviewed, 0, 3);
        foreach ($moved as $taskId) {
            self::assertSame((int) $execution->id, (int) SeoProjectTask::query()->find($taskId)?->project_id);
        }
        foreach ($unreviewed as $taskId) {
            self::assertSame((int) $draft->id, (int) SeoProjectTask::query()->find($taskId)?->project_id);
        }
        foreach (array_slice($reviewed, 3) as $taskId) {
            self::assertSame((int) $draft->id, (int) SeoProjectTask::query()->find($taskId)?->project_id);
        }

        Carbon::setTestNow();
    }

    public function test_62_items_three_empty_users_create_30_30_2(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $draft = $this->createDraft(93111, 94111);
        $ids = [];
        for ($i = 0; $i < 62; $i++) {
            $ids[] = $this->createTask($draft, 'm-'.$i, reviewed: true);
        }

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88111, 88112, 88113],
        );

        self::assertSame(3, count($result['execution_project_ids']));
        self::assertSame(62, $result['moved_count']);
        self::assertSame(0, $result['remaining_count']);
        self::assertSame(ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS, $result['max_writer_monthly_items']);
        self::assertSame([30, 30, 2], array_column($result['projects'], 'moved_count'));
        self::assertSame([88111, 88112, 88113], array_column($result['projects'], 'user_id'));

        $p1 = SeoProject::query()->find($result['execution_project_ids'][0]);
        $p2 = SeoProject::query()->find($result['execution_project_ids'][1]);
        $p3 = SeoProject::query()->find($result['execution_project_ids'][2]);
        self::assertInstanceOf(SeoProject::class, $p1);
        self::assertInstanceOf(SeoProject::class, $p2);
        self::assertInstanceOf(SeoProject::class, $p3);
        self::assertSame('2026-08-01', Carbon::parse((string) $p1->month)->format('Y-m-d'));
        self::assertSame(30, $p1->registeredTaskCount());
        self::assertSame(30, $p2->registeredTaskCount());
        self::assertSame(2, $p3->registeredTaskCount());
        self::assertSame(88111, (int) $p1->user_id);
        self::assertSame(88112, (int) $p2->user_id);
        self::assertSame(88113, (int) $p3->user_id);
        self::assertSame(SeoProject::defaultNameFromMonth('2026-08-01'), (string) $p1->name);
        self::assertSame(SeoProject::defaultNameFromMonth('2026-08-01').'-2', (string) $p2->name);
        self::assertSame(SeoProject::defaultNameFromMonth('2026-08-01').'-3', (string) $p3->name);

        foreach (array_slice($ids, 0, 30) as $taskId) {
            self::assertSame((int) $p1->id, (int) SeoProjectTask::query()->find($taskId)?->project_id);
        }
        foreach (array_slice($ids, 30, 30) as $taskId) {
            self::assertSame((int) $p2->id, (int) SeoProjectTask::query()->find($taskId)?->project_id);
        }
        foreach (array_slice($ids, 60) as $taskId) {
            self::assertSame((int) $p3->id, (int) SeoProjectTask::query()->find($taskId)?->project_id);
        }

        Carbon::setTestNow();
    }

    public function test_allocation_sizes_follow_selected_writer_capacity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $cases = [
            7 => [[88141], [7]],
            30 => [[88142], [30]],
            31 => [[88143, 88144], [30, 1]],
            60 => [[88145, 88146], [30, 30]],
            90 => [[88147, 88148, 88149], [30, 30, 30]],
        ];

        $siteBase = 94200;
        foreach ($cases as $total => [$writerIds, $expectedCounts]) {
            $draft = $this->createDraft(93200 + $total, $siteBase + $total);
            for ($i = 0; $i < $total; $i++) {
                $this->createTask($draft, 'c'.$total.'-'.$i, reviewed: true);
            }

            $result = app(SplitDraftContentProjectService::class)->split(
                $draft,
                SplitDraftContentProjectCommand::MODE_ALL,
                null,
                [],
                null,
                $writerIds,
            );

            self::assertSame($expectedCounts, array_column($result['projects'], 'moved_count'), 'total='.$total);
            self::assertSame($writerIds, array_column($result['projects'], 'user_id'), 'writers total='.$total);
            foreach ($result['projects'] as $row) {
                self::assertSame('2026-08-01', $row['month']);
                self::assertTrue($row['has_real_writer']);
                self::assertGreaterThan(0, (int) $row['user_id']);
            }
        }

        Carbon::setTestNow();
    }

    public function test_naming_continues_existing_suffix_and_skips_holes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10'));

        $draft = $this->createDraft(93120, 94120);
        for ($i = 0; $i < 5; $i++) {
            $this->createTask($draft, 'n-'.$i, reviewed: true);
        }

        SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth('2026-08-01'),
            'user_id' => 88120,
            'site_id' => 94120,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth('2026-08-01').'-3',
            'user_id' => 88120,
            'site_id' => 94120,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88121],
        );

        self::assertSame(
            SeoProject::defaultNameFromMonth('2026-08-01').'-4',
            (string) ($result['projects'][0]['project_name'] ?? ''),
        );

        Carbon::setTestNow();
    }

    public function test_zero_reviewed_cannot_split(): void
    {
        $draft = $this->createDraft(93113, 94113);
        for ($i = 0; $i < 4; $i++) {
            $this->createTask($draft, 'z-'.$i, reviewed: false);
        }

        $this->expectException(InvalidArgumentException::class);
        app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
        );
    }

    public function test_selected_mode_requires_reviewed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93102, 94102);
        $t1 = $this->createTask($draft, 'a', reviewed: false);
        $articleId = $this->uniqueArticleId();
        $t2 = $this->createRewriteTask($draft, $articleId, 'rewrite-'.$articleId, reviewed: true);
        $t3 = $this->createTask($draft, 'c', reviewed: true);

        $originAttrs = [
            'project_id' => (int) $draft->id,
            'project_task_id' => $t2,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT,
            'planner_run_id' => null,
        ];
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_content_project_item_origins', 'source_fingerprint')) {
            $originAttrs['source_fingerprint'] = 'fp-rewrite-'.$articleId;
        }

        $origin = SeoContentProjectItemOrigin::query()->create($originAttrs);

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_SELECTED,
            null,
            [$t2],
            null,
            [88102],
        );

        self::assertSame(1, $result['moved_count']);
        $task = SeoProjectTask::query()->find($t2);
        self::assertSame($t2, (int) $task?->id);
        self::assertSame($articleId, (int) $task?->article_id);
        self::assertSame((int) $result['execution_project_id'], (int) $task?->project_id);

        $origin->refresh();
        self::assertSame((int) $result['execution_project_id'], (int) $origin->project_id);

        self::assertSame((int) $draft->id, (int) SeoProjectTask::query()->find($t1)?->project_id);
        self::assertSame((int) $draft->id, (int) SeoProjectTask::query()->find($t3)?->project_id);

        $this->expectException(InvalidArgumentException::class);
        app(SplitDraftContentProjectService::class)->split(
            $draft->fresh() ?? $draft,
            SplitDraftContentProjectCommand::MODE_SELECTED,
            null,
            [$t1],
        );

        Carbon::setTestNow();
    }

    public function test_activate_all_leaves_draft_plannable_with_unreviewed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93103, 94103);
        for ($i = 0; $i < 3; $i++) {
            $this->createTask($draft, 'un-'.$i, reviewed: false);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->createTask($draft, 'all-'.$i, reviewed: true);
        }

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88103],
        );
        self::assertSame(5, $result['moved_count']);
        self::assertSame(3, $result['remaining_count']);
        $draft->refresh();
        self::assertSame(SeoProject::STATUS_DRAFT, (string) $draft->status);
        self::assertTrue($draft->isDraftPlanning());
        self::assertSame(3, $draft->registeredTaskCount());

        Carbon::setTestNow();
    }

    public function test_command_bus_rejects_non_draft_and_supports_preview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $active = SeoProject::query()->create([
            'name' => 'Active Aug',
            'user_id' => 93104,
            'site_id' => 94104,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        $fail = app(ContentProjectCommandBus::class)->dispatch(
            new SplitDraftContentProjectCommand((int) $active->id, SplitDraftContentProjectCommand::MODE_ALL),
            ActorContext::system('test:split:non-draft'),
        );
        self::assertFalse($fail->success);
        self::assertSame(ContentProjectActionCodes::PROJECT_NOT_DRAFT, $fail->code);

        $draft = $this->createDraft(93105, 94105);
        for ($i = 0; $i < 35; $i++) {
            $this->createTask($draft, 'prev-'.$i, reviewed: true);
        }

        $preview = app(ContentProjectCommandBus::class)->dispatch(
            new SplitDraftContentProjectCommand(
                (int) $draft->id,
                SplitDraftContentProjectCommand::MODE_FIRST_N,
                35,
                [],
                true,
                [88104, 88105],
            ),
            ActorContext::system('test:split:preview'),
        );
        self::assertTrue($preview->success);
        self::assertSame(35, (int) ($preview->metadata['moved_count'] ?? 0));
        self::assertSame(2, (int) ($preview->metadata['project_count'] ?? 0));
        self::assertSame(0, (int) ($preview->metadata['insufficient_slots'] ?? -1));
        self::assertCount(2, $preview->metadata['allocations'] ?? []);
        self::assertSame([30, 5], array_column($preview->metadata['allocations'], 'item_count'));
        self::assertSame([88104, 88105], array_column($preview->metadata['allocations'], 'user_id'));
        self::assertSame(35, $draft->fresh()?->registeredTaskCount());

        Carbon::setTestNow();
    }

    public function test_idempotency_replays_same_result(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93106, 94106);
        for ($i = 0; $i < 6; $i++) {
            $this->createTask($draft, 'idem-'.$i, reviewed: true);
        }

        $key = 'test:split:idem:'.uniqid('', true);
        $cmd = new SplitDraftContentProjectCommand(
            (int) $draft->id,
            SplitDraftContentProjectCommand::MODE_FIRST_N,
            2,
            [],
            false,
            [88106],
        );

        $first = app(ContentProjectCommandBus::class)->dispatch(
            $cmd,
            new ActorContext('system', null, 94106, $key),
        );
        $second = app(ContentProjectCommandBus::class)->dispatch(
            $cmd,
            new ActorContext('system', null, 94106, $key),
        );

        self::assertTrue($first->success, $first->message);
        self::assertTrue($second->success, $second->message);
        self::assertTrue(
            (bool) ($second->metadata['idempotent_replay'] ?? $second->metadata['idempotent'] ?? false),
        );
        self::assertSame(
            (int) ($first->metadata['execution_project_id'] ?? 0),
            (int) ($second->metadata['execution_project_id'] ?? 0),
        );
        self::assertSame(1, SeoProject::query()
            ->where('source_draft_project_id', $draft->id)
            ->count());
        self::assertSame(4, $draft->fresh()?->registeredTaskCount());

        Carbon::setTestNow();
    }

    public function test_execution_generate_gate_enabled_when_real_writer_assigned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93107, 94107);
        $this->createTask($draft, 'gate-create', reviewed: true);

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88107],
        );

        $execution = SeoProject::query()->find($result['execution_project_id']);
        self::assertInstanceOf(SeoProject::class, $execution);
        self::assertSame(88107, (int) $execution->user_id);
        self::assertTrue(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment::hasRealWriter($execution));

        $draftGate = app(ContentProjectProjectGenerationGate::class)->forGenerateWorkingItems($draft->fresh() ?? $draft);
        self::assertFalse($draftGate->enabled);

        $execGate = app(ContentProjectProjectGenerationGate::class)->forGenerateWorkingItems($execution);
        self::assertTrue($execGate->enabled);
        self::assertNotEmpty($execGate->eligibleTaskIds);

        Carbon::setTestNow();
    }

    public function test_delete_unstarted_restores_reviewed_to_source_draft(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93130, 94130);
        for ($i = 0; $i < 2; $i++) {
            $this->createTask($draft, 'keep-'.$i, reviewed: false);
        }
        for ($i = 0; $i < 25; $i++) {
            $this->createTask($draft, 'move-'.$i, reviewed: true);
        }

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88130],
        );

        $execution = SeoProject::query()->find($result['execution_project_id']);
        self::assertInstanceOf(SeoProject::class, $execution);
        self::assertSame(25, $execution->registeredTaskCount());
        self::assertSame(2, $draft->fresh()?->registeredTaskCount());
        self::assertSame(0, app(SplitDraftContentProjectService::class)->currentReviewedDraftItemCount($draft->fresh() ?? $draft));

        $delete = app(SeoProjectTaskMoveService::class)->deleteProject($execution);
        self::assertTrue($delete['deleted']);
        self::assertSame(25, $delete['restored']);

        $draft->refresh();
        self::assertSame(27, $draft->registeredTaskCount());
        self::assertSame(25, app(SplitDraftContentProjectService::class)->currentReviewedDraftItemCount($draft));
        self::assertNull(SeoProject::query()->find($execution->id));

        $restored = SeoProjectTask::query()
            ->where('project_id', $draft->id)
            ->whereNotNull('planning_reviewed_at')
            ->count();
        self::assertSame(25, $restored);

        Carbon::setTestNow();
    }

    public function test_delete_started_execution_is_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93131, 94131);
        $this->createTask($draft, 'run-1', reviewed: true);

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88131],
        );

        $execution = SeoProject::query()->find($result['execution_project_id']);
        self::assertInstanceOf(SeoProject::class, $execution);

        SeoProjectRun::query()->create([
            'project_id' => (int) $execution->id,
            'user_id' => 93131,
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => 1,
            'succeeded' => 0,
            'failed' => 0,
        ]);

        try {
            app(SeoProjectTaskMoveService::class)->deleteProject($execution);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString(
                'đã bắt đầu chạy',
                $e->validator->errors()->first() ?: $e->getMessage(),
            );
        }

        self::assertNotNull(SeoProject::query()->find($execution->id));
        self::assertSame(1, $execution->fresh()?->registeredTaskCount());

        Carbon::setTestNow();
    }

    public function test_existing_workload_and_insufficient_capacity_and_second_project(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $existingA = SeoProject::query()->create([
            'name' => 'existing-a',
            'user_id' => 88201,
            'site_id' => 94201,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        for ($i = 0; $i < 20; $i++) {
            $this->createTask($existingA, 'wa-'.$i, reviewed: true);
        }
        $existingA->syncTotalTasksCounter();

        $existingB = SeoProject::query()->create([
            'name' => 'existing-b',
            'user_id' => 88202,
            'site_id' => 94202,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->createTask($existingB, 'wb-'.$i, reviewed: true);
        }
        $existingB->syncTotalTasksCounter();

        $draft = $this->createDraft(93201, 94301);
        for ($i = 0; $i < 40; $i++) {
            $this->createTask($draft, 'alloc-'.$i, reviewed: true);
        }

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [88201, 88202, 88203],
        );

        self::assertSame([10, 25, 5], array_column($result['projects'], 'moved_count'));
        self::assertSame([88201, 88202, 88203], array_column($result['projects'], 'user_id'));
        self::assertSame(20, $existingA->fresh()?->registeredTaskCount());
        self::assertSame(2, SeoProject::query()->where('user_id', 88201)->whereDate('month', '2026-08-01')->where('status', '!=', SeoProject::STATUS_DRAFT)->count());

        $shortA = SeoProject::query()->create([
            'name' => 'short-a',
            'user_id' => 88221,
            'site_id' => 94221,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        for ($i = 0; $i < 20; $i++) {
            $this->createTask($shortA, 'sa-'.$i, reviewed: true);
        }
        $shortA->syncTotalTasksCounter();

        $shortB = SeoProject::query()->create([
            'name' => 'short-b',
            'user_id' => 88222,
            'site_id' => 94222,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->createTask($shortB, 'sb-'.$i, reviewed: true);
        }
        $shortB->syncTotalTasksCounter();

        $blockedDraft = $this->createDraft(93202, 94302);
        for ($i = 0; $i < 50; $i++) {
            $this->createTask($blockedDraft, 'short-'.$i, reviewed: true);
        }

        $preview = app(SplitDraftContentProjectService::class)->preview(
            $blockedDraft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            [88221, 88222],
        );
        self::assertSame(15, (int) $preview['insufficient_slots']);
        self::assertStringContainsString('15', (string) $preview['insufficient_message']);

        try {
            app(SplitDraftContentProjectService::class)->split(
                $blockedDraft,
                SplitDraftContentProjectCommand::MODE_ALL,
                null,
                [],
                null,
                [88221, 88222],
            );
            self::fail('Expected insufficient capacity');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('15', $e->getMessage());
        }

        $full = SeoProject::query()->create([
            'name' => 'full-user',
            'user_id' => 88210,
            'site_id' => 94210,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        for ($i = 0; $i < 30; $i++) {
            $this->createTask($full, 'full-'.$i, reviewed: true);
        }
        $full->syncTotalTasksCounter();

        $remaining = app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService::class)
            ->remainingByUserId([88210, 88203], '2026-08');
        self::assertSame(0, $remaining[88210]);
        self::assertSame(25, $remaining[88203]);

        $draftIgnore = $this->createDraft(93210, 94310);
        $this->createTask($draftIgnore, 'draft-work', reviewed: true);
        $draftCounts = app(\Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService::class)
            ->itemCountsByUserId([93210], '2026-08');
        self::assertSame(0, $draftCounts[93210]);

        Carbon::setTestNow();
    }

    public function test_system_user_is_stripped_from_create_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $draft = $this->createDraft(93199, 94199);
        $this->createTask($draft, 'sys-1', reviewed: true);

        \App\Services\Users\SeoOpsSystemUser::setCachedIdForTests(4242);

        try {
            app(SplitDraftContentProjectService::class)->split(
                $draft,
                SplitDraftContentProjectCommand::MODE_ALL,
                null,
                [],
                null,
                [4242],
            );
            self::fail('Expected system user to be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        } finally {
            \App\Services\Users\SeoOpsSystemUser::clearCache();
        }

        Carbon::setTestNow();
    }

    private function createDraft(int $userId, int $siteId): SeoProject
    {
        return SeoProject::query()->create([
            'name' => 'Draft plan '.$siteId.' '.uniqid(),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function createTask(SeoProject $project, string $token, bool $reviewed = true): int
    {
        $token = $token.'-'.uniqid();
        $attrs = [
            'project_id' => (int) $project->id,
            'site_id' => (int) $project->site_id,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => $token,
            'keyword' => $token,
            'title' => $token,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => $project->monthCarbon()->format('Y-m-d'),
            'status' => SeoProjectTask::STATUS_PENDING,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'planning_reviewed_at' => $reviewed ? now() : null,
        ];

        $task = SeoProjectTask::query()->create($attrs);

        return (int) $task->id;
    }

    private function createRewriteTask(SeoProject $project, int $articleId, string $token, bool $reviewed = true): int
    {
        $task = SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => (int) $project->site_id,
            'article_id' => $articleId,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'source_content' => (string) $articleId,
            'keyword' => $token,
            'title' => $token,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => $project->monthCarbon()->format('Y-m-d'),
            'status' => SeoProjectTask::STATUS_PENDING,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'planning_reviewed_at' => $reviewed ? now() : null,
        ]);

        return (int) $task->id;
    }

    private function uniqueArticleId(): int
    {
        $this->articleSeq++;

        return 9_100_000 + (int) (microtime(true) * 1000) % 100_000 + $this->articleSeq;
    }
}
