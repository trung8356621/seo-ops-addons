<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ImportLegacyContentArchiveService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL integration for Legacy → canonical archive staging import.
 */
final class LegacyContentArchiveImportIntegrationTest extends TestCase
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
            'seo_content_archive_items',
            'articles',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->markTestSkipped("Missing table: {$table}");
            }
        }
    }

    public function test_dry_run_does_not_mutate_and_apply_is_idempotent_with_active_membership_safety(): void
    {
        $siteA = 94001;
        $siteB = 94002;
        $articleSafe = $this->createArticle($siteA, 'Legacy safe');
        $articleReused = $this->createArticle($siteB, 'Legacy reused');

        SeoContentArchiveItem::query()->create([
            'site_id' => $siteA,
            'article_id' => (int) $articleSafe->id,
            'archived_by' => 1,
            'completed_at' => '2026-08-01 10:00:00',
            'archived_at' => '2026-08-01 11:00:00',
            'source_content' => 'kw-safe',
        ]);
        SeoContentArchiveItem::query()->create([
            'site_id' => $siteB,
            'article_id' => (int) $articleReused->id,
            'archived_by' => 1,
            'completed_at' => '2026-09-04 03:00:00',
            'archived_at' => '2026-09-04 04:00:00',
            'source_content' => 'kw-reused',
        ]);

        $activeProject = SeoProject::query()->create([
            'name' => 'Active hold '.$this->seq++,
            'user_id' => 1,
            'site_id' => $siteB,
            'month' => '2026-09-01',
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        $activeTask = SeoProjectTask::query()->create([
            'project_id' => (int) $activeProject->id,
            'site_id' => $siteB,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'active-'.$this->seq,
            'source_key' => hash('sha256', 'active-hold-'.$this->seq),
            'keyword' => 'active',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-09-10',
            'status' => SeoProjectTask::STATUS_REVIEWING,
            'article_id' => (int) $articleReused->id,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
        ]);

        $projectsBefore = (int) SeoProject::query()->count();
        $archiveItemsBefore = (int) SeoProjectArchiveItem::query()->count();
        $importer = app(ImportLegacyContentArchiveService::class);

        $dry = $importer->dryRun();
        self::assertSame($projectsBefore, (int) SeoProject::query()->count());
        self::assertSame($archiveItemsBefore, (int) SeoProjectArchiveItem::query()->count());
        self::assertGreaterThanOrEqual(2, (int) $dry['valid_article_ids']);
        self::assertGreaterThanOrEqual(1, (int) $dry['active_cp_memberships']);

        $first = $importer->apply(1);
        self::assertNotNull($first['project_id']);
        self::assertNotNull($first['archive_id']);
        self::assertSame(
            ImportLegacyContentArchiveService::PROJECT_NAME,
            SeoProject::query()->find($first['project_id'])?->name,
        );

        $project = SeoProject::query()->findOrFail((int) $first['project_id']);
        self::assertNotNull($project->archived_at);
        self::assertNull($project->site_id);
        self::assertNotNull($project->month);
        self::assertSame(ImportLegacyContentArchiveService::META_IMPORT_SOURCE, $project->meta['import_source'] ?? null);

        $archive = SeoProjectArchive::query()->findOrFail((int) $first['archive_id']);
        self::assertNull($archive->site_id);

        $itemArticleIds = SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', (int) $archive->id)
            ->pluck('article_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        self::assertContains((int) $articleSafe->id, $itemArticleIds);
        self::assertContains((int) $articleReused->id, $itemArticleIds);

        $domains = SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', (int) $archive->id)
            ->whereIn('article_id', [(int) $articleSafe->id, (int) $articleReused->id])
            ->get()
            ->map(static fn (SeoProjectArchiveItem $item): int => (int) (($item->article_snapshot['site_id'] ?? 0)))
            ->unique()
            ->values()
            ->all();
        self::assertContains($siteA, $domains);
        self::assertContains($siteB, $domains);

        $safeItem = SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', (int) $archive->id)
            ->where('article_id', (int) $articleSafe->id)
            ->first();
        self::assertNotNull($safeItem);
        self::assertStringContainsString('2026-08-01', (string) ($safeItem->article_snapshot['completed_at'] ?? ''));

        // Active ownership untouched.
        $activeTask->refresh();
        self::assertSame((int) $articleReused->id, (int) $activeTask->article_id);
        self::assertSame((int) $activeProject->id, (int) $activeTask->project_id);

        $shells = SeoProjectTask::query()->where('project_id', (int) $project->id)->get();
        self::assertGreaterThanOrEqual(2, $shells->count());
        foreach ($shells as $shell) {
            self::assertNull($shell->article_id);
            self::assertSame(SeoProjectTask::STATUS_PENDING, (string) $shell->status);
        }

        $itemCount = (int) SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', (int) $archive->id)
            ->count();
        $taskCount = (int) SeoProjectTask::query()->where('project_id', (int) $project->id)->count();

        $second = $importer->apply(1);
        self::assertSame((int) $first['project_id'], (int) $second['project_id']);
        self::assertSame((int) $first['archive_id'], (int) $second['archive_id']);
        self::assertSame(
            1,
            (int) SeoProject::query()->where('name', ImportLegacyContentArchiveService::PROJECT_NAME)->count(),
        );
        self::assertSame(
            $itemCount,
            (int) SeoProjectArchiveItem::query()->where('seo_project_archive_id', (int) $archive->id)->count(),
        );
        self::assertSame(
            $taskCount,
            (int) SeoProjectTask::query()->where('project_id', (int) $project->id)->count(),
        );

        $recon = $importer->reconcile();
        self::assertSame([], $recon['missing_in_target']);
        self::assertSame([], $recon['duplicate_target_articles']);
        self::assertSame((int) $recon['source_unique_articles'], (int) $recon['target_unique_articles']);

        $presenter = app(ArchivePreviewArticlePresenter::class);
        $items = SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', (int) $archive->id)
            ->whereIn('article_id', [(int) $articleSafe->id, (int) $articleReused->id])
            ->get();
        $articlesById = $presenter->loadArticlesById($items);
        $rows = $presenter->presentItems($items, $articlesById, []);
        $rowSiteIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['site_id'] ?? 0),
            $rows,
        )));
        self::assertContains($siteA, $rowSiteIds);
        self::assertContains($siteB, $rowSiteIds);
    }

    public function test_manual_gc_skips_imported_legacy_article_reused_by_active_cp(): void
    {
        $siteId = 94011;
        $safeArticle = $this->createArticle($siteId, 'Import GC safe');
        $reusedArticle = $this->createArticle($siteId, 'Import GC reused');

        $importedProject = SeoProject::query()->create([
            'name' => 'Legacy import GC fixture '.$this->seq++,
            'user_id' => 1,
            'site_id' => null,
            'month' => '2026-09-01',
            'status' => SeoProject::STATUS_COMPLETED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'archived_at' => '2026-09-04 04:00:00',
            'archived_by' => 1,
            'meta' => [
                'import_source' => ImportLegacyContentArchiveService::META_IMPORT_SOURCE,
                'import_version' => ImportLegacyContentArchiveService::META_IMPORT_VERSION,
            ],
        ]);

        $keys = new ProjectTaskSourceKeyGenerator;
        $taskSafe = SeoProjectTask::query()->create([
            'project_id' => (int) $importedProject->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => ImportLegacyContentArchiveService::SOURCE_PREFIX.$safeArticle->id,
            'source_key' => $keys->generate(
                (int) $importedProject->id,
                SeoProjectTask::TYPE_CREATE,
                SeoProjectTask::POST_TYPE_ARTICLE,
                ImportLegacyContentArchiveService::SOURCE_PREFIX.$safeArticle->id,
            ),
            'keyword' => 'safe',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-08-01',
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => null,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
        ]);
        $taskReused = SeoProjectTask::query()->create([
            'project_id' => (int) $importedProject->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => ImportLegacyContentArchiveService::SOURCE_PREFIX.$reusedArticle->id,
            'source_key' => $keys->generate(
                (int) $importedProject->id,
                SeoProjectTask::TYPE_CREATE,
                SeoProjectTask::POST_TYPE_ARTICLE,
                ImportLegacyContentArchiveService::SOURCE_PREFIX.$reusedArticle->id,
            ),
            'keyword' => 'reused',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-09-04',
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => null,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
        ]);

        $activeProject = SeoProject::query()->create([
            'name' => 'Active reuse for legacy import '.$this->seq++,
            'user_id' => 1,
            'site_id' => $siteId,
            'month' => '2026-09-01',
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        SeoProjectTask::query()->create([
            'project_id' => (int) $activeProject->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'active-reuse-'.$this->seq,
            'source_key' => hash('sha256', 'active-reuse-'.$this->seq),
            'keyword' => 'active',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-09-10',
            'status' => SeoProjectTask::STATUS_REVIEWING,
            'article_id' => (int) $reusedArticle->id,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
        ]);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $importedProject->id,
            'site_id' => null,
            'owner_id' => 1,
            'project_name' => 'Legacy import GC fixture',
            'articles_count' => 2,
            'total_articles' => 2,
            'archived_by' => 1,
            'archived_at' => '2026-09-04 04:00:00',
            'summary_snapshot' => [
                'multi_domain' => true,
                'domain_name' => 'Multiple domains',
                'import_source' => ImportLegacyContentArchiveService::META_IMPORT_SOURCE,
            ],
        ]);

        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $safeArticle->id,
            'task_id' => (int) $taskSafe->id,
            'position' => 1,
            'article_snapshot' => [
                'article_id' => (int) $safeArticle->id,
                'site_id' => $siteId,
                'title' => 'Import GC safe',
            ],
        ]);
        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $reusedArticle->id,
            'task_id' => (int) $taskReused->id,
            'position' => 2,
            'article_snapshot' => [
                'article_id' => (int) $reusedArticle->id,
                'site_id' => $siteId,
                'title' => 'Import GC reused',
            ],
        ]);

        SeoArticleRevision::query()->create([
            'article_id' => (int) $safeArticle->id,
            'title' => 'clean-me',
            'content' => '<p>clean</p>',
            'user_id' => 1,
        ]);
        $reusedRevision = SeoArticleRevision::query()->create([
            'article_id' => (int) $reusedArticle->id,
            'title' => 'keep-me',
            'content' => '<p>keep</p>',
            'user_id' => 1,
        ]);

        $registry = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupRegistry::class);
        if ($registry->keys() === []) {
            foreach ([
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\EditorRevisionWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\CacheLockWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\ExecutionWorkspaceCleaner,
            ] as $cleaner) {
                $registry->register($cleaner);
            }
        }
        app()->instance(
            ContentProjectAiWorkspaceDestroyer::class,
            new ContentProjectAiWorkspaceDestroyer($registry),
        );
        app()->forgetInstance(ArchiveContentProjectService::class);

        $stats = app(ArchiveContentProjectService::class)->cleanupArchivedWorkspace(
            $archive->fresh(['project', 'items']),
            1,
        );

        self::assertSame(2, (int) ($stats['workspace_articles_considered'] ?? 0));
        self::assertSame(1, (int) ($stats['workspace_articles_safe'] ?? 0));
        self::assertSame(1, (int) ($stats['workspace_articles_skipped_reused'] ?? 0));
        self::assertNotNull(SeoArticleRevision::query()->find($reusedRevision->id));
    }

    private function createArticle(int $siteId, string $title): SeoArticle
    {
        $slug = 'legacy-imp-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'a').'-'.$this->seq++;

        return SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
        ]);
    }
}
