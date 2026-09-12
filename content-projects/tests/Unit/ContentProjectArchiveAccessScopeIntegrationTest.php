<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchiveAccessScope;
use Omnichannel\Addons\ContentProjects\Services\ImportLegacyContentArchiveService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Multi-domain archive tenant scope regressions (list + preview compatibility).
 */
final class ContentProjectArchiveAccessScopeIntegrationTest extends TestCase
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
            'seo_project_archives',
            'seo_project_archive_items',
            'articles',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->markTestSkipped("Missing table: {$table}");
            }
        }
    }

    public function test_single_domain_archive_visible_only_when_site_accessible(): void
    {
        $scope = app(ContentProjectArchiveAccessScope::class);
        $visible = $this->createArchive(siteId: 95001, month: 9, year: 2026, name: 'Single visible');
        $hidden = $this->createArchive(siteId: 95002, month: 9, year: 2026, name: 'Single hidden');

        $query = SeoProjectArchive::query()->whereIn('id', [(int) $visible->id, (int) $hidden->id]);
        $scope->constrainQuery($query, [95001]);
        $ids = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        self::assertContains((int) $visible->id, $ids);
        self::assertNotContains((int) $hidden->id, $ids);
        self::assertTrue($scope->userCanAccessArchive($visible, [95001]));
        self::assertFalse($scope->userCanAccessArchive($hidden, [95001]));
    }

    public function test_multi_domain_archive_visible_when_it_contains_accessible_item(): void
    {
        $scope = app(ContentProjectArchiveAccessScope::class);
        $archive = $this->createArchive(siteId: null, month: 9, year: 2026, name: 'Multi with access');
        $this->addItem($archive, siteId: 95011, title: 'A');
        $this->addItem($archive, siteId: 95012, title: 'B');

        $query = SeoProjectArchive::query()->whereKey((int) $archive->id);
        $scope->constrainQuery($query, [95011]);
        self::assertSame(1, $query->count());
        self::assertTrue($scope->userCanAccessArchive($archive->fresh(['items']), [95011]));
    }

    public function test_multi_domain_archive_hidden_when_no_accessible_item(): void
    {
        $scope = app(ContentProjectArchiveAccessScope::class);
        $archive = $this->createArchive(siteId: null, month: 9, year: 2026, name: 'Multi no access');
        $this->addItem($archive, siteId: 95021, title: 'Only foreign');

        $query = SeoProjectArchive::query()->whereKey((int) $archive->id);
        $scope->constrainQuery($query, [95099]);
        self::assertSame(0, $query->count());
        self::assertFalse($scope->userCanAccessArchive($archive->fresh(['items']), [95099]));
    }

    public function test_mixed_domain_preview_filters_to_accessible_rows_only(): void
    {
        $scope = app(ContentProjectArchiveAccessScope::class);
        $archive = $this->createArchive(siteId: null, month: 9, year: 2026, name: 'Mixed preview');
        $this->addItem($archive, siteId: 95031, title: 'Keep');
        $this->addItem($archive, siteId: 95032, title: 'Drop');

        $rows = [
            ['item_id' => 1, 'site_id' => 95031, 'title' => 'Keep'],
            ['item_id' => 2, 'site_id' => 95032, 'title' => 'Drop'],
        ];
        $filtered = $scope->filterPresenterRows($rows, [95031], $archive);

        self::assertCount(1, $filtered);
        self::assertSame(95031, (int) $filtered[0]['site_id']);
        self::assertTrue($scope->userCanAccessArchive($archive->fresh(['items']), [95031]));
    }

    public function test_legacy_import_shape_returned_by_list_query_with_month_year_filters(): void
    {
        $scope = app(ContentProjectArchiveAccessScope::class);
        $archive = $this->createArchive(siteId: null, month: 9, year: 2026, name: ImportLegacyContentArchiveService::PROJECT_NAME);
        $this->addItem($archive, siteId: 95041, title: 'Legacy-like');
        $this->addItem($archive, siteId: 95042, title: 'Legacy-like-2');

        $query = SeoProjectArchive::query()
            ->whereKey((int) $archive->id)
            ->where('project_month', 9)
            ->where('project_year', 2026);
        $scope->constrainQuery($query, [95041, 95042]);

        self::assertSame(1, $query->count());
        self::assertNull($archive->fresh()->site_id);
        self::assertSame(9, (int) $archive->fresh()->project_month);
        self::assertSame(2026, (int) $archive->fresh()->project_year);
    }

    public function test_unconditional_null_site_leak_is_not_used_in_access_scope(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(ContentProjectArchiveAccessScope::class))->getFileName(),
        );
        self::assertStringNotContainsString('orWhereNull', $source);
        self::assertStringContainsString('whereArchiveHasAccessibleItem', $source);
        self::assertStringContainsString('JSON_EXTRACT', $source);
    }

    private function createArchive(?int $siteId, int $month, int $year, string $name): SeoProjectArchive
    {
        $project = SeoProject::query()->create([
            'name' => $name.' '.$this->seq++,
            'user_id' => 1,
            'site_id' => $siteId,
            'month' => sprintf('%04d-%02d-01', $year, $month),
            'status' => SeoProject::STATUS_COMPLETED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'archived_at' => sprintf('%04d-%02d-04 03:00:00', $year, $month),
            'archived_by' => 1,
        ]);

        return SeoProjectArchive::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'owner_id' => 1,
            'project_name' => $name,
            'project_month' => $month,
            'project_year' => $year,
            'articles_count' => 0,
            'total_articles' => 0,
            'completed_articles' => 0,
            'archived_by' => 1,
            'archived_at' => sprintf('%04d-%02d-04 03:00:00', $year, $month),
            'summary_snapshot' => [
                'multi_domain' => $siteId === null,
                'domain_name' => $siteId === null ? 'Multiple domains' : null,
            ],
        ]);
    }

    private function addItem(SeoProjectArchive $archive, int $siteId, string $title): void
    {
        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => 'access-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'a').'-'.$this->seq++,
            'body' => '<p>'.$title.'</p>',
        ]);

        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $article->id,
            'task_id' => null,
            'position' => $this->seq,
            'article_snapshot' => [
                'article_id' => (int) $article->id,
                'site_id' => $siteId,
                'title' => $title,
                'domain' => 'site-'.$siteId.'.test',
            ],
        ]);
    }
}
