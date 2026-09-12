<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\Social\Models\SeoArticleSocialLink;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Archived monthly export/charts must use SeoProjectArchiveItem cardinality
 * (1 row per unique archived article), not live seo_project_tasks.
 */
final class ContentProjectArchivedMonthlyCanonicalArticleCardinalityTest extends TestCase
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

    public function test_source_uses_archive_items_not_task_cardinality_for_archived_export(): void
    {
        $workloadSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->getFileName(),
        );
        $coreSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('archivedCanonicalItemQuery', $workloadSrc);
        self::assertStringContainsString('seo_project_archive_items as ai', $coreSrc);
        self::assertStringContainsString('COUNT(ai.id)', $coreSrc);
        self::assertStringNotContainsString('hydrateArchiveItemMaps', $workloadSrc);
    }

    public function test_duplicate_task_same_article_exports_one_row_and_matches_stats(): void
    {
        $fixture = $this->seedArchivedProjectWithDuplicateArticleTask();

        $archiveItemCount = SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', $fixture['archive_id'])
            ->count();
        self::assertSame(2, $archiveItemCount);

        $workload = app(ContentProjectArchivedMonthlyWorkloadService::class);
        $itemRows = $workload->itemRows('2026-07');
        $projectRows = array_values(array_filter(
            $itemRows,
            static fn (array $row): bool => (string) ($row['project_name'] ?? '') === $fixture['project_name'],
        ));

        self::assertCount(2, $projectRows, 'itemRows must be 1 per archive item, not per task');

        $articleIds = array_map(static fn (array $row): int => (int) ($row['article_id'] ?? 0), $projectRows);
        self::assertEqualsCanonicalizing(
            [$fixture['article_a'], $fixture['article_b']],
            $articleIds,
        );
        self::assertSame(1, count(array_filter($articleIds, static fn (int $id): bool => $id === $fixture['article_b'])));

        foreach ($projectRows as $row) {
            self::assertNotSame('', trim((string) ($row['title'] ?? '')), 'no empty title from phantom task');
            self::assertGreaterThan(0, (int) ($row['article_id'] ?? 0));
        }

        $domainItemCount = app(ContentProjectMonthlyWorkloadService::class)
            ->archivedCanonicalItemQuery('2026-07')
            ->whereRaw('COALESCE(t.site_id, art.site_id) = ?', [$fixture['site_id']])
            ->count();
        self::assertSame(2, $domainItemCount, 'archived domain cardinality must match archive items');

        $writerChart = $workload->articlesByWriter('2026-07');
        $writerCount = 0;
        foreach ($writerChart['rows'] as $row) {
            if ((int) ($row['user_id'] ?? 0) === $fixture['writer_id']) {
                $writerCount = (int) ($row['count'] ?? $row['total_count'] ?? 0);
                break;
            }
        }
        self::assertSame(2, $writerCount, 'archived writer chart must match archive item count');

        $payload = app(ContentProjectArchivedMonthExportService::class)->buildPayload('2026-07');
        self::assertSame(count($itemRows), (int) $payload['total_articles']);

        $sheetArticleRows = [];
        foreach ($payload['writer_sheets'] as $sheet) {
            if ((int) ($sheet['user_id'] ?? 0) !== $fixture['writer_id']) {
                continue;
            }
            foreach ($sheet['rows'] as $row) {
                if (($row['row_kind'] ?? '') === 'social') {
                    continue;
                }
                if ((string) ($row['project'] ?? '') !== $fixture['project_name']) {
                    continue;
                }
                $sheetArticleRows[] = $row;
            }
        }

        self::assertCount(2, $sheetArticleRows);
        $exportedIds = array_map(static fn (array $row): int => (int) ($row['article_id'] ?? 0), $sheetArticleRows);
        self::assertSame(1, count(array_filter($exportedIds, static fn (int $id): bool => $id === $fixture['article_b'])));
        foreach ($sheetArticleRows as $row) {
            self::assertNotSame('', trim((string) ($row['title'] ?? '')));
        }

        $canonicalCount = app(ContentProjectMonthlyWorkloadService::class)
            ->archivedCanonicalItemQuery('2026-07')
            ->where('p.id', $fixture['project_id'])
            ->count();
        self::assertSame(2, $canonicalCount);

        $liveTaskCount = SeoProjectTask::query()
            ->where('project_id', $fixture['project_id'])
            ->whereNull('archived_at')
            ->count();
        self::assertSame(3, $liveTaskCount, 'fixture still has 3 live tasks including duplicate');
    }

    public function test_social_child_rows_do_not_inflate_article_count(): void
    {
        $fixture = $this->seedArchivedProjectWithDuplicateArticleTask();

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_social_links')) {
            $this->markTestSkipped('Missing seo_article_social_links');
        }

        $socialUrl = 'https://facebook.com/posts/canonical-cardinality-'.$fixture['archive_id'];
        SeoArticleSocialLink::query()->create([
            'article_id' => $fixture['article_b'],
            'site_id' => $fixture['site_id'],
            'url' => $socialUrl,
            'url_hash' => hash('sha256', $socialUrl),
            'domain' => 'facebook.com',
            'source' => 'manual',
            'recorded_at' => now(),
            'created_by' => 1,
        ]);

        $payload = app(ContentProjectArchivedMonthExportService::class)->buildPayload('2026-07');

        $articleRows = 0;
        $socialRows = 0;
        $seenArticleB = 0;
        foreach ($payload['writer_sheets'] as $sheet) {
            if ((int) ($sheet['user_id'] ?? 0) !== $fixture['writer_id']) {
                continue;
            }
            foreach ($sheet['rows'] as $row) {
                if (($row['row_kind'] ?? '') === 'social') {
                    if (str_contains((string) ($row['hyperlink_url'] ?? ''), (string) $fixture['archive_id'])) {
                        $socialRows++;
                    }

                    continue;
                }
                if ((string) ($row['project'] ?? '') !== $fixture['project_name']) {
                    continue;
                }
                $articleRows++;
                if ((int) ($row['article_id'] ?? 0) === $fixture['article_b']) {
                    $seenArticleB++;
                }
            }
        }

        self::assertSame(2, $articleRows);
        self::assertSame(1, $seenArticleB);
        self::assertSame(1, $socialRows);

        $projectArticleCount = count(array_filter(
            app(ContentProjectArchivedMonthlyWorkloadService::class)->itemRows('2026-07'),
            static fn (array $row): bool => (string) ($row['project_name'] ?? '') === $fixture['project_name'],
        ));
        self::assertSame(2, $projectArticleCount);
    }

    public function test_null_article_id_duplicate_task_does_not_create_phantom_row(): void
    {
        $fixture = $this->seedArchivedProjectWithDuplicateArticleTask();

        $nullArticleTasks = SeoProjectTask::query()
            ->where('project_id', $fixture['project_id'])
            ->whereNull('article_id')
            ->count();
        self::assertSame(3, $nullArticleTasks);

        $rows = app(ContentProjectArchivedMonthlyWorkloadService::class)->itemRows('2026-07');
        $projectRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['project_name'] ?? '') === $fixture['project_name'],
        ));
        self::assertCount(2, $projectRows);
        foreach ($projectRows as $row) {
            self::assertGreaterThan(0, (int) ($row['article_id'] ?? 0));
            self::assertNotSame('', trim((string) ($row['title'] ?? '')));
        }
    }

    /**
     * 3 tasks / 2 unique articles after archive (post-reset article_id=null on tasks).
     *
     * @return array{
     *     project_id: int,
     *     archive_id: int,
     *     project_name: string,
     *     site_id: int,
     *     writer_id: int,
     *     article_a: int,
     *     article_b: int
     * }
     */
    private function seedArchivedProjectWithDuplicateArticleTask(): array
    {
        $nonce = (int) (microtime(true) * 1000) % 100000 + $this->seq;
        $siteId = 970000 + $nonce;
        $writerId = 980000 + $nonce;
        $projectName = 'Canonical Cardinality '.$nonce;

        $articleA = $this->createArticle($siteId, 'Article Unique A '.$nonce);
        $articleB = $this->createArticle($siteId, 'Article Dup Shared '.$nonce);

        $project = SeoProject::query()->create([
            'name' => $projectName,
            'user_id' => $writerId,
            'site_id' => $siteId,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_COMPLETED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 3,
            'archived_at' => '2026-07-20 12:00:00',
            'archived_by' => 1,
        ]);

        $keys = new ProjectTaskSourceKeyGenerator;
        $taskA = $this->createDetachedTask($project, $siteId, $keys, 'task-a-'.$nonce);
        $taskB = $this->createDetachedTask($project, $siteId, $keys, 'task-b-'.$nonce);
        $this->createDetachedTask($project, $siteId, $keys, 'task-c-dup-'.$nonce);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'owner_id' => $writerId,
            'project_name' => $projectName,
            'project_month' => 7,
            'project_year' => 2026,
            'articles_count' => 2,
            'total_articles' => 2,
            'completed_articles' => 2,
            'archived_by' => 1,
            'archived_at' => '2026-07-20 12:00:00',
        ]);

        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $articleA->id,
            'task_id' => (int) $taskA->id,
            'position' => 1,
            'article_snapshot' => [
                'article_id' => (int) $articleA->id,
                'title' => (string) $articleA->title,
                'primary_keyword' => 'kw-a-'.$nonce,
            ],
        ]);
        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $articleB->id,
            'task_id' => (int) $taskB->id,
            'position' => 2,
            'article_snapshot' => [
                'article_id' => (int) $articleB->id,
                'title' => (string) $articleB->title,
                'primary_keyword' => 'kw-b-'.$nonce,
            ],
        ]);

        $this->seq++;

        return [
            'project_id' => (int) $project->id,
            'archive_id' => (int) $archive->id,
            'project_name' => $projectName,
            'site_id' => $siteId,
            'writer_id' => $writerId,
            'article_a' => (int) $articleA->id,
            'article_b' => (int) $articleB->id,
        ];
    }

    private function createDetachedTask(
        SeoProject $project,
        int $siteId,
        ProjectTaskSourceKeyGenerator $keys,
        string $suffix,
    ): SeoProjectTask {
        $source = 'canonical-card-'.$suffix;

        return SeoProjectTask::query()->create([
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
            'keyword' => 'kw-'.$suffix,
            'title' => '',
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-07-10',
            'status' => SeoProjectTask::STATUS_PENDING,
            'article_id' => null,
            'completed_at' => null,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
        ]);
    }

    private function createArticle(int $siteId, string $title): SeoArticle
    {
        $slug = 'cc-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'a').'-'.$this->seq;

        return SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
        ]);
    }
}
