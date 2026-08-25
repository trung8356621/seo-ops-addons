<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAllocator;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Month is reporting period only — allocation stays on the source project (unlimited capacity).
 */
final class ContentProjectItemAllocatorTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

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
    }

    public function test_capacity_api_is_unlimited_for_monthly_projects(): void
    {
        $source = $this->createMonthlyProject(userId: 91001, siteId: 92001, month: '2026-09-01');
        self::assertSame(PHP_INT_MAX, $source->maxTasksAllowed());
        self::assertSame(PHP_INT_MAX, $source->remainingTaskCapacity());
        self::assertTrue($source->canRegisterMoreTasks());
    }

    public function test_large_batch_stays_on_source_without_month_continuation(): void
    {
        $source = $this->createMonthlyProject(userId: 91002, siteId: 92002, month: '2026-09-01');
        $this->fillProject($source, 10);

        $summary = $this->allocateNewTasks($source, 60);

        $source->refresh();
        self::assertSame(70, $source->registeredTaskCount());
        self::assertCount(1, $summary);
        self::assertSame((int) $source->id, (int) $summary[0]['project_id']);
        self::assertSame(60, $summary[0]['added']);
        self::assertSame(1, $this->chainProjectCount(91002, 92002));
        self::assertNull($this->findChainMonth(91002, 92002, '2026-10-01'));
    }

    public function test_ninety_items_valid_in_thirty_day_month(): void
    {
        $source = $this->createMonthlyProject(userId: 91003, siteId: 92003, month: '2026-09-01');
        self::assertSame(30, $source->monthCarbon()->daysInMonth);

        $this->allocateNewTasks($source, 90);

        $source->refresh();
        self::assertSame(90, $source->registeredTaskCount());
        self::assertSame(1, $this->chainProjectCount(91003, 92003));
    }

    public function test_multiple_execution_projects_same_month_allowed(): void
    {
        $a = $this->createMonthlyProject(userId: 91004, siteId: 92004, month: '2026-09-01', name: 'Batch A');
        $b = $this->createMonthlyProject(userId: 91004, siteId: 92004, month: '2026-09-01', name: 'Batch B');

        self::assertNotSame((int) $a->id, (int) $b->id);
        self::assertSame(2, $this->chainMonthCount(91004, 92004, '2026-09-01'));
    }

    public function test_duplicate_retry_does_not_readd(): void
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

    public function test_ignore_monthly_capacity_flag_still_adds_to_source(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            $this->markTestSkipped('articles table is not available.');
        }

        $source = $this->createMonthlyProject(userId: 91013, siteId: 92013, month: '2026-07-01');
        $this->fillProject($source, 5);

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
        self::assertSame(7, $source->registeredTaskCount());
        self::assertSame(2, $summary['added']);
        self::assertNull($this->findChainMonth(91013, 92013, '2026-08-01'));
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
                self::assertSame((int) $source->id, (int) $target->getKey());

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

    private function createMonthlyProject(
        int $userId,
        int $siteId,
        string $month,
        ?string $description = null,
        ?string $name = null,
    ): SeoProject {
        $carbon = \Carbon\Carbon::parse($month)->startOfMonth();

        return SeoProject::query()->create([
            'name' => $name ?? SeoProject::defaultNameFromMonth($carbon),
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
