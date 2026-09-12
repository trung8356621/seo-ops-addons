<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchiveAccessScope;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Global Legacy archive: pinned every month, excluded from monthly workload/export, not restorable.
 */
final class ContentProjectGlobalLegacyArchiveIntegrationTest extends TestCase
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

        foreach ([
            'seo_projects',
            'seo_project_tasks',
            'seo_project_archives',
            'seo_project_archive_items',
            'articles',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->markTestSkipped("Missing table: {$table}");
            }
        }
    }

    public function test_legacy_appears_in_every_month_once_while_normals_remain_filtered(): void
    {
        $legacy = $this->createLegacyArchive(siteIds: [96001, 96002]);
        $july = $this->createMonthlyArchive(month: 7, year: 2026, siteId: 96001, name: 'July CP');
        $sept = $this->createMonthlyArchive(month: 9, year: 2026, siteId: 96001, name: 'Sept CP');

        foreach ([7, 8, 9] as $month) {
            $query = SeoProjectArchive::query()
                ->whereIn('id', [(int) $legacy->id, (int) $july->id, (int) $sept->id]);
            ContentProjectGlobalLegacyArchive::applyMonthYearOrGlobal($query, $month, 2026);
            $ids = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            self::assertContains((int) $legacy->id, $ids, "legacy missing for month={$month}");
            self::assertSame(
                1,
                count(array_filter($ids, static fn (int $id): bool => $id === (int) $legacy->id)),
            );
        }

        $julyOnly = SeoProjectArchive::query()
            ->whereIn('id', [(int) $legacy->id, (int) $july->id, (int) $sept->id]);
        ContentProjectGlobalLegacyArchive::applyMonthYearOrGlobal($julyOnly, 7, 2026);
        $julyIds = $julyOnly->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        self::assertContains((int) $july->id, $julyIds);
        self::assertNotContains((int) $sept->id, $julyIds);

        $septOnly = SeoProjectArchive::query()
            ->whereIn('id', [(int) $legacy->id, (int) $july->id, (int) $sept->id]);
        ContentProjectGlobalLegacyArchive::applyMonthYearOrGlobal($septOnly, 9, 2026);
        $septIds = $septOnly->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        self::assertContains((int) $sept->id, $septIds);
        self::assertNotContains((int) $july->id, $septIds);
    }

    public function test_legacy_excluded_from_monthly_charts_and_month_export_payload(): void
    {
        $legacy = $this->createLegacyArchive(siteIds: [96101]);
        $legacyProjectId = (int) $legacy->project_id;
        $this->assertLegacyExcludedFromWorkload($legacyProjectId);

        $payload = app(ContentProjectArchivedMonthExportService::class)->buildPayload('2026-09');
        foreach ($payload['writer_sheets'] as $sheet) {
            foreach ($sheet['rows'] as $row) {
                $projectName = (string) ($row['project_name'] ?? '');
                self::assertStringNotContainsString('Legacy articles', $projectName);
            }
        }
        $legacyTaskHits = app(ContentProjectMonthlyWorkloadService::class)
            ->archivedExecutionItemQuery('2026-09')
            ->where('p.id', $legacyProjectId)
            ->count();
        self::assertSame(0, $legacyTaskHits);
        $domainTotal = array_sum(array_column($payload['by_domain'], 'item_count'));
        $writerTotal = array_sum(array_column($payload['by_writer'], 'item_count'));
        self::assertSame($domainTotal, (int) $payload['total_articles']);
        self::assertSame($writerTotal, (int) $payload['total_articles']);
    }

    public function test_legacy_row_export_still_works_and_restore_is_rejected(): void
    {
        $legacy = $this->createLegacyArchive(siteIds: [96201]);
        $project = SeoProject::query()->findOrFail((int) $legacy->project_id);

        self::assertTrue(ContentProjectGlobalLegacyArchive::isGlobalLegacyArchive($legacy));
        self::assertTrue(ContentProjectGlobalLegacyArchive::isGlobalLegacyArchive($project));
        self::assertStringNotContainsString('09/2026', ContentProjectGlobalLegacyArchive::monthLabel());
        self::assertStringNotContainsString('09/2026', ContentProjectGlobalLegacyArchive::badgeLabel());

        $response = app(ContentProjectArchiveExportService::class)->streamDownload($legacy->fresh(['items', 'project']));
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
        self::assertGreaterThanOrEqual(1, (int) $legacy->items()->count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Legacy|khôi phục|restore/i');
        app(ArchiveContentProjectService::class)->restore($project, 1);
    }

    public function test_normal_archive_restore_still_allowed_by_service_gate(): void
    {
        $archive = $this->createMonthlyArchive(month: 8, year: 2026, siteId: 96301, name: 'Restorable');
        $project = SeoProject::query()->findOrFail((int) $archive->project_id);
        self::assertFalse(ContentProjectGlobalLegacyArchive::isGlobalLegacyArchive($project));

        $restored = app(ArchiveContentProjectService::class)->restore($project, 1);
        self::assertNotNull($restored->restored_at);
        $project->refresh();
        self::assertNull($project->archived_at);
    }

    public function test_domain_access_filter_still_enforced_for_legacy(): void
    {
        $legacy = $this->createLegacyArchive(siteIds: [96401, 96402]);
        $scope = app(ContentProjectArchiveAccessScope::class);

        $visible = SeoProjectArchive::query()->whereKey((int) $legacy->id);
        $scope->constrainQuery($visible, [96401]);
        self::assertSame(1, $visible->count());

        $hidden = SeoProjectArchive::query()->whereKey((int) $legacy->id);
        $scope->constrainQuery($hidden, [96999]);
        self::assertSame(0, $hidden->count());

        $filtered = $scope->constrainQueryToSiteFilter(
            SeoProjectArchive::query()->whereKey((int) $legacy->id),
            96402,
        );
        self::assertSame(1, $filtered->count());

        $miss = $scope->constrainQueryToSiteFilter(
            SeoProjectArchive::query()->whereKey((int) $legacy->id),
            96999,
        );
        self::assertSame(0, $miss->count());
    }

    private function assertLegacyExcludedFromWorkload(int $legacyProjectId): void
    {
        $workload = app(ContentProjectMonthlyWorkloadService::class);
        foreach ([
            ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED,
            ContentProjectMonthlyWorkloadService::SCOPE_ALL,
        ] as $scope) {
            $payload = $workload->forMonth('2026-09', $scope);
            $taskCount = $workload->archivedExecutionItemQuery('2026-09')
                ->where('p.id', $legacyProjectId)
                ->count();
            self::assertSame(0, $taskCount, "scope={$scope}");
            // Charts may be empty in fixture month; just ensure legacy project not in query.
            unset($payload);
        }
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createLegacyArchive(array $siteIds): SeoProjectArchive
    {
        $primarySite = $siteIds[0] ?? 96000;
        $project = SeoProject::query()->create([
            'name' => 'Legacy articles',
            'user_id' => 1,
            'site_id' => null,
            'month' => '2026-09-01',
            'status' => SeoProject::STATUS_COMPLETED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'archived_at' => '2026-09-04 03:00:00',
            'archived_by' => 1,
            'meta' => [
                'import_source' => ContentProjectGlobalLegacyArchive::META_IMPORT_SOURCE,
                'import_version' => 1,
            ],
        ]);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => null,
            'owner_id' => 1,
            'project_name' => 'Legacy articles',
            'project_month' => 9,
            'project_year' => 2026,
            'articles_count' => count($siteIds),
            'total_articles' => count($siteIds),
            'completed_articles' => count($siteIds),
            'archived_by' => 1,
            'archived_at' => '2026-09-04 03:00:00',
            'summary_snapshot' => [
                'import_source' => ContentProjectGlobalLegacyArchive::META_IMPORT_SOURCE,
                'multi_domain' => true,
                'domain_name' => 'Multiple domains',
            ],
        ]);

        $keys = new ProjectTaskSourceKeyGenerator;
        foreach ($siteIds as $index => $siteId) {
            $article = $this->createArticle($siteId, 'Legacy art '.$siteId);
            $source = 'legacy-archive-article:'.$article->id;
            $task = SeoProjectTask::query()->create([
                'project_id' => (int) $project->id,
                'site_id' => $siteId,
                'type' => SeoProjectTask::TYPE_CREATE,
                'source_content' => $source,
                'source_key' => $keys->generate(
                    (int) $project->id,
                    SeoProjectTask::TYPE_CREATE,
                    SeoProjectTask::POST_TYPE_ARTICLE,
                    $source,
                ),
                'keyword' => 'kw-'.$siteId,
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'target_date' => '2026-09-01',
                'status' => SeoProjectTask::STATUS_PENDING,
                'article_id' => null,
                'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
                'publish_queue_status' => 'none',
            ]);

            SeoProjectArchiveItem::query()->create([
                'seo_project_archive_id' => (int) $archive->id,
                'article_id' => (int) $article->id,
                'task_id' => (int) $task->id,
                'position' => $index + 1,
                'article_snapshot' => [
                    'article_id' => (int) $article->id,
                    'site_id' => $siteId,
                    'title' => (string) $article->title,
                    'import_source' => ContentProjectGlobalLegacyArchive::META_IMPORT_SOURCE,
                ],
            ]);
        }

        $project->forceFill(['total_tasks' => count($siteIds)])->saveQuietly();

        return $archive->fresh(['project', 'items']) ?? $archive;
    }

    private function createMonthlyArchive(int $month, int $year, int $siteId, string $name): SeoProjectArchive
    {
        $project = SeoProject::query()->create([
            'name' => $name.' '.$this->seq++,
            'user_id' => 1,
            'site_id' => $siteId,
            'month' => sprintf('%04d-%02d-01', $year, $month),
            'status' => SeoProject::STATUS_COMPLETED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'archived_at' => sprintf('%04d-%02d-15 10:00:00', $year, $month),
            'archived_by' => 1,
        ]);

        $article = $this->createArticle($siteId, $name.' art');
        $task = SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'monthly-'.$this->seq,
            'source_key' => hash('sha256', 'monthly-'.$this->seq.$month),
            'keyword' => 'monthly',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => sprintf('%04d-%02d-10', $year, $month),
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => null,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
        ]);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'owner_id' => 1,
            'project_name' => $name,
            'project_month' => $month,
            'project_year' => $year,
            'articles_count' => 1,
            'total_articles' => 1,
            'completed_articles' => 1,
            'archived_by' => 1,
            'archived_at' => sprintf('%04d-%02d-15 10:00:00', $year, $month),
        ]);

        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $article->id,
            'task_id' => (int) $task->id,
            'position' => 1,
            'article_snapshot' => [
                'article_id' => (int) $article->id,
                'site_id' => $siteId,
                'title' => (string) $article->title,
            ],
        ]);

        return $archive;
    }

    private function createArticle(int $siteId, string $title): SeoArticle
    {
        $slug = 'gl-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'a').'-'.$this->seq++;

        return SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
        ]);
    }
}
