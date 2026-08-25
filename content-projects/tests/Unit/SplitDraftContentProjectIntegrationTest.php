<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
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

        // PHPUnit may resolve CommandBus without SeoContentAi singleton registration.
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

    public function test_split_first_n_moves_task_ids_and_keeps_draft(): void
    {
        $draft = $this->createDraft(93101, 94101);
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->createTask($draft, 'kw-'.$i);
        }

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_FIRST_N,
            3,
            [],
            '2026-09-01',
            'Content execution — 09/2026',
            93101,
        );

        self::assertSame(3, $result['moved_count']);
        self::assertSame(7, $result['remaining_count']);
        self::assertSame(SeoProject::STATUS_PENDING, $result['status']);

        $execution = SeoProject::query()->find($result['execution_project_id']);
        self::assertInstanceOf(SeoProject::class, $execution);
        self::assertSame((int) $draft->id, (int) $execution->source_draft_project_id);
        self::assertSame(SeoProject::STATUS_PENDING, (string) $execution->status);

        $moved = array_slice($ids, 0, 3);
        foreach ($moved as $taskId) {
            $task = SeoProjectTask::query()->find($taskId);
            self::assertInstanceOf(SeoProjectTask::class, $task);
            self::assertSame((int) $execution->id, (int) $task->project_id);
            self::assertSame($taskId, (int) $task->id);
        }

        $draft->refresh();
        self::assertSame(SeoProject::STATUS_DRAFT, (string) $draft->status);
        self::assertSame(7, $draft->registeredTaskCount());
        self::assertSame(3, $execution->registeredTaskCount());
    }

    public function test_selected_mode_and_rewrite_article_unique(): void
    {
        $draft = $this->createDraft(93102, 94102);
        $t1 = $this->createTask($draft, 'a');
        $articleId = $this->uniqueArticleId();
        $t2 = $this->createRewriteTask($draft, $articleId, 'rewrite-'.$articleId);
        $t3 = $this->createTask($draft, 'c');

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
            '2026-09-01',
            null,
            93102,
        );

        self::assertSame(1, $result['moved_count']);
        $task = SeoProjectTask::query()->find($t2);
        self::assertSame($t2, (int) $task?->id);
        self::assertSame($articleId, (int) $task?->article_id);
        self::assertSame((int) $result['execution_project_id'], (int) $task?->project_id);

        $origin->refresh();
        self::assertSame((int) $result['execution_project_id'], (int) $origin->project_id);
        self::assertSame($t2, (int) $origin->project_task_id);
        self::assertSame(SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT, (string) $origin->source_type);

        self::assertSame((int) $draft->id, (int) SeoProjectTask::query()->find($t1)?->project_id);
        self::assertSame((int) $draft->id, (int) SeoProjectTask::query()->find($t3)?->project_id);
    }

    public function test_activate_all_leaves_draft_plannable(): void
    {
        $draft = $this->createDraft(93103, 94103);
        for ($i = 0; $i < 5; $i++) {
            $this->createTask($draft, 'all-'.$i);
        }

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            '2026-09-01',
            null,
            93103,
        );

        self::assertSame(5, $result['moved_count']);
        self::assertSame(0, $result['remaining_count']);
        $draft->refresh();
        self::assertSame(SeoProject::STATUS_DRAFT, (string) $draft->status);
        self::assertTrue($draft->isDraftPlanning());
        self::assertSame(0, $draft->registeredTaskCount());
    }

    public function test_command_bus_rejects_non_draft_and_supports_preview(): void
    {
        $active = SeoProject::query()->create([
            'name' => 'Active Sep',
            'user_id' => 93104,
            'site_id' => 94104,
            'month' => '2026-09-01',
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
        for ($i = 0; $i < 4; $i++) {
            $this->createTask($draft, 'prev-'.$i);
        }

        $preview = app(ContentProjectCommandBus::class)->dispatch(
            new SplitDraftContentProjectCommand(
                (int) $draft->id,
                SplitDraftContentProjectCommand::MODE_FIRST_N,
                2,
                [],
                '2026-09-01',
                null,
                true,
            ),
            ActorContext::system('test:split:preview'),
        );
        self::assertTrue($preview->success);
        self::assertSame(2, (int) ($preview->metadata['moved_count'] ?? 0));
        self::assertSame(4, $draft->fresh()?->registeredTaskCount());
    }

    public function test_idempotency_replays_same_result(): void
    {
        $draft = $this->createDraft(93106, 94106);
        for ($i = 0; $i < 6; $i++) {
            $this->createTask($draft, 'idem-'.$i);
        }

        $key = 'test:split:idem:'.uniqid('', true);
        $cmd = new SplitDraftContentProjectCommand(
            (int) $draft->id,
            SplitDraftContentProjectCommand::MODE_FIRST_N,
            2,
            [],
            '2026-09-01',
            'Idem exec',
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
    }

    public function test_execution_generate_gate_allows_pending_after_split(): void
    {
        $draft = $this->createDraft(93107, 94107);
        $this->createTask($draft, 'gate-create');

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            '2026-09-01',
            null,
            93107,
        );

        $execution = SeoProject::query()->find($result['execution_project_id']);
        self::assertInstanceOf(SeoProject::class, $execution);

        $draftGate = app(ContentProjectProjectGenerationGate::class)->forGenerateWorkingItems($draft->fresh() ?? $draft);
        self::assertFalse($draftGate->enabled);

        $execGate = app(ContentProjectProjectGenerationGate::class)->forGenerateWorkingItems($execution);
        self::assertTrue($execGate->enabled);
        self::assertNotEmpty($execGate->eligibleTaskIds);
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

    private function createTask(SeoProject $project, string $token): int
    {
        $token = $token.'-'.uniqid();
        $task = SeoProjectTask::query()->create([
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
        ]);

        return (int) $task->id;
    }

    private function createRewriteTask(SeoProject $project, int $articleId, string $token): int
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
        ]);

        return (int) $task->id;
    }

    private function uniqueArticleId(): int
    {
        $this->articleSeq++;

        return 9_100_000 + (int) (microtime(true) * 1000) % 100_000 + $this->articleSeq;
    }
}
