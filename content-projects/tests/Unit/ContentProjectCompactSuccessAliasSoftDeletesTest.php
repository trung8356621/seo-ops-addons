<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectCompactSuccessService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression: aliased seo_project_tasks queries must not emit SoftDeletes
 * as `seo_project_tasks.deleted_at` (MySQL rejects the original table name).
 *
 * @requires extension pdo_mysql
 */
final class ContentProjectCompactSuccessAliasSoftDeletesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    private int $seq = 0;

    private ContentProjectCompactSuccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local mysql + omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            || ! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')
        ) {
            $this->markTestSkipped('seo_project_tasks (+ deleted_at) not available on omi_seo_ai.');
        }

        $this->service = app(ContentProjectCompactSuccessService::class);
    }

    public function test_domain_options_for_month_runs_without_alias_sql_error(): void
    {
        [$site, $month] = $this->seedEligibleFixture();

        $options = $this->service->domainOptionsForMonth($month);

        self::assertArrayHasKey((int) $site->id, $options);
        self::assertSame((string) $site->domain, $options[(int) $site->id]);
    }

    public function test_domain_options_excludes_soft_deleted_archived_and_draft(): void
    {
        $owner = $this->makeOwner();
        $month = '2026-08';
        $monthDate = '2026-08-01';

        $eligibleSite = $this->makeSite($owner, 'eligible');
        $softDeletedSite = $this->makeSite($owner, 'soft-deleted');
        $archivedTaskSite = $this->makeSite($owner, 'archived-task');
        $archivedProjectSite = $this->makeSite($owner, 'archived-project');
        $draftSite = $this->makeSite($owner, 'draft');
        $otherMonthSite = $this->makeSite($owner, 'other-month');

        $eligibleProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING);
        $this->makeTask($eligibleProject, (int) $eligibleSite->id, $monthDate);

        $softDeletedProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING);
        $softDeletedTask = $this->makeTask($softDeletedProject, (int) $softDeletedSite->id, $monthDate);
        $softDeletedTask->delete();

        $archivedTaskProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING);
        $archivedTask = $this->makeTask($archivedTaskProject, (int) $archivedTaskSite->id, $monthDate);
        $archivedTask->forceFill(['archived_at' => now()])->save();

        $archivedProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING);
        $archivedProject->forceFill(['archived_at' => now()])->save();
        $this->makeTask($archivedProject, (int) $archivedProjectSite->id, $monthDate);

        $draftProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_DRAFT);
        $this->makeTask($draftProject, (int) $draftSite->id, $monthDate);

        $otherMonthProject = $this->makeProject($owner, '2026-09-01', SeoProject::STATUS_PENDING);
        $this->makeTask($otherMonthProject, (int) $otherMonthSite->id, '2026-09-01');

        $options = $this->service->domainOptionsForMonth($month);
        $siteIds = array_map('intval', array_keys($options));

        self::assertContains((int) $eligibleSite->id, $siteIds);
        self::assertNotContains((int) $softDeletedSite->id, $siteIds);
        self::assertNotContains((int) $archivedTaskSite->id, $siteIds);
        self::assertNotContains((int) $archivedProjectSite->id, $siteIds);
        self::assertNotContains((int) $draftSite->id, $siteIds);
        self::assertNotContains((int) $otherMonthSite->id, $siteIds);
    }

    public function test_domain_options_includes_monthly_and_excludes_archive_kind(): void
    {
        $owner = $this->makeOwner();
        $month = '2026-08';
        $monthDate = '2026-08-01';

        $monthlySite = $this->makeSite($owner, 'monthly');
        $archiveKindSite = $this->makeSite($owner, 'archive-kind');

        $monthly = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING, SeoProject::KIND_MONTHLY);
        $this->makeTask($monthly, (int) $monthlySite->id, $monthDate);

        $archiveKind = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING, SeoProject::KIND_ARCHIVE);
        $this->makeTask($archiveKind, (int) $archiveKindSite->id, $monthDate);

        $options = $this->service->domainOptionsForMonth($month);
        $siteIds = array_map('intval', array_keys($options));

        self::assertContains((int) $monthlySite->id, $siteIds);
        self::assertNotContains((int) $archiveKindSite->id, $siteIds);
    }

    public function test_build_scope_snapshot_excludes_soft_deleted_and_runs_without_alias_error(): void
    {
        $owner = $this->makeOwner();
        $month = '2026-08';
        $monthDate = '2026-08-01';
        $site = $this->makeSite($owner, 'scope');

        $liveProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING);
        $this->makeTask($liveProject, (int) $site->id, $monthDate);

        $softOnlyProject = $this->makeProject($owner, $monthDate, SeoProject::STATUS_PENDING);
        $softTask = $this->makeTask($softOnlyProject, (int) $site->id, $monthDate);
        $softTask->delete();

        $method = new ReflectionMethod(ContentProjectCompactSuccessService::class, 'buildScopeSnapshot');
        $method->setAccessible(true);
        /** @var array{projects: list<array{project_id: int}>} $scope */
        $scope = $method->invoke($this->service, (int) $site->id, $month, false);

        $projectIds = array_map(
            static fn (array $row): int => (int) $row['project_id'],
            $scope['projects'],
        );

        self::assertContains((int) $liveProject->id, $projectIds);
        self::assertNotContains((int) $softOnlyProject->id, $projectIds);
    }

    /**
     * @return array{0: Site, 1: string}
     */
    private function seedEligibleFixture(): array
    {
        $owner = $this->makeOwner();
        $month = '2026-08';
        $site = $this->makeSite($owner, 'run');
        $project = $this->makeProject($owner, '2026-08-01', SeoProject::STATUS_PENDING);
        $this->makeTask($project, (int) $site->id, '2026-08-01');

        return [$site, $month];
    }

    private function makeOwner(): User
    {
        return User::query()->create([
            'name' => 'Compact Alias Owner',
            'email' => 'compact-alias-'.uniqid('', true).'@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    private function makeSite(User $owner, string $label): Site
    {
        return Site::query()->create([
            'user_id' => (int) $owner->id,
            'domain' => 'compact-'.$label.'-'.uniqid('', true).'.test',
            'status' => 'active',
        ]);
    }

    private function makeProject(
        User $owner,
        string $monthDate,
        string $status,
        ?string $kind = SeoProject::KIND_MONTHLY,
    ): SeoProject {
        return SeoProject::query()->create([
            'name' => 'Compact alias '. (++$this->seq),
            'user_id' => (int) $owner->id,
            'site_id' => null,
            'month' => $monthDate,
            'status' => $status,
            'kind' => $kind,
            'total_tasks' => 0,
        ]);
    }

    private function makeTask(SeoProject $project, int $siteId, string $monthDate): SeoProjectTask
    {
        $n = ++$this->seq;

        return SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'compact-alias-'.$n,
            'keyword' => 'compact-alias-'.$n,
            'title' => 'compact-alias-'.$n,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => $monthDate,
            'status' => SeoProjectTask::STATUS_PENDING,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
        ]);
    }
}
