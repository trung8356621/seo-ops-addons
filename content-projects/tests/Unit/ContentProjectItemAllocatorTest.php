<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAllocator;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContentProjectItemAllocatorTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
        ) {
            $this->markTestSkipped('seo_projects tables are not available.');
        }
    }

    public function test_source_with_enough_capacity_does_not_create_continuation(): void
    {
        $source = $this->createMonthlyProject(userId: 91001, siteId: 92001, month: '2026-07-01');
        $max = $source->maxTasksAllowed();
        $this->fillProject($source, $max - 5);

        $summary = $this->allocateNewTasks($source, 5);

        $source->refresh();
        self::assertSame($max, $source->registeredTaskCount());
        self::assertCount(1, $summary);
        self::assertSame((int) $source->id, (int) $summary[0]['project_id']);
        self::assertSame(1, $this->chainProjectCount(91001, 92001));
    }

    public function test_partial_overflow_fills_source_then_next_month_clone(): void
    {
        $source = $this->createMonthlyProject(userId: 91002, siteId: 92002, month: '2026-07-01');
        $max = $source->maxTasksAllowed();
        $this->fillProject($source, $max - 5);

        $summary = $this->allocateNewTasks($source, 14);

        $source->refresh();
        self::assertSame($max, $source->registeredTaskCount());
        self::assertSame(5, $summary[0]['added']);
        self::assertSame(9, $summary[1]['added'] ?? 0);
        self::assertSame('08/2026', $summary[1]['month'] ?? null);

        $august = $this->findChainMonth(91002, 92002, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame(91002, (int) $august->user_id);
        self::assertSame(92002, (int) $august->site_id);
        self::assertSame(9, $august->registeredTaskCount());
    }

    public function test_full_source_sends_all_items_to_next_month(): void
    {
        $source = $this->createMonthlyProject(userId: 91003, siteId: 92003, month: '2026-07-01');
        $this->fillProject($source, $source->maxTasksAllowed());

        $this->allocateNewTasks($source, 14);

        $source->refresh();
        self::assertSame($source->maxTasksAllowed(), $source->registeredTaskCount());
        $august = $this->findChainMonth(91003, 92003, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame(14, $august->registeredTaskCount());
    }

    public function test_multiple_months_split_across_capacity(): void
    {
        $source = $this->createMonthlyProject(userId: 91004, siteId: 92004, month: '2026-07-01');
        $julyMax = $source->maxTasksAllowed();

        $this->allocateNewTasks($source, 90);

        $july = $source->fresh();
        $august = $this->findChainMonth(91004, 92004, '2026-08-01');
        $september = $this->findChainMonth(91004, 92004, '2026-09-01');

        self::assertSame($julyMax, $july?->registeredTaskCount());
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame($august->maxTasksAllowed(), $august->registeredTaskCount());
        self::assertInstanceOf(SeoProject::class, $september);
        self::assertSame(90 - $julyMax - $august->maxTasksAllowed(), $september->registeredTaskCount());
        self::assertNull($this->findChainMonth(91004, 92004, '2026-10-01'));
    }

    public function test_december_overflow_rolls_to_january_next_year(): void
    {
        $source = $this->createMonthlyProject(userId: 91005, siteId: 92005, month: '2026-12-01');
        $this->fillProject($source, $source->maxTasksAllowed());

        $this->allocateNewTasks($source, 3);

        $january = $this->findChainMonth(91005, 92005, '2027-01-01');
        self::assertInstanceOf(SeoProject::class, $january);
        self::assertSame('project 1/2027', $january->name);
        self::assertSame(3, $january->registeredTaskCount());
    }

    public function test_continuation_keeps_source_owner_site_and_description(): void
    {
        $source = $this->createMonthlyProject(
            userId: 91006,
            siteId: 92006,
            month: '2026-07-01',
            description: 'writer-a-config',
        );
        $this->fillProject($source, $source->maxTasksAllowed());

        $this->allocateNewTasks($source, 2);

        $august = $this->findChainMonth(91006, 92006, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame(91006, (int) $august->user_id);
        self::assertSame(92006, (int) $august->site_id);
        self::assertSame('writer-a-config', $august->description);
        self::assertSame(SeoProject::KIND_MONTHLY, (string) $august->kind);
        self::assertSame(SeoProject::STATUS_MANUAL, (string) $august->status);
    }

    public function test_does_not_use_another_users_project_on_same_domain(): void
    {
        $source = $this->createMonthlyProject(userId: 91007, siteId: 92007, month: '2026-07-01');
        $userBAugust = $this->createMonthlyProject(userId: 91077, siteId: 92007, month: '2026-08-01');
        $this->fillProject($source, $source->maxTasksAllowed());
        $userBBefore = $userBAugust->registeredTaskCount();

        $this->allocateNewTasks($source, 4);

        $userAAugust = $this->findChainMonth(91007, 92007, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $userAAugust);
        self::assertNotSame((int) $userBAugust->id, (int) $userAAugust->id);
        self::assertSame(91007, (int) $userAAugust->user_id);
        self::assertSame(4, $userAAugust->registeredTaskCount());
        self::assertSame($userBBefore, $userBAugust->fresh()?->registeredTaskCount());
    }

    public function test_reuses_existing_same_chain_continuation(): void
    {
        $source = $this->createMonthlyProject(userId: 91008, siteId: 92008, month: '2026-07-01');
        $existingAugust = $this->createMonthlyProject(userId: 91008, siteId: 92008, month: '2026-08-01');
        $this->fillProject($source, $source->maxTasksAllowed());
        $this->fillProject($existingAugust, 2);

        $this->allocateNewTasks($source, 3);

        self::assertSame(1, $this->chainMonthCount(91008, 92008, '2026-08-01'));
        self::assertSame(5, $existingAugust->fresh()?->registeredTaskCount());
    }

    public function test_full_continuation_continues_to_following_month(): void
    {
        $source = $this->createMonthlyProject(userId: 91009, siteId: 92009, month: '2026-07-01');
        $august = $this->createMonthlyProject(userId: 91009, siteId: 92009, month: '2026-08-01');
        $this->fillProject($source, $source->maxTasksAllowed());
        $this->fillProject($august, $august->maxTasksAllowed());

        $this->allocateNewTasks($source, 6);

        $september = $this->findChainMonth(91009, 92009, '2026-09-01');
        self::assertInstanceOf(SeoProject::class, $september);
        self::assertSame(6, $september->registeredTaskCount());
        self::assertSame($august->maxTasksAllowed(), $august->fresh()?->registeredTaskCount());
    }

    public function test_sequential_allocations_recount_and_never_exceed_capacity(): void
    {
        $source = $this->createMonthlyProject(userId: 91010, siteId: 92010, month: '2026-07-01');
        $max = $source->maxTasksAllowed();
        $this->fillProject($source, $max - 1);

        $this->allocateNewTasks($source, 1);
        $this->allocateNewTasks($source, 1);

        $source->refresh();
        self::assertSame($max, $source->registeredTaskCount());
        $august = $this->findChainMonth(91010, 92010, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame(1, $august->registeredTaskCount());
        self::assertLessThanOrEqual($max, $source->registeredTaskCount());
        self::assertLessThanOrEqual($august->maxTasksAllowed(), $august->registeredTaskCount());
    }

    public function test_duplicate_retry_does_not_readd_or_create_extra_month(): void
    {
        $source = $this->createMonthlyProject(userId: 91011, siteId: 92011, month: '2026-07-01');
        $phrases = ['retry-kw-one', 'retry-kw-two'];

        $first = app(KeywordProjectAssignmentService::class)->assignPhrases(
            $phrases,
            (int) $source->id,
            92011,
        );
        $second = app(KeywordProjectAssignmentService::class)->assignPhrases(
            $phrases,
            (int) $source->id,
            92011,
        );

        self::assertSame(2, $first['added']);
        self::assertSame(0, $second['added']);
        self::assertSame(2, $second['duplicate']);
        self::assertSame(2, $source->fresh()?->registeredTaskCount());
        self::assertSame(1, $this->chainProjectCount(91011, 92011));
    }

    public function test_continuation_does_not_clone_runtime_state(): void
    {
        $source = $this->createMonthlyProject(userId: 91012, siteId: 92012, month: '2026-07-01');
        $this->fillProject($source, $source->maxTasksAllowed());
        SeoProjectRun::query()->create([
            'project_id' => (int) $source->id,
            'user_id' => 91012,
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => 1,
            'succeeded' => 0,
            'failed' => 0,
            'items' => [['task_id' => 1]],
            'settings' => ['generate_post_images' => true],
        ]);

        $this->allocateNewTasks($source, 2);

        $august = $this->findChainMonth(91012, 92012, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame(2, $august->registeredTaskCount());
        self::assertSame(0, $august->runs()->count());
        self::assertSame(2, (int) $august->total_tasks);
        self::assertNull($august->archived_at);
        self::assertFalse(
            $august->tasks()->where('source_content', 'like', 'filler-%')->exists(),
            'Continuation must not inherit source filler items.',
        );
    }

    public function test_ignore_monthly_capacity_flag_cannot_exceed_source_capacity(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            $this->markTestSkipped('articles table is not available.');
        }

        $source = $this->createMonthlyProject(userId: 91013, siteId: 92013, month: '2026-07-01');
        $this->fillProject($source, $source->maxTasksAllowed() - 1);

        $articles = Collection::make([
            $this->createArticle(92013, 'Cap article A'),
            $this->createArticle(92013, 'Cap article B'),
        ]);

        $summary = app(SeoIssueProjectTaskAssignmentService::class)->assignArticles(
            $articles,
            (int) $source->id,
            SeoProjectTask::TYPE_REWRITE,
            ignoreMonthlyCapacity: true,
        );

        $source->refresh();
        self::assertSame($source->maxTasksAllowed(), $source->registeredTaskCount());
        self::assertSame(2, $summary['added']);
        $august = $this->findChainMonth(91013, 92013, '2026-08-01');
        self::assertInstanceOf(SeoProject::class, $august);
        self::assertSame(1, $august->registeredTaskCount());
    }

    /**
     * @return list<array{project_id:int, month:string, added:int}>
     */
    private function allocateNewTasks(SeoProject $source, int $count): array
    {
        $allocations = [];

        DB::connection($source->getConnectionName())->transaction(function () use ($source, $count, &$allocations): void {
            $session = app(ContentProjectItemAllocator::class)->begin($source);
            for ($i = 0; $i < $count; $i++) {
                $target = $session->projectWithRemainingCapacity();
                self::assertInstanceOf(SeoProject::class, $target);
                self::assertGreaterThan(0, (int) $target->getKey());

                $token = 'alloc-'.$this->seq++;
                SeoProjectTask::query()->create([
                    'project_id' => (int) $target->getKey(),
                    'site_id' => (int) $target->site_id,
                    'type' => SeoProjectTask::TYPE_CREATE,
                    'source_content' => $token,
                    'keyword' => $token,
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'target_date' => $target->monthCarbon()->copy()->addDays($session->occupiedCount($target))->format('Y-m-d'),
                    'status' => SeoProjectTask::STATUS_PENDING,
                    'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
                ]);
                $session->recordAdded($target);
            }
            $allocations = $session->allocations();
            $session->syncTouchedCounters();
        });

        return $allocations;
    }

    private function createMonthlyProject(int $userId, int $siteId, string $month, ?string $description = null): SeoProject
    {
        $carbon = \Carbon\Carbon::parse($month)->startOfMonth();

        return SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth($carbon),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => $carbon->format('Y-m-d'),
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'description' => $description,
        ]);
    }

    private function fillProject(SeoProject $project, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $token = 'filler-'.$project->id.'-'.$i;
            SeoProjectTask::query()->create([
                'project_id' => (int) $project->id,
                'site_id' => (int) $project->site_id,
                'type' => SeoProjectTask::TYPE_CREATE,
                'source_content' => $token,
                'keyword' => $token,
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'target_date' => $project->monthCarbon()->copy()->addDays(min($i, 27))->format('Y-m-d'),
                'status' => SeoProjectTask::STATUS_PENDING,
                'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            ]);
        }
        $project->syncTotalTasksCounter();
        $project->unsetRelation('tasks');
    }

    private function findChainMonth(int $userId, int $siteId, string $month): ?SeoProject
    {
        $found = SeoProject::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->whereDate('month', $month)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();

        return $found instanceof SeoProject ? $found : null;
    }

    private function chainProjectCount(int $userId, int $siteId): int
    {
        return (int) SeoProject::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->count();
    }

    private function chainMonthCount(int $userId, int $siteId, string $month): int
    {
        return (int) SeoProject::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->whereDate('month', $month)
            ->count();
    }

    private function createArticle(int $siteId, string $title): SeoArticle
    {
        $slug = 'alloc-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'a').'-'.$this->seq++;

        return SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
        ]);
    }
}
